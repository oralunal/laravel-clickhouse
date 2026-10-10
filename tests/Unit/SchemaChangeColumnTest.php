<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Statement;
use ClickHouseDB\Transport\CurlerRequest;
use Closure;
use Illuminate\Database\Query\Expression;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\SchemaBlueprint;
use Oralunal\LaravelClickHouse\SchemaBuilder;
use Oralunal\LaravelClickHouse\SchemaGrammar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The statements that ->change() compiles in Schema::table(), from the column state that system.columns gives,
 * which these tests stub through the active node's client. tests/SchemaBlueprintTest.php runs them against
 * ClickHouse.
 */
class SchemaChangeColumnTest extends TestCase
{
    /**
     * The SQL of each read that the grammar sent to the client.
     *
     * @var list<string>
     */
    private array $selects = [];

    /**
     * The statements that the mocked connection received through statement().
     *
     * @var list<string>
     */
    private array $statements = [];

    public function test_a_column_without_default_or_comment_gets_one_modify(): void
    {
        $this->assertSame(
            ['ALTER TABLE `visits` MODIFY COLUMN `source` Nullable(String)'],
            $this->compileChange(fn (SchemaBlueprint $table) => $table->string('source')->nullable()->change(), $this->state('String'))
        );
        $this->assertSame(
            ["SELECT type, default_kind, comment FROM system.columns WHERE database = 'analytics' AND table = 'visits' AND name = 'source'"],
            $this->selects
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function defaultKinds(): array
    {
        return [
            'DEFAULT' => ['DEFAULT'],
            'MATERIALIZED' => ['MATERIALIZED'],
            'ALIAS' => ['ALIAS'],
        ];
    }

    /**
     * Laravel drops what change() does not repeat, and ClickHouse keeps it, so
     * the default expression is removed first, in a statement of its own.
     */
    #[DataProvider('defaultKinds')]
    public function test_a_default_expression_that_the_new_definition_leaves_out_is_removed(string $kind): void
    {
        $this->assertSame(
            [
                "ALTER TABLE `visits` MODIFY COLUMN `source` REMOVE {$kind}",
                'ALTER TABLE `visits` MODIFY COLUMN `source` String',
            ],
            $this->compileChange(fn (SchemaBlueprint $table) => $table->string('source')->change(), $this->state('String', $kind))
        );
    }

    /**
     * @return array<string, array{Closure(SchemaBlueprint): mixed, string}>
     */
    public static function newDefaultExpressions(): array
    {
        return [
            'default()' => [fn (SchemaBlueprint $table) => $table->string('c')->default('x')->change(), "`c` String DEFAULT 'x'"],
            'default() with an expression' => [
                fn (SchemaBlueprint $table) => $table->string('c')->default(new Expression("concat('a', 'b')"))->change(),
                "`c` String DEFAULT concat('a', 'b')",
            ],
            'useCurrent()' => [fn (SchemaBlueprint $table) => $table->dateTime('c')->useCurrent()->change(), '`c` DateTime DEFAULT now()'],
            'storedAs()' => [fn (SchemaBlueprint $table) => $table->string('c')->storedAs('upper(s)')->change(), '`c` String MATERIALIZED upper(s)'],
            'virtualAs()' => [fn (SchemaBlueprint $table) => $table->string('c')->virtualAs('upper(s)')->change(), '`c` String ALIAS upper(s)'],
            'ephemeral()' => [fn (SchemaBlueprint $table) => $table->string('c')->ephemeral()->change(), '`c` String EPHEMERAL'],
            'ephemeral() with a default' => [fn (SchemaBlueprint $table) => $table->string('c')->ephemeral('e')->change(), "`c` String EPHEMERAL 'e'"],
        ];
    }

    /**
     * A new default expression replaces the old one in the MODIFY, whatever
     * its kind, so nothing is removed first.
     *
     * @param Closure(SchemaBlueprint): mixed $change
     */
    #[DataProvider('newDefaultExpressions')]
    public function test_a_new_default_expression_removes_nothing(Closure $change, string $definition): void
    {
        foreach (['DEFAULT', 'MATERIALIZED', 'ALIAS'] as $kind) {
            $this->assertSame(
                ["ALTER TABLE `visits` MODIFY COLUMN {$definition}"],
                $this->compileChange($change, $this->state('String', $kind)),
                $kind
            );
        }
    }

    /**
     * useCurrent() gives no default to a string column, so the old default is removed.
     */
    public function test_use_current_without_a_current_time_default_removes_the_default(): void
    {
        $this->assertSame(
            ['ALTER TABLE `visits` MODIFY COLUMN `c` REMOVE DEFAULT', 'ALTER TABLE `visits` MODIFY COLUMN `c` String'],
            $this->compileChange(fn (SchemaBlueprint $table) => $table->string('c')->useCurrent()->change(), $this->state('String', 'DEFAULT'))
        );
    }

    /**
     * ClickHouse has no REMOVE EPHEMERAL, and a MODIFY without a default keeps it.
     */
    public function test_an_ephemeral_default_is_kept(): void
    {
        $this->assertSame(
            ['ALTER TABLE `visits` MODIFY COLUMN `c` String'],
            $this->compileChange(fn (SchemaBlueprint $table) => $table->string('c')->change(), $this->state('String', 'EPHEMERAL'))
        );
    }

    public function test_a_comment_is_removed_unless_the_new_definition_has_one(): void
    {
        $this->assertSame(
            ['ALTER TABLE `visits` MODIFY COLUMN `c` REMOVE COMMENT', 'ALTER TABLE `visits` MODIFY COLUMN `c` String'],
            $this->compileChange(fn (SchemaBlueprint $table) => $table->string('c')->change(), $this->state('String', '', 'old'))
        );
        $this->assertSame(
            ["ALTER TABLE `visits` MODIFY COLUMN `c` String COMMENT 'it\\'s new'"],
            $this->compileChange(fn (SchemaBlueprint $table) => $table->string('c')->comment("it's new")->change(), $this->state('String', '', 'old'))
        );
        $this->assertSame(
            ["ALTER TABLE `visits` MODIFY COLUMN `c` String COMMENT ''"],
            $this->compileChange(fn (SchemaBlueprint $table) => $table->string('c')->comment('')->change(), $this->state('String', '', 'old')),
            'an empty comment() replaces the comment'
        );
    }

    /**
     * The CODEC and the TTL of the column are kept, since system.columns has
     * no TTL; codec() and ttl() give new ones.
     */
    public function test_codec_and_ttl_are_only_written_when_given(): void
    {
        $this->assertSame(
            ["ALTER TABLE `visits` MODIFY COLUMN `c` String DEFAULT 'p' COMMENT 'kept' CODEC(LZ4) TTL at + INTERVAL 2 DAY"],
            $this->compileChange(
                fn (SchemaBlueprint $table) => $table->string('c')->default('p')->comment('kept')->codec('LZ4')->ttl('at + INTERVAL 2 DAY')->change(),
                $this->state('String', 'DEFAULT', 'kept')
            )
        );
    }

    /**
     * The example of the design: a column `source` String DEFAULT 'web' COMMENT 'src'.
     */
    public function test_every_statement_in_order(): void
    {
        $this->assertSame(
            [
                'ALTER TABLE `visits` MODIFY COLUMN `source` REMOVE DEFAULT',
                'ALTER TABLE `visits` MODIFY COLUMN `source` REMOVE COMMENT',
                'ALTER TABLE `visits` MODIFY COLUMN `source` Nullable(String) AFTER `id`',
                'ALTER TABLE `visits` RENAME COLUMN `source` TO `traffic_source`',
            ],
            $this->compileChange(
                fn (SchemaBlueprint $table) => $table->string('source')->nullable()->after('id')->renameTo('traffic_source')->change(),
                $this->state('String', 'DEFAULT', 'src')
            )
        );
    }

    public function test_first_and_rename_to(): void
    {
        $this->assertSame(
            ['ALTER TABLE `visits` MODIFY COLUMN `n.a` Int64 FIRST', 'ALTER TABLE `visits` RENAME COLUMN `n.a` TO `we\\`ird`'],
            $this->compileChange(fn (SchemaBlueprint $table) => $table->bigInteger('n.a')->first()->renameTo('we`ird')->change(), $this->state('Int32'))
        );
        $this->assertSame(
            ['ALTER TABLE `visits` MODIFY COLUMN `c` Int64'],
            $this->compileChange(fn (SchemaBlueprint $table) => $table->bigInteger('c')->renameTo('c')->change(), $this->state('Int32')),
            'renaming a column to its own name sends nothing'
        );
    }

    /**
     * A column that system.columns does not list, such as one of a table that
     * an earlier pretended migration creates, gets the MODIFY only.
     */
    public function test_a_column_that_system_columns_does_not_list_gets_the_modify_only(): void
    {
        $this->assertSame(
            ['ALTER TABLE `visits` MODIFY COLUMN `c` String', 'ALTER TABLE `visits` RENAME COLUMN `c` TO `d`'],
            $this->compileChange(fn (SchemaBlueprint $table) => $table->string('c')->renameTo('d')->change(), null)
        );
        $this->assertCount(1, $this->selects, 'no NULL check without a column');
    }

    /**
     * The old type, the change, and the condition that finds the NULL values
     * that the new type cannot keep.
     *
     * @return array<string, array{string, Closure(SchemaBlueprint): mixed, string}>
     */
    public static function changesThatDropNull(): array
    {
        return [
            'Nullable to plain' => ['Nullable(String)', fn (SchemaBlueprint $table) => $table->string('c')->change(), '`c` IS NULL'],
            'Nullable to plain with a default' => [
                'Nullable(String)',
                fn (SchemaBlueprint $table) => $table->string('c')->default('x')->change(),
                '`c` IS NULL',
            ],
            'LowCardinality(Nullable) to LowCardinality' => [
                'LowCardinality(Nullable(String))',
                fn (SchemaBlueprint $table) => $table->string('c')->lowCardinality()->change(),
                '`c` IS NULL',
            ],
            'Variant to plain' => ['Variant(String, UInt64)', fn (SchemaBlueprint $table) => $table->string('c')->change(), '`c` IS NULL'],
            'Nullable to a raw plain type' => [
                'Nullable(String)',
                fn (SchemaBlueprint $table) => $table->rawColumn('c', 'LowCardinality(String)')->change(),
                '`c` IS NULL',
            ],
            'an array of Nullable to an array' => [
                'Array(Nullable(String))',
                fn (SchemaBlueprint $table) => $table->array('c', 'String')->change(),
                'arrayExists(x1 -> x1 IS NULL, `c`)',
            ],
            'an array of LowCardinality(Nullable) to an array' => [
                'Array(LowCardinality(Nullable(String)))',
                fn (SchemaBlueprint $table) => $table->array('c', 'LowCardinality(String)')->change(),
                'arrayExists(x1 -> x1 IS NULL, `c`)',
            ],
            'nested arrays' => [
                'Array(Array(Nullable(UInt8)))',
                fn (SchemaBlueprint $table) => $table->array('c', 'Array(UInt8)')->change(),
                'arrayExists(x1 -> arrayExists(x2 -> x2 IS NULL, x1), `c`)',
            ],
        ];
    }

    /**
     * ClickHouse would change the type, fail to convert the NULL values in a
     * mutation, and leave every later query of the table failing, so the
     * change throws before the blueprint sends anything, the column added
     * before it included.
     *
     * @param Closure(SchemaBlueprint): mixed $change
     */
    #[DataProvider('changesThatDropNull')]
    public function test_a_column_holding_null_cannot_lose_null(string $oldType, Closure $change, string $condition): void
    {
        $connection = $this->connection($this->state($oldType, 'DEFAULT', 'c'), holdsNull: true);

        try {
            (new SchemaBuilder($connection))->table('visits', function (SchemaBlueprint $table) use ($change) {
                $table->string('added_before');
                $change($table);
            });
            $this->fail('change() should throw');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith(
                "The column [c] of the table [visits] holds NULL values, so its type cannot change from {$oldType} to ",
                $exception->getMessage()
            );
            $this->assertStringEndsWith(
                ': ClickHouse would fail to convert them in a mutation, and every later query of the table would fail'
                . ' until the mutation is killed. Replace the NULL values first, or keep the column nullable(). Rows'
                . ' removed by a lightweight DELETE count too until a merge rewrites their parts; ALTER TABLE `visits`'
                . ' APPLY DELETED MASK removes them.',
                $exception->getMessage()
            );
        }

        $this->assertSame([], $this->statements);
        $this->assertSame("SELECT 1 FROM `visits` WHERE {$condition} LIMIT 1 SETTINGS apply_deleted_mask = 0", $this->selects[1]);
    }

    /**
     * The check reads the rows that a lightweight DELETE removed too
     * (apply_deleted_mask = 0): the mutation of the MODIFY still converts
     * them until a merge rewrites their parts.
     */
    public function test_a_column_without_null_values_can_lose_null(): void
    {
        $this->assertSame(
            ['ALTER TABLE `visits` MODIFY COLUMN `c` String'],
            $this->compileChange(fn (SchemaBlueprint $table) => $table->string('c')->change(), $this->state('Nullable(String)'))
        );
        $this->assertSame('SELECT 1 FROM `visits` WHERE `c` IS NULL LIMIT 1 SETTINGS apply_deleted_mask = 0', $this->selects[1]);
    }

    /**
     * @return array<string, array{string, Closure(SchemaBlueprint): mixed}>
     */
    public static function changesThatNeedNoNullCheck(): array
    {
        return [
            'Nullable stays Nullable' => ['Nullable(String)', fn (SchemaBlueprint $table) => $table->string('c')->nullable()->lowCardinality()->change()],
            'plain becomes Nullable' => ['String', fn (SchemaBlueprint $table) => $table->string('c')->nullable()->change()],
            'plain stays plain' => ['String', fn (SchemaBlueprint $table) => $table->bigInteger('c')->change()],
            'an array of Nullable to a type that is no array' => ['Array(Nullable(String))', fn (SchemaBlueprint $table) => $table->string('c')->change()],
            'an array of Nullable stays one' => [
                'Array(Nullable(String))',
                fn (SchemaBlueprint $table) => $table->array('c', 'LowCardinality(Nullable(String))')->change(),
            ],
            'a map of Nullable values is not checked' => [
                'Map(String, Nullable(String))',
                fn (SchemaBlueprint $table) => $table->map('c', 'String', 'String')->change(),
            ],
        ];
    }

    /**
     * @param Closure(SchemaBlueprint): mixed $change
     */
    #[DataProvider('changesThatNeedNoNullCheck')]
    public function test_the_null_check_runs_only_when_the_new_type_drops_null(string $oldType, Closure $change): void
    {
        $this->compileChange($change, $this->state($oldType), holdsNull: true);

        $this->assertCount(1, $this->selects);
    }

    /**
     * A `<database>.<table>` name reads the column state of that database,
     * and the table prefix is part of the name in system.columns.
     */
    public function test_a_qualified_table_and_the_table_prefix(): void
    {
        $this->assertSame(
            ['ALTER TABLE `archive`.`app_visits` MODIFY COLUMN `c` String'],
            $this->compileChange(
                fn (SchemaBlueprint $table) => $table->string('c')->change(),
                $this->state('Nullable(String)'),
                table: 'archive.visits',
                prefix: 'app_'
            )
        );
        $this->assertSame(
            [
                "SELECT type, default_kind, comment FROM system.columns WHERE database = 'archive' AND table = 'app_visits' AND name = 'c'",
                'SELECT 1 FROM `archive`.`app_visits` WHERE `c` IS NULL LIMIT 1 SETTINGS apply_deleted_mask = 0',
            ],
            $this->selects
        );
    }

    public function test_names_in_the_reads_are_escaped(): void
    {
        $this->compileChange(fn (SchemaBlueprint $table) => $table->string("it's `x`")->change(), $this->state('Nullable(String)'), table: "my 'visits'");

        $this->assertSame(
            [
                "SELECT type, default_kind, comment FROM system.columns WHERE database = 'analytics' AND table = 'my \\'visits\\''"
                . " AND name = 'it\\'s `x`'",
                "SELECT 1 FROM `my 'visits'` WHERE `it's \\`x\\`` IS NULL LIMIT 1 SETTINGS apply_deleted_mask = 0",
            ],
            $this->selects
        );
    }

    /**
     * The reads go to the active node's client, not through select(), which
     * returns no rows while the connection pretends: so migrate --pretend
     * shows the REMOVE statements that would run, and the NULL check still
     * refuses the change.
     */
    public function test_the_reads_do_not_go_through_select(): void
    {
        $connection = $this->connection($this->state('Nullable(String)', 'DEFAULT'), holdsNull: false);
        $connection->expects($this->never())->method('select');

        $blueprint = new SchemaBlueprint($connection, 'visits');
        $blueprint->string('c')->change();

        $this->assertSame(
            ['ALTER TABLE `visits` MODIFY COLUMN `c` REMOVE DEFAULT', 'ALTER TABLE `visits` MODIFY COLUMN `c` String'],
            $blueprint->toSql()
        );
    }

    /**
     * Each change() reads its own column; the statements of every command are
     * in the order of the commands.
     */
    public function test_several_changes_in_one_blueprint(): void
    {
        $this->assertSame(
            [
                'ALTER TABLE `visits` MODIFY COLUMN `a` REMOVE DEFAULT',
                'ALTER TABLE `visits` MODIFY COLUMN `a` String',
                'ALTER TABLE `visits` ADD COLUMN `n` String',
                'ALTER TABLE `visits` MODIFY COLUMN `b` REMOVE DEFAULT',
                'ALTER TABLE `visits` MODIFY COLUMN `b` Int64',
            ],
            $this->compileChange(function (SchemaBlueprint $table) {
                $table->string('a')->change();
                $table->string('n');
                $table->bigInteger('b')->change();
            }, $this->state('String', 'DEFAULT'))
        );
        $this->assertSame(
            [
                "SELECT type, default_kind, comment FROM system.columns WHERE database = 'analytics' AND table = 'visits' AND name = 'a'",
                "SELECT type, default_kind, comment FROM system.columns WHERE database = 'analytics' AND table = 'visits' AND name = 'b'",
            ],
            $this->selects
        );
    }

    /**
     * Compile the statements of Schema::table() for a blueprint whose columns
     * have the given state in system.columns.
     *
     * @param Closure(SchemaBlueprint): mixed $callback
     * @param array{type: string, default_kind: string, comment: string}|null $state Null when system.columns does not list the column
     * @return list<string>
     */
    private function compileChange(
        Closure $callback,
        ?array $state,
        bool $holdsNull = false,
        string $table = 'visits',
        string $prefix = '',
    ): array {
        $blueprint = new SchemaBlueprint($this->connection($state, $holdsNull, $prefix), $table);
        $callback($blueprint);

        return $blueprint->toSql();
    }

    /**
     * A column state as system.columns gives it.
     *
     * @return array{type: string, default_kind: string, comment: string}
     */
    private function state(string $type, string $defaultKind = '', string $comment = ''): array
    {
        return ['type' => $type, 'default_kind' => $defaultKind, 'comment' => $comment];
    }

    /**
     * A connection with a real SchemaGrammar and the database `analytics`, whose active node's client answers the
     * system.columns read with the given state and the NULL check with a row when $holdsNull is true. It records
     * the reads and the statements it is given.
     *
     * @param array{type: string, default_kind: string, comment: string}|null $state
     */
    private function connection(?array $state, bool $holdsNull = false, string $prefix = ''): Connection
    {
        $client = $this->createMock(Client::class);
        $client->method('select')->willReturnCallback(function (string $sql) use ($state, $holdsNull): Statement {
            $this->selects[] = $sql;

            if (str_contains($sql, 'FROM system.columns')) {
                return $this->statement($state === null ? [] : [$state]);
            }

            return $this->statement($holdsNull ? [['1' => 1]] : []);
        });

        $connection = $this->createMock(Connection::class);
        $grammar = new SchemaGrammar($connection);
        $connection->method('getSchemaGrammar')->willReturn($grammar);
        $connection->method('getSchemaBuilder')->willReturnCallback(fn (): SchemaBuilder => new SchemaBuilder($connection));
        $connection->method('getConfig')->willReturn(null);
        $connection->method('getTablePrefix')->willReturn($prefix);
        $connection->method('getDatabaseName')->willReturn('analytics');
        $connection->method('getClient')->willReturn($client);
        $connection->method('statement')->willReturnCallback(function (string $query): bool {
            $this->statements[] = $query;

            return true;
        });

        return $connection;
    }

    /**
     * A statement whose rows() returns the given rows and whose request has no response, as ClientRequests::select()
     * reads it.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function statement(array $rows): Statement
    {
        $statement = $this->createMock(Statement::class);
        $statement->method('rows')->willReturn($rows);
        $statement->method('getRequest')->willReturn(new CurlerRequest());
        $statement->method('getFormat')->willReturn('JSON');

        return $statement;
    }
}
