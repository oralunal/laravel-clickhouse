<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

/**
 * schema:dump and migrate --schema-path on a connection with a cluster_name and both nodes of the test cluster. Its
 * schema builder creates every table ON CLUSTER and replicated on the {uuid} replica path, the migrations table
 * included, which ClickHouse only accepts ON CLUSTER, so SchemaState::load() creates each object of the dump ON
 * CLUSTER and inserts the rows of a replicated migrations table once. A dump whose migrations table is not
 * replicated, as 3.0.0 made it, still gives its rows to the table of each node, and one whose migrations table has a
 * fixed replica path loads after the repository has dropped the table with SYNC.
 *
 * The connection works on a database of the test's own, which the test creates ON CLUSTER on both nodes and drops at
 * the end.
 */
class SchemaDumpClusterTest extends TestCase
{
    /** The connection that the test makes: clickhouse-cluster on the test's database. */
    private const CONNECTION = 'phpch_schema_cluster';

    /** The connections that read each node of the cluster on their own. */
    private const NODES = ['clickhouse', 'clickhouse2'];

    private const THINGS_MIGRATION = '2024_01_01_000000_create_things_table';
    private const VIEW_MIGRATION = '2024_01_02_000000_create_things_view';

    private string $workDir;

    private Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('CLICKHOUSE_CLUSTER_AVAILABLE')) {
            $this->markTestSkipped('company_cluster config requires docker-compose services');
        }

        $this->files = new Filesystem();
        $this->workDir = sys_get_temp_dir() . '/phpch-schema-cluster-' . bin2hex(random_bytes(4));
        $this->files->ensureDirectoryExists($this->workDir . '/migrations');
        $this->app->useDatabasePath($this->workDir . '/database');

        $database = $this->databaseName();
        $this->app['config']->set(
            'database.connections.' . self::CONNECTION,
            ['database' => $database, 'timeout_query' => 60] + $this->app['config']->get('database.connections.clickhouse-cluster')
        );
        $this->onTheCluster("DROP DATABASE IF EXISTS {$database} ON CLUSTER 'company_cluster' SYNC");
        $this->onTheCluster("CREATE DATABASE {$database} ON CLUSTER 'company_cluster'");

        $this->files->put($this->workDir . '/migrations/' . self::THINGS_MIGRATION . '.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\Schema;
            use Oralunal\LaravelClickHouse\SchemaBlueprint;

            return new class extends Migration {
                protected $connection = 'phpch_schema_cluster';

                public function up(): void
                {
                    Schema::connection('phpch_schema_cluster')->create('things', function (SchemaBlueprint $table) {
                        $table->integer('id');
                        $table->string('name');
                    });
                }

                public function down(): void
                {
                    Schema::connection('phpch_schema_cluster')->dropIfExistsSync('things');
                }
            };
            PHP);
        $this->files->put($this->workDir . '/migrations/' . self::VIEW_MIGRATION . '.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;

            return new class extends Migration {
                protected $connection = 'phpch_schema_cluster';

                public function up(): void
                {
                    $connection = DB::connection('phpch_schema_cluster');
                    $connection->statement(
                        "CREATE VIEW things_view ON CLUSTER 'company_cluster' AS SELECT id, name FROM things"
                    );
                    $connection->statement(
                        "CREATE MATERIALIZED VIEW things_mv ON CLUSTER 'company_cluster'"
                        . " ENGINE = ReplicatedMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}') ORDER BY id"
                        . ' AS SELECT id, name FROM things'
                    );
                    $connection->statement(
                        "CREATE DICTIONARY things_dict ON CLUSTER 'company_cluster' (id UInt64, name String)"
                        . " PRIMARY KEY id SOURCE(CLICKHOUSE(TABLE 'things')) LAYOUT(HASHED()) LIFETIME(0)"
                    );
                }

                public function down(): void
                {
                    $connection = DB::connection('phpch_schema_cluster');
                    $connection->statement("DROP DICTIONARY IF EXISTS things_dict ON CLUSTER 'company_cluster' SYNC");
                    $connection->statement("DROP VIEW IF EXISTS things_mv ON CLUSTER 'company_cluster' SYNC");
                    $connection->statement("DROP VIEW IF EXISTS things_view ON CLUSTER 'company_cluster' SYNC");
                }
            };
            PHP);
    }

    protected function tearDown(): void
    {
        if (isset($this->workDir)) {
            $this->onTheCluster('DROP DATABASE IF EXISTS ' . $this->databaseName() . " ON CLUSTER 'company_cluster' SYNC");
            $this->files->deleteDirectory($this->workDir);
        }

        parent::tearDown();
    }

    protected function migratorPaths(): array
    {
        return [];
    }

    /**
     * The tables, the view, the materialized view, the dictionary and the migrations rows of the dump exist on both
     * nodes after migrate --schema-path, each replicated table and the materialized view's inner table as one
     * replicated table, and the loaded migrations roll back and run again on both nodes, twice.
     */
    public function testMigrateLoadsTheDumpOfAClusterConnectionOnEveryNode(): void
    {
        $arguments = [
            '--database' => self::CONNECTION,
            '--path' => $this->workDir . '/migrations',
            '--realpath' => true,
        ];
        $path = $this->workDir . '/schema.sql';
        $tables = [
            ['migrations', 'ReplicatedMergeTree'],
            ['things', 'ReplicatedMergeTree'],
            ['things_dict', 'Dictionary'],
            ['things_mv', 'MaterializedView'],
            ['things_view', 'View'],
        ];
        $migrations = [[self::THINGS_MIGRATION, 1], [self::VIEW_MIGRATION, 1]];

        $this->artisan('migrate', $arguments)->assertSuccessful();
        $this->artisan('schema:dump', ['--database' => self::CONNECTION, '--path' => $path])->assertSuccessful();

        $dump = $this->files->get($path);
        $this->assertStringContainsString(
            "CREATE TABLE migrations\n(\n    `id` Int32,\n    `migration` String,\n    `batch` Int32\n)\n"
            . "ENGINE = ReplicatedMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}')",
            $dump
        );
        // The dump keeps both deduplication windows of the migrations table, so the table that it creates again keeps
        // every insert too.
        $this->assertMatchesRegularExpression(
            "/CREATE TABLE migrations\n[^;]*\nORDER BY \\(?id\\)?\nSETTINGS replicated_deduplication_window = 0,"
            . ' replicated_deduplication_window_for_async_inserts = 0, index_granularity = 8192;/',
            $dump
        );
        $this->assertStringContainsString("CREATE VIEW things_view\n", $dump);
        $this->assertStringContainsString("CREATE MATERIALIZED VIEW things_mv\n", $dump);
        $this->assertStringContainsString("CREATE DICTIONARY things_dict\n", $dump);
        $this->assertStringContainsString("INSERT INTO `migrations` VALUES\n", $dump);

        $this->artisan('db:wipe', ['--database' => self::CONNECTION])->assertSuccessful();
        foreach (self::NODES as $node) {
            $this->assertSame([], $this->tablesOn($node), "Tables left on {$node}");
        }

        $this->artisan('migrate', $arguments + ['--schema-path' => $path])
            ->expectsOutputToContain('Nothing to migrate')
            ->assertSuccessful();

        foreach (self::NODES as $node) {
            $this->assertSame($tables, $this->tablesOn($node), "Tables on {$node}");
            $this->assertSame($migrations, $this->migrationsOn($node), "Migrations on {$node}");
        }
        $this->assertSame(
            1,
            count(array_unique(array_merge(...array_map(
                fn (string $node): array => array_column(DB::connection($node)->select(
                    "SELECT toString(uuid) AS uuid FROM system.tables WHERE database = '{$this->databaseName()}'"
                    . " AND name = 'things'"
                ), 'uuid'),
                self::NODES
            ))))
        );

        // A row inserted on node1 reaches node2's table, and so its view, its dictionary and, through node1's
        // materialized view, the view's inner table on node2.
        DB::connection(self::CONNECTION)->statement("INSERT INTO things VALUES (1, 'a')");
        DB::connection('clickhouse2')->statement("SYSTEM SYNC REPLICA {$this->databaseName()}.things");
        $this->syncInnerTablesOn('clickhouse2');
        foreach (['things_view', 'things_mv'] as $view) {
            $this->assertSame(
                [['id' => 1, 'name' => 'a']],
                DB::connection('clickhouse2')->select("SELECT id, name FROM {$this->databaseName()}.{$view}"),
                "Rows of {$view} on clickhouse2"
            );
        }
        $this->assertSame(
            [['name' => 'a']],
            DB::connection('clickhouse2')->select(
                "SELECT dictGet('{$this->databaseName()}.things_dict', 'name', toUInt64(1)) AS name"
            )
        );

        // The second round logs each row again, identical to the insert of the first round, which the replicated
        // migrations table keeps: the schema builder made it with replicated_deduplication_window = 0 and
        // replicated_deduplication_window_for_async_inserts = 0, and each insert is synchronous and has a
        // deduplication token of its own.
        for ($round = 1; $round <= 2; $round++) {
            $this->artisan('migrate:rollback', $arguments)->assertSuccessful();
            foreach (self::NODES as $node) {
                $this->assertSame([['migrations', 'ReplicatedMergeTree']], $this->tablesOn($node), "Tables on {$node}");
                $this->assertSame([], $this->migrationsOn($node), "Migrations on {$node} after rollback {$round}");
            }

            $this->artisan('migrate', $arguments)->assertSuccessful();
            foreach (self::NODES as $node) {
                $this->assertSame($tables, $this->tablesOn($node), "Tables on {$node}");
                $this->assertSame($migrations, $this->migrationsOn($node), "Migrations on {$node} after migrate {$round}");
            }
        }
    }

    /**
     * A dump whose tables are not replicated, as 3.0.0 or replicated(false) made them, creates them ON CLUSTER, and
     * its migrations rows reach the migrations table of each node.
     */
    public function testADumpWhoseMigrationsTableIsNotReplicatedGivesItsRowsToEveryNode(): void
    {
        $path = $this->workDir . '/schema.sql';
        $this->files->put($path, implode(PHP_EOL . PHP_EOL, [
            "CREATE TABLE migrations\n(\n    `id` Int32,\n    `migration` String,\n    `batch` Int32\n)\nENGINE = MergeTree\n"
            . "ORDER BY id\nSETTINGS index_granularity = 8192;",
            "CREATE TABLE things\n(\n    `id` Int32,\n    `name` String\n)\nENGINE = MergeTree\nORDER BY id\n"
            . "SETTINGS index_granularity = 8192;",
            "INSERT INTO `migrations` VALUES\n(0,'" . self::THINGS_MIGRATION . "',1);",
        ]) . PHP_EOL);

        DB::connection(self::CONNECTION)->getSchemaState()->load($path);

        foreach (self::NODES as $node) {
            $this->assertSame([['migrations', 'MergeTree'], ['things', 'MergeTree']], $this->tablesOn($node), "Tables on {$node}");
            $this->assertSame([[self::THINGS_MIGRATION, 1]], $this->migrationsOn($node, replicated: false), "Migrations on {$node}");
        }
    }

    /**
     * migrate drops the migrations table right before the dump creates it again. A migrations table whose engine
     * option names a fixed replica path, which the repository keeps, and which the dump writes with the path filled
     * in, is dropped with SYNC, so its replica is gone from ClickHouse Keeper when the dump creates it: without SYNC,
     * the dump's CREATE TABLE failed with REPLICA_ALREADY_EXISTS and nothing was loaded.
     */
    public function testMigrateLoadsTheDumpOfAMigrationsTableWithAFixedReplicaPath(): void
    {
        $this->app['config']->set(
            'database.connections.' . self::CONNECTION . '.engine',
            "ReplicatedMergeTree('/clickhouse/tables/{shard}/{database}/{table}', '{replica}')"
        );
        DB::purge(self::CONNECTION);
        $arguments = [
            '--database' => self::CONNECTION,
            '--path' => $this->workDir . '/migrations',
            '--realpath' => true,
        ];
        $path = $this->workDir . '/schema.sql';

        $this->artisan('migrate', $arguments)->assertSuccessful();
        $this->artisan('schema:dump', ['--database' => self::CONNECTION, '--path' => $path])->assertSuccessful();
        $this->assertStringContainsString(
            "ENGINE = ReplicatedMergeTree('/clickhouse/tables/{shard}/{$this->databaseName()}/migrations', '{replica}')",
            $this->files->get($path)
        );
        $this->artisan('db:wipe', ['--database' => self::CONNECTION])->assertSuccessful();

        $this->artisan('migrate', $arguments + ['--schema-path' => $path])
            ->expectsOutputToContain('Nothing to migrate')
            ->assertSuccessful();

        foreach (self::NODES as $node) {
            $this->assertSame([
                ['migrations', 'ReplicatedMergeTree'],
                ['things', 'ReplicatedMergeTree'],
                ['things_dict', 'Dictionary'],
                ['things_mv', 'MaterializedView'],
                ['things_view', 'View'],
            ], $this->tablesOn($node), "Tables on {$node}");
            $this->assertSame(
                [[self::THINGS_MIGRATION, 1], [self::VIEW_MIGRATION, 1]],
                $this->migrationsOn($node),
                "Migrations on {$node}"
            );
        }
    }

    /**
     * Get the database of the connection that the test makes.
     */
    protected function databaseName(): string
    {
        return 'phpch_schema_cluster';
    }

    /**
     * Run a statement on the first node of the cluster, outside the test's database.
     */
    private function onTheCluster(string $sql): void
    {
        DB::connection('clickhouse-cluster')->getClient()->write($sql);
    }

    /**
     * Get the name and the engine of each table, view and dictionary of the test's database on a node.
     *
     * @return list<array{string, string}>
     */
    private function tablesOn(string $node): array
    {
        return array_map(
            fn (array $row): array => [$row['name'], $row['engine']],
            DB::connection($node)->select(
                "SELECT name, engine FROM system.tables WHERE database = '{$this->databaseName()}'"
                . " AND NOT startsWith(name, '.inner') ORDER BY name"
            )
        );
    }

    /**
     * Wait until the inner table of each materialized view of the test's database on a node has fetched what the
     * other replica wrote: ClickHouse refuses SYSTEM SYNC REPLICA on the view itself (BAD_ARGUMENTS, 'is not
     * replicated').
     */
    private function syncInnerTablesOn(string $node): void
    {
        $innerTables = DB::connection($node)->select(
            "SELECT name FROM system.tables WHERE database = '{$this->databaseName()}' AND startsWith(name, '.inner')"
        );

        foreach ($innerTables as $innerTable) {
            DB::connection($node)->statement("SYSTEM SYNC REPLICA {$this->databaseName()}.`{$innerTable['name']}`");
        }
    }

    /**
     * Get each migration and its batch from the migrations table of the test's database on a node, after the node
     * has fetched what the other replica wrote when the table is replicated.
     *
     * @return list<array{string, int}>
     */
    private function migrationsOn(string $node, bool $replicated = true): array
    {
        if ($replicated) {
            DB::connection($node)->statement("SYSTEM SYNC REPLICA {$this->databaseName()}.migrations");
        }

        return array_map(
            fn (array $row): array => [$row['migration'], (int) $row['batch']],
            DB::connection($node)->select(
                "SELECT migration, batch FROM {$this->databaseName()}.migrations ORDER BY migration, batch"
            )
        );
    }
}
