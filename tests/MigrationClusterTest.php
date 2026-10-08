<?php

namespace Tests;

use ClickHouseDB\Statement;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Engine;
use Oralunal\LaravelClickHouse\Migration;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Tables\MergeTree;

class MigrationClusterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! env('CLICKHOUSE_CLUSTER_AVAILABLE')) {
            $this->markTestSkipped('company_cluster config requires docker-compose services');
        }
    }

    /**
     * Pins the cluster branch of Migration::createMergeTree by asserting the
     * compiled DDL itself (ON CLUSTER + Replicated engine) — the two-node
     * existence checks alone cannot distinguish ON CLUSTER coordination from
     * Cluster::write's fan-out — and smoke-checks the table on every node.
     */
    public function testCreateMergeTreeOnClusterCreatesReplicatedTableOnAllNodes(): void
    {
        $this->assertCreatedOnAllNodes('ReplicatedMergeTree', fn (MergeTree $table) => $table
            ->ifNotExists()
            ->columns([
                $table->int64('f_int'),
                $table->string('f_string'),
            ])
            ->orderBy('f_int'));
    }

    /**
     * Setting the engine type in the callback must not drop the replication
     * that createMergeTree() set up for the cluster.
     */
    public function testCreateMergeTreeOnClusterKeepsTheEngineTypeReplicated(): void
    {
        $this->assertCreatedOnAllNodes('ReplicatedReplacingMergeTree', fn (MergeTree $table) => $table
            ->ifNotExists()
            ->columns([
                $table->int64('f_int'),
                $table->uInt64('f_version'),
            ])
            ->orderBy('f_int')
            ->engine(Engine::REPLACING_MERGE_TREE, 'f_version'));
    }

    /**
     * Run createMergeTree() on the cluster connection and assert the table has
     * the given engine on every node.
     *
     * @param string $engine
     * @param callable(MergeTree): MergeTree $define
     * @return void
     */
    private function assertCreatedOnAllNodes(string $engine, callable $define): void
    {
        $migration = new class extends Migration {
            protected $connection = 'clickhouse-cluster';

            /** Unique per run so stale ZooKeeper replica metadata can never collide. */
            public string $table;

            /** @var callable(MergeTree): MergeTree */
            public $define;

            /** @var string[] DDL captured from createMergeTree via the write() override. */
            public static array $writtenSql = [];

            public function up(): void
            {
                static::createMergeTree($this->table, $this->define);
            }

            public function down(): void
            {
                static::write("DROP TABLE IF EXISTS {$this->table} ON CLUSTER 'company_cluster' SYNC");
            }

            protected static function write(string $sql, array $bindings = []): Statement
            {
                static::$writtenSql[] = $sql;
                return parent::write($sql, $bindings);
            }
        };
        $migration->table = 'examples6_' . bin2hex(random_bytes(4));
        $migration->define = $define;

        try {
            $migration::$writtenSql = [];
            $migration->up();

            $createSql = array_values(array_filter(
                $migration::$writtenSql,
                fn (string $sql): bool => str_starts_with($sql, 'CREATE TABLE')
            ));
            $this->assertCount(1, $createSql);
            $this->assertStringContainsString("ON CLUSTER 'company_cluster'", $createSql[0]);
            $this->assertStringContainsString("ENGINE = {$engine}(", $createSql[0]);

            $engineSql = "SELECT engine FROM system.tables WHERE database = 'default' AND name = '{$migration->table}'";
            foreach (['clickhouse', 'clickhouse2'] as $connectionName) {
                $rows = DB::connection($connectionName)->getClient()->select($engineSql)->rows();
                $this->assertNotEmpty($rows, "{$migration->table} is missing on node '$connectionName'");
                $this->assertSame(
                    $engine,
                    $rows[0]['engine'],
                    "{$migration->table} on node '$connectionName' has the wrong engine"
                );
            }
        } finally {
            $migration->down();
        }
    }
}
