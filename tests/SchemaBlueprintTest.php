<?php

declare(strict_types=1);

namespace Tests;

use ClickHouseDB\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Column;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Tables\MergeTree;
use Oralunal\LaravelClickHouse\Migration;
use Oralunal\LaravelClickHouse\SchemaBlueprint;

/**
 * Laravel's schema builder on a ClickHouse connection: the CREATE TABLE
 * statements that Schema::create() sends, and the ALTER, RENAME and DROP
 * statements of Schema::table(), rename() and drop(), checked in
 * system.tables, system.columns and system.data_skipping_indices.
 *
 * The checks hold on ClickHouse 24.8, 26.3 and 26.8: 64-bit integers are
 * read with toString(), since 25.8 and later no longer quote them in JSON;
 * a key of one column is read without the parentheses that 26.8 keeps (see
 * table()); the command of a mutation is read without the parentheses that
 * 25.8 and later add; and a change() that drops nullable() also gives a
 * default(), which 26.3 and 26.8 require when a MODIFY COLUMN turns a
 * Nullable type into one without NULL (BAD_ARGUMENTS).
 */
class SchemaBlueprintTest extends TestCase
{
    private const VISITS = 'schema_blueprint_visits';

    private const EVENTS = 'schema_blueprint_events';

    private const TYPES = 'schema_blueprint_types';

    private const KEYS = 'schema_blueprint_keys';

    private const MEMORY = 'schema_blueprint_memory';

    private const QUOTED = "schema_blueprint it's `quoted`";

    private const MERGE_TREE = "schema_blueprint_merge_tree it's";

    private const DECIMALS = 'schema_blueprint_decimals';

    private const KEY_VALUE = 'schema_blueprint_key_value';

    private const MERGE_TREE_VALUES = 'schema_blueprint_merge_tree_values';

    private const QUALIFIED = 'schema_blueprint_qualified';

    private const HAND_QUOTED = 'schema_blueprint hand quoted';

    private const ENUMS = 'schema_blueprint_enums';

    private const MERGE_TREE_ENUMS = 'schema_blueprint_merge_tree_enums';

    private const ALTERED = 'schema_blueprint_altered';

    private const ALTERED_RENAMED = 'schema_blueprint_altered_renamed';

    private const CHANGED = 'schema_blueprint_changed';

    private const INDEXED = 'schema_blueprint_indexed';

    private const CONSTRAINED = 'schema_blueprint_constrained';

    private const REPLICATED = 'schema_blueprint_replicated';

    /** A copy of the clickhouse connection that maps integers to their exact types. */
    private const EXACT = 'clickhouse-exact-integers';

    /** A copy of the clickhouse connection whose tables default to ReplacingMergeTree. */
    private const REPLACING = 'clickhouse-replacing-engine';

    /** A copy of the clickhouse connection whose indexes without a type are minmax indexes. */
    private const DEFAULT_INDEX_TYPE = 'clickhouse-default-index-type';

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->app['config'];
        $clickhouse = $config->get('database.connections.clickhouse');
        $config->set('database.connections.' . self::EXACT, ['exact_integer_types' => true] + $clickhouse);
        $config->set('database.connections.' . self::REPLACING, ['engine' => 'ReplacingMergeTree'] + $clickhouse);
        $config->set('database.connections.' . self::DEFAULT_INDEX_TYPE, ['default_index_type' => 'minmax'] + $clickhouse);

        $this->dropTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function testEveryTableClause(): void
    {
        Schema::create(self::VISITS, function (SchemaBlueprint $table) {
            $table->unsignedBigInteger('id');
            $table->unsignedBigInteger('version');
            $table->dateTime('visited_at', 3)->timezone('UTC')->useCurrent();
            $table->string('country')->nullable()->lowCardinality()->comment("it's the ISO code");
            $table->string('url')->codec('ZSTD(3)');
            $table->string('url_domain')->storedAs('domain(url)');
            $table->string('url_upper')->virtualAs('upper(url)');
            $table->string('raw')->ephemeral('');
            $table->dateTime('expires')->ttl('toDateTime(visited_at) + INTERVAL 1 DAY');
            $table->index('url', null, 'bloom_filter(0.01)')->granularity(4);
            $table->index(['country', 'id'], 'visits_country_id_index', 'minmax');
            $table->rawIndex('lower(url)', 'visits_lower_url')->algorithm('ngrambf_v1(3, 256, 2, 0)');
            $table->index('raw');
            $table->engine('ReplacingMergeTree(version)');
            $table->partitionBy('toYYYYMM(visited_at)');
            $table->primary(['id', DB::raw('intHash32(id)')], 'visits_primary');
            $table->orderBy('id', DB::raw('intHash32(id)'));
            $table->sampleBy('intHash32(id)');
            $table->ttl('toDateTime(visited_at) + INTERVAL 1 YEAR');
            $table->settings(['index_granularity' => 4096, 'ttl_only_drop_parts' => true]);
            $table->comment("Visits, it's deduplicated");
        });

        $this->assertSame([
            'engine' => 'ReplacingMergeTree',
            'sorting_key' => 'id, intHash32(id)',
            'primary_key' => 'id, intHash32(id)',
            'partition_key' => 'toYYYYMM(visited_at)',
            'sampling_key' => 'intHash32(id)',
            'comment' => "Visits, it's deduplicated",
        ], $this->table(self::VISITS, ['engine', 'sorting_key', 'primary_key', 'partition_key', 'sampling_key', 'comment']));
        $this->assertStringContainsString(
            'TTL toDateTime(visited_at) + toIntervalYear(1) SETTINGS index_granularity = 4096, ttl_only_drop_parts = 1',
            $this->table(self::VISITS, ['engine_full'])['engine_full']
        );
        $this->assertSame([
            ['id', 'Int64', '', '', '', ''],
            ['version', 'Int64', '', '', '', ''],
            ['visited_at', "DateTime64(3, 'UTC')", 'DEFAULT', 'now64(3)', '', ''],
            ['country', 'LowCardinality(Nullable(String))', '', '', "it's the ISO code", ''],
            ['url', 'String', '', '', '', 'CODEC(ZSTD(3))'],
            ['url_domain', 'String', 'MATERIALIZED', 'domain(url)', '', ''],
            ['url_upper', 'String', 'ALIAS', 'upper(url)', '', ''],
            ['raw', 'String', 'EPHEMERAL', "''", '', ''],
            ['expires', 'DateTime', '', '', '', ''],
        ], $this->columns(self::VISITS, ['name', 'type', 'default_kind', 'default_expression', 'comment', 'compression_codec']));
        $this->assertSame([
            ['schema_blueprint_visits_url_index', 'bloom_filter(0.01)', 'url', '4'],
            ['visits_country_id_index', 'minmax', 'country, id', '1'],
            ['visits_lower_url', 'ngrambf_v1(3, 256, 2, 0)', 'lower(url)', '1'],
        ], array_map(fn (array $row): array => array_map('strval', array_values($row)), $this->client()->select(
            'SELECT name, type_full, expr, granularity FROM system.data_skipping_indices'
            . " WHERE database = currentDatabase() AND table = '" . self::VISITS . "' ORDER BY name"
        )->rows()));
    }

    public function testLaravelColumnsKeepTheirModifiers(): void
    {
        Schema::create(self::EVENTS, function ($table) {
            $table->id();
            $table->string('name')->nullable()->default("it's")->comment('Event name');
            $table->timestamps();
        });

        $this->assertSame([
            ['id', 'Int64', '', '', ''],
            ['name', 'Nullable(String)', 'DEFAULT', "'it\\'s'", 'Event name'],
            ['created_at', 'Nullable(DateTime)', '', '', ''],
            ['updated_at', 'Nullable(DateTime)', '', '', ''],
        ], $this->columns(self::EVENTS, ['name', 'type', 'default_kind', 'default_expression', 'comment']));
        $this->assertSame('id', $this->table(self::EVENTS, ['sorting_key'])['sorting_key']);
    }

    public function testEveryColumnTypeWithoutExactIntegerTypes(): void
    {
        $this->createTypesTable('clickhouse');

        $this->assertSame([
            'c_char' => 'FixedString(2)',
            'c_string' => 'String',
            'c_text' => 'String',
            'c_tiny' => 'Int16',
            'c_utiny' => 'Int16',
            'c_small' => 'Int16',
            'c_usmall' => 'Int32',
            'c_medium' => 'Int32',
            'c_umedium' => 'Int32',
            'c_int' => 'Int32',
            'c_uint' => 'Int32',
            'c_big' => 'Int64',
            'c_ubig' => 'Int64',
            'c_float' => 'Float64',
            'c_float24' => 'Float32',
            'c_double' => 'Float64',
            'c_decimal' => 'Decimal(10, 2)',
            'c_bool' => 'Bool',
            'c_enum' => "Enum8('a' = 1, 'it\\'s' = 2)",
            'c_enum_numbered' => "Enum8('low' = 1, 'high' = 10)",
            'c_enum_numeric_names' => "Enum8('active' = 1, '200' = 2)",
            'c_json' => 'String',
            'c_date' => 'Date',
            'c_date32' => 'Date32',
            'c_datetime' => 'DateTime',
            'c_datetime3' => "DateTime64(3, 'UTC')",
            'c_year' => 'UInt16',
            'c_binary' => 'String',
            'c_fixed_binary' => 'FixedString(4)',
            'c_uuid' => 'UUID',
            'c_ulid' => 'FixedString(26)',
            'c_ip' => 'String',
            'c_mac' => 'String',
            'c_ipv4' => 'IPv4',
            'c_ipv6' => 'IPv6',
            'c_point' => 'Point',
            'c_polygon' => 'Polygon',
            'c_vector' => 'Array(Float32)',
            'c_array' => 'Array(String)',
            'c_map' => 'Map(String, UInt64)',
            'c_raw' => 'Decimal(76, 4)',
            'deleted_at' => 'Nullable(DateTime)',
        ], array_column($this->columns(self::TYPES, ['name', 'type']), 1, 0));

        $this->insertAndReadBackTypes();
    }

    public function testEveryIntegerTypeWithExactIntegerTypes(): void
    {
        $this->createTypesTable(self::EXACT);

        $this->assertSame([
            'c_tiny' => 'Int8',
            'c_utiny' => 'UInt8',
            'c_small' => 'Int16',
            'c_usmall' => 'UInt16',
            'c_medium' => 'Int32',
            'c_umedium' => 'UInt32',
            'c_int' => 'Int32',
            'c_uint' => 'UInt32',
            'c_big' => 'Int64',
            'c_ubig' => 'UInt64',
        ], array_intersect_key(
            array_column($this->columns(self::TYPES, ['name', 'type']), 1, 0),
            array_flip(['c_tiny', 'c_utiny', 'c_small', 'c_usmall', 'c_medium', 'c_umedium', 'c_int', 'c_uint', 'c_big', 'c_ubig'])
        ));

        $this->insertAndReadBackTypes();
    }

    public function testTheSortingKey(): void
    {
        Schema::create(self::EVENTS, function (SchemaBlueprint $table) {
            $table->string('name')->nullable();
            $table->id();
        });
        $this->assertSame(['sorting_key' => '', 'primary_key' => ''], $this->table(self::EVENTS, ['sorting_key', 'primary_key']));

        Schema::create(self::KEYS, function (SchemaBlueprint $table) {
            $table->string('name');
            $table->unsignedBigInteger('id')->primary();
        });
        $this->assertSame(['sorting_key' => 'id', 'primary_key' => 'id'], $this->table(self::KEYS, ['sorting_key', 'primary_key']));
        Schema::drop(self::KEYS);

        Schema::create(self::KEYS, function (SchemaBlueprint $table) {
            $table->unsignedBigInteger('id');
            $table->dateTime('at');
            $table->primary('id');
            $table->orderBy('id', 'at');
        });
        $this->assertSame(['sorting_key' => 'id, at', 'primary_key' => 'id'], $this->table(self::KEYS, ['sorting_key', 'primary_key']));
        Schema::drop(self::KEYS);

        Schema::create(self::KEYS, function (SchemaBlueprint $table) {
            $table->array('tags', 'Nullable(String)');
            $table->id();
        });
        $this->assertSame(['sorting_key' => '', 'primary_key' => ''], $this->table(self::KEYS, ['sorting_key', 'primary_key']));
    }

    /**
     * Without a sorting key, ReplacingMergeTree would keep one row of each
     * partition, so a first column that cannot be the key throws before
     * anything is sent.
     */
    public function testAnEngineThatMergesRowsNeedsASortingKey(): void
    {
        try {
            Schema::connection(self::REPLACING)->create(self::EVENTS, function (SchemaBlueprint $table) {
                $table->string('name')->nullable();
                $table->id();
            });
            $this->fail('Schema::create() should throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'the ReplacingMergeTree engine would merge the rows of each partition into one',
                $exception->getMessage()
            );
        }
        $this->assertFalse(Schema::hasTable(self::EVENTS));

        Schema::connection(self::REPLACING)->create(self::EVENTS, function (SchemaBlueprint $table) {
            $table->string('name')->nullable();
            $table->id();
            $table->orderBy('id');
        });
        $this->assertSame(['engine' => 'ReplacingMergeTree', 'sorting_key' => 'id'], $this->table(self::EVENTS, ['engine', 'sorting_key']));
    }

    /**
     * EmbeddedRocksDB requires a PRIMARY KEY, which primary() gives.
     */
    public function testPrimaryIsTheKeyOfAKeyValueEngine(): void
    {
        Schema::create(self::KEY_VALUE, function (SchemaBlueprint $table) {
            $table->string('key')->primary();
            $table->string('value');
            $table->engine('EmbeddedRocksDB');
        });
        $this->client()->write('INSERT INTO ' . self::KEY_VALUE . " VALUES ('a', 'first'), ('a', 'second'), ('b', 'third')");

        $this->assertSame(['engine' => 'EmbeddedRocksDB', 'primary_key' => 'key'], $this->table(self::KEY_VALUE, ['engine', 'primary_key']));
        $this->assertSame(
            [['key' => 'a', 'value' => 'second'], ['key' => 'b', 'value' => 'third']],
            $this->client()->select('SELECT key, value FROM ' . self::KEY_VALUE . ' ORDER BY key')->rows()
        );
    }

    /**
     * ClickHouse truncates a bare float to the Decimal's scale (DEFAULT 19.99
     * stores 19.98), so the float defaults of Decimal columns are sent as
     * string literals, which it reads exactly.
     */
    public function testAFloatDefaultOfADecimalColumnIsStoredExactly(): void
    {
        Schema::create(self::DECIMALS, function (SchemaBlueprint $table) {
            $table->unsignedBigInteger('id');
            $table->decimal('price', 10, 2)->default(19.99);
            $table->decimal('discount', 10, 2)->nullable()->default(0.29);
            $table->rawColumn('fee', 'Decimal(10, 2)')->default(1.15);
            $table->array('prices', 'Decimal(10, 2)')->default([19.99, 0.29]);
            $table->decimal('input', 10, 2)->ephemeral(19.99);
            $table->string('input_text')->default(DB::raw('toString(input)'));
            $table->double('ratio')->default(0.1 + 0.2);
        });
        $this->client()->write('INSERT INTO ' . self::DECIMALS . ' (id) VALUES (1)');

        $this->assertSame(
            ['price' => 19.99, 'discount' => 0.29, 'fee' => 1.15, 'prices' => [19.99, 0.29], 'input_text' => '19.99', 'ratio' => 0.30000000000000004],
            $this->client()->select('SELECT price, discount, fee, prices, input_text, ratio FROM ' . self::DECIMALS)->rows()[0]
        );
    }

    public function testTheEngine(): void
    {
        Schema::create(self::MEMORY, function (SchemaBlueprint $table) {
            $table->id();
            $table->primary('id');
            $table->engine('Memory');
            $table->comment('kept in memory');
        });
        $this->assertSame(
            ['engine_full' => 'Memory', 'sorting_key' => '', 'comment' => 'kept in memory'],
            $this->table(self::MEMORY, ['engine_full', 'sorting_key', 'comment'])
        );

        Schema::connection(self::REPLACING)->create(self::EVENTS, fn (SchemaBlueprint $table) => $table->id());
        $this->assertSame(
            ['engine' => 'ReplacingMergeTree', 'sorting_key' => 'id'],
            $this->table(self::EVENTS, ['engine', 'sorting_key'])
        );
    }

    public function testIfNotExists(): void
    {
        foreach ([1, 2] as $run) {
            Schema::create(self::EVENTS, function (SchemaBlueprint $table) use ($run) {
                $table->id();
                $table->string("column_of_run_{$run}");
                $table->ifNotExists();
            });
        }

        $this->assertSame(['id', 'column_of_run_1'], array_column($this->columns(self::EVENTS, ['name']), 0));
    }

    /**
     * Laravel's migration repository created the migrations table of this
     * connection with Schema::create(), before the first test ran.
     */
    public function testTheMigrationsTableKeepsItsColumns(): void
    {
        $this->assertSame(
            [['id', 'Int32'], ['migration', 'String'], ['batch', 'Int32']],
            $this->columns('migrations', ['name', 'type'])
        );
        $this->assertSame('id', $this->table('migrations', ['sorting_key'])['sorting_key']);
        $this->assertTrue(Schema::hasTable('migrations'));
    }

    public function testNamesThatNeedQuoting(): void
    {
        Schema::create(self::QUOTED, function (SchemaBlueprint $table) {
            $table->unsignedBigInteger('we`ird');
            $table->string('n.a');
            $table->string('end\\');
            $table->index('n.a', 'idx `n.a`', 'bloom_filter');
        });
        Schema::table(self::QUOTED, fn (SchemaBlueprint $table) => $table->renameColumn('end\\', "it's"));

        $this->assertSame(['we`ird', 'n.a', "it's"], array_column($this->columns(self::QUOTED, ['name']), 0));
        $this->assertSame(['primary', 'idx `n.a`'], Schema::getIndexListing(self::QUOTED));
        $this->assertSame(['we`ird'], Schema::getIndexes(self::QUOTED)[0]['columns']);
        $this->assertTrue(Schema::hasTable(self::QUOTED));

        Schema::drop(self::QUOTED);
        $this->assertFalse(Schema::hasTable(self::QUOTED));
    }

    public function testCreateMergeTreeEscapesNamesAndValues(): void
    {
        $migration = new class extends Migration {
            public function up(): void
            {
                static::createMergeTree("schema_blueprint_merge_tree it's", fn (MergeTree $table) => $table
                    ->columns([
                        $table->uInt32('id'),
                        $table->string('my col')->default("a'b")->comment("it's \\ here"),
                        $table->string('slash')->default('ends with \\'),
                        $table->enum('status', ["it's", 'back\\slash']),
                    ])
                    ->orderBy('id'));
            }
        };

        $migration->up();

        $this->assertSame([
            ['id', 'UInt32', '', '', ''],
            ['my col', 'String', 'DEFAULT', "'a\\'b'", "it's \\ here"],
            ['slash', 'String', 'DEFAULT', "'ends with \\\\'", ''],
            ['status', "Enum8('it\\'s' = 1, 'back\\\\slash' = 2)", '', '', ''],
        ], $this->columns(self::MERGE_TREE, ['name', 'type', 'default_kind', 'default_expression', 'comment']));
    }

    /**
     * A database name qualifies a createMergeTree() table name, and a name
     * quoted by hand is kept, as in 3.0.0.
     */
    public function testCreateMergeTreeKeepsQualifiedAndHandQuotedNames(): void
    {
        $migration = new class extends Migration {
            public static string $qualifiedName = '';

            public function up(): void
            {
                static::createMergeTree(static::$qualifiedName, fn (MergeTree $table) => $table
                    ->columns([$table->uInt32('id')])
                    ->orderBy('id'));
                static::createMergeTree('`schema_blueprint hand quoted`', fn (MergeTree $table) => $table
                    ->columns([$table->uInt32('id'), $table->string('`my col`'), $table->string('n.a')])
                    ->orderBy('id'));
            }
        };
        $migration::$qualifiedName = DB::connection('clickhouse')->getDatabaseName() . '.' . self::QUALIFIED;

        $migration->up();

        $this->assertSame([['id']], $this->columns(self::QUALIFIED, ['name']));
        $this->assertSame([['id'], ['my col'], ['n.a']], $this->columns(self::HAND_QUOTED, ['name']));
    }

    /**
     * createMergeTree() sends the float default of a Decimal column as a
     * string literal, numbers an enum with a string key by its keys, a
     * numeric name included, and writes the settings as literals.
     */
    public function testCreateMergeTreeWritesDecimalDefaultsEnumsAndSettings(): void
    {
        $migration = new class extends Migration {
            public function up(): void
            {
                static::createMergeTree('schema_blueprint_merge_tree_values', fn (MergeTree $table) => $table
                    ->columns([
                        $table->uInt32('id'),
                        $table->decimal('price', 10, 2)->default(19.99),
                        $table->enum('status', ['active' => 1, '404' => 2]),
                    ])
                    ->orderBy('id')
                    ->settings(['ttl_only_drop_parts' => false, 'storage_policy' => 'default']));
            }
        };

        $migration->up();
        $this->client()->write('INSERT INTO ' . self::MERGE_TREE_VALUES . " (id, status) VALUES (1, '404')");

        $this->assertSame(
            ['price' => 19.99, 'status' => '404'],
            $this->client()->select('SELECT price, status FROM ' . self::MERGE_TREE_VALUES)->rows()[0]
        );
        $this->assertSame("Enum8('active' = 1, '404' = 2)", $this->columns(self::MERGE_TREE_VALUES, ['type'])[2][0]);
        $this->assertStringContainsString(
            "SETTINGS ttl_only_drop_parts = 0, storage_policy = 'default'",
            $this->table(self::MERGE_TREE_VALUES, ['engine_full'])['engine_full']
        );
    }

    /**
     * Laravel's enum() and createMergeTree()'s enum() read an array the same
     * way. An array with a string key maps each name to its number, an
     * integer key (which PHP makes of a numeric string key) being a name too.
     * An array whose keys are all integers is a list of names written from
     * its values, whatever the gaps, as in 3.0.0; so ['200' => 1, '404' => 2]
     * gives the names '1' and '2', and names that are all numbers are
     * numbered with a raw type.
     */
    public function testBothEnumMethodsReadNamesAndNumbersAlike(): void
    {
        $enums = [
            'e_unique_numbers' => array_unique([200, 404, 200, 500]),
            'e_filtered_numbers' => array_filter([0, 200, 404]),
            'e_numbered' => ['active' => 1, 'inactive' => 2],
            'e_numeric_name' => ['active' => 1, '200' => 2],
            'e_numeric_name_first' => [200 => 1, 'active' => 2],
            'e_numeric_names_only' => ['200' => 1, '404' => 2],
        ];
        $rawType = "Enum8('200' = 1, '404' = 2)";

        Schema::create(self::ENUMS, function (SchemaBlueprint $table) use ($enums, $rawType) {
            $table->unsignedBigInteger('id');
            foreach ($enums as $column => $allowed) {
                $table->enum($column, $allowed);
            }
            $table->rawColumn('e_raw', $rawType);
        });
        $migration = new class extends Migration {
            /** @var array<string, array<int|string, int|string>> */
            public static array $enums = [];

            public static string $rawType = '';

            public function up(): void
            {
                static::createMergeTree('schema_blueprint_merge_tree_enums', fn (MergeTree $table) => $table
                    ->columns(fn (MergeTree $table): array => [
                        $table->uInt64('id'),
                        ...array_map(
                            fn (string $column, array $allowed): Column => $table->enum($column, $allowed),
                            array_keys(static::$enums),
                            static::$enums
                        ),
                        $table->column('e_raw', static::$rawType),
                    ])
                    ->orderBy('id'));
            }
        };
        $migration::$enums = $enums;
        $migration::$rawType = $rawType;
        $migration->up();

        foreach ([self::ENUMS, self::MERGE_TREE_ENUMS] as $table) {
            $this->assertSame([
                ['id', $table === self::ENUMS ? 'Int64' : 'UInt64'],
                ['e_unique_numbers', "Enum8('200' = 1, '404' = 2, '500' = 3)"],
                ['e_filtered_numbers', "Enum8('200' = 1, '404' = 2)"],
                ['e_numbered', "Enum8('active' = 1, 'inactive' = 2)"],
                ['e_numeric_name', "Enum8('active' = 1, '200' = 2)"],
                ['e_numeric_name_first', "Enum8('200' = 1, 'active' = 2)"],
                ['e_numeric_names_only', "Enum8('1' = 1, '2' = 2)"],
                ['e_raw', $rawType],
            ], $this->columns($table, ['name', 'type']), $table);

            $this->client()->write(
                "INSERT INTO {$table} VALUES (1, '500', '404', 'inactive', '200', '200', '2', '404')"
            );
            $this->assertSame([[
                'e_unique_numbers' => '500',
                'e_filtered_numbers' => '404',
                'e_numbered' => 'inactive',
                'e_numeric_name' => '200',
                'e_numeric_name_first' => '200',
                'e_numeric_names_only' => '2',
                'e_raw' => '404',
                'raw_number' => 2,
            ]], $this->client()->select(
                'SELECT ' . implode(', ', array_keys($enums)) . ", e_raw, toInt8(e_raw) AS raw_number FROM {$table}"
            )->rows(), $table);
        }
    }

    /**
     * Schema::table() adds the columns in one ALTER, at their positions, and
     * the sorting key that orderBy() extends with them goes into that ALTER.
     */
    public function testSchemaTableAddsColumnsAndExtendsTheSortingKey(): void
    {
        $this->createAlteredTable();

        Schema::table(self::ALTERED, function (SchemaBlueprint $table) {
            $table->orderBy('id', DB::raw('intHash32(id)'), 'step');
            $table->string('source')->lowCardinality()->default('web')->comment("it's the source")->first();
            $table->integer('step')->after('id');
        });

        $this->assertSame([
            ['source', 'LowCardinality(String)', 'DEFAULT', "'web'", "it's the source"],
            ['id', 'Int64', '', '', ''],
            ['step', 'Int32', '', '', ''],
            ['visited_at', 'DateTime', '', '', ''],
            ['referrer', 'String', '', '', ''],
            ['traffic_source', 'String', '', '', ''],
            ['payload', 'String', '', '', ''],
        ], $this->columns(self::ALTERED, ['name', 'type', 'default_kind', 'default_expression', 'comment']));
        $this->assertSame(
            ['sorting_key' => 'id, intHash32(id), step', 'primary_key' => 'id, intHash32(id)'],
            $this->table(self::ALTERED, ['sorting_key', 'primary_key'])
        );
        $this->assertSame(
            [['source' => 'web', 'id' => '1', 'step' => 0]],
            $this->client()->select('SELECT source, toString(id) AS id, step FROM ' . self::ALTERED)->rows()
        );
    }

    public function testSchemaTableDropsAndRenamesColumnsAndRenameKeepsTheDatabase(): void
    {
        $this->createAlteredTable();

        Schema::table(self::ALTERED, function (SchemaBlueprint $table) {
            $table->dropColumn(['referrer', 'traffic_source']);
            $table->renameColumn('payload', 'body');
        });
        $this->assertSame(['id', 'visited_at', 'body'], array_column($this->columns(self::ALTERED, ['name']), 0));

        Schema::rename(self::ALTERED, self::ALTERED_RENAMED);
        $this->assertFalse(Schema::hasTable(self::ALTERED));
        $this->assertTrue(Schema::hasTable(self::ALTERED_RENAMED));

        Schema::rename(DB::connection('clickhouse')->getDatabaseName() . '.' . self::ALTERED_RENAMED, self::ALTERED);
        $this->assertTrue(Schema::hasTable(self::ALTERED));
        $this->assertSame([['id' => '1', 'body' => 'p']], $this->client()->select('SELECT toString(id) AS id, body FROM ' . self::ALTERED)->rows());
    }

    /**
     * The statements of Schema::table() run in the order of the calls: a
     * column added after a drop or a rename is added after it runs, so a
     * column can be dropped and added again with a new type, and after() can
     * name a column that an earlier rename creates.
     */
    public function testSchemaTableRunsItsStatementsInTheOrderOfTheCalls(): void
    {
        $this->createAlteredTable();

        Schema::table(self::ALTERED, function (SchemaBlueprint $table) {
            $table->string('note');
            $table->dropColumn('payload');
            $table->integer('payload');
        });
        Schema::table(self::ALTERED, function (SchemaBlueprint $table) {
            $table->dropColumn('traffic_source');
            $table->string('a');
            $table->renameColumn('referrer', 'source');
            $table->string('b')->after('source');
        });

        $this->assertSame(
            [['id', 'Int64'], ['visited_at', 'DateTime'], ['source', 'String'], ['b', 'String'], ['note', 'String'], ['payload', 'Int32'], ['a', 'String']],
            $this->columns(self::ALTERED, ['name', 'type'])
        );

        try {
            Schema::table(self::ALTERED, function (SchemaBlueprint $table) {
                $table->integer('step');
                $table->dropColumn('a');
                $table->integer('step2');
                $table->orderBy('id', DB::raw('intHash32(id)'), 'step', 'step2');
            });
            $this->fail('orderBy() with added columns in several statements should throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringStartsWith('ClickHouse accepts a new sorting key only in the ALTER that adds its columns', $exception->getMessage());
        }
        $this->assertSame(
            ['id', 'visited_at', 'source', 'b', 'note', 'payload', 'a'],
            array_column($this->columns(self::ALTERED, ['name']), 0)
        );
    }

    public function testSchemaTableChangesTheTableClauses(): void
    {
        $this->createAlteredTable();

        Schema::table(self::ALTERED, function (SchemaBlueprint $table) {
            $table->comment("Visits, it's deduplicated");
            $table->sampleBy('intHash32(id)');
            $table->ttl('visited_at + INTERVAL 2 YEAR');
            $table->settings(['merge_with_ttl_timeout' => 3600, 'ttl_only_drop_parts' => true]);
        });

        $this->assertSame(
            ['sampling_key' => 'intHash32(id)', 'comment' => "Visits, it's deduplicated"],
            $this->table(self::ALTERED, ['sampling_key', 'comment'])
        );
        $this->assertStringContainsString(
            'SAMPLE BY intHash32(id) TTL visited_at + toIntervalYear(2) SETTINGS index_granularity = 8192,'
            . ' merge_with_ttl_timeout = 3600, ttl_only_drop_parts = 1',
            $this->table(self::ALTERED, ['engine_full'])['engine_full']
        );

        try {
            Schema::table(self::ALTERED, function (SchemaBlueprint $table) {
                $table->comment('never sent');
                $table->partitionBy('toYYYYMM(visited_at)');
            });
            $this->fail('partitionBy() in Schema::table() should throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringStartsWith('ClickHouse cannot change the partition key of the table', $exception->getMessage());
        }
        $this->assertSame(
            ['partition_key' => '', 'comment' => "Visits, it's deduplicated"],
            $this->table(self::ALTERED, ['partition_key', 'comment'])
        );
    }

    /**
     * change() gives the column its new definition, as Laravel's drivers do:
     * a default expression or a comment that the new definition leaves out is
     * removed, while the CODEC and the TTL of the column are kept.
     */
    public function testChangeGivesTheColumnItsNewDefinition(): void
    {
        $this->createChangedTable('MergeTree ORDER BY id');

        $this->changeEveryColumn(self::CHANGED);

        $this->assertChangedColumns(self::CHANGED);
    }

    /**
     * The same change() on a ReplicatedMergeTree table, whose ALTERs go
     * through ClickHouse Keeper. Needs the test cluster's Keeper.
     */
    public function testChangeOnAReplicatedTable(): void
    {
        if (! env('CLICKHOUSE_CLUSTER_AVAILABLE')) {
            $this->markTestSkipped('ReplicatedMergeTree needs ClickHouse Keeper; set CLICKHOUSE_CLUSTER_AVAILABLE=1');
        }

        $this->createChangedTable("ReplicatedMergeTree('/clickhouse/tables/{database}/{table}', 'r1') ORDER BY id", self::REPLICATED);

        $this->changeEveryColumn(self::REPLICATED);

        $this->assertChangedColumns(self::REPLICATED);
    }

    /**
     * Changing a Nullable column that holds NULL into a type without NULL
     * would leave a mutation that makes every query of the table fail, so it
     * throws before the blueprint sends anything.
     */
    public function testChangeRefusesToDropNullWhileTheColumnHoldsNull(): void
    {
        $this->client()->write('CREATE TABLE ' . self::CHANGED . ' (id UInt64, note Nullable(String)) ENGINE = MergeTree ORDER BY id');
        $this->client()->write('INSERT INTO ' . self::CHANGED . " VALUES (1, 'a'), (2, NULL)");
        $change = function (SchemaBlueprint $table) {
            $table->string('added_before');
            $table->string('note')->default('')->change();
        };

        foreach (['pretend' => true, 'run' => false] as $label => $pretend) {
            try {
                $pretend
                    ? DB::connection('clickhouse')->pretend(fn () => Schema::table(self::CHANGED, $change))
                    : Schema::table(self::CHANGED, $change);
                $this->fail("change() should throw ({$label})");
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'The column [note] of the table [' . self::CHANGED . '] holds NULL values, so its type cannot change'
                    . " from Nullable(String) to String: ClickHouse would fail to convert them in a mutation, and every later"
                    . ' query of the table would fail until the mutation is killed. Replace the NULL values first, or keep'
                    . ' the column nullable(). Rows removed by a lightweight DELETE count too until a merge rewrites their'
                    . ' parts; ALTER TABLE `' . self::CHANGED . '` APPLY DELETED MASK removes them.',
                    $exception->getMessage(),
                    $label
                );
            }
        }

        $this->assertSame([['id', 'UInt64'], ['note', 'Nullable(String)']], $this->columns(self::CHANGED, ['name', 'type']));
        $this->assertSame(
            [['id' => '1', 'note' => 'a'], ['id' => '2', 'note' => null]],
            $this->client()->select('SELECT toString(id) AS id, note FROM ' . self::CHANGED . ' ORDER BY id')->rows()
        );
        $this->assertSame(0, $this->mutationCount(self::CHANGED));

        $this->client()->write('ALTER TABLE ' . self::CHANGED . " UPDATE note = '' WHERE note IS NULL SETTINGS mutations_sync = 2");
        Schema::table(self::CHANGED, fn (SchemaBlueprint $table) => $table->string('note')->default('')->change());
        $this->assertSame([['id', 'UInt64'], ['note', 'String']], $this->columns(self::CHANGED, ['name', 'type']));
    }

    /**
     * The NULL elements of an array fail the conversion in the same way, so
     * Array(Nullable(String)) cannot become Array(String) while one is left.
     */
    public function testChangeRefusesToDropNullWhileAnArrayHoldsNull(): void
    {
        $this->client()->write('CREATE TABLE ' . self::CHANGED . ' (id UInt64, tags Array(Nullable(String))) ENGINE = MergeTree ORDER BY id');
        $this->client()->write('INSERT INTO ' . self::CHANGED . " VALUES (1, ['a']), (2, ['b', NULL])");

        try {
            Schema::table(self::CHANGED, fn (SchemaBlueprint $table) => $table->array('tags', 'String')->change());
            $this->fail('change() should throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringStartsWith(
                'The column [tags] of the table [' . self::CHANGED . '] holds NULL values, so its type cannot change'
                . ' from Array(Nullable(String)) to Array(String)',
                $exception->getMessage()
            );
        }
        $this->assertSame([['id', 'UInt64'], ['tags', 'Array(Nullable(String))']], $this->columns(self::CHANGED, ['name', 'type']));
        $this->assertSame(0, $this->mutationCount(self::CHANGED));

        $this->client()->write('ALTER TABLE ' . self::CHANGED . ' UPDATE tags = arrayFilter(x -> x IS NOT NULL, tags) WHERE 1 SETTINGS mutations_sync = 2');
        Schema::table(self::CHANGED, fn (SchemaBlueprint $table) => $table->array('tags', 'String')->change());
        $this->assertSame([['id', 'UInt64'], ['tags', 'Array(String)']], $this->columns(self::CHANGED, ['name', 'type']));
        $this->assertSame(
            [['id' => '1', 'tags' => ['a']], ['id' => '2', 'tags' => ['b']]],
            $this->client()->select('SELECT toString(id) AS id, tags FROM ' . self::CHANGED . ' ORDER BY id')->rows()
        );
    }

    /**
     * A lightweight DELETE only hides its rows until a merge rewrites their
     * parts, and the mutation of the MODIFY would still fail on their NULL
     * values, so they count too. Once APPLY DELETED MASK has rewritten the
     * parts, the change runs.
     */
    public function testChangeCountsTheNullValuesOfRowsThatALightweightDeleteRemoved(): void
    {
        $this->client()->write('CREATE TABLE ' . self::CHANGED . ' (id UInt64, note Nullable(String)) ENGINE = MergeTree ORDER BY id');
        $this->client()->write('INSERT INTO ' . self::CHANGED . " VALUES (1, 'a'), (2, NULL), (3, 'c')");
        $this->client()->write('DELETE FROM ' . self::CHANGED . ' WHERE note IS NULL');
        $this->assertSame(
            [['id' => '1', 'note' => 'a'], ['id' => '3', 'note' => 'c']],
            $this->client()->select('SELECT toString(id) AS id, note FROM ' . self::CHANGED . ' ORDER BY id')->rows()
        );

        try {
            Schema::table(self::CHANGED, fn (SchemaBlueprint $table) => $table->string('note')->change());
            $this->fail('change() should throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringStartsWith(
                'The column [note] of the table [' . self::CHANGED . '] holds NULL values, so its type cannot change'
                . ' from Nullable(String) to String',
                $exception->getMessage()
            );
        }
        $this->assertSame([['id', 'UInt64'], ['note', 'Nullable(String)']], $this->columns(self::CHANGED, ['name', 'type']));
        $this->assertSame([], $this->client()->select(
            "SELECT command FROM system.mutations WHERE database = currentDatabase() AND table = '" . self::CHANGED . "' AND is_done = 0"
        )->rows());

        $this->client()->write('ALTER TABLE ' . self::CHANGED . ' APPLY DELETED MASK SETTINGS mutations_sync = 2');
        Schema::table(self::CHANGED, fn (SchemaBlueprint $table) => $table->string('note')->default('')->change());
        $this->assertSame([['id', 'UInt64'], ['note', 'String']], $this->columns(self::CHANGED, ['name', 'type']));
        $this->assertSame(
            [['id' => '1', 'note' => 'a'], ['id' => '3', 'note' => 'c']],
            $this->client()->select('SELECT toString(id) AS id, note FROM ' . self::CHANGED . ' ORDER BY id')->rows()
        );
    }

    /**
     * Typed indexes are added, a raw expression with algorithm(), a tuple of
     * columns, and materialize() builds the index for the rows the table
     * holds; an index without a type sends nothing. dropIndex() drops by name
     * or by columns, also an index that index() never created.
     */
    public function testDataSkippingIndexes(): void
    {
        $this->createAlteredTable();

        Schema::table(self::ALTERED, function (SchemaBlueprint $table) {
            $table->index('payload', null, 'tokenbf_v1(512, 3, 0)')->granularity(2)->materialize();
            $table->rawIndex('lower(referrer)', 'altered_lower_referrer')->algorithm('bloom_filter');
            $table->index(['id', 'visited_at'], null, 'minmax');
            $table->index('traffic_source');
        });

        $this->assertSame([
            ['altered_lower_referrer', 'bloom_filter', 'lower(referrer)', '1'],
            ['schema_blueprint_altered_id_visited_at_index', 'minmax', 'id, visited_at', '1'],
            ['schema_blueprint_altered_payload_index', 'tokenbf_v1(512, 3, 0)', 'payload', '2'],
        ], $this->indexes(self::ALTERED));
        $this->assertSame(
            ['primary', 'schema_blueprint_altered_payload_index', 'altered_lower_referrer', 'schema_blueprint_altered_id_visited_at_index'],
            Schema::getIndexListing(self::ALTERED)
        );
        $this->assertSame(
            ['name' => 'schema_blueprint_altered_id_visited_at_index', 'columns' => ['id', 'visited_at'], 'type' => 'minmax', 'unique' => false, 'primary' => false],
            Schema::getIndexes(self::ALTERED)[3]
        );
        $this->assertSame(
            ['MATERIALIZE INDEX schema_blueprint_altered_payload_index'],
            array_map(
                fn (string $command): string => preg_replace('/\A\((.*)\)\z/s', '$1', $command),
                array_column($this->client()->select(
                    "SELECT command FROM system.mutations WHERE database = currentDatabase() AND table = '" . self::ALTERED . "'"
                )->rows(), 'command')
            )
        );

        Schema::table(self::ALTERED, function (SchemaBlueprint $table) {
            $table->dropIndex('altered_lower_referrer');
            $table->dropIndex(['id', 'visited_at']);
            $table->dropIndex(['traffic_source']);
        });

        $this->assertSame(
            [['schema_blueprint_altered_payload_index', 'tokenbf_v1(512, 3, 0)', 'payload', '2']],
            $this->indexes(self::ALTERED)
        );
    }

    /**
     * With default_index_type, an index without a type, such as the one of
     * morphs(), gets that type in CREATE and in ALTER.
     */
    public function testTheDefaultIndexTypeOption(): void
    {
        Schema::connection(self::DEFAULT_INDEX_TYPE)->create(self::INDEXED, function (SchemaBlueprint $table) {
            $table->id();
            $table->morphs('owner');
        });
        Schema::connection(self::DEFAULT_INDEX_TYPE)->table(self::INDEXED, function (SchemaBlueprint $table) {
            $table->string('url')->index();
        });

        $this->assertSame([
            ['schema_blueprint_indexed_owner_type_owner_id_index', 'minmax', 'owner_type, owner_id', '1'],
            ['schema_blueprint_indexed_url_index', 'minmax', 'url', '1'],
        ], $this->indexes(self::INDEXED));

        Schema::connection(self::DEFAULT_INDEX_TYPE)->table(self::INDEXED, fn (SchemaBlueprint $table) => $table->dropMorphs('owner'));
        $this->assertSame(['id', 'url'], array_column($this->columns(self::INDEXED, ['name']), 0));
        $this->assertSame([['schema_blueprint_indexed_url_index', 'minmax', 'url', '1']], $this->indexes(self::INDEXED));
    }

    /**
     * Only tables of the MergeTree family have data-skipping indexes, and the
     * others refuse DROP INDEX even with IF EXISTS. So the stock down() of a
     * migration with morphs() drops the columns of a Memory table, and
     * default_index_type does not apply to it, in CREATE or in ALTER.
     */
    public function testATableWithoutDataSkippingIndexes(): void
    {
        foreach (['clickhouse', self::DEFAULT_INDEX_TYPE] as $connection) {
            Schema::connection($connection)->create(self::MEMORY, function (SchemaBlueprint $table) {
                $table->id();
                $table->morphs('owner');
                $table->string('url')->index();
                $table->engine('Memory');
            });
            Schema::connection($connection)->table(self::MEMORY, function (SchemaBlueprint $table) {
                $table->string('title')->index();
                $table->rawIndex('lower(url)', 'memory_lower_url');
            });
            $this->assertSame(['id', 'owner_type', 'owner_id', 'url', 'title'], array_column($this->columns(self::MEMORY, ['name']), 0), $connection);

            $log = DB::connection($connection)->pretend(fn () => Schema::connection($connection)->table(self::MEMORY, function (SchemaBlueprint $table) {
                $table->dropMorphs('owner');
                $table->dropIndex(['url']);
                $table->dropIndex('memory_lower_url');
            }));
            $this->assertSame(
                ['ALTER TABLE `' . self::MEMORY . '` DROP COLUMN `owner_type`, DROP COLUMN `owner_id`'],
                array_column($log, 'query'),
                $connection
            );

            Schema::connection($connection)->table(self::MEMORY, function (SchemaBlueprint $table) {
                $table->dropMorphs('owner');
                $table->dropIndex(['url']);
            });
            $this->assertSame(['id', 'url', 'title'], array_column($this->columns(self::MEMORY, ['name']), 0), $connection);

            Schema::connection($connection)->drop(self::MEMORY);
        }
    }

    /**
     * foreign(), unique() and spatialIndex() send nothing: ClickHouse has no
     * such constraint or index. A migration with foreignId()->constrained()
     * creates only the column.
     */
    public function testConstraintsThatClickHouseDoesNotHaveSendNothing(): void
    {
        Schema::create(self::CONSTRAINED, function (SchemaBlueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email')->unique();
        });
        $this->assertSame([['id', 'Int64'], ['user_id', 'Int64'], ['email', 'String']], $this->columns(self::CONSTRAINED, ['name', 'type']));

        Schema::table(self::CONSTRAINED, function (SchemaBlueprint $table) {
            $table->dropUnique(['email']);
            $table->dropConstrainedForeignId('user_id');
        });
        $this->assertSame([['id'], ['email']], $this->columns(self::CONSTRAINED, ['name']));
    }

    /**
     * A command that ClickHouse cannot run throws while the blueprint is
     * compiled, so nothing of the blueprint is sent.
     */
    public function testACommandThatClickHouseCannotRunStopsTheWholeBlueprint(): void
    {
        $this->createAlteredTable();

        foreach ([
            fn (SchemaBlueprint $table) => $table->renameIndex('a', 'b'),
            fn (SchemaBlueprint $table) => $table->primary('id'),
            fn (SchemaBlueprint $table) => $table->dropPrimary(),
            fn (SchemaBlueprint $table) => $table->fullText('payload'),
            fn (SchemaBlueprint $table) => $table->time('at'),
        ] as $command) {
            try {
                Schema::table(self::ALTERED, function (SchemaBlueprint $table) use ($command) {
                    $table->string('never_added');
                    $command($table);
                });
                $this->fail('Schema::table() should throw');
            } catch (\RuntimeException $exception) {
                $this->assertStringStartsWith('ClickHouse ', $exception->getMessage());
            }
        }

        $this->assertNotContains('never_added', array_column($this->columns(self::ALTERED, ['name']), 0));
    }

    /**
     * dropSync() and drop()->sync() wait until ClickHouse has removed the
     * data, so a replicated table with a fixed replica path can be created
     * again at once. Needs the test cluster's Keeper for that part.
     */
    public function testDropSync(): void
    {
        Schema::create(self::ALTERED, fn (SchemaBlueprint $table) => $table->id());
        Schema::dropSync(self::ALTERED);
        $this->assertFalse(Schema::hasTable(self::ALTERED));
        Schema::dropIfExistsSync(self::ALTERED);

        Schema::create(self::ALTERED, fn (SchemaBlueprint $table) => $table->id());
        Schema::table(self::ALTERED, fn (SchemaBlueprint $table) => $table->dropIfExists()->sync());
        $this->assertFalse(Schema::hasTable(self::ALTERED));

        if (! env('CLICKHOUSE_CLUSTER_AVAILABLE')) {
            return;
        }

        foreach ([1, 2] as $run) {
            Schema::create(self::REPLICATED, function (SchemaBlueprint $table) {
                $table->id();
                $table->engine("ReplicatedMergeTree('/clickhouse/tables/{database}/" . self::REPLICATED . "_fixed', 'r1')");
            });
            $this->assertTrue(Schema::hasTable(self::REPLICATED), "created {$run}");
            Schema::table(self::REPLICATED, fn (SchemaBlueprint $table) => $table->drop()->sync());
        }
    }

    /**
     * While the connection pretends, every statement of Schema::table() is
     * logged and none is sent. change() still reads the column's state, so
     * the log shows the REMOVE statements that would run.
     */
    public function testPretendLogsEveryStatementOfSchemaTableAndChangesNothing(): void
    {
        $this->createAlteredTable();
        $this->client()->write('ALTER TABLE ' . self::ALTERED . " MODIFY COLUMN referrer String DEFAULT 'none' COMMENT 'from'");
        $columnsBefore = $this->columns(self::ALTERED, ['name', 'type', 'default_kind', 'comment']);
        $tableBefore = $this->table(self::ALTERED, ['engine_full', 'comment']);

        $log = DB::connection('clickhouse')->pretend(fn () => Schema::table(self::ALTERED, function (SchemaBlueprint $table) {
            $table->string('source')->first();
            $table->orderBy('id', DB::raw('intHash32(id)'), 'source');
            $table->string('referrer')->nullable()->change();
            $table->dropColumn('traffic_source');
            $table->renameColumn('payload', 'body');
            $table->index('body', null, 'bloom_filter')->materialize();
            $table->dropIndex(['body']);
            $table->comment('Visits');
            $table->sampleBy('intHash32(id)');
            $table->ttl('visited_at + INTERVAL 1 YEAR');
            $table->settings(['merge_with_ttl_timeout' => 3600]);
        }));

        $table = '`' . self::ALTERED . '`';
        $this->assertSame([
            "ALTER TABLE {$table} ADD COLUMN `source` String FIRST, MODIFY ORDER BY (`id`, intHash32(id), `source`)",
            "ALTER TABLE {$table} MODIFY COLUMN `referrer` REMOVE DEFAULT",
            "ALTER TABLE {$table} MODIFY COLUMN `referrer` REMOVE COMMENT",
            "ALTER TABLE {$table} MODIFY COLUMN `referrer` Nullable(String)",
            "ALTER TABLE {$table} DROP COLUMN `traffic_source`",
            "ALTER TABLE {$table} RENAME COLUMN `payload` TO `body`",
            "ALTER TABLE {$table} ADD INDEX `schema_blueprint_altered_body_index` `body` TYPE bloom_filter",
            "ALTER TABLE {$table} MATERIALIZE INDEX `schema_blueprint_altered_body_index`",
            "ALTER TABLE {$table} DROP INDEX IF EXISTS `schema_blueprint_altered_body_index`",
            "ALTER TABLE {$table} MODIFY COMMENT 'Visits'",
            "ALTER TABLE {$table} MODIFY SAMPLE BY intHash32(id)",
            "ALTER TABLE {$table} MODIFY TTL visited_at + INTERVAL 1 YEAR",
            "ALTER TABLE {$table} MODIFY SETTING merge_with_ttl_timeout=3600",
        ], array_column($log, 'query'));
        $this->assertSame($columnsBefore, $this->columns(self::ALTERED, ['name', 'type', 'default_kind', 'comment']));
        $this->assertSame($tableBefore, $this->table(self::ALTERED, ['engine_full', 'comment']));
        $this->assertSame([], $this->indexes(self::ALTERED));
        $this->assertSame(0, $this->mutationCount(self::ALTERED));

        $log = DB::connection('clickhouse')->pretend(function () {
            Schema::rename(self::ALTERED, self::ALTERED_RENAMED);
            Schema::dropSync(self::ALTERED);
        });
        $this->assertSame(
            ["RENAME TABLE {$table} TO `" . self::ALTERED_RENAMED . '`', "DROP TABLE {$table} SYNC"],
            array_column($log, 'query')
        );
        $this->assertTrue(Schema::hasTable(self::ALTERED));
    }

    /**
     * createDatabase() and dropDatabaseIfExists() send their statements to
     * every node through Connection::statementOnEveryNode(), so they are
     * logged and not sent while pretending.
     */
    public function testPretendedDatabaseStatementsAreLoggedAndNotSent(): void
    {
        $created = 'pretend_database_' . bin2hex(random_bytes(4));
        $kept = 'pretend_database_' . bin2hex(random_bytes(4));
        $this->client()->write("CREATE DATABASE `{$kept}`");

        try {
            $log = DB::connection('clickhouse')->pretend(function () use ($created, $kept): void {
                $this->assertTrue(Schema::connection('clickhouse')->createDatabase($created));
                $this->assertTrue(Schema::connection('clickhouse')->dropDatabaseIfExists($kept));
            });

            $this->assertSame(
                ["CREATE DATABASE IF NOT EXISTS `{$created}`", "DROP DATABASE IF EXISTS `{$kept}` SYNC"],
                array_column($log, 'query')
            );
            $this->assertSame(
                [['name' => $kept]],
                $this->client()->select("SELECT name FROM system.databases WHERE name IN ('{$created}', '{$kept}')")->rows()
            );
        } finally {
            $this->client()->write("DROP DATABASE IF EXISTS `{$created}` SYNC");
            $this->client()->write("DROP DATABASE IF EXISTS `{$kept}` SYNC");
        }
    }

    /**
     * Create ALTERED with one row: id 1, referrer 'r', payload 'p'.
     */
    private function createAlteredTable(): void
    {
        Schema::create(self::ALTERED, function (SchemaBlueprint $table) {
            $table->unsignedBigInteger('id');
            $table->dateTime('visited_at');
            $table->string('referrer');
            $table->string('traffic_source');
            $table->string('payload');
            $table->orderBy('id', DB::raw('intHash32(id)'));
        });
        $this->client()->write(
            'INSERT INTO ' . self::ALTERED . " (id, visited_at, referrer, payload) VALUES (1, '2024-01-02 03:04:05', 'r', 'p')"
        );
    }

    /**
     * Create a table for change() with a column of each kind of default
     * expression, a comment, a CODEC and a TTL, and two rows.
     */
    private function createChangedTable(string $engine, string $table = self::CHANGED): void
    {
        $this->client()->write("CREATE TABLE {$table} ("
            . " id UInt64, at DateTime, source String DEFAULT 'web' COMMENT 'src', doubled UInt64 MATERIALIZED id * 2,"
            . " upper_source String ALIAS upper(source), packed String DEFAULT 'p' COMMENT 'kept' CODEC(ZSTD(3)) TTL at + INTERVAL 1 DAY,"
            . " eph String EPHEMERAL 'e', nullable_clean Nullable(String), kind String DEFAULT 'a'"
            . ") ENGINE = {$engine}");
        $this->client()->write("INSERT INTO {$table} (id, at, source, packed, nullable_clean)"
            . " VALUES (1, '2030-01-02 03:04:05', 'x', 'pp', 'c'), (2, '2030-01-02 03:04:05', 'y', 'qq', 'd')");
    }

    /**
     * Change every column of a table that createChangedTable() created.
     */
    private function changeEveryColumn(string $table): void
    {
        Schema::table($table, function (SchemaBlueprint $table) {
            $table->string('source')->nullable()->after('id')->renameTo('traffic_source')->change();
            $table->unsignedBigInteger('doubled')->change();
            $table->string('upper_source')->change();
            $table->string('packed')->change();
            $table->string('eph')->change();
            $table->string('kind')->storedAs("concat('k', toString(id))")->change();
            $table->string('nullable_clean')->default('')->comment('no NULL left')->change();
        });
    }

    /**
     * Check the columns of a table after changeEveryColumn().
     */
    private function assertChangedColumns(string $table): void
    {
        $this->assertSame([
            ['id', 'UInt64', '', '', '', ''],
            ['traffic_source', 'Nullable(String)', '', '', '', ''],
            ['at', 'DateTime', '', '', '', ''],
            ['doubled', 'Int64', '', '', '', ''],
            ['upper_source', 'String', '', '', '', ''],
            ['packed', 'String', '', '', '', 'CODEC(ZSTD(3))'],
            ['eph', 'String', 'EPHEMERAL', "'e'", '', ''],
            ['nullable_clean', 'String', 'DEFAULT', "''", 'no NULL left', ''],
            ['kind', 'String', 'MATERIALIZED', "concat('k', toString(id))", '', ''],
        ], $this->columns($table, ['name', 'type', 'default_kind', 'default_expression', 'comment', 'compression_codec']));
        $this->assertStringContainsString(
            '`packed` String CODEC(ZSTD(3)) TTL at + toIntervalDay(1)',
            $this->table($table, ['create_table_query'])['create_table_query']
        );
        $this->assertSame(
            [
                ['id' => '1', 'traffic_source' => 'x', 'doubled' => '2', 'packed' => 'pp', 'nullable_clean' => 'c'],
                ['id' => '2', 'traffic_source' => 'y', 'doubled' => '4', 'packed' => 'qq', 'nullable_clean' => 'd'],
            ],
            $this->client()->select(
                "SELECT toString(id) AS id, traffic_source, toString(doubled) AS doubled, packed, nullable_clean FROM {$table} ORDER BY id"
            )->rows()
        );
        $this->assertSame(
            0,
            (int) $this->client()->select(
                "SELECT count() AS n FROM system.mutations WHERE database = currentDatabase() AND table = '{$table}' AND is_done = 0"
            )->rows()[0]['n']
        );
    }

    /**
     * Get the name, type, expression and granularity of each data-skipping index of a table, by name.
     *
     * @return list<list<string>>
     */
    private function indexes(string $table): array
    {
        return array_map(fn (array $row): array => array_map('strval', array_values($row)), $this->client()->select(
            'SELECT name, type_full, expr, granularity FROM system.data_skipping_indices'
            . ' WHERE database = currentDatabase() AND table = :table ORDER BY name',
            ['table' => $table]
        )->rows());
    }

    /**
     * Count the mutations of a table.
     */
    private function mutationCount(string $table): int
    {
        return (int) $this->client()->select(
            'SELECT count() AS n FROM system.mutations WHERE database = currentDatabase() AND table = :table',
            ['table' => $table]
        )->rows()[0]['n'];
    }

    /**
     * Create TYPES on the given connection with every Laravel column type that ClickHouse has.
     */
    private function createTypesTable(string $connection): void
    {
        Schema::connection($connection)->create(self::TYPES, function (SchemaBlueprint $table) {
            $table->char('c_char', 2);
            $table->string('c_string');
            $table->text('c_text');
            $table->tinyInteger('c_tiny');
            $table->unsignedTinyInteger('c_utiny');
            $table->smallInteger('c_small');
            $table->unsignedSmallInteger('c_usmall');
            $table->mediumInteger('c_medium');
            $table->unsignedMediumInteger('c_umedium');
            $table->integer('c_int');
            $table->unsignedInteger('c_uint');
            $table->bigInteger('c_big');
            $table->unsignedBigInteger('c_ubig');
            $table->float('c_float');
            $table->float('c_float24', 24);
            $table->double('c_double');
            $table->decimal('c_decimal', 10, 2);
            $table->boolean('c_bool');
            $table->enum('c_enum', ['a', "it's"]);
            $table->enum('c_enum_numbered', ['low' => 1, 'high' => 10]);
            $table->enum('c_enum_numeric_names', ['active' => 1, '200' => 2]);
            $table->json('c_json');
            $table->date('c_date');
            $table->date32('c_date32');
            $table->dateTime('c_datetime');
            $table->timestamp('c_datetime3', 3)->timezone('UTC');
            $table->year('c_year');
            $table->binary('c_binary');
            $table->binary('c_fixed_binary', 4, true);
            $table->uuid('c_uuid');
            $table->ulid('c_ulid');
            $table->ipAddress('c_ip');
            $table->macAddress('c_mac');
            $table->ipv4('c_ipv4');
            $table->ipv6('c_ipv6');
            $table->geometry('c_point', 'point');
            $table->geography('c_polygon', 'polygon');
            $table->vector('c_vector', 3);
            $table->array('c_array', 'String');
            $table->map('c_map', 'String', 'UInt64');
            $table->rawColumn('c_raw', 'Decimal256(4)');
            $table->softDeletes();
            $table->orderBy('c_int');
        });
    }

    /**
     * Insert a row into TYPES through a model's insertAssoc() and read it back.
     * The values fit both integer mappings. The UInt64 column c_ubig is
     * compared as a string, which ClickHouse 24.8 returns and 25.8 and later
     * do not.
     */
    private function insertAndReadBackTypes(): void
    {
        $model = new class extends BaseModel {
            protected $table = 'schema_blueprint_types';
        };
        $row = [
            'c_char' => 'ab',
            'c_string' => "it's",
            'c_tiny' => -5,
            'c_utiny' => 200,
            'c_usmall' => 60000,
            'c_umedium' => 16000000,
            'c_int' => -2000000000,
            'c_uint' => 2000000000,
            'c_ubig' => 9000000000000000000,
            'c_float24' => 0.5,
            'c_decimal' => '12.34',
            'c_enum' => "it's",
            'c_enum_numbered' => 'high',
            'c_enum_numeric_names' => '200',
            'c_json' => '{"a":1}',
            'c_date' => '2024-01-02',
            'c_date32' => '1950-06-07',
            'c_datetime3' => '2024-01-02 03:04:05.678',
            'c_year' => 2024,
            'c_uuid' => '6f1c2a4e-9b7d-4c3a-8e21-5d4f3b2a1c0e',
            'c_ipv4' => '127.0.0.1',
            'c_ipv6' => '127.0.0.1',
            'c_array' => ['a', "b'c"],
        ];

        $model::insertAssoc([$row]);

        $read = $this->client()->select(
            'SELECT ' . implode(', ', array_keys($row)) . ', deleted_at FROM ' . self::TYPES
        )->rows()[0];
        $read['c_ubig'] = (string) $read['c_ubig'];
        $this->assertSame([
            'c_char' => 'ab',
            'c_string' => "it's",
            'c_tiny' => -5,
            'c_utiny' => 200,
            'c_usmall' => 60000,
            'c_umedium' => 16000000,
            'c_int' => -2000000000,
            'c_uint' => 2000000000,
            'c_ubig' => '9000000000000000000',
            'c_float24' => 0.5,
            'c_decimal' => 12.34,
            'c_enum' => "it's",
            'c_enum_numbered' => 'high',
            'c_enum_numeric_names' => '200',
            'c_json' => '{"a":1}',
            'c_date' => '2024-01-02',
            'c_date32' => '1950-06-07',
            'c_datetime3' => '2024-01-02 03:04:05.678',
            'c_year' => 2024,
            'c_uuid' => '6f1c2a4e-9b7d-4c3a-8e21-5d4f3b2a1c0e',
            'c_ipv4' => '127.0.0.1',
            'c_ipv6' => '::ffff:127.0.0.1',
            'c_array' => ['a', "b'c"],
            'deleted_at' => null,
        ], $read);
    }

    /**
     * Get the given system.tables fields of a table in the connection's database.
     *
     * A sorting_key or primary_key of one column in parentheses, such as (id), is read without them: ClickHouse 26.8
     * keeps the parentheses of ORDER BY (id), which the schema builder writes, where 24.8 and 26.3 print id.
     *
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function table(string $table, array $fields): array
    {
        $row = $this->client()->select(
            'SELECT ' . implode(', ', $fields) . ' FROM system.tables WHERE database = currentDatabase() AND name = :table',
            ['table' => $table]
        )->rows()[0];

        foreach (array_intersect(['sorting_key', 'primary_key'], array_keys($row)) as $key) {
            $row[$key] = preg_replace('/\A\(([^(),]+)\)\z/', '$1', $row[$key]);
        }

        return $row;
    }

    /**
     * Get the given system.columns fields of each column of a table, in table order.
     *
     * @param list<string> $fields
     * @return list<list<mixed>>
     */
    private function columns(string $table, array $fields): array
    {
        return array_map('array_values', $this->client()->select(
            'SELECT ' . implode(', ', $fields) . ' FROM system.columns'
            . ' WHERE database = currentDatabase() AND table = :table ORDER BY position',
            ['table' => $table]
        )->rows());
    }

    private function client(): Client
    {
        return DB::connection('clickhouse')->getClient();
    }

    private function dropTables(): void
    {
        foreach ([
            self::VISITS, self::EVENTS, self::TYPES, self::KEYS, self::MEMORY, self::QUOTED, self::MERGE_TREE,
            self::DECIMALS, self::KEY_VALUE, self::MERGE_TREE_VALUES, self::QUALIFIED, self::HAND_QUOTED,
            self::ENUMS, self::MERGE_TREE_ENUMS, self::ALTERED, self::ALTERED_RENAMED, self::CHANGED, self::INDEXED,
            self::CONSTRAINED, self::REPLICATED,
        ] as $table) {
            $this->client()->write('DROP TABLE IF EXISTS `' . str_replace(['\\', '`'], ['\\\\', '\\`'], $table) . '` SYNC');
        }
    }
}
