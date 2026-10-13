<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Larastan\Larastan\SQL\ColumnDefinition;
use Larastan\Larastan\SQL\SqlParser;
use Larastan\Larastan\SQL\TableDefinition;
use Oralunal\LaravelClickHouse\PHPStan\ClickHouseSqlParser;
use Oralunal\LaravelClickHouse\PHPStan\ConnectionConfig;
use Oralunal\LaravelClickHouse\SchemaState;
use PHPUnit\Framework\TestCase;

/**
 * The SQL parser of the PHPStan extension reads the schema dumps of ClickHouse connections, and gives any other SQL
 * to Larastan's parser.
 */
class ClickHouseSqlParserTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->useConnections(['clickhouse' => ['driver' => 'clickhouse']]);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    /**
     * @param array<string, array<string, mixed>> $connections
     * @return void
     */
    private function useConnections(array $connections): void
    {
        $container = new Container();
        $container->instance('config', new Repository([
            'database' => ['default' => 'clickhouse', 'connections' => $connections],
        ]));
        Container::setInstance($container);
    }

    /**
     * @param SqlParser|null $fallback
     * @return ClickHouseSqlParser
     */
    private function parser(?SqlParser $fallback = null): ClickHouseSqlParser
    {
        return new ClickHouseSqlParser($fallback ?? new RecordingSqlParser(), new ConnectionConfig());
    }

    /**
     * @param list<TableDefinition> $tables
     * @return array<string, array<string, array{string, list<string>, bool, list<string>}>>
     */
    private static function columnsByTable(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $result[$table->name] = [];
            foreach ($table->columns as $column) {
                $result[$table->name][$column->name] = [$column->type, $column->typeOptions, $column->nullable, $column->values];
            }
        }

        return $result;
    }

    /**
     * Each column gets the PHP type of its values. A MATERIALIZED or ALIAS column is nullable, because SELECT * does
     * not read it.
     */
    public function test_a_dump_of_the_package_is_read_with_the_php_types_of_its_values(): void
    {
        $tables = self::columnsByTable($this->parser()->parseTables((string) file_get_contents(__DIR__ . '/../../Types/schema/clickhouse-schema.sql')));

        $this->assertSame(['events', 'migrations', 'events_by_day', 'users_dict', 'events_view'], array_keys($tables));
        $this->assertSame([
            'id' => ['CLICKHOUSE', [], false, []],
            'counter' => ['INT', [], false, []],
            'small' => ['INT', ['unsigned'], false, []],
            'signed' => ['INT', [], false, []],
            'name' => ['VARCHAR', [], false, []],
            'note' => ['VARCHAR', [], true, []],
            'code' => ['VARCHAR', [], false, []],
            'fixed' => ['VARCHAR', [], false, []],
            'price' => ['VARCHAR', [], false, []],
            'ratio' => ['DOUBLE', [], false, []],
            'flag' => ['BOOLEAN', [], false, []],
            'happened_at' => ['VARCHAR', [], false, []],
            'day' => ['VARCHAR', [], false, []],
            'uuid' => ['VARCHAR', [], false, []],
            'ip' => ['VARCHAR', [], false, []],
            'status' => ['ENUM', [], false, ['new', 'done']],
            'tags' => ['CLICKHOUSE', [], false, []],
            'attributes' => ['CLICKHOUSE', [], false, []],
            'pair' => ['CLICKHOUSE', [], false, []],
            'payload' => ['VARCHAR', [], false, []],
            'big' => ['CLICKHOUSE', [], false, []],
            'nested.k' => ['CLICKHOUSE', [], false, []],
            'nested.v' => ['CLICKHOUSE', [], false, []],
            'maybe_tags' => ['CLICKHOUSE', [], false, []],
            'computed' => ['CLICKHOUSE', [], true, []],
            'alias_col' => ['VARCHAR', [], true, []],
        ], $tables['events']);
        $this->assertSame(['day' => ['VARCHAR', [], false, []], 'c' => ['CLICKHOUSE', [], false, []]], $tables['events_by_day']);
        $this->assertSame(['id' => ['CLICKHOUSE', [], false, []], 'name' => ['VARCHAR', [], false, []]], $tables['users_dict']);
        $this->assertSame(['id', 'name', 'ratio'], array_keys($tables['events_view']));
    }

    /**
     * A read-only user cannot ask ClickHouse to quote NaN and the infinities, which then come as null. Larastan finds
     * the columns of a model by its table only, so a floating-point column is nullable in the dump of any connection
     * when a ClickHouse connection of the config is read-only.
     */
    public function test_a_float_is_nullable_when_a_clickhouse_connection_is_read_only(): void
    {
        $this->useConnections([
            'clickhouse' => ['driver' => 'clickhouse'],
            'reader' => ['driver' => 'clickhouse', 'readonly' => true],
        ]);
        $dump = SchemaState::DUMP_HEADER . "clickhouse\n\nCREATE TABLE t\n(\n    `f` Float64,\n    `n` Nullable(Float32),\n    `i` UInt8\n)\nENGINE = Memory;\n";

        $this->assertSame(
            ['t' => ['f' => ['DOUBLE', [], true, []], 'n' => ['DOUBLE', [], true, []], 'i' => ['INT', ['unsigned'], false, []]]],
            self::columnsByTable($this->parser()->parseTables($dump))
        );
    }

    /**
     * A dump without the header of the package is read when it has a ClickHouse ENGINE clause. The names can be
     * quoted, an EPHEMERAL column and the INDEX, PROJECTION and CONSTRAINT lines are left out, and the type of a
     * SimpleAggregateFunction or a LowCardinality column is the type that it holds.
     */
    public function test_a_clickhouse_create_statement_without_the_header_is_read(): void
    {
        $sql = "CREATE TABLE `my table`\n(\n"
            . "    `id` UInt32,\n"
            . "    `a b` LowCardinality(Nullable(String)),\n"
            . "    `total` SimpleAggregateFunction(sum, Int64),\n"
            . "    `raw` String EPHEMERAL,\n"
            . "    `when` DateTime64(3, 'UTC') DEFAULT now64(3) COMMENT 'a, b',\n"
            . "    INDEX idx id TYPE minmax GRANULARITY 1,\n"
            . "    PROJECTION p (SELECT id ORDER BY id),\n"
            . "    CONSTRAINT c CHECK id > 0\n"
            . ")\nENGINE = MergeTree\nORDER BY id;\n";

        $this->assertSame(
            ['my table' => [
                'id' => ['INT', ['unsigned'], false, []],
                'a b' => ['VARCHAR', [], true, []],
                'total' => ['INT', [], false, []],
                'when' => ['VARCHAR', [], false, []],
            ]],
            self::columnsByTable($this->parser()->parseTables($sql))
        );
    }

    /**
     * Any other SQL, such as the dump of MySQL, goes to Larastan's parser.
     */
    public function test_other_sql_goes_to_the_parser_of_larastan(): void
    {
        $fallback = new RecordingSqlParser();
        $sql = "CREATE TABLE `users` (\n  `id` bigint unsigned NOT NULL\n) ENGINE=InnoDB;";

        $tables = $this->parser($fallback)->parseTables($sql);

        $this->assertSame([$sql], $fallback->parsed);
        $this->assertSame('from the fallback', $tables[0]->name);
    }
}

/**
 * A parser in place of Larastan's, which records the SQL that it gets.
 */
final class RecordingSqlParser implements SqlParser
{
    /**
     * @var list<string>
     */
    public array $parsed = [];

    public function parseTables(string $sql): array
    {
        $this->parsed[] = $sql;

        return [new TableDefinition('from the fallback', [new ColumnDefinition('id', 'INT', [], false)])];
    }
}
