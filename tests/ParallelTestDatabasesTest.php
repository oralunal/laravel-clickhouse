<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;

/**
 * Parallel test processes (`php artisan test --parallel`) each get their own
 * database on the secondary ClickHouse connections, so the `migrate:fresh` of
 * one process cannot drop the tables of another.
 *
 * The tests drive Laravel's parallel testing hooks directly, with the
 * environment variables paratest sets for each process.
 */
class ParallelTestDatabasesTest extends TestCase
{
    /** Secondary ClickHouse connection (and base database) the migrations write to. */
    private const ANALYTICS = 'phpch_parallel';

    private const PARALLEL_SERVER_KEYS = [
        'LARAVEL_PARALLEL_TESTING',
        'TEST_TOKEN',
        'LARAVEL_PARALLEL_TESTING_RECREATE_DATABASES',
        'LARAVEL_PARALLEL_TESTING_DROP_DATABASES',
        'LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES',
    ];

    private string $workDir;

    private Filesystem $files;

    /** Token prefix that is unique to this test, so per-process databases never clash. */
    private string $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem();
        $this->run = bin2hex(random_bytes(3));
        $this->workDir = sys_get_temp_dir() . '/phpch-parallel-' . $this->run;
        $this->files->ensureDirectoryExists($this->workDir . '/database/migrations');
        $this->files->ensureDirectoryExists($this->workDir . '/clickhouse-only');
        $this->app->useDatabasePath($this->workDir . '/database');

        $config = $this->app['config'];
        $config->set(
            'database.connections.' . self::ANALYTICS,
            ['database' => self::ANALYTICS] + $config->get('database.connections.clickhouse')
        );
        $this->clickhouse()->write('DROP DATABASE IF EXISTS ' . self::ANALYTICS . ' SYNC');
        $this->clickhouse()->write('CREATE DATABASE ' . self::ANALYTICS);

        $config->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $config->set('database.default', 'sqlite');

        $events = <<<'PHP'
            <?php

            return new class extends \PhpClickHouseLaravel\Migration {
                protected $connection = 'phpch_parallel';

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
        $this->files->put($this->workDir . '/database/migrations/2024_01_01_000000_create_events_table.php', $events);
        $this->files->put($this->workDir . '/clickhouse-only/2024_01_01_000000_create_events_table.php', $events);
    }

    protected function tearDown(): void
    {
        foreach (self::PARALLEL_SERVER_KEYS as $key) {
            unset($_SERVER[$key]);
        }

        $databases = array_column($this->clickhouse()->select(
            "SELECT name FROM system.databases WHERE name LIKE '" . self::ANALYTICS . "%'"
        )->rows(), 'name');
        foreach ($databases as $database) {
            $this->clickhouse()->write("DROP DATABASE IF EXISTS {$database} SYNC");
        }
        $this->files->deleteDirectory($this->workDir);

        parent::tearDown();
    }

    protected function migratorPaths(): array
    {
        return [];
    }

    public function testEachProcessKeepsItsOwnClickhouseTables(): void
    {
        $this->startProcess('1');
        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->clickhouse(self::ANALYTICS)->write('INSERT INTO events VALUES (1)');

        $this->startProcess('2');
        $this->artisan('migrate:fresh')->assertSuccessful();

        $this->assertSame(['events'], $this->tables($this->testDatabase('1')));
        $this->assertSame(1, $this->eventCount($this->testDatabase('1')));
        $this->assertSame(['events'], $this->tables($this->testDatabase('2')));
        $this->assertSame(0, $this->eventCount($this->testDatabase('2')));
        $this->assertSame([], $this->tables(self::ANALYTICS));
    }

    public function testEveryTestCaseOfAProcessUsesTheSameDatabase(): void
    {
        $this->startProcess('1');
        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->clickhouse(self::ANALYTICS)->write('INSERT INTO events VALUES (1)');

        // The next test case of the same process starts from the original configuration.
        $this->startProcess('1');

        $this->assertSame($this->testDatabase('1'), $this->databaseOf(self::ANALYTICS));
        $this->assertSame(1, $this->eventCount($this->testDatabase('1')));
    }

    public function testSequentialRunsKeepTheConfiguredDatabase(): void
    {
        ParallelTesting::callSetUpTestCaseCallbacks($this->testCaseUsingDatabase());

        $this->assertSame(self::ANALYTICS, $this->databaseOf(self::ANALYTICS));
        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->assertSame(['events'], $this->tables(self::ANALYTICS));
    }

    public function testTestCasesWithoutDatabaseTraitsKeepTheConfiguredDatabase(): void
    {
        $this->startProcess('1', new class {
        });

        $this->assertSame(self::ANALYTICS, $this->databaseOf(self::ANALYTICS));
    }

    public function testWithoutDatabasesOptionKeepsTheConfiguredDatabase(): void
    {
        $_SERVER['LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES'] = '1';

        $this->startProcess('1');

        $this->assertSame(self::ANALYTICS, $this->databaseOf(self::ANALYTICS));
        $this->assertNotContains($this->testDatabase('1'), $this->databases());
    }

    public function testDropDatabasesOptionDropsTheProcessDatabaseAtTheEnd(): void
    {
        $this->startProcess('1');
        $this->assertContains($this->testDatabase('1'), $this->databases());

        $_SERVER['LARAVEL_PARALLEL_TESTING_DROP_DATABASES'] = '1';
        $this->resetConnection();
        ParallelTesting::callTearDownProcessCallbacks();

        $this->assertNotContains($this->testDatabase('1'), $this->databases());
    }

    public function testRecreateDatabasesOptionStartsFromAnEmptyDatabase(): void
    {
        $this->startProcess('1');
        $this->artisan('migrate:fresh')->assertSuccessful();

        $_SERVER['LARAVEL_PARALLEL_TESTING_RECREATE_DATABASES'] = '1';
        $this->resetConnection();
        ParallelTesting::callSetUpProcessCallbacks();
        $this->assertNotContains($this->testDatabase('1'), $this->databases());

        $this->startProcess('1');
        $this->assertSame([], $this->tables($this->testDatabase('1')));
    }

    public function testClickhouseAsDefaultConnectionGetsLaravelsPerProcessDatabase(): void
    {
        $this->app['config']->set('database.default', self::ANALYTICS);
        DB::purge();

        $this->startProcess('1');

        $this->assertSame($this->testDatabase('1'), DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--path' => $this->workDir . '/clickhouse-only', '--realpath' => true])
            ->assertSuccessful();
        $this->assertSame(['events', 'migrations'], $this->tables($this->testDatabase('1')));
        $this->assertSame([], $this->tables(self::ANALYTICS));
    }

    /**
     * Run the parallel testing hooks of a test case in the process with the given token.
     */
    private function startProcess(string $token, ?object $testCase = null): void
    {
        $_SERVER['LARAVEL_PARALLEL_TESTING'] = '1';
        $_SERVER['TEST_TOKEN'] = $this->run . $token;

        $this->resetConnection();
        ParallelTesting::callSetUpTestCaseCallbacks($testCase ?? $this->testCaseUsingDatabase());
    }

    /**
     * Restore the configured database, as a new application of the next test case would.
     */
    private function resetConnection(): void
    {
        $this->app['config']->set('database.connections.' . self::ANALYTICS . '.database', self::ANALYTICS);
        DB::purge(self::ANALYTICS);
    }

    private function testCaseUsingDatabase(): object
    {
        return new class {
            use RefreshDatabase;
        };
    }

    private function testDatabase(string $token): string
    {
        return self::ANALYTICS . '_test_' . $this->run . $token;
    }

    private function databaseOf(string $connection): string
    {
        return $this->app['config']->get("database.connections.{$connection}.database");
    }

    private function clickhouse(string $connection = 'clickhouse'): \ClickHouseDB\Client
    {
        return DB::connection($connection)->getClient();
    }

    /**
     * @return string[]
     */
    private function databases(): array
    {
        return array_column($this->clickhouse()->select('SELECT name FROM system.databases')->rows(), 'name');
    }

    /**
     * @return string[]
     */
    private function tables(string $database): array
    {
        return array_column($this->clickhouse()->select(
            "SELECT name FROM system.tables WHERE database = '{$database}' AND NOT startsWith(name, '.inner') ORDER BY name"
        )->rows(), 'name');
    }

    private function eventCount(string $database): int
    {
        return (int) $this->clickhouse()->select("SELECT count() AS c FROM {$database}.events")->rows()[0]['c'];
    }
}
