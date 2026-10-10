<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Statement;
use ClickHouseDB\Transport\CurlerRequest;
use Closure;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Builder;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\SchemaBlueprint;
use Oralunal\LaravelClickHouse\SchemaBuilder;
use Oralunal\LaravelClickHouse\SchemaGrammar;
use PHPUnit\Framework\TestCase;

/**
 * The CREATE TABLE statements that Schema::create() compiles from a
 * SchemaBlueprint, and the ALTER, RENAME and DROP statements of
 * Schema::table(), drop() and rename(). tests/SchemaBlueprintTest.php runs
 * them against ClickHouse; tests/Unit/SchemaChangeColumnTest.php covers change().
 */
class SchemaBlueprintSqlTest extends TestCase
{
    /**
     * The statements that the mocked connection received through statement().
     *
     * @var list<string>
     */
    private array $statements = [];

    /**
     * The SQL of each read that the grammar sent to the active node's client.
     *
     * @var list<string>
     */
    private array $selects = [];

    protected function tearDown(): void
    {
        Builder::defaultTimePrecision(0);

        parent::tearDown();
    }

    public function test_laravel_columns_with_their_modifiers(): void
    {
        $this->assertSame(
            ['CREATE TABLE `events` (`id` Int64, `name` Nullable(String) DEFAULT \'x\' COMMENT \'it\\\'s\','
                . ' `created_at` Nullable(DateTime), `updated_at` Nullable(DateTime)) ENGINE = MergeTree() ORDER BY (`id`)'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->id();
                $table->string('name')->nullable()->default('x')->comment("it's");
                $table->timestamps();
            })
        );
    }

    /**
     * Laravel's migration repository creates its table this way. Without
     * exact_integer_types it gets the columns of 3.0.0.
     */
    public function test_the_migrations_table(): void
    {
        $migrations = function (SchemaBlueprint $table) {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        };

        $this->assertSame(
            ['CREATE TABLE `migrations` (`id` Int32, `migration` String, `batch` Int32) ENGINE = MergeTree() ORDER BY (`id`)'],
            $this->compileCreate($migrations, table: 'migrations')
        );
        $this->assertSame(
            ['CREATE TABLE `migrations` (`id` UInt32, `migration` String, `batch` Int32) ENGINE = MergeTree() ORDER BY (`id`)'],
            $this->compileCreate($migrations, ['exact_integer_types' => true], 'migrations')
        );
    }

    public function test_order_by_sets_the_sorting_key(): void
    {
        $this->assertSame(
            ['CREATE TABLE `events` (`name` String, `id` Int64) ENGINE = MergeTree() ORDER BY (`id`, intHash32(id))'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->string('name');
                $table->bigInteger('id');
                $table->orderBy('id', new Expression('intHash32(id)'));
            })
        );
    }

    public function test_order_by_takes_an_array_and_the_last_call_wins(): void
    {
        $this->assertStringEndsWith(
            ' ENGINE = MergeTree() ORDER BY (`id`, `name`)',
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->bigInteger('id');
                $table->string('name');
                $table->orderBy('name');
                $table->orderBy(['id', 'name']);
            })[0]
        );
    }

    public function test_order_by_without_columns_gives_no_sorting_key(): void
    {
        $this->assertStringEndsWith(
            ' ENGINE = MergeTree() ORDER BY tuple()',
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->bigInteger('id');
                $table->orderBy();
            })[0]
        );
    }

    public function test_primary_alone_is_the_sorting_key(): void
    {
        $this->assertSame(
            ['CREATE TABLE `events` (`name` String, `id` Int64) ENGINE = MergeTree() ORDER BY (`id`)'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->string('name');
                $table->bigInteger('id')->primary();
            })
        );
        $this->assertSame(
            ['CREATE TABLE `events` (`name` String, `id` Int64, `at` DateTime) ENGINE = MergeTree() ORDER BY (`id`, `at`)'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->string('name');
                $table->bigInteger('id');
                $table->dateTime('at');
                $table->primary(['id', 'at']);
            })
        );
    }

    public function test_primary_with_order_by_is_the_primary_key(): void
    {
        $this->assertStringEndsWith(
            ' ENGINE = MergeTree() PRIMARY KEY (`id`) ORDER BY (`id`, `at`)',
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->bigInteger('id');
                $table->dateTime('at');
                $table->primary('id');
                $table->orderBy('id', 'at');
            })[0]
        );
    }

    /**
     * @return array<string, array{Closure(SchemaBlueprint): void}>
     */
    public static function firstColumnsThatCannotBeASortingKey(): array
    {
        return [
            'nullable' => [fn (SchemaBlueprint $table) => $table->string('name')->nullable()],
            'low cardinality and nullable' => [fn (SchemaBlueprint $table) => $table->string('name')->nullable()->lowCardinality()],
            'a raw type that accepts NULL' => [fn (SchemaBlueprint $table) => $table->rawColumn('name', 'Nullable(String)')],
            'alias' => [fn (SchemaBlueprint $table) => $table->string('name')->virtualAs('upper(id)')],
            'ephemeral' => [fn (SchemaBlueprint $table) => $table->string('name')->ephemeral()],
            'ephemeral with a default' => [fn (SchemaBlueprint $table) => $table->string('name')->ephemeral('')],
            'an array of a nullable type' => [fn (SchemaBlueprint $table) => $table->array('name', 'Nullable(String)')],
            'an array of a low cardinality nullable type' => [fn (SchemaBlueprint $table) => $table->array('name', 'LowCardinality(Nullable(String))')],
            'a tuple with a nullable element' => [fn (SchemaBlueprint $table) => $table->rawColumn('name', 'Tuple(Nullable(String), UInt8)')],
            'a map with nullable values' => [fn (SchemaBlueprint $table) => $table->map('name', 'String', 'Nullable(String)')],
            'a variant' => [fn (SchemaBlueprint $table) => $table->rawColumn('name', 'Variant(String, UInt64)')],
            'an aggregate function state' => [fn (SchemaBlueprint $table) => $table->rawColumn('name', 'AggregateFunction(sum, UInt64)')],
            'a simple aggregate function' => [fn (SchemaBlueprint $table) => $table->rawColumn('name', 'SimpleAggregateFunction(sum, UInt64)')],
            'a Nested column' => [fn (SchemaBlueprint $table) => $table->rawColumn('name', 'Nested(a UInt8, b String)')],
        ];
    }

    /**
     * @param Closure(SchemaBlueprint): void $firstColumn
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('firstColumnsThatCannotBeASortingKey')]
    public function test_a_first_column_that_cannot_be_a_sorting_key_gives_no_sorting_key(Closure $firstColumn): void
    {
        $this->assertStringEndsWith(
            ' ENGINE = MergeTree() ORDER BY tuple()',
            $this->compileCreate(function (SchemaBlueprint $table) use ($firstColumn) {
                $firstColumn($table);
                $table->bigInteger('id');
            })[0]
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function enginesWithoutRowMerging(): array
    {
        return [
            'MergeTree' => ['MergeTree'],
            'ReplicatedMergeTree' => ["ReplicatedMergeTree('/clickhouse/tables/{uuid}', '{replica}')"],
            'ReplicatedMergeTree without parameters' => ['ReplicatedMergeTree()'],
            'SharedMergeTree' => ['SharedMergeTree()'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('enginesWithoutRowMerging')]
    public function test_an_engine_that_merges_no_rows_takes_no_sorting_key(string $engine): void
    {
        $this->assertSame(
            ["CREATE TABLE `events` (`name` Nullable(String), `id` Int64) ENGINE = {$engine} ORDER BY tuple()"],
            $this->compileCreate(function (SchemaBlueprint $table) use ($engine) {
                $table->string('name')->nullable();
                $table->bigInteger('id');
                $table->engine($engine);
            })
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function enginesThatMergeRows(): array
    {
        return [
            'ReplacingMergeTree' => ['ReplacingMergeTree'],
            'ReplacingMergeTree with a version' => ['ReplacingMergeTree(version)'],
            'SummingMergeTree' => ['SummingMergeTree'],
            'AggregatingMergeTree' => ['AggregatingMergeTree()'],
            'CollapsingMergeTree' => ['CollapsingMergeTree(sign)'],
            'VersionedCollapsingMergeTree' => ['VersionedCollapsingMergeTree(sign, version)'],
            'GraphiteMergeTree' => ["GraphiteMergeTree('graphite_rollup')"],
            'ReplicatedReplacingMergeTree' => ["ReplicatedReplacingMergeTree('/clickhouse/tables/{uuid}', '{replica}')"],
        ];
    }

    /**
     * Without a sorting key, every row has the same key, so these engines
     * would merge the rows of each partition into one.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('enginesThatMergeRows')]
    public function test_an_engine_that_merges_rows_needs_a_sorting_key(string $engine): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            "The first column [name] of the table [events] cannot be its sorting key, and without a sorting key the {$engine}"
            . ' engine would merge the rows of each partition into one. Call orderBy() or primary() with the columns that identify a row.'
        );

        $this->compileCreate(function (SchemaBlueprint $table) use ($engine) {
            $table->string('name')->nullable();
            $table->bigInteger('id');
            $table->engine($engine);
        });
    }

    public function test_the_engine_option_that_merges_rows_needs_a_sorting_key_too(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the ReplacingMergeTree engine would merge the rows of each partition into one');

        $this->compileCreate(fn (SchemaBlueprint $table) => $table->array('tags', 'Nullable(String)'), ['engine' => 'ReplacingMergeTree']);
    }

    public function test_an_engine_that_merges_rows_takes_the_sorting_key_it_is_given(): void
    {
        $nullableFirst = fn (SchemaBlueprint $table) => [$table->string('name')->nullable(), $table->bigInteger('id'), $table->engine('ReplacingMergeTree')];

        $this->assertStringEndsWith(' ENGINE = ReplacingMergeTree ORDER BY (`id`)', $this->compileCreate(function (SchemaBlueprint $table) use ($nullableFirst) {
            $nullableFirst($table);
            $table->orderBy('id');
        })[0]);
        $this->assertStringEndsWith(' ENGINE = ReplacingMergeTree ORDER BY (`id`)', $this->compileCreate(function (SchemaBlueprint $table) use ($nullableFirst) {
            $nullableFirst($table);
            $table->primary('id');
        })[0]);
        $this->assertStringEndsWith(
            ' ENGINE = ReplacingMergeTree ORDER BY tuple()',
            $this->compileCreate(function (SchemaBlueprint $table) use ($nullableFirst) {
                $nullableFirst($table);
                $table->orderBy();
            })[0],
            'an empty orderBy() is a choice the blueprint makes'
        );
        $this->assertStringEndsWith(
            ' ENGINE = ReplacingMergeTree ORDER BY (`id`)',
            $this->compileCreate(fn (SchemaBlueprint $table) => [$table->id(), $table->string('name')->nullable(), $table->engine('ReplacingMergeTree')])[0],
            'a first column that can be a sorting key is used'
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function enginesWithAPrimaryKey(): array
    {
        return [
            'EmbeddedRocksDB' => ['EmbeddedRocksDB'],
            'EmbeddedRocksDB with a TTL' => ['EmbeddedRocksDB(3600)'],
            'KeeperMap' => ["KeeperMap('/events')"],
            'Redis' => ["Redis('redis:6379', 0, '', 4)"],
            'MaterializedPostgreSQL' => ["MaterializedPostgreSQL('postgres:5432', 'db', 'events', 'user', 'secret')"],
        ];
    }

    /**
     * These engines require a PRIMARY KEY clause, which is their key, and
     * take no sorting key from ORDER BY.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('enginesWithAPrimaryKey')]
    public function test_primary_gives_the_primary_key_of_a_key_value_engine(string $engine): void
    {
        $this->assertSame(
            ["CREATE TABLE `events` (`key` String, `value` String) ENGINE = {$engine} PRIMARY KEY (`key`)"],
            $this->compileCreate(function (SchemaBlueprint $table) use ($engine) {
                $table->string('key')->primary();
                $table->string('value');
                $table->engine($engine);
            })
        );
        $this->assertSame(
            ["CREATE TABLE `events` (`key` String, `value` String) ENGINE = {$engine} PRIMARY KEY (`key`) ORDER BY (`key`)"],
            $this->compileCreate(function (SchemaBlueprint $table) use ($engine) {
                $table->string('key');
                $table->string('value');
                $table->primary('key');
                $table->orderBy('key');
                $table->engine($engine);
            })
        );
        $this->assertSame(
            ["CREATE TABLE `events` (`key` String, `value` String) ENGINE = {$engine}"],
            $this->compileCreate(function (SchemaBlueprint $table) use ($engine) {
                $table->string('key');
                $table->string('value');
                $table->engine($engine);
            }),
            'without primary() the server refuses the table'
        );
    }

    public function test_a_stored_first_column_is_the_sorting_key(): void
    {
        $this->assertStringEndsWith(
            ' ORDER BY (`name_length`)',
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->unsignedBigInteger('name_length')->storedAs('length(name)');
                $table->string('name');
            })[0]
        );
    }

    public function test_engines_outside_the_merge_tree_family_get_no_sorting_key(): void
    {
        $this->assertSame(
            ["CREATE TABLE `events` (`id` Int64) ENGINE = Memory SETTINGS min_rows_to_keep=0 COMMENT 'cache'"],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->id();
                $table->primary('id');
                $table->engine('Memory');
                $table->settings(['min_rows_to_keep' => 0]);
                $table->comment('cache');
            })
        );
    }

    /**
     * ClickHouse refuses the clause for a Log table; it is sent all the same,
     * so that the migration fails instead of creating another table.
     */
    public function test_a_clause_set_by_name_is_written_for_every_engine(): void
    {
        $this->assertSame(
            ['CREATE TABLE `events` (`id` Int64) ENGINE = Log ORDER BY (`id`)'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->id();
                $table->engine('Log');
                $table->orderBy('id');
            })
        );
        $this->assertSame(
            ["CREATE TABLE `events` (`id` Int64) ENGINE = S3('https://bucket/{_partition_id}.csv', 'CSV') PARTITION BY id % 4"],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->id();
                $table->engine("S3('https://bucket/{_partition_id}.csv', 'CSV')");
                $table->partitionBy('id % 4');
            })
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function engines(): array
    {
        return [
            'MergeTree()' => ['MergeTree()', true],
            'MergeTree' => ['MergeTree', true],
            'with parameters' => ['ReplacingMergeTree(version)', true],
            'replicated' => ["ReplicatedMergeTree('/clickhouse/tables/{uuid}', '{replica}')", true],
            'space before the parameters' => ['SummingMergeTree (amount)', true],
            'Memory' => ['Memory', false],
            'Log' => ['Log', false],
            'Merge' => ["Merge(currentDatabase(), '^events')", false],
            'Distributed' => ['Distributed(company_cluster, default, events)', false],
            'a name that only contains MergeTree' => ['MergeTreeLike', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('engines')]
    public function test_the_merge_tree_family(string $engine, bool $hasSortingKey): void
    {
        $sql = $this->compileCreate(function (SchemaBlueprint $table) use ($engine) {
            $table->id();
            $table->engine($engine);
        })[0];

        $this->assertStringContainsString(" ENGINE = {$engine}", $sql);
        $this->assertSame($hasSortingKey, str_ends_with($sql, ' ORDER BY (`id`)'));
    }

    public function test_the_engine_option_of_the_connection(): void
    {
        $this->assertSame(
            ['CREATE TABLE `events` (`id` Int64) ENGINE = ReplacingMergeTree ORDER BY (`id`)'],
            $this->compileCreate(fn (SchemaBlueprint $table) => $table->id(), ['engine' => 'ReplacingMergeTree'])
        );
        $this->assertSame(
            ['CREATE TABLE `events` (`id` Int64) ENGINE = Memory'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->id();
                $table->engine('Memory');
            }, ['engine' => 'ReplacingMergeTree']),
            "the blueprint's engine wins"
        );
        $this->assertSame(
            ['CREATE TABLE `events` (`id` Int64) ENGINE = MergeTree() ORDER BY (`id`)'],
            $this->compileCreate(fn (SchemaBlueprint $table) => $table->id(), ['engine' => '  ']),
            'a blank engine counts as not set'
        );
    }

    public function test_every_table_clause(): void
    {
        $this->assertSame(
            ['CREATE TABLE IF NOT EXISTS `visits` (`id` UInt64, `version` UInt64, `visited_at` DateTime)'
                . ' ENGINE = ReplacingMergeTree(version) PARTITION BY toYYYYMM(visited_at)'
                . ' PRIMARY KEY (`id`, intHash32(id)) ORDER BY (`id`, intHash32(id)) SAMPLE BY intHash32(id)'
                . ' TTL visited_at + INTERVAL 1 YEAR SETTINGS index_granularity=8192, ttl_only_drop_parts=1'
                . " COMMENT 'Visits, it\\'s \\\\ deduplicated'"],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->unsignedBigInteger('id');
                $table->unsignedBigInteger('version');
                $table->dateTime('visited_at');
                $table->engine('ReplacingMergeTree(version)');
                $table->partitionBy(new Expression('toYYYYMM(visited_at)'));
                $table->primary(['id', new Expression('intHash32(id)')], 'visits_primary');
                $table->orderBy('id', new Expression('intHash32(id)'));
                $table->sampleBy('intHash32(id)');
                $table->ttl('visited_at + INTERVAL 1 YEAR');
                $table->settings(['index_granularity' => 8192]);
                $table->settings(['ttl_only_drop_parts' => true]);
                $table->comment("Visits, it's \\ deduplicated");
                $table->ifNotExists();
            }, ['exact_integer_types' => true], 'visits')
        );
    }

    public function test_settings(): void
    {
        $this->assertStringEndsWith(
            " SETTINGS a=2, b='it\\'s', c=1.5, d=0, e=toUInt8(1)",
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->id();
                $table->settings(['a' => 1, 'b' => "it's", 'c' => 1.5]);
                $table->settings(['a' => 2, 'd' => false, 'e' => new Expression('toUInt8(1)'), 'f' => null]);
            })[0]
        );
    }

    public function test_an_invalid_setting_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ClickHouse setting name [bad name]');

        $this->compileCreate(function (SchemaBlueprint $table) {
            $table->id();
            $table->settings(['bad name' => 1]);
        });
    }

    public function test_indexes_with_a_type_go_into_the_column_list(): void
    {
        $this->assertSame(
            ['CREATE TABLE `visits` (`id` Int64, `url` String, `country` String,'
                . ' INDEX `visits_url_index` `url` TYPE bloom_filter(0.01) GRANULARITY 4,'
                . ' INDEX `visits_country_id_index` (`country`, `id`) TYPE minmax,'
                . ' INDEX `visits_lower_url` lower(url) TYPE ngrambf_v1(3, 256, 2, 0) GRANULARITY 2)'
                . ' ENGINE = MergeTree() ORDER BY (`id`)'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->id();
                $table->string('url')->index();
                $table->string('country');
                $table->index('url', null, 'bloom_filter(0.01)')->granularity(4);
                $table->index(['country', 'id'], null, 'minmax');
                $table->rawIndex('lower(url)', 'visits_lower_url')->algorithm('ngrambf_v1(3, 256, 2, 0)')->granularity(2);
                $table->index('country');
            }, table: 'visits')
        );
    }

    /**
     * The commands that the CREATE statement takes in compile to no statement
     * of their own. So does an index without a type, which the statement
     * leaves out: an ALTER after the CREATE could not add it either.
     */
    public function test_the_commands_in_the_create_statement_are_skipped(): void
    {
        $blueprint = new SchemaBlueprint($this->connection(), 'events');
        $blueprint->create();
        $blueprint->id();
        $typed = $blueprint->index('id', null, 'minmax');
        $typeless = $blueprint->index('id', 'events_typeless');
        $commands = [
            $blueprint->primary('id'),
            $blueprint->orderBy('id'),
            $blueprint->partitionBy('id % 4'),
            $blueprint->sampleBy('id'),
            $blueprint->ttl('now()'),
            $blueprint->settings(['index_granularity' => 1024]),
            $blueprint->comment('events'),
            $typed,
        ];

        $this->assertCount(1, $blueprint->toSql());

        foreach ($commands as $command) {
            $this->assertTrue($command->shouldBeSkipped, "the {$command->name} command is part of the CREATE statement");
        }
        $this->assertTrue($typeless->shouldBeSkipped, 'an index without a type is left out');
    }

    public function test_column_positions_are_ignored_in_create(): void
    {
        $this->assertSame(
            ['CREATE TABLE `events` (`id` Int64, `name` String, `email` String) ENGINE = MergeTree() ORDER BY (`id`)'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->id();
                $table->string('name')->first();
                $table->string('email')->after('id');
            })
        );
    }

    public function test_names_are_quoted_as_one_identifier(): void
    {
        $this->assertSame(
            ['CREATE TABLE `analytics`.`my events` (`we\\`ird` Int64, `n.a` String, `end\\\\` String,'
                . ' INDEX `idx \\`n.a\\`` `n.a` TYPE bloom_filter) ENGINE = MergeTree() ORDER BY (`we\\`ird`)'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->bigInteger('we`ird');
                $table->string('n.a');
                $table->string('end\\');
                $table->index('n.a', 'idx `n.a`', 'bloom_filter');
            }, table: 'analytics.my events')
        );
    }

    public function test_the_default_time_precision_gives_date_time_64(): void
    {
        Builder::defaultTimePrecision(3);

        $this->assertSame(
            ['CREATE TABLE `events` (`id` Int64, `created_at` Nullable(DateTime64(3)), `updated_at` Nullable(DateTime64(3)),'
                . ' `seen_at` DateTime64(3) DEFAULT now64(3), `day` DateTime) ENGINE = MergeTree() ORDER BY (`id`)'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->id();
                $table->timestamps();
                $table->timestamp('seen_at')->useCurrent();
                $table->dateTime('day', 0);
            })
        );
    }

    public function test_a_plain_laravel_blueprint_compiles_the_same_way(): void
    {
        $blueprint = new \Illuminate\Database\Schema\Blueprint($this->connection(), 'events');
        $blueprint->create();
        $blueprint->id();
        $blueprint->string('name')->nullable()->lowCardinality();
        $blueprint->index('name', null, 'set(100)');

        $this->assertSame(
            ['CREATE TABLE `events` (`id` Int64, `name` LowCardinality(Nullable(String)), INDEX `events_name_index` `name` TYPE set(100))'
                . ' ENGINE = MergeTree() ORDER BY (`id`)'],
            $blueprint->toSql()
        );
    }

    public function test_added_columns_go_into_one_statement_with_their_positions(): void
    {
        $this->assertSame(
            ["ALTER TABLE `visits` ADD COLUMN `source` LowCardinality(String) DEFAULT 'web' COMMENT 'traffic source' FIRST,"
                . ' ADD COLUMN `s` Int32 AFTER `id`, ADD COLUMN `t` Nullable(String) AFTER `s`, ADD COLUMN `u` String'],
            $this->compileTable(function (SchemaBlueprint $table) {
                $table->string('source')->lowCardinality()->default('web')->comment('traffic source')->first();
                $table->after('id', function (SchemaBlueprint $table) {
                    $table->integer('s');
                    $table->string('t')->nullable();
                });
                $table->string('u');
            }, table: 'visits')
        );
    }

    /**
     * ClickHouse accepts a new sorting key only in the ALTER that adds the
     * columns it appends, so MODIFY ORDER BY joins the ADD COLUMN statement,
     * whether orderBy() comes before or after the columns.
     */
    public function test_order_by_joins_the_statement_that_adds_columns(): void
    {
        $expected = ['ALTER TABLE `ob1` ADD COLUMN `s` Int32, ADD COLUMN `t` String AFTER `s`, MODIFY ORDER BY (`id`, `s`)'];
        $columns = function (SchemaBlueprint $table): void {
            $table->integer('s');
            $table->string('t')->after('s');
        };

        $this->assertSame($expected, $this->compileTable(function (SchemaBlueprint $table) use ($columns) {
            $columns($table);
            $table->orderBy('id', 's');
        }, table: 'ob1'));
        $this->assertSame($expected, $this->compileTable(function (SchemaBlueprint $table) use ($columns) {
            $table->orderBy('id', 's');
            $columns($table);
        }, table: 'ob1'));
        $this->assertSame($expected, $this->compileTable(function (SchemaBlueprint $table) use ($columns) {
            $table->orderBy('id');
            $columns($table);
            $table->orderBy(['id', 's']);
        }, table: 'ob1'), 'the last orderBy() wins');
    }

    public function test_order_by_without_added_columns_has_a_statement_of_its_own(): void
    {
        $this->assertSame(
            ['ALTER TABLE `visits` MODIFY ORDER BY (`id`, intHash32(id))'],
            $this->compileTable(fn (SchemaBlueprint $table) => $table->orderBy('id', new Expression('intHash32(id)')), table: 'visits')
        );
    }

    /**
     * A dropColumn(), renameColumn() or change() between two added columns
     * starts a new ADD COLUMN statement, so the column added after it is
     * added after it runs, as on Laravel's other drivers.
     */
    public function test_a_column_change_between_added_columns_starts_a_new_statement(): void
    {
        $this->assertSame(
            [
                'ALTER TABLE `o1` ADD COLUMN `note` String',
                'ALTER TABLE `o1` DROP COLUMN `amount`',
                'ALTER TABLE `o1` ADD COLUMN `amount` Decimal(10, 2)',
            ],
            $this->compileTable(function (SchemaBlueprint $table) {
                $table->string('note');
                $table->dropColumn('amount');
                $table->decimal('amount', 10, 2);
            }, table: 'o1'),
            'a column dropped and added again with a new type'
        );
        $this->assertSame(
            [
                'ALTER TABLE `o1` DROP COLUMN `tmp`',
                'ALTER TABLE `o1` ADD COLUMN `a` String',
                'ALTER TABLE `o1` RENAME COLUMN `x` TO `y`',
                'ALTER TABLE `o1` ADD COLUMN `b` String AFTER `y`',
            ],
            $this->compileTable(function (SchemaBlueprint $table) {
                $table->dropColumn('tmp');
                $table->string('a');
                $table->renameColumn('x', 'y');
                $table->string('b')->after('y');
            }, table: 'o1'),
            'a column added after a column that a rename creates'
        );
        $this->assertSame(
            [
                'ALTER TABLE `o1` ADD COLUMN `a` String',
                'ALTER TABLE `o1` MODIFY COLUMN `x` String',
                'ALTER TABLE `o1` RENAME COLUMN `x` TO `y`',
                'ALTER TABLE `o1` ADD COLUMN `b` String AFTER `y`',
            ],
            $this->compileTable(function (SchemaBlueprint $table) {
                $table->string('a');
                $table->string('x')->renameTo('y')->change();
                $table->string('b')->after('y');
            }, table: 'o1'),
            'a column added after a column that change() renames'
        );
    }

    /**
     * The statements of the other commands cannot fail because of a column
     * added before them, so the columns added around them go into one
     * statement, at the position of the first of them.
     */
    public function test_other_commands_keep_the_added_columns_in_one_statement(): void
    {
        $this->assertSame(
            [
                'ALTER TABLE `o1` ADD COLUMN `a` String, ADD COLUMN `b` String, ADD COLUMN `c` String, MODIFY ORDER BY (`id`, `a`, `b`)',
                'ALTER TABLE `o1` ADD INDEX `o1_a_index` `a` TYPE minmax',
                'ALTER TABLE `o1` ADD INDEX `o1_b_set` `b` TYPE set(10)',
                'ALTER TABLE `o1` DROP INDEX IF EXISTS `o1_old_index`',
                "ALTER TABLE `o1` MODIFY COMMENT 'c'",
                'ALTER TABLE `o1` MODIFY TTL at + INTERVAL 1 DAY',
                'ALTER TABLE `o1` MODIFY SETTING merge_with_ttl_timeout=3600',
                'ALTER TABLE `o1` MODIFY SAMPLE BY intHash32(id)',
            ],
            $this->compileTable(function (SchemaBlueprint $table) {
                $table->string('a');
                $table->foreign('a')->references('id')->on('users');
                $table->unique('a');
                $table->spatialIndex('a');
                $table->index('a');
                $table->index('b', 'o1_b_set', 'set(10)');
                $table->dropIndex('o1_old_index');
                $table->comment('c');
                $table->string('b');
                $table->ttl('at + INTERVAL 1 DAY');
                $table->settings(['merge_with_ttl_timeout' => 3600]);
                $table->sampleBy('intHash32(id)');
                $table->orderBy('id', 'a', 'b');
                $table->string('c');
            }, ['default_index_type' => 'minmax'], 'o1')
        );
    }

    /**
     * ClickHouse accepts a new sorting key only in the ALTER that adds its
     * columns, so orderBy() throws before anything is sent when a
     * dropColumn(), renameColumn() or change() splits the added columns into
     * several statements.
     */
    public function test_order_by_needs_the_added_columns_in_one_statement(): void
    {
        $connection = $this->connection();

        try {
            (new SchemaBuilder($connection))->table('o1', function (SchemaBlueprint $table) {
                $table->string('a');
                $table->dropColumn('x');
                $table->string('b');
                $table->orderBy('id', 'a', 'b');
            });
            $this->fail('Schema::table() should throw');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'ClickHouse accepts a new sorting key only in the ALTER that adds its columns, but a dropColumn(),'
                . ' renameColumn() or change() between the columns that Schema::table() adds to the table [o1] splits'
                . ' them into 2 ALTER statements. Call those commands before or after the added columns, not between them.',
                $exception->getMessage()
            );
        }
        $this->assertSame([], $this->statements);

        try {
            $this->compileTable(function (SchemaBlueprint $table) {
                $table->orderBy('id', 'a', 'b', 'c');
                $table->string('a');
                $table->renameColumn('p', 'q');
                $table->string('b');
                $table->string('x')->change();
                $table->string('c');
            }, table: 'o1');
            $this->fail('toSql() should throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('splits them into 3 ALTER statements.', $exception->getMessage());
        }

        $this->assertSame(
            ['ALTER TABLE `o1` DROP COLUMN `x`', 'ALTER TABLE `o1` ADD COLUMN `a` String, ADD COLUMN `b` String, MODIFY ORDER BY (`id`, `a`, `b`)'],
            $this->compileTable(function (SchemaBlueprint $table) {
                $table->dropColumn('x');
                $table->string('a');
                $table->string('b');
                $table->orderBy('id', 'a', 'b');
            }, table: 'o1'),
            'a command before or after the added columns does not split them'
        );
    }

    public function test_drop_rename_and_rename_column(): void
    {
        $this->assertSame(
            [
                'ALTER TABLE `visits` DROP COLUMN `referrer`, DROP COLUMN `traffic_source`',
                'ALTER TABLE `visits` DROP COLUMN `created_at`, DROP COLUMN `updated_at`',
                'ALTER TABLE `visits` RENAME COLUMN `payload` TO `body`',
                'RENAME TABLE `visits` TO `visits_old`',
            ],
            $this->compileTable(function (SchemaBlueprint $table) {
                $table->dropColumn(['referrer', 'traffic_source']);
                $table->dropTimestamps();
                $table->renameColumn('payload', 'body');
                $table->rename('visits_old');
            }, table: 'visits')
        );
    }

    public function test_rename_keeps_the_table_in_its_database(): void
    {
        $this->assertSame(
            ['RENAME TABLE `analytics`.`visits` TO `analytics`.`visits_old`'],
            $this->compileTable(fn (SchemaBlueprint $table) => $table->rename('visits_old'), table: 'analytics.visits')
        );
        $this->assertSame(
            ['RENAME TABLE `analytics`.`visits` TO `archive`.`visits`'],
            $this->compileTable(fn (SchemaBlueprint $table) => $table->rename('archive.visits'), table: 'analytics.visits')
        );
    }

    public function test_the_table_clauses_in_alter_table(): void
    {
        $this->assertSame(
            [
                "ALTER TABLE `visits` MODIFY COMMENT 'Visits, it\\'s deduplicated'",
                'ALTER TABLE `visits` MODIFY SAMPLE BY intHash32(id)',
                'ALTER TABLE `visits` MODIFY TTL toDateTime(visited_at) + INTERVAL 2 YEAR',
                "ALTER TABLE `visits` MODIFY SETTING merge_with_ttl_timeout=3600, ttl_only_drop_parts=1, storage_policy='it\\'s'",
            ],
            $this->compileTable(function (SchemaBlueprint $table) {
                $table->comment("Visits, it's deduplicated");
                $table->sampleBy(new Expression('intHash32(id)'));
                $table->ttl('toDateTime(visited_at) + INTERVAL 2 YEAR');
                $table->settings(['merge_with_ttl_timeout' => 3600, 'ttl_only_drop_parts' => true, 'storage_policy' => "it's", 'unset' => null]);
                $table->settings(['only_null' => null]);
            }, table: 'visits')
        );
    }

    public function test_an_invalid_setting_name_throws_in_alter_table(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ClickHouse setting name [bad name]');

        $this->compileTable(fn (SchemaBlueprint $table) => $table->settings(['bad name' => 1]));
    }

    public function test_the_partition_key_cannot_change(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'ClickHouse cannot change the partition key of the table [visits]. Create a new table with partitionBy() in'
            . ' Schema::create() and copy the rows, for example with INSERT INTO ... SELECT.'
        );

        $this->compileTable(fn (SchemaBlueprint $table) => $table->partitionBy('toYYYYMM(visited_at)'), table: 'visits');
    }

    public function test_data_skipping_indexes_in_alter_table(): void
    {
        $this->assertSame(
            [
                'ALTER TABLE `visits` ADD INDEX `visits_body_index` `body` TYPE tokenbf_v1(512, 3, 0) GRANULARITY 2',
                'ALTER TABLE `visits` MATERIALIZE INDEX `visits_body_index`',
                'ALTER TABLE `visits` ADD INDEX `visits_lower_url` lower(url) TYPE bloom_filter',
                'ALTER TABLE `visits` ADD INDEX `visits_country_id_index` (`country`, `id`) TYPE minmax',
                'ALTER TABLE `visits` DROP INDEX IF EXISTS `visits_lower_url`',
                'ALTER TABLE `visits` DROP INDEX IF EXISTS `visits_url_index`',
            ],
            $this->compileTable(function (SchemaBlueprint $table) {
                $table->index('body', null, 'tokenbf_v1(512, 3, 0)')->granularity(2)->materialize();
                $table->rawIndex('lower(url)', 'visits_lower_url')->algorithm(' bloom_filter ');
                $table->index(['country', 'id'], null, 'minmax');
                $table->index('url');
                $table->dropIndex('visits_lower_url');
                $table->dropIndex(['url']);
            }, table: 'visits')
        );
    }

    /**
     * Only tables of the MergeTree family can have data-skipping indexes:
     * the others refuse DROP INDEX even with IF EXISTS, so dropIndex() and
     * the index half of dropMorphs() send nothing for them. The engine is
     * read from system.tables; a table that it does not list keeps the
     * statement, so that the server decides.
     */
    public function test_drop_index_sends_nothing_for_a_table_without_data_skipping_indexes(): void
    {
        $drops = function (SchemaBlueprint $table): void {
            $table->dropMorphs('owner');
            $table->dropIndex('visits_url_index');
        };

        foreach (['Memory', 'Log', 'Null', 'Distributed', 'Buffer', 'MaterializedView'] as $engine) {
            $this->assertSame(
                ['ALTER TABLE `visits` DROP COLUMN `owner_type`, DROP COLUMN `owner_id`'],
                $this->compileTable($drops, table: 'visits', engine: $engine),
                $engine
            );
        }

        foreach (['MergeTree', 'ReplicatedMergeTree', 'ReplacingMergeTree', 'SharedMergeTree', null] as $engine) {
            $this->assertSame(
                [
                    'ALTER TABLE `visits` DROP INDEX IF EXISTS `visits_owner_type_owner_id_index`',
                    'ALTER TABLE `visits` DROP COLUMN `owner_type`, DROP COLUMN `owner_id`',
                    'ALTER TABLE `visits` DROP INDEX IF EXISTS `visits_url_index`',
                ],
                $this->compileTable($drops, table: 'visits', engine: $engine),
                $engine ?? 'a table that system.tables does not list'
            );
        }

        $this->selects = [];
        $this->compileTable(fn (SchemaBlueprint $table) => $table->dropIndex('i'), table: "archive.it's", engine: 'Memory');
        $this->assertSame(["SELECT engine FROM system.tables WHERE database = 'archive' AND name = 'it\\'s'"], $this->selects);
    }

    /**
     * default_index_type only applies to a table of the MergeTree family,
     * since no other engine has data-skipping indexes: an index without a
     * type sends nothing for the others, in CREATE and in ALTER. An index's
     * own type is always written, so that the server accepts or refuses it.
     */
    public function test_the_default_index_type_only_applies_to_the_merge_tree_family(): void
    {
        $this->assertSame(
            ['CREATE TABLE `visits` (`id` Int64, `owner_type` String, `owner_id` Int64, INDEX `visits_set` `id` TYPE set(100))'
                . ' ENGINE = Memory'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->id();
                $table->morphs('owner');
                $table->index('id', 'visits_set', 'set(100)');
                $table->engine('Memory');
            }, ['default_index_type' => 'minmax'], 'visits')
        );
        $this->assertSame(
            ['CREATE TABLE `visits` (`id` Int64, `url` String) ENGINE = Log'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->id();
                $table->string('url')->index();
            }, ['default_index_type' => 'minmax', 'engine' => 'Log'], 'visits'),
            "the connection's engine option"
        );

        $typeless = function (SchemaBlueprint $table): void {
            $table->string('url')->index();
            $table->rawIndex('lower(url)', 'visits_lower_url');
            $table->index('url', 'visits_url_set', 'set(10)');
        };

        $this->assertSame(
            [
                'ALTER TABLE `visits` ADD COLUMN `url` String',
                'ALTER TABLE `visits` ADD INDEX `visits_url_set` `url` TYPE set(10)',
            ],
            $this->compileTable($typeless, ['default_index_type' => 'minmax'], 'visits', 'Memory')
        );
        $this->assertSame(
            [
                'ALTER TABLE `visits` ADD COLUMN `url` String',
                'ALTER TABLE `visits` ADD INDEX `visits_lower_url` lower(url) TYPE minmax',
                'ALTER TABLE `visits` ADD INDEX `visits_url_set` `url` TYPE set(10)',
                'ALTER TABLE `visits` ADD INDEX `visits_url_index` `url` TYPE minmax',
            ],
            $this->compileTable($typeless, ['default_index_type' => 'minmax'], 'visits', 'ReplicatedReplacingMergeTree')
        );

        $this->selects = [];
        $this->compileTable($typeless, table: 'visits', engine: 'Memory');
        $this->assertSame([], $this->selects, 'no engine read without default_index_type');
    }

    /**
     * Without default_index_type, an index without a type sends nothing, as
     * in 3.0.0; with it, the index gets that type in CREATE and in ALTER.
     */
    public function test_the_default_index_type(): void
    {
        $typeless = function (SchemaBlueprint $table): void {
            $table->string('url')->index();
            $table->morphs('owner');
            $table->rawIndex('lower(url)', 'visits_lower_url')->granularity(3);
        };

        $this->assertSame(
            ['ALTER TABLE `visits` ADD COLUMN `url` String, ADD COLUMN `owner_type` String, ADD COLUMN `owner_id` Int64'],
            $this->compileTable($typeless, table: 'visits')
        );
        $this->assertSame(
            ['ALTER TABLE `visits` ADD COLUMN `url` String, ADD COLUMN `owner_type` String, ADD COLUMN `owner_id` Int64'],
            $this->compileTable($typeless, ['default_index_type' => ' '], 'visits'),
            'a blank type counts as not set'
        );
        $this->assertSame(
            [
                'ALTER TABLE `visits` ADD COLUMN `url` String, ADD COLUMN `owner_type` String, ADD COLUMN `owner_id` Int64',
                'ALTER TABLE `visits` ADD INDEX `visits_owner_type_owner_id_index` (`owner_type`, `owner_id`) TYPE bloom_filter',
                'ALTER TABLE `visits` ADD INDEX `visits_lower_url` lower(url) TYPE bloom_filter GRANULARITY 3',
                'ALTER TABLE `visits` ADD INDEX `visits_url_index` `url` TYPE bloom_filter',
            ],
            $this->compileTable($typeless, ['default_index_type' => 'bloom_filter'], 'visits')
        );
        $this->assertSame(
            ['CREATE TABLE `visits` (`id` Int64, `owner_type` String, `owner_id` Int64,'
                . ' INDEX `visits_owner_type_owner_id_index` (`owner_type`, `owner_id`) TYPE minmax, INDEX `visits_set` `id` TYPE set(100))'
                . ' ENGINE = MergeTree() ORDER BY (`id`)'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->id();
                $table->morphs('owner');
                $table->index('id', 'visits_set', 'set(100)');
            }, ['default_index_type' => 'minmax'], 'visits')
        );
        $this->assertSame(
            ['ALTER TABLE `visits` ADD INDEX `visits_url_index` `url` TYPE set(10)'],
            $this->compileTable(fn (SchemaBlueprint $table) => $table->index('url', null, 'set(10)'), ['default_index_type' => 'minmax'], 'visits'),
            "the index's own type wins"
        );
    }

    /**
     * unique(), spatialIndex() and foreign() compile to nothing, as do their
     * drops: ClickHouse has no such constraint or index.
     */
    public function test_constraints_that_clickhouse_does_not_have_send_nothing(): void
    {
        $this->assertSame(
            ['CREATE TABLE `posts` (`id` Int64, `user_id` Int64, `email` String) ENGINE = MergeTree() ORDER BY (`id`)'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('email')->unique();
                $table->spatialIndex('email');
            }, table: 'posts')
        );
        $this->assertSame(
            ['ALTER TABLE `posts` ADD COLUMN `team_id` Int64', 'ALTER TABLE `posts` DROP COLUMN `user_id`'],
            $this->compileTable(function (SchemaBlueprint $table) {
                $table->foreignId('team_id')->constrained();
                $table->foreign('team_id')->references('id')->on('teams');
                $table->unique('email');
                $table->dropUnique(['email']);
                $table->spatialIndex('email');
                $table->dropSpatialIndex(['email']);
                $table->dropForeign(['team_id']);
                $table->dropConstrainedForeignId('user_id');
            }, table: 'posts')
        );
    }

    /**
     * @return array<string, array{Closure(SchemaBlueprint): mixed, string}>
     */
    public static function commandsThatClickHouseCannotRun(): array
    {
        return [
            'primary' => [
                fn (SchemaBlueprint $table) => $table->primary('id'),
                'ClickHouse cannot change the primary key of the table [visits]. Give the key with primary() or orderBy()'
                . ' in Schema::create(); orderBy() in Schema::table() can append columns that the same call adds to the sorting key.',
            ],
            'primary of an added column' => [
                fn (SchemaBlueprint $table) => $table->string('code')->primary(),
                'ClickHouse cannot change the primary key of the table [visits].',
            ],
            'dropPrimary' => [
                fn (SchemaBlueprint $table) => $table->dropPrimary(),
                'ClickHouse cannot drop the primary key of the table [visits]: it is part of the table. Create a new table'
                . ' with the key you need and copy the rows.',
            ],
            'renameIndex' => [
                fn (SchemaBlueprint $table) => $table->renameIndex('a', 'b'),
                'ClickHouse cannot rename the index [a] of the table [visits]. Drop it with dropIndex() and add it again with index().',
            ],
            'fullText' => [
                fn (SchemaBlueprint $table) => $table->fullText('body'),
                'ClickHouse has no full-text index for fullText() (index [visits_body_fulltext] of the table [visits]). Add a'
                . " data-skipping index of a token or n-gram type instead, such as index('body', null, 'tokenbf_v1(512, 3, 0)').",
            ],
            'dropFullText' => [
                fn (SchemaBlueprint $table) => $table->dropFullText(['body']),
                'ClickHouse has no full-text index for dropFullText() (index [visits_body_fulltext] of the table [visits]).'
                . ' Drop a data-skipping index with dropIndex().',
            ],
            'vectorIndex' => [
                fn (SchemaBlueprint $table) => $table->vectorIndex('embedding'),
                'ClickHouse has no vector index for vectorIndex() (index [visits_embedding_vectorindex] of the table [visits]).'
                . ' Pass a vector similarity index type that the server supports to index() as its algorithm.',
            ],
            'index() of a vector column' => [
                fn (SchemaBlueprint $table) => $table->vector('embedding', 3)->index(),
                'ClickHouse has no vector index for vectorIndex()',
            ],
            'dropVectorIndex' => [
                fn (SchemaBlueprint $table) => $table->dropVectorIndex(['embedding']),
                'ClickHouse has no vector index for dropVectorIndex() (index [visits_embedding_vectorindex] of the table [visits]).',
            ],
            'time' => [fn (SchemaBlueprint $table) => $table->time('at'), 'ClickHouse has no time-of-day type for time() (column [at]).'],
            'set' => [fn (SchemaBlueprint $table) => $table->set('flags', ['a']), 'ClickHouse has no SET type (column [flags]).'],
        ];
    }

    /**
     * Blueprint::build() compiles every command before it sends the first
     * statement, so a command that ClickHouse cannot run stops the whole
     * blueprint: the column added before it is not sent either.
     *
     * @param Closure(SchemaBlueprint): mixed $command
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('commandsThatClickHouseCannotRun')]
    public function test_a_command_that_clickhouse_cannot_run_throws_before_anything_is_sent(Closure $command, string $message): void
    {
        $connection = $this->connection();

        try {
            (new SchemaBuilder($connection))->table('visits', function (SchemaBlueprint $table) use ($command) {
                $table->string('added_before');
                $command($table);
            });
            $this->fail('Schema::table() should throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertSame([], $this->statements);
    }

    public function test_drop_and_drop_if_exists_with_sync(): void
    {
        $builder = new SchemaBuilder($this->connection());

        $builder->dropSync('visits_old');
        $builder->dropIfExistsSync('analytics.visits_old');
        $builder->drop('visits');
        $builder->table('visits', fn (SchemaBlueprint $table) => $table->dropIfExists()->sync());

        $this->assertSame(
            [
                'DROP TABLE `visits_old` SYNC',
                'DROP TABLE IF EXISTS `analytics`.`visits_old` SYNC',
                'DROP TABLE `visits`',
                'DROP TABLE IF EXISTS `visits` SYNC',
            ],
            $this->statements
        );
    }

    public function test_schema_table_sends_each_statement_in_order(): void
    {
        (new SchemaBuilder($this->connection()))->table('visits', function (SchemaBlueprint $table) {
            $table->dropIndex('visits_referrer_index');
            $table->dropColumn('referrer');
            $table->string('source')->after('id');
            $table->comment('Visits');
        });

        $this->assertSame(
            [
                'ALTER TABLE `visits` DROP INDEX IF EXISTS `visits_referrer_index`',
                'ALTER TABLE `visits` DROP COLUMN `referrer`',
                'ALTER TABLE `visits` ADD COLUMN `source` String AFTER `id`',
                "ALTER TABLE `visits` MODIFY COMMENT 'Visits'",
            ],
            $this->statements
        );
    }

    /**
     * createDatabase(), dropDatabaseIfExists() and the DROPs of dropAllTables()
     * go through Connection::statementOnEveryNode(), which logs them and sends
     * nothing while the connection pretends, instead of the cluster's write().
     */
    public function test_database_statements_go_through_statement_on_every_node(): void
    {
        $connection = $this->connection();
        $connection->expects($this->never())->method('getCluster');
        $connection->method('statementOnEveryNode')->willReturnCallback(function (string $query): bool {
            $this->statements[] = $query;

            return true;
        });
        $connection->method('select')->willReturn([
            ['name' => 'events_dictionary', 'engine' => 'Dictionary', 'dependents' => []],
            ['name' => 'events', 'engine' => 'MergeTree', 'dependents' => ['events_dictionary']],
        ]);
        $builder = new SchemaBuilder($connection);

        $this->assertTrue($builder->createDatabase('we`ird'));
        $this->assertTrue($builder->dropDatabaseIfExists('archive'));
        $builder->dropAllTables();

        $this->assertSame(
            [
                'CREATE DATABASE IF NOT EXISTS `we\\`ird`',
                'DROP DATABASE IF EXISTS `archive` SYNC',
                'DROP DICTIONARY IF EXISTS `analytics`.`events_dictionary` SYNC',
                'DROP TABLE IF EXISTS `analytics`.`events` SYNC',
            ],
            $this->statements
        );
    }

    /**
     * Compile the statements of Schema::table() for a SchemaBlueprint.
     *
     * @param Closure(SchemaBlueprint): mixed $callback
     * @param array<string, mixed> $config
     * @param string|null $engine The engine that system.tables gives for the table; null when it does not list it
     * @return list<string>
     */
    private function compileTable(Closure $callback, array $config = [], string $table = 'events', ?string $engine = null): array
    {
        $blueprint = new SchemaBlueprint($this->connection($config, $engine), $table);
        $callback($blueprint);

        return $blueprint->toSql();
    }

    /**
     * Compile the statements of Schema::create() for a SchemaBlueprint.
     *
     * @param Closure(SchemaBlueprint): mixed $callback
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function compileCreate(Closure $callback, array $config = [], string $table = 'events'): array
    {
        $blueprint = new SchemaBlueprint($this->connection($config), $table);
        $blueprint->create();
        $callback($blueprint);

        return $blueprint->toSql();
    }

    /**
     * A connection with the given options, a real SchemaGrammar and the
     * database `analytics`, which records the statements it is given. Its
     * active node's client answers a read of system.tables with the given
     * engine, and any other read with no rows; it records the reads.
     *
     * @param array<string, mixed> $config
     * @param string|null $engine The engine that system.tables gives for the table; null when it does not list it
     */
    private function connection(array $config = [], ?string $engine = null): Connection
    {
        $client = $this->createMock(Client::class);
        $client->method('select')->willReturnCallback(function (string $sql) use ($engine): Statement {
            $this->selects[] = $sql;

            return $this->statement(str_contains($sql, 'FROM system.tables') && $engine !== null ? [['engine' => $engine]] : []);
        });

        $connection = $this->createMock(Connection::class);
        $connection->method('getClient')->willReturn($client);
        $grammar = new SchemaGrammar($connection);
        $connection->method('getSchemaGrammar')->willReturn($grammar);
        $connection->method('getSchemaBuilder')->willReturnCallback(fn (): SchemaBuilder => new SchemaBuilder($connection));
        $connection->method('getConfig')->willReturnCallback(fn (?string $option = null): mixed => $config[$option] ?? null);
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('getDatabaseName')->willReturn('analytics');
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
