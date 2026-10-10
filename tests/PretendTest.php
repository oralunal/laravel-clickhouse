<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Tables\MergeTree;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Migration;

/**
 * DB::pretend() and php artisan migrate --pretend on a ClickHouse connection: every statement is logged and none is
 * sent, as on Laravel's own drivers. Selects return no rows while pretending.
 */
class PretendTest extends TestCase
{
    public const TABLE = 'pretend_rows';

    public const CREATED = 'pretend_created';

    public const CLUSTER_NAME_CONNECTION = 'clickhouse-pretend-cluster-name';

    private const LARAVEL_CONNECTION = 'clickhouse-pretend-laravel';

    private ?string $migrationPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $client->write('DROP TABLE IF EXISTS ' . self::CREATED . ' SYNC');
        $client->write('CREATE TABLE ' . self::TABLE . ' (id UInt32, v String) ENGINE = MergeTree ORDER BY id');
        $client->write('INSERT INTO ' . self::TABLE . " VALUES (1, 'a'), (2, 'b'), (3, 'c')");
    }

    protected function tearDown(): void
    {
        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $client->write('DROP TABLE IF EXISTS ' . self::CREATED . ' SYNC');

        if ($this->migrationPath !== null) {
            foreach (glob($this->migrationPath . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->migrationPath);
        }

        parent::tearDown();
    }

    public function test_a_pretended_create_table_is_logged_and_not_sent(): void
    {
        $sql = 'CREATE TABLE ' . self::CREATED . ' (id UInt8) ENGINE = Memory';

        $log = DB::connection('clickhouse')->pretend(fn (Connection $connection) => $connection->statement($sql));

        $this->assertSame([$sql], array_column($log, 'query'));
        $this->assertTableDoesNotExist(self::CREATED);
    }

    public function test_pretended_schema_builder_statements_change_nothing(): void
    {
        $log = DB::connection('clickhouse')->pretend(function (): void {
            Schema::connection('clickhouse')->create(self::CREATED, function (Blueprint $table): void {
                $table->integer('id');
            });
            Schema::connection('clickhouse')->drop(self::TABLE);
        });

        $this->assertCount(2, $log);
        $this->assertStringContainsString(self::CREATED, $log[0]['query']);
        $this->assertStringStartsWith('CREATE TABLE', $log[0]['query']);
        $this->assertStringStartsWith('DROP TABLE', $log[1]['query']);
        $this->assertTableDoesNotExist(self::CREATED);
        $this->assertSame(3, $this->countRows());
    }

    public function test_a_pretended_alter_table_leaves_the_columns(): void
    {
        $sql = 'ALTER TABLE ' . self::TABLE . ' ADD COLUMN extra String';

        $log = DB::connection('clickhouse')->pretend(fn () => DB::connection('clickhouse')->statement($sql));

        $this->assertSame([$sql], array_column($log, 'query'));
        $this->assertSame(
            [['name' => 'id'], ['name' => 'v']],
            DB::connection('clickhouse')->getClient()
                ->select("SELECT name FROM system.columns WHERE database = currentDatabase() AND table = '" . self::TABLE . "' ORDER BY position")
                ->rows()
        );
    }

    public function test_pretended_mutations_change_no_rows(): void
    {
        $connection = DB::connection('clickhouse');

        $log = $connection->pretend(function (Connection $connection): void {
            $this->assertSame(0, $connection->affectingStatement(
                'ALTER TABLE ' . self::TABLE . ' DELETE WHERE id = ? SETTINGS mutations_sync = 2',
                [1]
            ));
            $this->assertSame(0, $connection->delete('DELETE FROM ' . self::TABLE . ' WHERE id = ?', [2]));
            $this->assertTrue($connection->insert('INSERT INTO ' . self::TABLE . ' (id, v) VALUES (?, ?)', [4, "it's"]));
            $this->assertTrue($connection->unprepared('TRUNCATE TABLE ' . self::TABLE));
            $this->assertTrue($connection->statementOnEveryNode('DROP TABLE ' . self::TABLE));
        });

        $this->assertSame(
            [
                'ALTER TABLE ' . self::TABLE . ' DELETE WHERE id = 1 SETTINGS mutations_sync = 2',
                'DELETE FROM ' . self::TABLE . ' WHERE id = 2',
                'INSERT INTO ' . self::TABLE . " (id, v) VALUES (4, 'it\\'s')",
                'TRUNCATE TABLE ' . self::TABLE,
                'DROP TABLE ' . self::TABLE,
            ],
            array_column($log, 'query')
        );
        $this->assertSame(3, $this->countRows());
    }

    /**
     * Laravel's query builder, with fix_default_query_builder set to false, sends its mutations through
     * affectingStatement().
     */
    public function test_pretended_mutations_of_laravels_query_builder_change_no_rows(): void
    {
        $connection = $this->laravelBuilderConnection();

        $log = $connection->pretend(function (Connection $connection): void {
            $this->assertSame(0, $connection->table(self::TABLE)->where('v', "it's")->delete());
            $this->assertSame(0, $connection->table(self::TABLE)->where('id', 2)->update(['v' => 'z']));
            $this->assertTrue($connection->table(self::TABLE)->insert(['id' => 5, 'v' => 'e']));
        });

        $this->assertCount(3, $log);
        $this->assertStringContainsString("'it\\'s'", $log[0]['query']);
        $this->assertStringStartsWith('alter table "' . self::TABLE . '" delete', $log[0]['query']);
        $this->assertStringStartsWith('alter table "' . self::TABLE . '" update', $log[1]['query']);
        $this->assertStringStartsWith('insert into "' . self::TABLE . '"', $log[2]['query']);
        $this->assertSame(3, $this->countRows());
        $this->assertSame(
            [['v' => 'b']],
            DB::connection('clickhouse')->getClient()->select('SELECT v FROM ' . self::TABLE . ' WHERE id = 2')->rows()
        );
    }

    public function test_selects_return_no_rows_while_pretending(): void
    {
        $connection = DB::connection('clickhouse');

        $log = $connection->pretend(function (Connection $connection): void {
            $this->assertSame([], $connection->select('SELECT * FROM ' . self::TABLE . ' WHERE id > ?', [0]));
            $this->assertNull($connection->scalar('SELECT count() FROM ' . self::TABLE));
            $this->assertSame([], iterator_to_array($connection->cursor('SELECT * FROM ' . self::TABLE)));
        });

        $this->assertSame(
            [
                'SELECT * FROM ' . self::TABLE . ' WHERE id > 0',
                'SELECT count() FROM ' . self::TABLE,
                'SELECT * FROM ' . self::TABLE,
            ],
            array_column($log, 'query')
        );
    }

    public function test_migration_write_and_create_merge_tree_are_logged_while_pretending(): void
    {
        $migration = new class extends Migration {
            public function up(): void
            {
                static::write('CREATE TABLE ' . PretendTest::CREATED . ' (id UInt8) ENGINE = Memory');
                static::createMergeTree(PretendTest::CREATED, function (MergeTree $table): void {
                    $table->columns([$table->int32('id')])->orderBy('id');
                });
                static::write('DROP TABLE ' . PretendTest::TABLE);
            }
        };

        $log = DB::connection('clickhouse')->pretend(fn () => $migration->up());

        $this->assertCount(3, $log);
        $this->assertSame('CREATE TABLE ' . self::CREATED . ' (id UInt8) ENGINE = Memory', $log[0]['query']);
        $this->assertStringContainsString('CREATE TABLE', $log[1]['query']);
        $this->assertStringContainsString(self::CREATED, $log[1]['query']);
        $this->assertSame('DROP TABLE ' . self::TABLE, $log[2]['query']);
        $this->assertTableDoesNotExist(self::CREATED);
        $this->assertSame(3, $this->countRows());
    }

    /**
     * The schema builder's reads go through select(), so they are logged and return nothing while pretending.
     */
    public function test_schema_reads_find_nothing_while_pretending(): void
    {
        $results = [];

        $log = DB::connection('clickhouse')->pretend(function () use (&$results): void {
            $schema = Schema::connection('clickhouse');
            $results[] = $schema->hasTable(self::TABLE);
            $results[] = $schema->hasColumn(self::TABLE, 'id');
            $results[] = $schema->getColumns(self::TABLE);
        });

        $this->assertSame([false, false, []], $results);
        $this->assertCount(3, $log);
        foreach ($log as $entry) {
            $this->assertStringContainsString(self::TABLE, $entry['query']);
        }
        $this->assertStringContainsString('system.tables', $log[0]['query']);
        $this->assertStringContainsString('system.columns', $log[1]['query']);
        $this->assertStringContainsString('system.columns', $log[2]['query']);
    }

    /**
     * createMergeTree() creates the table ON CLUSTER the connection's cluster_name, and leaves the clause out for a
     * blank cluster_name.
     */
    public function test_create_merge_tree_follows_the_cluster_name_of_the_connection(): void
    {
        $migration = new class extends Migration {
            protected $connection = PretendTest::CLUSTER_NAME_CONNECTION;

            public function up(): void
            {
                static::createMergeTree(PretendTest::CREATED, function (MergeTree $table): void {
                    $table->columns([$table->int32('id')])->orderBy('id');
                });
            }
        };

        $onCluster = $this->clusterNameConnection('company_cluster')->pretend(fn () => $migration->up());
        $blank = $this->clusterNameConnection(' ')->pretend(fn () => $migration->up());

        $this->assertCount(1, $onCluster);
        $this->assertStringContainsString("ON CLUSTER 'company_cluster'", $onCluster[0]['query']);
        $this->assertCount(1, $blank);
        $this->assertStringContainsString(self::CREATED, $blank[0]['query']);
        $this->assertStringNotContainsString('ON CLUSTER', $blank[0]['query']);
        $this->assertTableDoesNotExist(self::CREATED);
    }

    public function test_migrate_pretend_prints_the_statements_and_runs_none(): void
    {
        $this->migrationPath = sys_get_temp_dir() . '/clickhouse-pretend-' . bin2hex(random_bytes(6));
        mkdir($this->migrationPath);
        file_put_contents($this->migrationPath . '/2030_01_01_000000_pretend_schema_create.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                protected $connection = 'clickhouse';

                public function up(): void
                {
                    Schema::connection('clickhouse')->create('pretend_created', function (Blueprint $table): void {
                        $table->integer('id');
                    });
                    Schema::connection('clickhouse')->drop('pretend_rows');
                }
            };
            PHP);
        file_put_contents($this->migrationPath . '/2030_01_01_000001_pretend_package_write.php', <<<'PHP'
            <?php

            return new class extends \Oralunal\LaravelClickHouse\Migration {
                public function up(): void
                {
                    static::write('TRUNCATE TABLE pretend_rows');
                }
            };
            PHP);

        Artisan::call('migrate', ['--pretend' => true, '--path' => $this->migrationPath, '--realpath' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('pretend_created', $output);
        $this->assertStringContainsString('TRUNCATE TABLE pretend_rows', $output);
        $this->assertTableDoesNotExist(self::CREATED);
        $this->assertSame(3, $this->countRows());
        $this->assertSame(
            [],
            DB::connection('clickhouse')->getClient()
                ->select("SELECT migration FROM migrations WHERE migration LIKE '2030_01_01_%'")
                ->rows()
        );
    }

    private function laravelBuilderConnection(): Connection
    {
        config(['database.connections.' . self::LARAVEL_CONNECTION => array_merge(
            config('database.connections.clickhouse'),
            ['fix_default_query_builder' => false]
        )]);
        DB::purge(self::LARAVEL_CONNECTION);

        return DB::connection(self::LARAVEL_CONNECTION);
    }

    private function clusterNameConnection(string $clusterName): Connection
    {
        config(['database.connections.' . self::CLUSTER_NAME_CONNECTION => array_merge(
            config('database.connections.clickhouse'),
            ['cluster_name' => $clusterName]
        )]);
        DB::purge(self::CLUSTER_NAME_CONNECTION);

        return DB::connection(self::CLUSTER_NAME_CONNECTION);
    }

    private function assertTableDoesNotExist(string $table): void
    {
        $this->assertSame(
            '0',
            (string) DB::connection('clickhouse')->getClient()->select("EXISTS TABLE {$table}")->fetchOne('result')
        );
    }

    private function countRows(): int
    {
        return (int) DB::connection('clickhouse')->getClient()
            ->select('SELECT count() AS c FROM ' . self::TABLE)
            ->fetchOne('c');
    }
}
