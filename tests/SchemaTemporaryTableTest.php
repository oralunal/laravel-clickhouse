<?php

declare(strict_types=1);

namespace Tests;

use ClickHouseDB\Exception\DatabaseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\SchemaBlueprint;
use RuntimeException;

/**
 * Temporary tables of Laravel's schema builder in a session of the clickhouse connection: Schema::create() with
 * temporary(), Schema::table() and change() on a temporary table, Schema::dropTemporary(), dropTemporaryIfExists()
 * and hasTemporaryTable(), and the refusal of a temporary table outside session() or missing from it.
 */
class SchemaTemporaryTableTest extends TestCase
{
    /** A temporary table, which lives only in its session. */
    private const TEMPORARY = 'schema_temporary_ids';

    /** A database table that a temporary table of the same name shadows in a session. */
    private const SHADOWED = 'schema_temporary_shadowed';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropDatabaseTables();
    }

    protected function tearDown(): void
    {
        $this->dropDatabaseTables();

        parent::tearDown();
    }

    public function test_a_temporary_table_lives_in_the_session(): void
    {
        $connection = $this->connection();
        $connection->enableQueryLog();
        $model = new class extends BaseModel {
            protected $table = 'schema_temporary_ids';
        };

        $found = $connection->session(function (Connection $connection) use ($model): array {
            Schema::connection('clickhouse')->create(self::TEMPORARY, function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('id');
                $table->string('name');
            });
            $connection->table(self::TEMPORARY)->insert([['id' => 1, 'name' => 'a']]);
            $model::insertAssoc([['id' => 2, 'name' => 'b']]);

            return [
                'rows' => $model::select()->orderBy('id')->getRows(),
                'table' => $connection->select(
                    "SELECT database, engine, is_temporary FROM system.tables WHERE name = '" . self::TEMPORARY . "'"
                ),
                'hasTemporaryTable' => Schema::connection('clickhouse')->hasTemporaryTable(self::TEMPORARY),
                'hasTable' => Schema::connection('clickhouse')->hasTable(self::TEMPORARY),
            ];
        });

        $this->assertSame([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']], $found['rows']);
        $this->assertSame([['database' => '', 'engine' => 'Memory', 'is_temporary' => 1]], $found['table']);
        $this->assertTrue($found['hasTemporaryTable']);
        $this->assertFalse($found['hasTable']);
        $this->assertContains(
            'CREATE TEMPORARY TABLE `' . self::TEMPORARY . '` (`id` Int32, `name` String)',
            array_column($connection->getQueryLog(), 'query')
        );

        $this->assertFalse(Schema::connection('clickhouse')->hasTemporaryTable(self::TEMPORARY));
        $this->assertSame('0', $this->countTables("name = '" . self::TEMPORARY . "'"));
    }

    /**
     * ClickHouse 26.8 keeps the parentheses of the ORDER BY (`id`) that the schema builder writes in
     * create_table_query, ORDER BY (id), where 24.8 and 26.3 print ORDER BY id; the test reads it without them.
     */
    public function test_a_temporary_table_takes_the_engine_of_the_blueprint(): void
    {
        $table = $this->connection()->session(function (Connection $connection): array {
            Schema::connection('clickhouse')->create(self::TEMPORARY, function (SchemaBlueprint $table) {
                $table->temporary();
                $table->engine('MergeTree()');
                $table->integer('id');
                $table->string('name');
            });

            return $connection->select(
                "SELECT engine, create_table_query FROM system.tables WHERE database = '' AND name = '"
                . self::TEMPORARY . "'"
            );
        });
        $table = array_map(
            fn (array $row): array => array_replace(
                $row,
                ['create_table_query' => str_replace(' ORDER BY (id)', ' ORDER BY id', $row['create_table_query'])]
            ),
            $table
        );

        $this->assertSame(
            [[
                'engine' => 'MergeTree',
                'create_table_query' => 'CREATE TEMPORARY TABLE ' . self::TEMPORARY
                    . ' (`id` Int32, `name` String) ENGINE = MergeTree ORDER BY id',
            ]],
            $table
        );
    }

    public function test_a_temporary_table_outside_a_session_throws_and_creates_nothing(): void
    {
        try {
            Schema::connection('clickhouse')->create(self::TEMPORARY, function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('id');
            });
            $this->fail('Schema::create() should refuse a temporary table outside a session.');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith(
                'Cannot create the temporary table ' . self::TEMPORARY . ' outside a session',
                $exception->getMessage()
            );
        }

        $this->assertSame('0', $this->countTables("name = '" . self::TEMPORARY . "'"));
    }

    /**
     * ClickHouse's DROP TABLE drops a temporary table of the name first, and the database table once there is none;
     * dropTemporaryIfExists() only ever drops the temporary table.
     */
    public function test_drop_temporary_if_exists_leaves_the_database_table(): void
    {
        $this->createShadowedTable();

        $this->connection()->session(function (): void {
            $schema = Schema::connection('clickhouse');
            $schema->create(self::SHADOWED, function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('id');
            });

            $schema->dropTemporaryIfExists(self::SHADOWED);
            $schema->dropTemporaryIfExists(self::SHADOWED);

            $this->assertFalse($schema->hasTemporaryTable(self::SHADOWED));
        });

        $this->assertSame([['id' => 1, 'v' => 'db']], $this->connection()->select('SELECT id, v FROM ' . self::SHADOWED));
    }

    public function test_drop_temporary_drops_the_temporary_table_only(): void
    {
        $this->connection()->session(function (): void {
            $schema = Schema::connection('clickhouse');
            $schema->create(self::TEMPORARY, function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('id');
            });

            $schema->dropTemporary(self::TEMPORARY);
            $this->assertFalse($schema->hasTemporaryTable(self::TEMPORARY));

            $this->expectException(DatabaseException::class);
            $this->expectExceptionMessage("Temporary table schema_temporary_ids doesn't exist");

            $schema->dropTemporary(self::TEMPORARY);
        });
    }

    /**
     * Inside a session, Schema::table() changes a temporary table that shadows a database table, and change() reads
     * the column of the temporary table for its checks.
     */
    public function test_schema_table_changes_the_temporary_table_of_the_session(): void
    {
        $this->createShadowedTable();

        $this->connection()->session(function (Connection $connection): void {
            $schema = Schema::connection('clickhouse');
            $schema->create(self::SHADOWED, function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('id');
                $table->string('v')->nullable();
            });
            $connection->statement('INSERT INTO ' . self::SHADOWED . ' VALUES (2, NULL)');

            try {
                $schema->table(self::SHADOWED, fn (SchemaBlueprint $table) => $table->string('v')->change());
                $this->fail('change() should see the NULL value of the temporary table.');
            } catch (RuntimeException $exception) {
                $this->assertStringStartsWith(
                    'The column [v] of the table [' . self::SHADOWED . '] holds NULL values',
                    $exception->getMessage()
                );
            }

            $connection->statement('ALTER TABLE ' . self::SHADOWED . " UPDATE v = 'tmp' WHERE v IS NULL");
            $schema->table(self::SHADOWED, function (SchemaBlueprint $table) {
                $table->string('v')->default('x')->change();
                $table->integer('extra');
            });

            $this->assertSame(
                [
                    ['name' => 'id', 'type' => 'Int32', 'default_expression' => ''],
                    ['name' => 'v', 'type' => 'String', 'default_expression' => "'x'"],
                    ['name' => 'extra', 'type' => 'Int32', 'default_expression' => ''],
                ],
                $connection->select(
                    "SELECT name, type, default_expression FROM system.columns WHERE database = ''"
                    . " AND table = '" . self::SHADOWED . "' ORDER BY position"
                )
            );
        });

        $this->assertSame(
            [['name' => 'id', 'type' => 'Int32'], ['name' => 'v', 'type' => 'Nullable(String)']],
            $this->connection()->select(
                "SELECT name, type FROM system.columns WHERE database = currentDatabase() AND table = '"
                . self::SHADOWED . "' ORDER BY position"
            )
        );
    }

    /**
     * Schema::table() with temporary() addresses the session's temporary table only: outside a session, and in a
     * session without such a temporary table, it throws before anything is sent, since its ALTER TABLE would change
     * the database table of that name instead. A temporary table cannot be renamed.
     */
    public function test_schema_table_with_temporary_needs_the_temporary_table_of_the_session(): void
    {
        $this->createShadowedTable();
        $schema = Schema::connection('clickhouse');
        $add = fn (string $column) => $schema->table(self::SHADOWED, function (SchemaBlueprint $table) use ($column) {
            $table->temporary();
            $table->integer($column);
        });
        $messages = [];

        try {
            $add('outside');
        } catch (QueryException $exception) {
            $messages[] = $exception->getMessage();
        }

        $this->connection()->session(function () use ($schema, $add, &$messages): void {
            try {
                $add('inside');
            } catch (QueryException $exception) {
                $messages[] = $exception->getMessage();
            }

            $schema->create(self::SHADOWED, function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('id');
            });

            try {
                $schema->table(self::SHADOWED, function (SchemaBlueprint $table) {
                    $table->temporary();
                    $table->rename(self::SHADOWED . '_renamed');
                });
            } catch (QueryException $exception) {
                $messages[] = $exception->getMessage();
            }
        });

        $this->assertCount(3, $messages);
        $this->assertStringStartsWith(
            'Cannot send ALTER TABLE for the temporary table ' . self::SHADOWED . ' outside a session',
            $messages[0]
        );
        $this->assertStringStartsWith(
            'Cannot send ALTER TABLE for the temporary table ' . self::SHADOWED . ': the session has no temporary table',
            $messages[1]
        );
        $this->assertStringStartsWith('Cannot rename the temporary table ' . self::SHADOWED, $messages[2]);
        $this->assertSame(
            [['name' => 'id'], ['name' => 'v']],
            $this->connection()->select(
                "SELECT name FROM system.columns WHERE database = currentDatabase() AND table = '" . self::SHADOWED
                . "' ORDER BY position"
            )
        );
        $this->assertSame('0', $this->countTables("name = '" . self::SHADOWED . "_renamed'"));
    }

    public function test_a_pretended_temporary_table_is_logged_and_not_created(): void
    {
        $log = $this->connection()->session(fn (Connection $connection): array => [
            'log' => $connection->pretend(function (): void {
                Schema::connection('clickhouse')->create(self::TEMPORARY, function (SchemaBlueprint $table) {
                    $table->temporary();
                    $table->integer('id');
                });
            }),
            'exists' => $connection->hasTemporaryTable(self::TEMPORARY),
        ]);

        $this->assertSame(['CREATE TEMPORARY TABLE `' . self::TEMPORARY . '` (`id` Int32)'], array_column($log['log'], 'query'));
        $this->assertFalse($log['exists']);
    }

    private function connection(): Connection
    {
        return DB::connection('clickhouse');
    }

    /**
     * Create the database table SHADOWED with one row.
     */
    private function createShadowedTable(): void
    {
        Schema::connection('clickhouse')->create(self::SHADOWED, function (SchemaBlueprint $table) {
            $table->integer('id');
            $table->string('v')->nullable();
        });
        $this->connection()->statement('INSERT INTO ' . self::SHADOWED . " VALUES (1, 'db')");
    }

    /**
     * Count the tables of system.tables that a condition matches, outside any session.
     */
    private function countTables(string $condition): string
    {
        return (string) $this->connection()->scalar('SELECT count() FROM system.tables WHERE ' . $condition);
    }

    private function dropDatabaseTables(): void
    {
        $client = $this->connection()->getClient();
        $client->write('DROP TABLE IF EXISTS ' . self::SHADOWED . ' SYNC');
        $client->write('DROP TABLE IF EXISTS ' . self::SHADOWED . '_renamed SYNC');
        $client->write('DROP TABLE IF EXISTS ' . self::TEMPORARY . ' SYNC');
    }
}
