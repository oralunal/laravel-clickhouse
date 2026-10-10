<?php

declare(strict_types=1);

namespace Tests\HybridTesting;

use ClickHouseDB\Client;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Oralunal\LaravelClickHouse\ClickhouseServiceProvider;

/**
 * Laravel's RefreshDatabase and DatabaseTruncation in one test class, for an application whose default connection is
 * an in-memory SQLite database and whose migrations also write to ClickHouse: the SQLite rows are rolled back, the
 * ClickHouse tables are truncated, and the first migrate:fresh empties the ClickHouse tables of an earlier run.
 *
 * migrate:fresh empties the whole ClickHouse database of the migrations, so the ClickHouse connection of this class
 * uses a database of its own, which the class creates and drops.
 */
class SqliteWithClickHouseTest extends TestCase
{
    use DatabaseTruncation;
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    protected $connectionsToTransact = ['sqlite'];

    /**
     * @var list<string>
     */
    protected $connectionsToTruncate = ['clickhouse'];

    private const DATABASE = 'hybrid_testing';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        self::client()->write('DROP DATABASE IF EXISTS ' . self::DATABASE . ' SYNC');
        self::client()->write('CREATE DATABASE ' . self::DATABASE);
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        self::client()->write('DROP DATABASE IF EXISTS ' . self::DATABASE . ' SYNC');

        parent::tearDownAfterClass();
    }

    /**
     * A client on the default database of the test server, to create and drop the database of this class.
     */
    private static function client(): Client
    {
        $client = new Client([
            'host' => getenv('CLICKHOUSE_HOST') ?: '127.0.0.1',
            'port' => getenv('CLICKHOUSE_PORT') ?: '18123',
            'username' => getenv('CLICKHOUSE_USERNAME') ?: 'default',
            'password' => (string) getenv('CLICKHOUSE_PASSWORD'),
        ]);
        $client->database(getenv('CLICKHOUSE_DATABASE') ?: 'default');

        return $client;
    }

    protected function getPackageProviders($app): array
    {
        return [ClickhouseServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        $app['config']->set('database.connections.clickhouse', [
            'driver' => 'clickhouse',
            'host' => env('CLICKHOUSE_HOST', '127.0.0.1'),
            'port' => env('CLICKHOUSE_PORT', '18123'),
            'database' => self::DATABASE,
            'username' => env('CLICKHOUSE_USERNAME', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', ''),
            'fix_default_query_builder' => true,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/migrations');
    }

    /**
     * A table that an earlier run left behind: the first migrate:fresh must drop it, or the migration that creates
     * it fails with TABLE_ALREADY_EXISTS.
     */
    protected function beforeRefreshingDatabase(): void
    {
        if (!RefreshDatabaseState::$migrated) {
            $client = DB::connection('clickhouse')->getClient();
            $client->write('CREATE TABLE IF NOT EXISTS hybrid_events (id UInt32, user_id UInt32) ENGINE = MergeTree ORDER BY id');
            $client->write('INSERT INTO hybrid_events VALUES (99, 99)');
        }
    }

    public function test_first_test_starts_empty_and_writes_rows(): void
    {
        $this->assertStartsEmptyAndWrite();
    }

    public function test_second_test_starts_empty_and_writes_rows(): void
    {
        $this->assertStartsEmptyAndWrite();
    }

    private function assertStartsEmptyAndWrite(): void
    {
        $this->assertSame(0, DB::table('hybrid_users')->count());
        $this->assertSame(0, (int) DB::connection('clickhouse')->select('SELECT count() AS c FROM hybrid_events')[0]['c']);

        DB::table('hybrid_users')->insert(['name' => 'Ada']);
        DB::connection('clickhouse')->table('hybrid_events')->insert(['id' => 1, 'user_id' => 1]);

        $this->assertSame(1, DB::table('hybrid_users')->count());
        $this->assertSame(1, (int) DB::connection('clickhouse')->select('SELECT count() AS c FROM hybrid_events')[0]['c']);
    }
}
