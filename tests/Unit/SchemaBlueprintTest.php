<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Exception\DatabaseException;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Fluent;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\SchemaBlueprint;
use Oralunal\LaravelClickHouse\SchemaBuilder;
use Oralunal\LaravelClickHouse\SchemaColumnDefinition;
use Oralunal\LaravelClickHouse\SchemaGrammar;
use PHPUnit\Framework\TestCase;

/**
 * SchemaBuilder::createBlueprint(), the SchemaBlueprint methods and hasDictionary().
 */
class SchemaBlueprintTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $statements = [];

    public function test_schema_create_passes_a_schema_blueprint(): void
    {
        $connection = $this->connection();
        $passed = null;

        (new SchemaBuilder($connection))->create('events', function (SchemaBlueprint $table) use (&$passed) {
            $passed = $table;
            $table->id();
            $table->orderBy('id');
        });

        $this->assertInstanceOf(SchemaBlueprint::class, $passed);
        $this->assertSame(['CREATE TABLE `events` (`id` Int64) ENGINE = MergeTree() ORDER BY (`id`)'], $this->statements);
    }

    public function test_a_callback_that_type_hints_laravels_blueprint_still_works(): void
    {
        $builder = new SchemaBuilder($this->connection());

        $builder->create('events', function (Blueprint $table) {
            $table->id();
        });
        $builder->table('events', function (Blueprint $table) {
            $table->renameColumn('id', 'event_id');
        });
        $builder->drop('events');

        $this->assertSame([
            'CREATE TABLE `events` (`id` Int64) ENGINE = MergeTree() ORDER BY (`id`)',
            'ALTER TABLE `events` RENAME COLUMN `id` TO `event_id`',
            'DROP TABLE `events`',
        ], $this->statements);
    }

    public function test_a_blueprint_resolver_still_decides(): void
    {
        $builder = new SchemaBuilder($this->connection());
        $builder->blueprintResolver(fn ($connection, string $table, ?\Closure $callback) => new Blueprint($connection, $table, $callback));
        $passed = null;

        $builder->create('events', function (Blueprint $table) use (&$passed) {
            $passed = $table;
            $table->id();
        });

        $this->assertSame(Blueprint::class, get_class($passed));
        $this->assertSame(['CREATE TABLE `events` (`id` Int64) ENGINE = MergeTree() ORDER BY (`id`)'], $this->statements);
    }

    public function test_order_by_adds_a_command_with_the_columns(): void
    {
        $blueprint = $this->blueprint();
        $hash = new Expression('intHash32(id)');

        $this->assertSame(['name' => 'orderBy', 'columns' => ['id', $hash]], $blueprint->orderBy('id', $hash)->getAttributes());
        $this->assertSame(['id', 'at', $hash], $blueprint->orderBy(['id', 'at'], $hash)->columns);
        $this->assertSame([], $blueprint->orderBy()->columns);
    }

    public function test_the_table_clauses_add_commands(): void
    {
        $blueprint = $this->blueprint();
        $partition = new Expression('toYYYYMM(at)');

        $this->assertSame(['name' => 'partitionBy', 'expression' => $partition], $blueprint->partitionBy($partition)->getAttributes());
        $this->assertSame(['name' => 'sampleBy', 'expression' => 'intHash32(id)'], $blueprint->sampleBy('intHash32(id)')->getAttributes());
        $this->assertSame(['name' => 'ttl', 'expression' => 'at + INTERVAL 1 DAY'], $blueprint->ttl('at + INTERVAL 1 DAY')->getAttributes());
        $this->assertSame(
            ['name' => 'settings', 'settings' => ['index_granularity' => 8192]],
            $blueprint->settings(['index_granularity' => 8192])->getAttributes()
        );
        $this->assertSame(
            ['partitionBy', 'sampleBy', 'ttl', 'settings'],
            array_map(fn (Fluent $command): string => $command->name, $blueprint->getCommands())
        );
    }

    public function test_if_not_exists(): void
    {
        $blueprint = $this->blueprint();
        $this->assertFalse($blueprint->ifNotExists);

        $blueprint->ifNotExists();
        $this->assertTrue($blueprint->ifNotExists);

        $blueprint->ifNotExists(false);
        $this->assertFalse($blueprint->ifNotExists);
    }

    public function test_the_clickhouse_column_types(): void
    {
        $blueprint = $this->blueprint();

        $columns = [
            $blueprint->array('tags', 'LowCardinality(String)'),
            $blueprint->map('attributes', 'String', 'UInt64'),
            $blueprint->ipv4(),
            $blueprint->ipv6('ip6'),
            $blueprint->date32('born_on'),
        ];

        $this->assertContainsOnlyInstancesOf(SchemaColumnDefinition::class, $columns);
        $this->assertSame([
            ['type' => 'array', 'name' => 'tags', 'innerType' => 'LowCardinality(String)'],
            ['type' => 'map', 'name' => 'attributes', 'keyType' => 'String', 'valueType' => 'UInt64'],
            ['type' => 'ipv4', 'name' => 'ip_address'],
            ['type' => 'ipv6', 'name' => 'ip6'],
            ['type' => 'date32', 'name' => 'born_on'],
        ], array_map(fn (ColumnDefinition $column): array => $column->getAttributes(), $columns));
    }

    public function test_laravel_column_methods_return_a_schema_column_definition(): void
    {
        $blueprint = $this->blueprint();

        $this->assertInstanceOf(SchemaColumnDefinition::class, $blueprint->id());
        $this->assertInstanceOf(SchemaColumnDefinition::class, $blueprint->string('name'));
        $this->assertInstanceOf(SchemaColumnDefinition::class, $blueprint->addColumn('uuid', 'uuid'));
    }

    public function test_the_clickhouse_modifiers_are_column_attributes(): void
    {
        $column = $this->blueprint()->dateTime('at', 3)
            ->lowCardinality()
            ->codec('Delta, ZSTD')
            ->ttl('at + INTERVAL 1 DAY')
            ->ephemeral()
            ->timezone('UTC');

        $this->assertSame(
            ['lowCardinality' => true, 'codec' => 'Delta, ZSTD', 'ttl' => 'at + INTERVAL 1 DAY', 'ephemeral' => true, 'timezone' => 'UTC'],
            array_intersect_key($column->getAttributes(), array_flip(['lowCardinality', 'codec', 'ttl', 'ephemeral', 'timezone']))
        );
    }

    public function test_has_table_and_has_dictionary_query_system_tables(): void
    {
        $connection = $this->connection();
        $queries = [];
        $connection->method('select')->willReturnCallback(function (string $query) use (&$queries): array {
            $queries[] = $query;

            return str_contains($query, "'events'") ? [['name' => 'events']] : [];
        });
        $builder = new SchemaBuilder($connection);

        $this->assertTrue($builder->hasTable('events'));
        $this->assertFalse($builder->hasDictionary('other.events_dictionary'));
        $this->assertSame([
            "select name from system.tables where database = 'analytics' and name = 'events'"
            . " and is_temporary = 0 and not startsWith(name, '.inner') and not endsWith(engine, 'View') and engine != 'Dictionary'",
            "select name from system.tables where database = 'other' and name = 'events_dictionary' and engine = 'Dictionary'",
        ], $queries);
    }

    /**
     * The server raises this error when the connection's database does not
     * exist; an unknown database in a `<database>.<dictionary>` name only
     * gives no row.
     */
    public function test_has_dictionary_rethrows_a_clickhouse_error_as_a_query_exception(): void
    {
        $connection = $this->connection();
        $connection->method('getName')->willReturn('clickhouse');
        $connection->method('select')->willThrowException(new DatabaseException('Database analytics does not exist. (UNKNOWN_DATABASE)', 81));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Database analytics does not exist. (UNKNOWN_DATABASE)');

        (new SchemaBuilder($connection))->hasDictionary('events_dictionary');
    }

    private function blueprint(): SchemaBlueprint
    {
        return new SchemaBlueprint($this->connection(), 'events');
    }

    /**
     * A connection with a real SchemaGrammar, the database `analytics`, and
     * statement() recording each statement in $this->statements.
     */
    private function connection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $grammar = new SchemaGrammar($connection);
        $connection->method('getSchemaGrammar')->willReturn($grammar);
        $connection->method('getSchemaBuilder')->willReturnCallback(fn (): SchemaBuilder => new SchemaBuilder($connection));
        $connection->method('getConfig')->willReturn(null);
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('getDatabaseName')->willReturn('analytics');
        $connection->method('statement')->willReturnCallback(function (string $query): bool {
            $this->statements[] = $query;

            return true;
        });

        return $connection;
    }
}
