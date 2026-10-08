<?php

namespace Tests;

use Illuminate\Database\Events\SchemaDumped;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            $config->set("database.connections.{$name}", ['database' => $name] + $clickhouse);
            $this->clickhouse('clickhouse')->write("DROP DATABASE IF EXISTS {$name} SYNC");
            $this->clickhouse('clickhouse')->write("CREATE DATABASE {$name}");
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
            $this->clickhouse('clickhouse')->write("DROP DATABASE IF EXISTS {$name} SYNC");
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
        $this->assertStringNotContainsString(self::ANALYTICS . '.', $dump);
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

    public function testWipeDropsObjectsThatDependOnEachOther(): void
    {
        $client = $this->clickhouse(self::ANALYTICS);
        $database = self::ANALYTICS;
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
     * @return string[]
     */
    private function tables(string $database, bool $includeInner = false): array
    {
        $inner = $includeInner ? '' : " AND NOT startsWith(name, '.inner')";

        return array_column($this->clickhouse('clickhouse')->select(
            "SELECT name FROM system.tables WHERE database = '{$database}'{$inner} ORDER BY name"
        )->rows(), 'name');
    }

    private function eventCount(): int
    {
        return (int) $this->clickhouse(self::ANALYTICS)->select('SELECT count() AS c FROM events')->rows()[0]['c'];
    }
}
