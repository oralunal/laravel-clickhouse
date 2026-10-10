<?php

namespace Tests;

use Illuminate\Database\Events\SchemaDumped;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * End-to-end migrate / schema:dump / migrate:fresh flows with ClickHouse as the
 * primary connection (under a non-default name) and as a secondary connection
 * next to an SQLite primary.
 */
class MigrationScenariosTest extends TestCase
{
    /** ClickHouse connection (and database) the app's migrations manage. */
    private const ANALYTICS = 'phpch_scenario';

    /** ClickHouse connection (and database) no migration or dump refers to. */
    private const EXTERNAL = 'phpch_scenario_external';

    private const USERS_MIGRATION = '2024_01_01_000000_create_users_table';
    private const EVENTS_MIGRATION = '2024_01_02_000000_create_events_table';
    private const SESSIONS_MIGRATION = '2024_01_03_000000_create_sessions_table';

    /**
     * The timeout_query, in seconds, of the ClickHouse connections that the test makes, in place of the 2 of the
     * `clickhouse` connection. ClickHouse 26.8, on a server with little RAM whose config does not set
     * background_pool_size, lowers it from 16 to the RAM in GiB (3 with 3.8 GiB), so that a mutation waits for idle
     * threads: the repository's delete, which waits for its mutation (mutations_sync = 1), then takes up to about 2
     * seconds, and migrate:rollback failed with 'Operation timed out after 2001 milliseconds'. 24.8 and 26.3 delete in
     * about 20 ms.
     */
    private const TIMEOUT_QUERY = 10;

    private string $workDir;

    private Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem();
        $this->workDir = sys_get_temp_dir() . '/phpch-scenario-' . bin2hex(random_bytes(4));
        $this->files->ensureDirectoryExists($this->workDir . '/database/migrations');
        $this->files->ensureDirectoryExists($this->workDir . '/database/schema');
        $this->files->ensureDirectoryExists($this->workDir . '/clickhouse-only');
        $this->app->useDatabasePath($this->workDir . '/database');

        $config = $this->app['config'];
        $clickhouse = $config->get('database.connections.clickhouse');
        foreach ([self::ANALYTICS, self::EXTERNAL] as $name) {
            $database = $this->databaseOf($name);
            $config->set(
                "database.connections.{$name}",
                ['database' => $database, 'timeout_query' => self::TIMEOUT_QUERY] + $clickhouse
            );
            $this->clickhouse('clickhouse')->write("DROP DATABASE IF EXISTS {$database} SYNC");
            $this->clickhouse('clickhouse')->write("CREATE DATABASE {$database}");
        }

        $config->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $config->set('database.default', 'sqlite');

        $this->files->put($this->migrationPath(self::USERS_MIGRATION), <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('users', function (Blueprint $table) {
                        $table->id();
                        $table->string('name');
                    });
                }

                public function down(): void
                {
                    Schema::dropIfExists('users');
                }
            };
            PHP);

        $events = <<<'PHP'
            <?php

            return new class extends \Oralunal\LaravelClickHouse\Migration {
                protected $connection = 'phpch_scenario';

                public function up(): void
                {
                    static::write('CREATE TABLE events (id UInt64) ENGINE = MergeTree ORDER BY id');
                }

                public function down(): void
                {
                    static::write('DROP TABLE IF EXISTS events');
                }
            };
            PHP;
        $this->files->put($this->migrationPath(self::EVENTS_MIGRATION), $events);
        $this->files->put($this->workDir . '/clickhouse-only/' . self::EVENTS_MIGRATION . '.php', $events);
    }

    protected function tearDown(): void
    {
        foreach ([self::ANALYTICS, self::EXTERNAL] as $name) {
            $this->clickhouse('clickhouse')->write('DROP DATABASE IF EXISTS ' . $this->databaseOf($name) . ' SYNC');
        }
        $this->files->deleteDirectory($this->workDir);

        parent::tearDown();
    }

    protected function migratorPaths(): array
    {
        return [];
    }

    public function testSecondaryMigrateRunsMigrationsOfBothConnections(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame(['events'], $this->analyticsTables());
        $this->assertSame([self::USERS_MIGRATION, self::EVENTS_MIGRATION], $this->ranMigrations());
    }

    public function testSecondaryFreshEmptiesAndRebuildsClickhouse(): void
    {
        $this->artisan('migrate')->assertSuccessful();
        $this->clickhouse(self::ANALYTICS)->write('INSERT INTO events VALUES (1)');

        $this->artisan('migrate:fresh')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame(['events'], $this->analyticsTables());
        $this->assertSame(0, $this->eventCount());
        $this->assertSame([self::USERS_MIGRATION, self::EVENTS_MIGRATION], $this->ranMigrations());
    }

    public function testPrimarySchemaDumpAlsoDumpsSecondaryClickhouse(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        event(new SchemaDumped(DB::connection('sqlite'), database_path('schema/sqlite-schema.sql')));

        $dump = $this->files->get(database_path('schema/' . self::ANALYTICS . '-schema.sql'));
        $this->assertStringContainsString('CREATE TABLE events', $dump);
        $this->assertStringNotContainsString($this->databaseOf(self::ANALYTICS) . '.', $dump);
        $this->assertFileDoesNotExist(database_path('schema/' . self::EXTERNAL . '-schema.sql'));
    }

    public function testSecondaryFreshLoadsBothDumpsAfterPrune(): void
    {
        $this->artisan('migrate')->assertSuccessful();
        event(new SchemaDumped(DB::connection('sqlite'), database_path('schema/sqlite-schema.sql')));
        $this->writeSqliteDump();
        $this->files->cleanDirectory(database_path('migrations'));
        $this->clickhouse(self::ANALYTICS)->write('INSERT INTO events VALUES (1)');

        $this->artisan('migrate:fresh')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame(['events'], $this->analyticsTables());
        $this->assertSame(0, $this->eventCount());
        $this->assertSame([self::USERS_MIGRATION, self::EVENTS_MIGRATION], $this->ranMigrations());
    }

    public function testSecondaryWithoutDumpRerunsItsMigrationsWhenEmpty(): void
    {
        $this->writeSqliteDump();

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame(['events'], $this->analyticsTables());
        $this->assertSame([self::USERS_MIGRATION, self::EVENTS_MIGRATION], $this->ranMigrations());
    }

    public function testSecondaryThatStillHoldsTablesIsLeftAlone(): void
    {
        $this->artisan('migrate')->assertSuccessful();
        event(new SchemaDumped(DB::connection('sqlite'), database_path('schema/sqlite-schema.sql')));
        $this->writeSqliteDump();
        $this->clickhouse(self::ANALYTICS)->write('INSERT INTO events VALUES (1)');
        // A new, empty SQLite database next to a ClickHouse database that kept its data.
        DB::purge('sqlite');

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame(1, $this->eventCount());
    }

    public function testFreshLeavesUnmanagedClickhouseConnectionsAlone(): void
    {
        $this->clickhouse(self::EXTERNAL)->write('CREATE TABLE keep (id UInt64) ENGINE = MergeTree ORDER BY id');
        $this->artisan('migrate')->assertSuccessful();

        $this->artisan('migrate:fresh')->assertSuccessful();

        $this->assertSame(['keep'], $this->tables(self::EXTERNAL));
    }

    public function testPrimaryUnderOtherNameMigratesDumpsAndRefreshes(): void
    {
        $arguments = [
            '--database' => self::ANALYTICS,
            '--path' => $this->workDir . '/clickhouse-only',
            '--realpath' => true,
        ];

        $defaultConnectionMigrations = $this->clickhouseMigrations('clickhouse');

        $this->artisan('migrate', $arguments)->assertSuccessful();

        // The migrations table lives on the named connection, not on `clickhouse`.
        $this->assertSame([self::EVENTS_MIGRATION], $this->clickhouseMigrations(self::ANALYTICS));
        $this->assertSame($defaultConnectionMigrations, $this->clickhouseMigrations('clickhouse'));

        $this->artisan('schema:dump', ['--database' => self::ANALYTICS])->assertSuccessful();
        $this->clickhouse(self::ANALYTICS)->write('INSERT INTO events VALUES (1)');

        $this->artisan('migrate:fresh', $arguments)->assertSuccessful();

        $this->assertSame(['events', 'migrations'], $this->analyticsTables());
        $this->assertSame(0, $this->eventCount());
        $this->assertSame([self::EVENTS_MIGRATION], $this->clickhouseMigrations(self::ANALYTICS));
    }

    /**
     * The migration repository on a ClickHouse connection (ClickhouseMigrationRepository) reads the migrations table
     * with Laravel's query builder: the ran migrations with pluck(), the next batch with max() and the batch of each
     * migration with pluck() by key; it records each migration with an INSERT of its own.
     */
    public function testPrimaryUnderOtherNameRecordsEachBatchAndShowsTheStatus(): void
    {
        $arguments = [
            '--database' => self::ANALYTICS,
            '--path' => $this->workDir . '/clickhouse-only',
            '--realpath' => true,
        ];
        $this->artisan('migrate', $arguments)->assertSuccessful();

        $this->writeSessionsMigration();
        $this->artisan('migrate', $arguments)->assertSuccessful();

        $this->assertSame(
            [[self::EVENTS_MIGRATION, 1], [self::SESSIONS_MIGRATION, 2]],
            array_map(
                fn (array $row): array => [$row['migration'], (int) $row['batch']],
                $this->clickhouse(self::ANALYTICS)->select('SELECT migration, batch FROM migrations ORDER BY migration')->rows()
            )
        );
        $this->artisan('migrate:status', $arguments)
            ->expectsOutputToContain('[1] Ran')
            ->expectsOutputToContain('[2] Ran')
            ->assertSuccessful();
        $this->artisan('migrate', $arguments)->expectsOutputToContain('Nothing to migrate')->assertSuccessful();
        $this->assertSame(['events', 'migrations', 'sessions'], $this->analyticsTables());
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function clickhouseMigrationConnections(): array
    {
        return [
            'default connection, fix_default_query_builder on' => [true, true],
            'default connection, fix_default_query_builder off' => [true, false],
            'named connection, fix_default_query_builder on' => [false, true],
            'named connection, fix_default_query_builder off' => [false, false],
        ];
    }

    /**
     * Every migration command keeps the migrations table of a ClickHouse connection in step with its tables.
     *
     * With fix_default_query_builder on, migrate:rollback, with or without --pretend, --step or --batch, failed with
     * 'Call to undefined method ClickHouseDB\Statement::all()': Laravel's repository read the table with the package
     * Builder. migrate:refresh could skip a migration that its rollback had just removed from the table, because
     * ClickHouse deleted the row in the background.
     */
    #[DataProvider('clickhouseMigrationConnections')]
    public function testEveryMigrationCommandKeepsTheMigrationsTableInStep(bool $isDefault, bool $fixDefaultQueryBuilder): void
    {
        $this->app['config']->set('database.connections.' . self::ANALYTICS . '.fix_default_query_builder', $fixDefaultQueryBuilder);
        DB::purge(self::ANALYTICS);
        $arguments = ['--path' => $this->workDir . '/clickhouse-only', '--realpath' => true];
        if ($isDefault) {
            $this->app['config']->set('database.default', self::ANALYTICS);
        } else {
            $arguments['--database'] = self::ANALYTICS;
        }
        $this->writeSessionsMigration();
        $both = ['events', 'migrations', 'sessions'];

        $this->artisan('migrate', $arguments + ['--step' => true])->assertSuccessful();
        $this->assertSame([[self::EVENTS_MIGRATION, 1], [self::SESSIONS_MIGRATION, 2]], $this->analyticsBatches());
        $this->assertSame($both, $this->analyticsTables());

        $this->artisan('migrate:status', $arguments)
            ->expectsOutputToContain('[1] Ran')
            ->expectsOutputToContain('[2] Ran')
            ->assertSuccessful();

        $this->artisan('migrate:rollback', $arguments + ['--pretend' => true])
            ->expectsOutputToContain('DROP TABLE IF EXISTS sessions')
            ->assertSuccessful();
        $this->assertSame([[self::EVENTS_MIGRATION, 1], [self::SESSIONS_MIGRATION, 2]], $this->analyticsBatches());
        $this->assertSame($both, $this->analyticsTables());

        $this->artisan('migrate:rollback', $arguments)->assertSuccessful();
        $this->assertSame([[self::EVENTS_MIGRATION, 1]], $this->analyticsBatches());
        $this->assertSame(['events', 'migrations'], $this->analyticsTables());

        $this->artisan('migrate', $arguments)->assertSuccessful();
        $this->assertSame([[self::EVENTS_MIGRATION, 1], [self::SESSIONS_MIGRATION, 2]], $this->analyticsBatches());

        $this->artisan('migrate:rollback', $arguments + ['--step' => 2])->assertSuccessful();
        $this->assertSame([], $this->analyticsBatches());
        $this->assertSame(['migrations'], $this->analyticsTables());

        $this->artisan('migrate', $arguments)->assertSuccessful();
        $this->assertSame([[self::EVENTS_MIGRATION, 1], [self::SESSIONS_MIGRATION, 1]], $this->analyticsBatches());

        $this->artisan('migrate:rollback', $arguments + ['--batch' => 1])->assertSuccessful();
        $this->assertSame([], $this->analyticsBatches());
        $this->assertSame(['migrations'], $this->analyticsTables());

        $this->artisan('migrate', $arguments)->assertSuccessful();
        $this->clickhouse(self::ANALYTICS)->write('INSERT INTO events VALUES (1)');

        $this->artisan('migrate:refresh', $arguments)->assertSuccessful();
        $this->assertSame([[self::EVENTS_MIGRATION, 1], [self::SESSIONS_MIGRATION, 1]], $this->analyticsBatches());
        $this->assertSame($both, $this->analyticsTables());
        $this->assertSame(0, $this->eventCount());

        $this->artisan('migrate:reset', $arguments)->assertSuccessful();
        $this->assertSame([], $this->analyticsBatches());
        $this->assertSame(['migrations'], $this->analyticsTables());

        $this->artisan('migrate:fresh', $arguments)->assertSuccessful();
        $this->assertSame([[self::EVENTS_MIGRATION, 1], [self::SESSIONS_MIGRATION, 1]], $this->analyticsBatches());
        $this->assertSame($both, $this->analyticsTables());
    }

    /**
     * A migrations table that 3.0.0 created, with the rows that its repository wrote, is read and written as it
     * is: 3.0.0 created it with CREATE TABLE migrations (id Int32, migration String, batch Int32) ENGINE = MergeTree()
     * ORDER BY (id) and inserted each row without an id.
     */
    public function testAMigrationsTableOfVersionThreeIsReadAndWritten(): void
    {
        $client = $this->clickhouse(self::ANALYTICS);
        $client->write("CREATE TABLE migrations (\n                id Int32,\nmigration String,\nbatch Int32\n            )\n"
            . "            ENGINE = MergeTree()\n            ORDER BY (id)");
        $client->insert('migrations', [[self::EVENTS_MIGRATION, 1]], ['migration', 'batch']);
        $client->write('CREATE TABLE events (id UInt64) ENGINE = MergeTree ORDER BY id');
        $columns = $this->migrationsTableColumns();
        $this->writeSessionsMigration();
        $arguments = [
            '--database' => self::ANALYTICS,
            '--path' => $this->workDir . '/clickhouse-only',
            '--realpath' => true,
        ];

        $this->artisan('migrate', $arguments)->assertSuccessful();
        $this->assertSame([[self::EVENTS_MIGRATION, 1], [self::SESSIONS_MIGRATION, 2]], $this->analyticsBatches());
        $this->assertSame(['events', 'migrations', 'sessions'], $this->analyticsTables());

        $this->artisan('migrate:rollback', $arguments)->assertSuccessful();
        $this->artisan('migrate:rollback', $arguments)->assertSuccessful();
        $this->assertSame([], $this->analyticsBatches());
        $this->assertSame(['migrations'], $this->analyticsTables());
        $this->assertSame($columns, $this->migrationsTableColumns());
    }

    /**
     * The migrations table that the repository creates has the columns of 3.0.0 and keeps every row, whatever the
     * connection's exact_integer_types and engine options say: with ReplacingMergeTree, the rows, which all have the
     * id 0, would be merged into one.
     */
    public function testTheMigrationsTableKeepsTheColumnsOfVersionThreeAndEveryRow(): void
    {
        $this->app['config']->set('database.connections.' . self::ANALYTICS . '.exact_integer_types', true);
        $this->app['config']->set('database.connections.' . self::ANALYTICS . '.engine', 'ReplacingMergeTree()');
        $this->writeSessionsMigration();

        $this->artisan('migrate', [
            '--database' => self::ANALYTICS,
            '--path' => $this->workDir . '/clickhouse-only',
            '--realpath' => true,
            '--step' => true,
        ])->assertSuccessful();
        $this->clickhouse(self::ANALYTICS)->write('OPTIMIZE TABLE migrations FINAL');

        $this->assertSame([['id', 'Int32'], ['migration', 'String'], ['batch', 'Int32']], $this->migrationsTableColumns());
        // ClickHouse 26.8 writes the sorting key of ORDER BY (`id`) as (id), 24.8 and 26.3 as id.
        $this->assertSame(
            [['engine' => 'MergeTree', 'sorting_key' => 'id']],
            array_map(
                fn (array $row): array => ['engine' => $row['engine'], 'sorting_key' => trim($row['sorting_key'], '()')],
                $this->clickhouse(self::ANALYTICS)->select(
                    "SELECT engine, sorting_key FROM system.tables WHERE database = '" . $this->databaseOf(self::ANALYTICS)
                    . "' AND name = 'migrations'"
                )->rows()
            )
        );
        $this->assertSame([[self::EVENTS_MIGRATION, 1], [self::SESSIONS_MIGRATION, 2]], $this->analyticsBatches());
    }

    /**
     * A migrations table that drops an inserted block identical to an earlier one keeps the row of a migration that
     * is rolled back and migrated again in the same batch, since each row goes in with an insert_deduplication_token
     * of its own. A MergeTree table with a non_replicated_deduplication_window, as here, drops such a block on every
     * version, and so does a replicated table without replicated_deduplication_window = 0.
     */
    public function testAMigrationLoggedAgainIsKeptByAMigrationsTableThatDeduplicates(): void
    {
        $this->clickhouse(self::ANALYTICS)->write('CREATE TABLE migrations (id Int32, migration String, batch Int32)'
            . ' ENGINE = MergeTree ORDER BY id SETTINGS non_replicated_deduplication_window = 100');
        $arguments = [
            '--database' => self::ANALYTICS,
            '--path' => $this->workDir . '/clickhouse-only',
            '--realpath' => true,
        ];

        $this->artisan('migrate', $arguments)->assertSuccessful();
        $this->artisan('migrate:rollback', $arguments)->assertSuccessful();
        $this->assertSame([], $this->analyticsBatches());

        $this->artisan('migrate', $arguments)->assertSuccessful();

        $this->assertSame([[self::EVENTS_MIGRATION, 1]], $this->analyticsBatches());
        $this->assertSame(['events', 'migrations'], $this->analyticsTables());
        $this->artisan('migrate:status', $arguments)->expectsOutputToContain('[1] Ran')->assertSuccessful();

        // The table drops an identical insert that has no token.
        $this->clickhouse(self::ANALYTICS)->write("INSERT INTO migrations (migration, batch) VALUES ('again', 9)");
        $this->clickhouse(self::ANALYTICS)->write("INSERT INTO migrations (migration, batch) VALUES ('again', 9)");
        $this->assertSame([[self::EVENTS_MIGRATION, 1], ['again', 9]], $this->analyticsBatches());
    }

    /**
     * The migrations are logged, and the rows of a schema dump are loaded, synchronously, also on a connection whose
     * settings say async_insert = 1, the default of ClickHouse 26.x, and wait_for_async_insert = 0. Such an insert
     * returns before its rows are written: the migrations table could miss a migration that had just run, and
     * migrate with a schema dump read the table before the dump's rows were written, ran the dump's migrations again
     * and failed with TABLE_ALREADY_EXISTS.
     */
    public function testMigrationsAreRecordedAtOnceOnAConnectionThatInsertsAsynchronously(): void
    {
        $settings = 'database.connections.' . self::ANALYTICS . '.settings';
        $this->app['config']->set(
            $settings,
            ['async_insert' => 1, 'wait_for_async_insert' => 0] + $this->app['config']->get($settings, [])
        );
        DB::purge(self::ANALYTICS);
        $this->writeSessionsMigration();
        $arguments = [
            '--database' => self::ANALYTICS,
            '--path' => $this->workDir . '/clickhouse-only',
            '--realpath' => true,
        ];
        $both = [[self::EVENTS_MIGRATION, 1], [self::SESSIONS_MIGRATION, 2]];

        $this->artisan('migrate', $arguments + ['--step' => true])->assertSuccessful();
        $this->assertSame($both, $this->analyticsBatches());

        $this->artisan('migrate:rollback', $arguments)->assertSuccessful();
        $this->assertSame([[self::EVENTS_MIGRATION, 1]], $this->analyticsBatches());
        $this->assertSame(['events', 'migrations'], $this->analyticsTables());

        $this->artisan('migrate', $arguments)->assertSuccessful();
        $this->assertSame($both, $this->analyticsBatches());

        $this->artisan('schema:dump', ['--database' => self::ANALYTICS])->assertSuccessful();
        $this->artisan('db:wipe', ['--database' => self::ANALYTICS])->assertSuccessful();
        $this->assertSame([], $this->analyticsTables());

        $this->artisan('migrate', $arguments)->expectsOutputToContain('Nothing to migrate')->assertSuccessful();
        $this->assertSame($both, $this->analyticsBatches());
        $this->assertSame(['events', 'migrations', 'sessions'], $this->analyticsTables());
    }

    public function testWipeDropsObjectsThatDependOnEachOther(): void
    {
        $client = $this->clickhouse(self::ANALYTICS);
        $database = $this->databaseOf(self::ANALYTICS);
        $client->write('CREATE TABLE src (id UInt64, s String) ENGINE = MergeTree ORDER BY id');
        $client->write('CREATE TABLE agg (id UInt64, n UInt64) ENGINE = SummingMergeTree ORDER BY id');
        $client->write('CREATE MATERIALIZED VIEW to_agg TO agg AS SELECT id, count() AS n FROM src GROUP BY id');
        $client->write('CREATE MATERIALIZED VIEW with_inner ENGINE = MergeTree ORDER BY id AS SELECT id FROM src');
        $client->write('CREATE VIEW plain AS SELECT * FROM src');
        $client->write("CREATE DICTIONARY dict (id UInt64, s String) PRIMARY KEY id"
            . " SOURCE(CLICKHOUSE(DB '{$database}' TABLE 'src')) LIFETIME(0) LAYOUT(FLAT())");
        $client->write("CREATE TABLE uses_dict (id UInt64, s String DEFAULT dictGet('{$database}.dict', 's', id))"
            . ' ENGINE = MergeTree ORDER BY id');

        Schema::connection(self::ANALYTICS)->dropAllViews();
        $this->assertSame(['agg', 'dict', 'src', 'uses_dict'], $this->analyticsTables());

        $this->artisan('db:wipe', ['--database' => self::ANALYTICS])->assertSuccessful();
        $this->assertSame([], $this->tables(self::ANALYTICS, includeInner: true));
    }

    /**
     * Write a second migration for the ClickHouse-only path, which creates the table sessions.
     */
    private function writeSessionsMigration(): void
    {
        $this->files->put($this->workDir . '/clickhouse-only/' . self::SESSIONS_MIGRATION . '.php', <<<'PHP'
            <?php

            return new class extends \Oralunal\LaravelClickHouse\Migration {
                protected $connection = 'phpch_scenario';

                public function up(): void
                {
                    static::write('CREATE TABLE sessions (id UInt64) ENGINE = MergeTree ORDER BY id');
                }

                public function down(): void
                {
                    static::write('DROP TABLE IF EXISTS sessions');
                }
            };
            PHP);
    }

    /**
     * Get each migration and its batch from the migrations table of the ClickHouse connection the migrations manage.
     *
     * @return list<array{string, int}>
     */
    private function analyticsBatches(): array
    {
        return array_map(
            fn (array $row): array => [$row['migration'], (int) $row['batch']],
            $this->clickhouse(self::ANALYTICS)->select('SELECT migration, batch FROM migrations ORDER BY migration, batch')->rows()
        );
    }

    /**
     * Get the name and the type of each column of the migrations table of the ClickHouse connection the migrations
     * manage.
     *
     * @return list<array{string, string}>
     */
    private function migrationsTableColumns(): array
    {
        return array_map(
            fn (array $row): array => [$row['name'], $row['type']],
            $this->clickhouse('clickhouse')->select(
                "SELECT name, type FROM system.columns WHERE database = '" . $this->databaseOf(self::ANALYTICS)
                . "' AND table = 'migrations'"
                . ' ORDER BY position'
            )->rows()
        );
    }

    private function clickhouse(string $connection): \ClickHouseDB\Client
    {
        return DB::connection($connection)->getClient();
    }

    private function migrationPath(string $migration): string
    {
        return database_path("migrations/{$migration}.php");
    }

    /**
     * Write the dump `schema:dump` produces for the SQLite primary once both migrations ran.
     */
    private function writeSqliteDump(): void
    {
        $this->files->put(database_path('schema/sqlite-schema.sql'), implode(PHP_EOL, [
            'CREATE TABLE IF NOT EXISTS "migrations" ("id" integer primary key autoincrement not null,'
            . ' "migration" varchar not null, "batch" integer not null);',
            'CREATE TABLE IF NOT EXISTS "users" ("id" integer primary key autoincrement not null,'
            . ' "name" varchar not null);',
            "INSERT INTO migrations VALUES(1,'" . self::USERS_MIGRATION . "',1);",
            "INSERT INTO migrations VALUES(2,'" . self::EVENTS_MIGRATION . "',1);",
        ]) . PHP_EOL);
    }

    /**
     * @return string[]
     */
    private function ranMigrations(): array
    {
        return DB::connection('sqlite')->table('migrations')->orderBy('migration')->pluck('migration')->all();
    }

    /**
     * @return string[]
     */
    private function clickhouseMigrations(string $connection): array
    {
        return array_column(
            $this->clickhouse($connection)->select('SELECT migration FROM migrations ORDER BY migration')->rows(),
            'migration'
        );
    }

    /**
     * @return string[]
     */
    private function analyticsTables(): array
    {
        return $this->tables(self::ANALYTICS);
    }

    /**
     * Get the tables of the database of a connection that the test makes.
     *
     * @return string[]
     */
    private function tables(string $connection, bool $includeInner = false): array
    {
        $inner = $includeInner ? '' : " AND NOT startsWith(name, '.inner')";

        return array_column($this->clickhouse('clickhouse')->select(
            "SELECT name FROM system.tables WHERE database = '{$this->databaseOf($connection)}'{$inner} ORDER BY name"
        )->rows(), 'name');
    }

    /**
     * Get the database of a ClickHouse connection that the test makes: the connection's own name.
     */
    protected function databaseOf(string $connection): string
    {
        return $connection;
    }

    private function eventCount(): int
    {
        return (int) $this->clickhouse(self::ANALYTICS)->select('SELECT count() AS c FROM events')->rows()[0]['c'];
    }
}
