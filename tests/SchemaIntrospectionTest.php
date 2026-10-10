<?php

namespace Tests;

use ClickHouseDB\Client;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's schema introspection (Schema::getTables(), getColumns(), getIndexes(), ...)
 * and the db:show and db:table commands on a ClickHouse connection.
 */
class SchemaIntrospectionTest extends TestCase
{
    private const EVENTS = 'introspection_events';

    /** A Log table: no primary key, and the target of TO_VIEW. */
    private const LOG = 'introspection_log';

    private const VIEW = 'introspection_view';

    /** A materialized view that stores its rows in an inner table. */
    private const INNER_VIEW = 'introspection_mv';

    /** A materialized view that writes to LOG. */
    private const TO_VIEW = 'introspection_mv_to';

    private const DICTIONARY = 'introspection_dict';

    /** A Merge table over EVENTS, created by the test that needs it. */
    private const MERGE = 'introspection_merge';

    /** A table with column types that accept NULL without Nullable, created by the test that needs it. */
    private const NULLABLE_TYPES = 'introspection_nullable_types';

    private const OTHER_DATABASE = 'phpch_introspection';

    private const OTHER_TABLE = 'other_events';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropObjects();

        $client = $this->client();
        $client->write('CREATE TABLE ' . self::EVENTS . " (
                id UInt64 COMMENT 'Event id',
                name String DEFAULT 'it''s' COMMENT 'Event name',
                name_length UInt64 MATERIALIZED length(name),
                name_upper String ALIAS upper(name),
                raw String EPHEMERAL '',
                nickname Nullable(String),
                city LowCardinality(Nullable(String)),
                tags Array(Nullable(String)),
                INDEX idx_name name TYPE bloom_filter GRANULARITY 1,
                INDEX idx_id_length (id, name_length) TYPE minmax GRANULARITY 2
            )
            ENGINE = MergeTree ORDER BY (id, intHash32(id))
            COMMENT 'Event log'");
        $client->write('CREATE TABLE ' . self::LOG . ' (id UInt64) ENGINE = Log');
        $client->write('CREATE VIEW ' . self::VIEW . ' AS SELECT id, name FROM ' . self::EVENTS);
        $client->write('CREATE MATERIALIZED VIEW ' . self::INNER_VIEW
            . ' ENGINE = MergeTree ORDER BY id AS SELECT id FROM ' . self::EVENTS);
        $client->write('CREATE MATERIALIZED VIEW ' . self::TO_VIEW . ' TO ' . self::LOG
            . ' AS SELECT id FROM ' . self::EVENTS);
        $client->write('CREATE DICTIONARY ' . self::DICTIONARY . ' (id UInt64, name String) PRIMARY KEY id'
            . " SOURCE(CLICKHOUSE(DB '{$this->database()}' TABLE '" . self::EVENTS . "'))"
            . ' LIFETIME(0) LAYOUT(FLAT())');
        $client->write('CREATE DATABASE ' . self::OTHER_DATABASE);
        $client->write('CREATE TABLE ' . self::OTHER_DATABASE . '.' . self::OTHER_TABLE
            . ' (id UInt64, payload String) ENGINE = MergeTree ORDER BY id');
    }

    protected function tearDown(): void
    {
        $this->dropObjects();

        parent::tearDown();
    }

    public function testGetTablesDescribesTheTablesOfTheConnectionsDatabase(): void
    {
        $this->client()->write('INSERT INTO ' . self::EVENTS . " (id, name) VALUES (1, 'a')");

        $tables = collect(Schema::getTables())->keyBy('name');

        $this->assertSame([
            'name' => self::EVENTS,
            'schema' => $this->database(),
            'schema_qualified_name' => $this->database() . '.' . self::EVENTS,
            'size' => $this->totalBytes(self::EVENTS),
            'comment' => 'Event log',
            'collation' => null,
            'engine' => 'MergeTree',
        ], $tables[self::EVENTS]);
        $this->assertGreaterThan(0, $tables[self::EVENTS]['size']);
        $this->assertSame('Log', $tables[self::LOG]['engine']);
        $this->assertNull($tables[self::LOG]['comment']);
        $this->assertSame($this->database(), Schema::getCurrentSchemaName());
    }

    /**
     * ClickHouse reports the bytes of the source tables as the total_bytes of a
     * Merge table, so db:show would count them twice.
     */
    public function testTheSizeOfAMergeTableIsNull(): void
    {
        $this->client()->write('INSERT INTO ' . self::EVENTS . " (id, name) VALUES (1, 'a')");
        $this->client()->write('CREATE TABLE ' . self::MERGE . ' (id UInt64)'
            . " ENGINE = Merge(currentDatabase(), '^" . self::EVENTS . "$')");
        $this->assertSame(
            $this->totalBytes(self::EVENTS),
            $this->totalBytes(self::MERGE),
            'system.tables repeats the bytes of the source table'
        );

        $tables = collect(Schema::getTables())->keyBy('name');

        $this->assertSame('Merge', $tables[self::MERGE]['engine']);
        $this->assertNull($tables[self::MERGE]['size']);
        $this->assertSame($this->totalBytes(self::EVENTS), $tables[self::EVENTS]['size']);
        $this->assertGreaterThan(0, $tables[self::EVENTS]['size']);
    }

    public function testGetTablesLeavesOutViewsMaterializedViewsTheirInnerTablesAndDictionaries(): void
    {
        $innerTables = $this->innerTables();
        $this->assertNotEmpty($innerTables, 'the materialized view should have an inner table');

        $names = Schema::getTableListing(schemaQualified: false);

        $this->assertContains(self::EVENTS, $names);
        $this->assertContains(self::LOG, $names);
        foreach ([self::VIEW, self::INNER_VIEW, self::TO_VIEW, self::DICTIONARY, ...$innerTables] as $name) {
            $this->assertNotContains($name, $names);
        }
        $this->assertContains($this->database() . '.' . self::EVENTS, Schema::getTableListing());
    }

    public function testHasTableTakesADatabaseQualifiedName(): void
    {
        $this->assertTrue(Schema::hasTable(self::EVENTS));
        $this->assertTrue(Schema::hasTable($this->database() . '.' . self::EVENTS));
        $this->assertTrue(Schema::hasTable(self::OTHER_DATABASE . '.' . self::OTHER_TABLE));
        $this->assertFalse(Schema::hasTable(self::OTHER_TABLE));
        $this->assertFalse(Schema::hasTable(self::OTHER_DATABASE . '.' . self::EVENTS));
    }

    /**
     * hasTable() agrees with getTables(), as on Laravel's other drivers: views,
     * materialized views and dictionaries are not tables. hasView() and
     * hasDictionary() find them.
     */
    public function testHasTableLeavesOutViewsMaterializedViewsAndDictionaries(): void
    {
        $this->client()->write('CREATE TABLE ' . self::MERGE . ' (id UInt64)'
            . " ENGINE = Merge(currentDatabase(), '^" . self::EVENTS . "$')");

        foreach ([self::EVENTS, self::LOG, self::MERGE] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} is a table");
        }
        foreach ([self::VIEW, self::INNER_VIEW, self::TO_VIEW, self::DICTIONARY] as $name) {
            $this->assertFalse(Schema::hasTable($name), "{$name} is not a table");
        }

        $this->assertTrue(Schema::hasView(self::VIEW));
        $this->assertTrue(Schema::hasView(self::INNER_VIEW));
        $this->assertTrue(Schema::hasView(self::TO_VIEW));
        $this->assertTrue(Schema::hasDictionary(self::DICTIONARY));
        $this->assertTrue(Schema::hasDictionary($this->database() . '.' . self::DICTIONARY));
        $this->assertFalse(Schema::hasDictionary(self::EVENTS));
        $this->assertFalse(Schema::hasDictionary(self::VIEW));
        $this->assertFalse(Schema::hasDictionary(self::OTHER_DATABASE . '.' . self::DICTIONARY));
    }

    public function testGetTablesOfOtherDatabases(): void
    {
        $other = self::OTHER_DATABASE . '.' . self::OTHER_TABLE;

        $this->assertSame([$other], Schema::getTableListing(self::OTHER_DATABASE));

        $listing = Schema::getTableListing([self::OTHER_DATABASE, $this->database()]);
        $this->assertContains($this->database() . '.' . self::EVENTS, $listing);
        $this->assertSame($other, end($listing), 'tables are ordered by database, then name');
        $this->assertSame([], Schema::getTableListing('phpch_introspection_missing'));
    }

    public function testGetViewsListsViewsAndMaterializedViews(): void
    {
        $views = collect(Schema::getViews())->keyBy('name');
        $source = $this->database() . '.' . self::EVENTS;

        $view = fn (string $name, string $definition): array => [
            'name' => $name,
            'schema' => $this->database(),
            'schema_qualified_name' => $this->database() . '.' . $name,
            'definition' => $definition,
        ];

        $this->assertSame([
            self::INNER_VIEW => $view(self::INNER_VIEW, "SELECT id FROM {$source}"),
            self::TO_VIEW => $view(self::TO_VIEW, "SELECT id FROM {$source}"),
            self::VIEW => $view(self::VIEW, "SELECT id, name FROM {$source}"),
        ], $views->only([self::VIEW, self::INNER_VIEW, self::TO_VIEW])->all());
        $this->assertFalse($views->has(self::EVENTS));
        $this->assertFalse($views->has(self::DICTIONARY));

        $this->assertTrue(Schema::hasView(self::VIEW));
        $this->assertTrue(Schema::hasView(self::INNER_VIEW));
        $this->assertFalse(Schema::hasView(self::EVENTS));
        $this->assertFalse(Schema::hasView(self::DICTIONARY));
    }

    public function testGetColumnsDescribesEveryKindOfColumn(): void
    {
        $column = fn (string $name, string $type, bool $nullable = false, ?string $default = null, ?string $comment = null, ?array $generation = null): array => [
            'name' => $name,
            'type_name' => $type,
            'type' => $type,
            'collation' => null,
            'nullable' => $nullable,
            'default' => $default,
            'auto_increment' => false,
            'comment' => $comment,
            'generation' => $generation,
        ];

        $this->assertSame([
            $column('id', 'UInt64', comment: 'Event id'),
            $column('name', 'String', default: "'it\\'s'", comment: 'Event name'),
            $column('name_length', 'UInt64', generation: ['type' => 'stored', 'expression' => 'length(name)']),
            $column('name_upper', 'String', generation: ['type' => 'virtual', 'expression' => 'upper(name)']),
            $column('raw', 'String', default: "''"),
            $column('nickname', 'Nullable(String)', nullable: true),
            $column('city', 'LowCardinality(Nullable(String))', nullable: true),
            $column('tags', 'Array(Nullable(String))'),
        ], Schema::getColumns(self::EVENTS));
    }

    /**
     * Variant and Dynamic columns, and a SimpleAggregateFunction over a type that
     * accepts NULL, store NULL too. Both types are experimental in ClickHouse 24.8.
     */
    public function testColumnsWhoseTypeAcceptsNullAreNullable(): void
    {
        $this->client()->write(
            'CREATE TABLE ' . self::NULLABLE_TYPES . ' (
                id UInt64,
                variant Variant(String, UInt64),
                dynamic Dynamic,
                dynamic_limited Dynamic(max_types=10),
                last SimpleAggregateFunction(anyLast, Nullable(String)),
                total SimpleAggregateFunction(sum, UInt64),
                variants Array(Variant(String, UInt64))
            )
            ENGINE = AggregatingMergeTree ORDER BY id',
            [],
            true,
            ['allow_experimental_variant_type' => 1, 'allow_experimental_dynamic_type' => 1]
        );
        $this->client()->write('INSERT INTO ' . self::NULLABLE_TYPES
            . ' (id, variant, dynamic, dynamic_limited, last, total, variants) VALUES (1, NULL, NULL, NULL, NULL, 1, [])');
        $this->assertSame(
            ['variant' => 1, 'dynamic' => 1, 'dynamic_limited' => 1, 'last' => 1],
            $this->client()->select('SELECT variant IS NULL AS variant, dynamic IS NULL AS dynamic,'
                . ' dynamic_limited IS NULL AS dynamic_limited, last IS NULL AS last FROM ' . self::NULLABLE_TYPES)->rows()[0],
            'the columns hold NULL'
        );

        $this->assertSame(
            [
                'id' => false,
                'variant' => true,
                'dynamic' => true,
                'dynamic_limited' => true,
                'last' => true,
                'total' => false,
                'variants' => false,
            ],
            collect(Schema::getColumns(self::NULLABLE_TYPES))->pluck('nullable', 'name')->all()
        );
    }

    public function testColumnHelpers(): void
    {
        $this->assertSame(
            ['id', 'name', 'name_length', 'name_upper', 'raw', 'nickname', 'city', 'tags'],
            Schema::getColumnListing(self::EVENTS)
        );
        $this->assertTrue(Schema::hasColumn(self::EVENTS, 'name_upper'));
        $this->assertFalse(Schema::hasColumn(self::EVENTS, 'missing'));
        $this->assertTrue(Schema::hasColumns(self::EVENTS, ['id', 'raw', 'tags']));
        $this->assertFalse(Schema::hasColumns(self::EVENTS, ['id', 'missing']));
        $this->assertSame('LowCardinality(Nullable(String))', Schema::getColumnType(self::EVENTS, 'city'));
        $this->assertSame('Array(Nullable(String))', Schema::getColumnType(self::EVENTS, 'tags', true));

        $other = self::OTHER_DATABASE . '.' . self::OTHER_TABLE;
        $this->assertSame(['id', 'payload'], Schema::getColumnListing($other));
        $this->assertTrue(Schema::hasColumn($other, 'payload'));
        $this->assertSame([], Schema::getColumns('introspection_missing'));
    }

    public function testGetColumnsOfANameThatNeedsEscaping(): void
    {
        $this->client()->write("CREATE TABLE `introspection_it's` (`it's` UInt8) ENGINE = Memory");

        try {
            $this->assertSame(["it's"], Schema::getColumnListing("introspection_it's"));
            $this->assertTrue(Schema::hasTable("introspection_it's"));
        } finally {
            $this->client()->write("DROP TABLE IF EXISTS `introspection_it's` SYNC");
        }
    }

    /**
     * The primary key's type is null, as on Laravel's SQLite driver; the primary flag marks it.
     */
    public function testGetIndexesListsThePrimaryKeyAndTheDataSkippingIndices(): void
    {
        $this->assertSame([
            ['name' => 'primary', 'columns' => ['id', 'intHash32(id)'], 'type' => null, 'unique' => false, 'primary' => true],
            ['name' => 'idx_name', 'columns' => ['name'], 'type' => 'bloom_filter', 'unique' => false, 'primary' => false],
            ['name' => 'idx_id_length', 'columns' => ['id', 'name_length'], 'type' => 'minmax', 'unique' => false, 'primary' => false],
        ], Schema::getIndexes(self::EVENTS));

        $this->assertSame(['primary', 'idx_name', 'idx_id_length'], Schema::getIndexListing(self::EVENTS));
        $this->assertTrue(Schema::hasIndex(self::EVENTS, 'primary'));
        $this->assertTrue(Schema::hasIndex(self::EVENTS, ['id', 'intHash32(id)'], 'primary'));
        $this->assertTrue(Schema::hasIndex(self::EVENTS, 'idx_name', 'bloom_filter'));
        $this->assertFalse(Schema::hasIndex(self::EVENTS, 'idx_name', 'unique'));
        $this->assertFalse(Schema::hasIndex(self::EVENTS, 'idx_missing'));

        $this->assertSame([], Schema::getIndexes(self::LOG), 'a Log table has no primary key');
        $this->assertSame(
            [['name' => 'primary', 'columns' => ['id'], 'type' => null, 'unique' => false, 'primary' => true]],
            Schema::getIndexes(self::OTHER_DATABASE . '.' . self::OTHER_TABLE)
        );
    }

    public function testThereAreNoForeignKeys(): void
    {
        $this->assertSame([], Schema::getForeignKeys(self::EVENTS));
        $this->assertFalse(Schema::hasForeignKey(self::EVENTS, ['id']));
    }

    public function testForeignKeyConstraintTogglesSendNoQuery(): void
    {
        DB::connection()->enableQueryLog();

        $this->assertTrue(Schema::disableForeignKeyConstraints());
        $this->assertTrue(Schema::enableForeignKeyConstraints());
        $this->assertSame('result', Schema::withoutForeignKeyConstraints(fn (): string => 'result'));

        $this->assertSame([], DB::connection()->getQueryLog());
    }

    public function testServerVersionAndDriverTitle(): void
    {
        $this->assertSame(
            $this->client()->select('SELECT version() AS version')->fetchOne('version'),
            DB::connection()->getServerVersion()
        );
        $this->assertSame('ClickHouse', DB::connection()->getDriverTitle());
    }

    public function testDbShow(): void
    {
        $this->artisan('db:show', ['--database' => 'clickhouse'])
            ->expectsOutputToContain('ClickHouse')
            ->expectsOutputToContain(self::EVENTS)
            ->assertExitCode(0);

        $this->client()->write('INSERT INTO ' . self::EVENTS . ' (id) VALUES (1), (2)');
        $this->assertSame(0, Artisan::call('db:show', [
            '--database' => 'clickhouse',
            '--counts' => true,
            '--views' => true,
            '--json' => true,
        ]));
        $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('ClickHouse', $data['platform']['name']);
        $this->assertSame(DB::connection()->getServerVersion(), $data['platform']['version']);
        $this->assertSame('clickhouse', $data['platform']['connection']);
        $tables = collect($data['tables'])->keyBy('table');
        $this->assertSame(2, $tables[self::EVENTS]['rows']);
        $this->assertSame('MergeTree', $tables[self::EVENTS]['engine']);
        $this->assertSame('Event log', $tables[self::EVENTS]['comment']);
        $this->assertSame(2, $tables[self::LOG]['rows'], 'the materialized view wrote to its target table');
        $this->assertFalse($tables->has(self::VIEW));
        $views = collect($data['views'])->keyBy('view');
        $this->assertSame(2, $views[self::VIEW]['rows']);
        $this->assertSame(2, $views[self::INNER_VIEW]['rows']);
        $this->assertSame(2, $views[self::TO_VIEW]['rows']);
    }

    public function testDbTable(): void
    {
        $this->artisan('db:table', ['table' => self::EVENTS, '--database' => 'clickhouse'])
            ->expectsOutputToContain($this->database() . '.' . self::EVENTS)
            ->assertExitCode(0);

        $this->assertSame(0, Artisan::call('db:table', [
            'table' => $this->database() . '.' . self::EVENTS,
            '--database' => 'clickhouse',
            '--json' => true,
        ]));
        $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(self::EVENTS, $data['table']['name']);
        $this->assertSame($this->database(), $data['table']['schema']);
        $this->assertSame(8, $data['table']['columns']);
        $this->assertSame('MergeTree', $data['table']['engine']);
        $this->assertSame('Event log', $data['table']['comment']);
        $columns = collect($data['columns'])->keyBy('column');
        $this->assertSame(['UInt64', 'stored'], array_values($columns['name_length']['attributes']));
        $this->assertSame(['Nullable(String)', 'nullable'], array_values($columns['nickname']['attributes']));
        $this->assertSame("'it\\'s'", $columns['name']['default']);
        $this->assertSame(
            ['primary', 'idx_name', 'idx_id_length'],
            array_column($data['indexes'], 'name')
        );
        $this->assertSame(['id', 'intHash32(id)'], $data['indexes'][0]['columns']);
        $this->assertSame(['compound', 'primary'], array_values($data['indexes'][0]['attributes']), 'primary appears once');
        $this->assertSame(['bloom_filter'], array_values($data['indexes'][1]['attributes']));
        $this->assertSame([], $data['foreign_keys']);

        $this->artisan('db:table', ['table' => self::VIEW, '--database' => 'clickhouse'])
            ->expectsOutputToContain("Table [" . self::VIEW . "] doesn't exist.")
            ->assertExitCode(1);
    }

    private function client(): Client
    {
        return DB::connection('clickhouse')->getClient();
    }

    private function database(): string
    {
        return DB::connection('clickhouse')->getDatabaseName();
    }

    private function totalBytes(string $table): int
    {
        return (int) $this->client()->select(
            "SELECT total_bytes FROM system.tables WHERE database = currentDatabase() AND name = '{$table}'"
        )->fetchOne('total_bytes');
    }

    /**
     * @return string[]
     */
    private function innerTables(): array
    {
        return array_column($this->client()->select(
            "SELECT name FROM system.tables WHERE database = currentDatabase() AND startsWith(name, '.inner')"
        )->rows(), 'name');
    }

    /**
     * Drop the objects of this test, dependents first.
     */
    private function dropObjects(): void
    {
        $client = $this->client();
        $client->write('DROP DICTIONARY IF EXISTS ' . self::DICTIONARY . ' SYNC');
        foreach ([self::NULLABLE_TYPES, self::MERGE, self::TO_VIEW, self::INNER_VIEW, self::VIEW, self::LOG, self::EVENTS] as $table) {
            $client->write("DROP TABLE IF EXISTS {$table} SYNC");
        }
        $client->write('DROP DATABASE IF EXISTS ' . self::OTHER_DATABASE . ' SYNC');
    }
}
