<?php

namespace Tests;

use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class SchemaDumpTest extends TestCase
{
    private const SOURCE = 'phpch_schema_source';
    private const TARGET = 'phpch_schema_target';

    /** Connections to the two ClickHouse servers behind `clickhouse-cluster`. */
    private const NODES = ['clickhouse', 'clickhouse2'];

    private string $workDir;

    /** @var array<string, mixed> */
    private array $defaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/phpch-schema-' . bin2hex(random_bytes(4));
        (new Filesystem())->ensureDirectoryExists($this->workDir . '/empty');
        // Dumps of secondary connections are written under database_path().
        $this->app->useDatabasePath($this->workDir . '/database');

        $this->defaultConnection = $this->app['config']->get('database.connections.clickhouse');
        foreach ([self::SOURCE, self::TARGET] as $database) {
            $this->app['config']->set(
                "database.connections.{$database}",
                ['database' => $database] + $this->defaultConnection
            );
            $this->recreateDatabase($database);
        }

        // Created out of name order: a_events_view depends on events_view.
        $source = DB::connection(self::SOURCE)->getClient();
        $source->write('CREATE TABLE events (id UInt64, name String DEFAULT \'a;b\') ENGINE = MergeTree ORDER BY id');
        $source->write('CREATE VIEW events_view AS SELECT id, name FROM events');
        $source->write('CREATE VIEW a_events_view AS SELECT id FROM events_view');

        $repository = new DatabaseMigrationRepository($this->app['db'], 'migrations');
        $repository->setSource(self::SOURCE);
        $repository->createRepository();
        // Rows go in through the client: the repository's query builder always
        // targets the default `clickhouse` connection.
        $source->write("INSERT INTO migrations (id, migration, batch) VALUES"
            . " (0, '2024_01_02_000000_create_events_views', 2), (0, '2024_01_01_000000_create_events_table', 1)");
    }

    protected function tearDown(): void
    {
        $this->app['config']->set('database.connections.clickhouse', $this->defaultConnection);
        DB::purge('clickhouse');
        foreach (self::NODES as $node) {
            foreach ([self::SOURCE, self::TARGET] as $database) {
                DB::connection($node)->getClient()->write("DROP DATABASE IF EXISTS {$database} SYNC");
            }
        }
        (new Filesystem())->deleteDirectory($this->workDir);

        parent::tearDown();
    }

    protected function migratorPaths(): array
    {
        return [];
    }

    public function testDumpWritesPortableDdlInDependencyOrderWithMigrationData(): void
    {
        $path = $this->workDir . '/schema.sql';

        $this->assertSame(0, Artisan::call('schema:dump', ['--database' => self::SOURCE, '--path' => $path]));

        $dump = file_get_contents($path);
        $this->assertStringNotContainsString(self::SOURCE, $dump);
        $this->assertStringContainsString("`name` String DEFAULT 'a;b'", $dump);
        $this->assertStringContainsString("FROM events_view;", $dump);
        $this->assertStringContainsString(
            "INSERT INTO `migrations` VALUES\n"
            . "(0,'2024_01_01_000000_create_events_table',1),\n"
            . "(0,'2024_01_02_000000_create_events_views',2);",
            $dump
        );

        $positions = array_map(fn (string $header) => strpos($dump, $header), [
            'CREATE TABLE events',
            'CREATE TABLE migrations',
            'CREATE VIEW events_view',
            'CREATE VIEW a_events_view',
            'INSERT INTO `migrations`',
        ]);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    public function testDumpWithoutMigrationDataOmitsInsert(): void
    {
        $path = $this->workDir . '/schema.sql';

        Artisan::call('schema:dump', [
            '--database' => self::SOURCE,
            '--path' => $path,
            '--without-migration-data' => true,
        ]);

        $dump = file_get_contents($path);
        $this->assertStringContainsString('CREATE TABLE migrations', $dump);
        $this->assertStringNotContainsString('INSERT INTO', $dump);
    }

    public function testPruneDeletesMigrationsDirectory(): void
    {
        (new Filesystem())->ensureDirectoryExists($this->workDir . '/database/migrations');
        touch($this->workDir . '/database/migrations/2024_01_01_000000_create_events_table.php');

        $this->assertSame(0, Artisan::call('schema:dump', ['--database' => self::SOURCE, '--prune' => true]));

        $this->assertFileExists($this->workDir . '/database/schema/' . self::SOURCE . '-schema.sql');
        $this->assertDirectoryDoesNotExist($this->workDir . '/database/migrations');
    }

    public function testMigrateLoadsDumpIntoFreshDatabase(): void
    {
        $path = $this->workDir . '/schema.sql';
        Artisan::call('schema:dump', ['--database' => self::SOURCE, '--path' => $path]);

        // The migration repository only works on the default `clickhouse`
        // connection, so point that one at the fresh database.
        $this->app['config']->set('database.connections.clickhouse.database', self::TARGET);
        DB::purge('clickhouse');

        $exitCode = Artisan::call('migrate', [
            '--database' => 'clickhouse',
            '--schema-path' => $path,
            '--path' => $this->workDir . '/empty',
            '--realpath' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $target = DB::connection(self::TARGET)->getClient();
        $tables = array_column(
            $target->select("SELECT name FROM system.tables WHERE database = '" . self::TARGET . "' ORDER BY name")->rows(),
            'name'
        );
        $this->assertSame(['a_events_view', 'events', 'events_view', 'migrations'], $tables);
        $this->assertSame(
            ['2024_01_01_000000_create_events_table', '2024_01_02_000000_create_events_views'],
            array_column($target->select('SELECT migration FROM migrations ORDER BY batch')->rows(), 'migration')
        );

        $target->write("INSERT INTO events (id) VALUES (1)");
        $this->assertEquals([['id' => 1]], $target->select('SELECT id FROM a_events_view')->rows());
    }

    /**
     * Needs two servers: with both node connections on one server, as when the suite runs against a single
     * ClickHouse container, the second node's CREATE finds the tables of the first and fails.
     */
    public function testLoadWritesToEveryClusterNode(): void
    {
        if (env('CLICKHOUSE_HOST', '127.0.0.1') . ':' . env('CLICKHOUSE_PORT', '18123')
            === env('CLICKHOUSE2_HOST', '127.0.0.1') . ':' . env('CLICKHOUSE2_PORT', '18124')
        ) {
            $this->markTestSkipped('Both node connections point at one ClickHouse server; set CLICKHOUSE2_PORT to a second one');
        }

        $path = $this->workDir . '/schema.sql';
        Artisan::call('schema:dump', ['--database' => self::SOURCE, '--path' => $path]);

        $this->app['config']->set('database.connections.clickhouse-cluster.database', self::TARGET);
        DB::purge('clickhouse-cluster');
        DB::connection('clickhouse-cluster')->getSchemaState()->load($path);

        foreach (self::NODES as $node) {
            $this->assertSame(
                ['a_events_view', 'events', 'events_view', 'migrations'],
                array_column(DB::connection($node)->getClient()->select(
                    "SELECT name FROM system.tables WHERE database = '" . self::TARGET . "' ORDER BY name"
                )->rows(), 'name'),
                "Schema not loaded on node '{$node}'"
            );
        }
    }

    private function recreateDatabase(string $database): void
    {
        foreach (self::NODES as $node) {
            $client = DB::connection($node)->getClient();
            $client->write("DROP DATABASE IF EXISTS {$database} SYNC");
            $client->write("CREATE DATABASE {$database}");
        }
    }
}
