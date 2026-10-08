<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Testing;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\ParallelTesting;
use Oralunal\LaravelClickHouse\SecondaryConnections;

/**
 * Gives each parallel test process its own database on the secondary ClickHouse
 * connections, as Laravel's TestDatabases does for the default connection.
 *
 * Without it, every process shares one ClickHouse database, and the
 * `migrate:fresh` of one process drops the tables that another process is
 * creating or using.
 */
class ParallelTestDatabases
{
    /**
     * Test traits that make Laravel switch to a per-process database.
     *
     * @var string[]
     */
    protected const DATABASE_TRAITS = [
        DatabaseMigrations::class,
        DatabaseTransactions::class,
        DatabaseTruncation::class,
        RefreshDatabase::class,
    ];

    /**
     * Per-process databases this process already created, keyed by connection and database.
     *
     * @var array<string, true>
     */
    protected static array $created = [];

    /**
     * Secondary connections found in this process, keyed by default connection and database path.
     *
     * @var array<string, string[]>
     */
    protected static array $connections = [];

    public function __construct(
        protected Application $app,
    ) {
    }

    /**
     * Register the parallel testing callbacks.
     *
     * @param ParallelTesting $parallelTesting
     * @return void
     */
    public function register(ParallelTesting $parallelTesting): void
    {
        $parallelTesting->setUpProcess(function () use ($parallelTesting): void {
            if ($parallelTesting->option('recreate_databases')) {
                $this->dropTestDatabases($parallelTesting);
            }
        });

        $parallelTesting->setUpTestCase(function ($testCase) use ($parallelTesting): void {
            $this->switchToTestDatabases($parallelTesting, $testCase);
        });

        $parallelTesting->tearDownProcess(function () use ($parallelTesting): void {
            if ($parallelTesting->option('drop_databases')) {
                $this->dropTestDatabases($parallelTesting);
            }
        });
    }

    /**
     * Point every secondary connection at this process's database, creating it once.
     *
     * @param ParallelTesting $parallelTesting
     * @param object $testCase
     * @return void
     */
    protected function switchToTestDatabases(ParallelTesting $parallelTesting, object $testCase): void
    {
        if ($parallelTesting->option('without_databases')
            || !array_intersect(self::DATABASE_TRAITS, class_uses_recursive($testCase))) {
            return;
        }

        foreach ($this->connections() as $name) {
            $testDatabase = $this->testDatabase($name, (string) $parallelTesting->token());

            if (!isset(static::$created["{$name}:{$testDatabase}"])) {
                $this->app['db']->connection($name)->getSchemaBuilder()->createDatabase($testDatabase);
                static::$created["{$name}:{$testDatabase}"] = true;
            }

            $this->app['config']->set("database.connections.{$name}.database", $testDatabase);
            $this->app['db']->purge($name);
        }
    }

    /**
     * Drop this process's database on every secondary connection.
     *
     * @param ParallelTesting $parallelTesting
     * @return void
     */
    protected function dropTestDatabases(ParallelTesting $parallelTesting): void
    {
        if ($parallelTesting->option('without_databases')) {
            return;
        }

        foreach ($this->connections() as $name) {
            $testDatabase = $this->testDatabase($name, (string) $parallelTesting->token());

            $this->app['db']->connection($name)->getSchemaBuilder()->dropDatabaseIfExists($testDatabase);
            unset(static::$created["{$name}:{$testDatabase}"]);
        }
    }

    /**
     * Get the secondary ClickHouse connections of the default connection.
     *
     * Finding them reads every migration file, so it happens once per process
     * rather than once per test case.
     *
     * @return string[]
     */
    protected function connections(): array
    {
        $key = $this->app['db']->getDefaultConnection() . '|' . $this->app->databasePath();

        return static::$connections[$key] ??= $this->app->make(SecondaryConnections::class)->names();
    }

    /**
     * Get the per-process database name, `<database>_test_<token>` like Laravel's.
     *
     * @param string $name
     * @param string $token
     * @return string
     */
    protected function testDatabase(string $name, string $token): string
    {
        $database = $this->app['config']->get("database.connections.{$name}.database");
        $suffix = "_test_{$token}";

        return str_ends_with($database, $suffix) ? $database : $database . $suffix;
    }
}
