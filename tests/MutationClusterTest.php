<?php

namespace Tests;

use ClickHouseDB\Client;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\Builder;

class MutationClusterRow extends BaseModel
{
    protected $connection = 'clickhouse-cluster';
    protected $table = 'mutation_cluster_rows';
}

/**
 * delete(), update() and truncate() with onCluster(), or with the use_on_cluster
 * option, on a table that is not replicated: each node holds its own copy of the
 * rows, so only ON CLUSTER reaches both.
 *
 * The clickhouse-cluster connection gets fix_default_query_builder, as in the
 * README's cluster config, so DB::connection()->table() returns this package's
 * builder.
 */
class MutationClusterTest extends TestCase
{
    private const TABLE = 'mutation_cluster_rows';
    private const CLUSTER = 'company_cluster';
    private const NODES = ['clickhouse', 'clickhouse2'];

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('CLICKHOUSE_CLUSTER_AVAILABLE')) {
            $this->markTestSkipped('company_cluster config requires docker-compose services');
        }

        config(['database.connections.clickhouse-cluster.fix_default_query_builder' => true]);
        DB::purge('clickhouse-cluster');

        $this->node('clickhouse')->write('DROP TABLE IF EXISTS ' . self::TABLE . " ON CLUSTER '" . self::CLUSTER . "' SYNC");
        $this->node('clickhouse')->write(
            'CREATE TABLE ' . self::TABLE . " ON CLUSTER '" . self::CLUSTER . "' (id UInt32, d Date, v String)"
            . ' ENGINE = MergeTree PARTITION BY toYYYYMM(d) ORDER BY id'
        );
        foreach (self::NODES as $node) {
            $this->node($node)->insert(self::TABLE, [
                [1, '2024-01-10', 'a'],
                [2, '2024-01-20', 'b'],
                [3, '2024-02-10', 'c'],
            ], ['id', 'd', 'v']);
        }
    }

    protected function tearDown(): void
    {
        if (env('CLICKHOUSE_CLUSTER_AVAILABLE')) {
            $this->node('clickhouse')->write('DROP TABLE IF EXISTS ' . self::TABLE . " ON CLUSTER '" . self::CLUSTER . "' SYNC");
        }
        parent::tearDown();
    }

    public function testAlterDeleteOnClusterChangesBothNodes(): void
    {
        $this->table()->where('id', 1)->onCluster(self::CLUSTER)->delete(false);
        $this->waitForMutations();

        $this->assertRowsOnEveryNode([[2, 'b'], [3, 'c']]);
    }

    public function testAlterDeleteOnClusterInPartitionChangesBothNodes(): void
    {
        $this->table()->where('id', '>', 0)->onCluster(self::CLUSTER)->delete(false, 202401);
        $this->waitForMutations();

        $this->assertRowsOnEveryNode([[3, 'c']]);
    }

    public function testLightweightDeleteOnClusterChangesBothNodes(): void
    {
        $this->table()->where('id', 1)->onCluster(self::CLUSTER)->delete(true);

        $this->assertRowsOnEveryNode([[2, 'b'], [3, 'c']]);
    }

    public function testUpdateOnClusterChangesBothNodes(): void
    {
        $this->table()->where('id', 2)->onCluster(self::CLUSTER)->update(['v' => 'x']);
        $this->waitForMutations();

        $this->assertRowsOnEveryNode([[1, 'a'], [2, 'x'], [3, 'c']]);
    }

    public function testModelMutationsOnCluster(): void
    {
        MutationClusterRow::where('id', 1)->onCluster(self::CLUSTER)->delete(true);
        MutationClusterRow::where('id', 2)->onCluster(self::CLUSTER)->update(['v' => 'x']);
        $this->waitForMutations();

        $this->assertRowsOnEveryNode([[2, 'x'], [3, 'c']]);
    }

    public function testUpdateOnClusterInPartitionChangesBothNodes(): void
    {
        $this->table()->where('id', '>', 0)->onCluster(self::CLUSTER)->update(['v' => 'x'], '202402');
        $this->waitForMutations();

        $this->assertRowsOnEveryNode([[1, 'a'], [2, 'b'], [3, 'x']]);
    }

    public function testTruncateOnClusterEmptiesBothNodes(): void
    {
        $this->table()->onCluster(self::CLUSTER)->truncate();

        $this->assertRowsOnEveryNode([]);
    }

    public function testWithoutOnClusterOnlyTheActiveNodeChanges(): void
    {
        $this->table()->where('id', 1)->delete(true);

        $this->assertSame([[2, 'b'], [3, 'c']], $this->rows('clickhouse'));
        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'c']], $this->rows('clickhouse2'));
    }

    /**
     * With use_on_cluster, a model's mutations, truncate() and optimize() reach every node without onCluster().
     */
    public function testUseOnClusterSendsModelStatementsToEveryNode(): void
    {
        $this->useOnCluster();

        MutationClusterRow::where('id', 1)->delete(true);
        MutationClusterRow::where('id', 2)->update(['v' => 'x']);
        $this->waitForMutations();

        $this->assertRowsOnEveryNode([[2, 'x'], [3, 'c']]);

        foreach (self::NODES as $node) {
            $this->node($node)->insert(self::TABLE, [[4, '2024-01-15', 'd']], ['id', 'd', 'v']);
        }
        MutationClusterRow::optimize(true, 202401);
        $this->assertSame([1, 1], $this->activePartsOfJanuary(), 'one part of 202401 on each node');

        MutationClusterRow::truncate();

        $this->assertRowsOnEveryNode([]);
    }

    /**
     * With use_on_cluster, the table() builder's delete(), update() and truncate() reach every node.
     */
    public function testUseOnClusterSendsBuilderStatementsToEveryNode(): void
    {
        $this->useOnCluster();

        $this->table()->where('id', 1)->delete(false);
        $this->table()->where('id', 2)->update(['v' => 'x']);
        $this->waitForMutations();

        $this->assertRowsOnEveryNode([[2, 'x'], [3, 'c']]);

        $this->table()->truncate();

        $this->assertRowsOnEveryNode([]);
    }

    /**
     * With use_on_cluster, Laravel's query builder (fix_default_query_builder set to false) sends its delete(),
     * update() and truncate() ON CLUSTER as well.
     */
    public function testUseOnClusterSendsLaravelStatementsToEveryNode(): void
    {
        $this->useOnCluster();
        config(['database.connections.clickhouse-cluster-laravel' => array_merge(
            config('database.connections.clickhouse-cluster'),
            ['fix_default_query_builder' => false]
        )]);
        DB::purge('clickhouse-cluster-laravel');
        $connection = DB::connection('clickhouse-cluster-laravel');
        $connection->enableQueryLog();

        $connection->table(self::TABLE)->where('id', 1)->delete();
        $connection->table(self::TABLE)->where('id', 2)->update(['v' => 'x']);
        $this->waitForMutations();

        $this->assertRowsOnEveryNode([[2, 'x'], [3, 'c']]);

        $connection->table(self::TABLE)->truncate();

        $this->assertRowsOnEveryNode([]);
        $this->assertSame(
            [
                'alter table "mutation_cluster_rows" on cluster \'company_cluster\' delete where "id" = ?',
                'alter table "mutation_cluster_rows" on cluster \'company_cluster\' update "v" = ? where "id" = ?',
                'truncate table "mutation_cluster_rows" on cluster \'company_cluster\'',
            ],
            array_column($connection->getQueryLog(), 'query')
        );
        DB::purge('clickhouse-cluster-laravel');
    }

    /**
     * Builder::withoutOnCluster() and Connection::withoutOnCluster() leave the default cluster out, so only the active
     * node changes.
     */
    public function testWithoutOnClusterChangesOnlyTheActiveNode(): void
    {
        $this->useOnCluster();
        $connection = DB::connection('clickhouse-cluster');

        $this->table()->where('id', 1)->withoutOnCluster()->delete(true);
        $connection->withoutOnCluster(function (): void {
            MutationClusterRow::where('id', 2)->delete(true);
            foreach (self::NODES as $node) {
                $this->node($node)->insert(self::TABLE, [[4, '2024-01-15', 'd']], ['id', 'd', 'v']);
            }
            MutationClusterRow::optimize(true, 202401);
        });

        $this->assertSame([[3, 'c'], [4, 'd']], $this->rows('clickhouse'));
        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'c'], [4, 'd']], $this->rows('clickhouse2'));
        $this->assertSame([1, 2], $this->activePartsOfJanuary(), 'only the active node merged its parts of 202401');

        $connection->withoutOnCluster(fn () => MutationClusterRow::truncate());

        $this->assertSame([], $this->rows('clickhouse'));
        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'c'], [4, 'd']], $this->rows('clickhouse2'));
    }

    /**
     * Turn on use_on_cluster for the clickhouse-cluster connection, whose cluster_name is company_cluster.
     *
     * @return void
     */
    private function useOnCluster(): void
    {
        config(['database.connections.clickhouse-cluster.use_on_cluster' => true]);
        DB::purge('clickhouse-cluster');
    }

    /**
     * Count the active data parts of the partition 202401 on each node.
     *
     * @return array<int, int>
     */
    private function activePartsOfJanuary(): array
    {
        return array_map(
            fn (string $node): int => (int) $this->node($node)->select(
                "SELECT count() AS c FROM system.parts WHERE database = currentDatabase() AND table = '" . self::TABLE . "'"
                . " AND partition = '202401' AND active"
            )->fetchOne('c'),
            self::NODES
        );
    }

    private function table(): Builder
    {
        return DB::connection('clickhouse-cluster')->table(self::TABLE);
    }

    private function node(string $connection): Client
    {
        return DB::connection($connection)->getClient();
    }

    /**
     * @param string $node
     * @return array<int, array{int, string}>
     */
    private function rows(string $node): array
    {
        return array_map(
            fn (array $row): array => [(int) $row['id'], $row['v']],
            $this->node($node)->select('SELECT id, v FROM ' . self::TABLE . ' ORDER BY id')->rows()
        );
    }

    /**
     * @param array<int, array{int, string}> $expected
     */
    private function assertRowsOnEveryNode(array $expected): void
    {
        foreach (self::NODES as $node) {
            $this->assertSame($expected, $this->rows($node), "rows on node '{$node}'");
        }
    }

    /**
     * Wait until every node has applied every mutation on the table.
     *
     * @param float $timeoutSeconds
     * @return void
     */
    private function waitForMutations(float $timeoutSeconds = 10.0): void
    {
        $sql = 'SELECT mutation_id, command, latest_fail_reason FROM system.mutations'
            . " WHERE database = currentDatabase() AND table = '" . self::TABLE . "' AND is_done = 0";
        $deadline = microtime(true) + $timeoutSeconds;

        foreach (self::NODES as $node) {
            while (($pending = $this->node($node)->select($sql)->rows()) !== []) {
                if (microtime(true) >= $deadline) {
                    $this->fail("Mutations on node '{$node}' still pending after {$timeoutSeconds}s: " . json_encode($pending));
                }
                usleep(10_000);
            }
        }
    }
}
