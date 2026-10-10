<?php

declare(strict_types=1);

namespace Tests\Unit;

use Closure;
use ClickHouseDB\Transport\CurlerResponse;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\SQLiteConnection;
use Oralunal\LaravelClickHouse\ClickhouseMigrationRepository;
use Oralunal\LaravelClickHouse\ClickhouseServiceProvider;
use Oralunal\LaravelClickHouse\Cluster;
use Oralunal\LaravelClickHouse\Connection;
use Orchestra\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;

/**
 * The migration repository that ClickhouseServiceProvider binds in place of Laravel's: the SQL that it sends to a
 * ClickHouse connection, whose active node is a client with canned responses, so no server is needed, and Laravel's
 * behaviour on an SQLite connection. tests/MigrationScenariosTest.php runs the migration commands against
 * ClickHouse.
 */
class ClickhouseMigrationRepositoryTest extends TestCase
{
    private const FIRST = '2024_01_01_000000_create_events_table';
    private const SECOND = '2024_01_02_000000_create_sessions_table';

    private CannedAsyncClient $client;

    protected function getPackageProviders($app): array
    {
        return [ClickhouseServiceProvider::class];
    }

    public function test_the_provider_puts_the_clickhouse_repository_in_place_of_laravels(): void
    {
        $repository = $this->app->make('migration.repository');

        $this->assertInstanceOf(ClickhouseMigrationRepository::class, $repository);
        $this->assertSame($repository, $this->app->make('migrator')->getRepository());
        $this->assertSame('migrations', (new ReflectionProperty(DatabaseMigrationRepository::class, 'table'))->getValue($repository));
    }

    /**
     * A repository that the app or another package binds keeps its place, also when it extends Laravel's.
     */
    public function test_a_repository_that_the_app_binds_is_kept(): void
    {
        $custom = new class (new ConnectionResolver(), 'migrations') extends DatabaseMigrationRepository {
        };
        $this->app->singleton('migration.repository', fn (): MigrationRepositoryInterface => $custom);

        $this->assertSame($custom, $this->app->make('migration.repository'));
    }

    public function test_from_laravel_repository_keeps_the_resolver_the_table_and_the_connection(): void
    {
        $resolver = new ConnectionResolver();
        $laravel = new DatabaseMigrationRepository($resolver, 'app_migrations');
        $laravel->setSource('analytics');

        $repository = ClickhouseMigrationRepository::fromLaravelRepository($laravel);

        $this->assertSame($resolver, $repository->getConnectionResolver());
        $this->assertSame('app_migrations', (new ReflectionProperty(DatabaseMigrationRepository::class, 'table'))->getValue($repository));
        $this->assertSame('analytics', (new ReflectionProperty(DatabaseMigrationRepository::class, 'connection'))->getValue($repository));
    }

    /**
     * @return array<string, array{bool|null}>
     */
    public static function queryBuilderOptions(): array
    {
        return [
            'fix_default_query_builder on' => [true],
            'fix_default_query_builder off' => [false],
            'fix_default_query_builder left out' => [null],
        ];
    }

    /**
     * Laravel's query builder reads the rows whatever the connection's fix_default_query_builder option says, and
     * the migrations come back as objects: the package Builder's get() returned smi2's Statement, which has no all().
     */
    #[DataProvider('queryBuilderOptions')]
    public function test_get_last_reads_the_last_batch_with_laravels_query_builder(?bool $fixDefaultQueryBuilder): void
    {
        $repository = $this->repository(['fix_default_query_builder' => $fixDefaultQueryBuilder], $this->rowsOfTwoBatches());

        $migrations = $repository->getLast();

        $this->assertEquals([(object) ['id' => 0, 'migration' => self::SECOND, 'batch' => 2]], $migrations);
        $this->assertSame([
            'select max("batch") as "aggregate" from "migrations"',
            'select * from "migrations" where "batch" = 2 order by "migration" desc',
        ], $this->sentSql());
    }

    #[DataProvider('queryBuilderOptions')]
    public function test_get_migrations_reads_the_last_steps(?bool $fixDefaultQueryBuilder): void
    {
        $repository = $this->repository(['fix_default_query_builder' => $fixDefaultQueryBuilder], $this->rowsOfTwoBatches());

        $migrations = $repository->getMigrations(2);

        $this->assertEquals([
            (object) ['id' => 0, 'migration' => self::SECOND, 'batch' => 2],
            (object) ['id' => 0, 'migration' => self::FIRST, 'batch' => 1],
        ], $migrations);
        $this->assertSame(
            ['select * from "migrations" where "batch" >= \'1\' order by "batch" desc, "migration" desc limit 2'],
            $this->sentSql()
        );
    }

    #[DataProvider('queryBuilderOptions')]
    public function test_get_migrations_by_batch_reads_one_batch(?bool $fixDefaultQueryBuilder): void
    {
        $repository = $this->repository(['fix_default_query_builder' => $fixDefaultQueryBuilder], $this->rowsOfTwoBatches());

        $migrations = $repository->getMigrationsByBatch(1);

        $this->assertEquals([(object) ['id' => 0, 'migration' => self::FIRST, 'batch' => 1]], $migrations);
        $this->assertSame(['select * from "migrations" where "batch" = 1 order by "migration" desc'], $this->sentSql());
    }

    public function test_get_ran_get_migration_batches_and_the_next_batch_number(): void
    {
        $repository = $this->repository(['fix_default_query_builder' => true], $this->rowsOfTwoBatches());

        $this->assertSame([self::FIRST, self::SECOND], $repository->getRan());
        $this->assertSame([self::FIRST => 1, self::SECOND => 2], $repository->getMigrationBatches());
        $this->assertSame(3, $repository->getNextBatchNumber());
        $this->assertSame([
            'select "migration" from "migrations" order by "batch" asc, "migration" asc',
            'select "batch", "migration" from "migrations" order by "batch" asc, "migration" asc',
            'select max("batch") as "aggregate" from "migrations"',
        ], $this->sentSql());
    }

    /**
     * Each insert has a token of its own, so that a migrations table that deduplicates keeps a row that is identical
     * to an earlier one, as the row of a migration that is rolled back and migrated again in the same batch.
     */
    public function test_log_sends_a_deduplication_token_of_its_own(): void
    {
        $repository = $this->repository();

        $repository->log(self::FIRST, 1);
        $repository->log(self::FIRST, 1);

        $sent = $this->sentSql();
        $this->assertCount(2, $sent);
        $pattern = '/\Ainsert into "migrations" \("migration", "batch"\) settings insert_deduplication_token = \''
            . preg_quote(self::FIRST, '/') . '\/1\/([0-9a-f]{32})\', async_insert = 0 values \(\''
            . preg_quote(self::FIRST, '/') . '\', 1\)\z/';
        $this->assertMatchesRegularExpression($pattern, $sent[0]);
        $this->assertMatchesRegularExpression($pattern, $sent[1]);
        $this->assertNotSame($sent[0], $sent[1]);
    }

    public function test_log_quotes_the_table_and_the_values(): void
    {
        $repository = $this->repository([], null, "app's.migrations");

        $repository->log("2024_01_01_000000_it's", 3);

        $this->assertMatchesRegularExpression(
            '/\Ainsert into "app\'s"\."migrations" \("migration", "batch"\) settings insert_deduplication_token ='
            . ' \'2024_01_01_000000_it\\\\\'s\/3\/[0-9a-f]{32}\', async_insert = 0 values'
            . ' \(\'2024_01_01_000000_it\\\\\'s\', 3\)\z/',
            $this->sentSql()[0]
        );
    }

    /**
     * The insert is synchronous whatever the connection's settings say: with async_insert = 1, the default of
     * ClickHouse 26.x, and wait_for_async_insert = 0, the insert would return before the row is written, and the
     * next read of the migrations table could miss it.
     */
    public function test_log_inserts_synchronously_whatever_the_connection_settings_say(): void
    {
        $repository = $this->repository(['settings' => ['async_insert' => 1, 'wait_for_async_insert' => 0]]);

        $repository->log(self::FIRST, 1);

        $this->assertMatchesRegularExpression(
            '/\Ainsert into "migrations" \("migration", "batch"\) settings insert_deduplication_token = \'[^\']+\','
            . ' async_insert = 0 values \(/',
            $this->sentSql()[0]
        );
    }

    /**
     * Without mutations_sync, ClickHouse deletes the row after the statement has returned, so the next command
     * could still read the migration as run.
     */
    public function test_delete_waits_until_the_active_node_has_deleted_the_row(): void
    {
        $repository = $this->repository(['use_lightweight_delete' => true, 'use_on_cluster' => true, 'cluster_name' => 'company_cluster']);

        $repository->delete((object) ['migration' => self::SECOND]);

        $this->assertSame(
            ['alter table "migrations" delete where "migration" = \'' . self::SECOND . '\' settings mutations_sync = 1'],
            $this->sentSql()
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function repositoryDrops(): array
    {
        return [
            'one node' => [[], 'DROP TABLE `migrations` SYNC'],
            'a cluster_name' => [['cluster_name' => 'company_cluster'], "DROP TABLE `migrations` ON CLUSTER 'company_cluster' SYNC"],
            'a cluster_name and cluster nodes' => [
                ['cluster_name' => 'company_cluster', 'cluster' => [['host' => 'a'], ['host' => 'b']]],
                "DROP TABLE `migrations` ON CLUSTER 'company_cluster' SYNC",
            ],
        ];
    }

    /**
     * migrate with a schema dump drops the migrations table right before the dump creates it again. Without SYNC, a
     * replicated table whose engine option names a fixed replica path, such as
     * ReplicatedMergeTree('/clickhouse/tables/{shard}/{database}/{table}', '{replica}'), keeps its replica in
     * ClickHouse Keeper for a while, and the dump's CREATE TABLE fails with REPLICA_ALREADY_EXISTS.
     *
     * @param array<string, mixed> $config
     */
    #[DataProvider('repositoryDrops')]
    public function test_delete_repository_drops_the_table_with_sync(array $config, string $drop): void
    {
        $repository = $this->repository($config);

        $repository->deleteRepository();

        $this->assertSame([$drop], $this->sentSql());
    }

    /**
     * While the connection pretends, as migrate --pretend makes it, log() and delete() send nothing.
     */
    public function test_log_and_delete_send_nothing_while_the_connection_pretends(): void
    {
        $repository = $this->repository();

        $queries = $repository->getConnection()->pretend(function () use ($repository): void {
            $repository->log(self::FIRST, 1);
            $repository->delete((object) ['migration' => self::FIRST]);
        });

        $this->assertSame([], $this->sentSql());
        $this->assertCount(2, $queries);
        $this->assertSame([self::FIRST, 1], array_slice($queries[0]['bindings'], 1));
        $this->assertSame([self::FIRST], $queries[1]['bindings']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function migrationsTables(): array
    {
        $columns = '(`id` Int32, `migration` String, `batch` Int32)';

        return [
            'no options' => [[], "CREATE TABLE `migrations` {$columns} ENGINE = MergeTree() ORDER BY (`id`)"],
            'exact_integer_types' => [
                ['exact_integer_types' => true],
                "CREATE TABLE `migrations` {$columns} ENGINE = MergeTree() ORDER BY (`id`)",
            ],
            'an engine that merges rows' => [
                ['engine' => 'ReplacingMergeTree()'],
                "CREATE TABLE `migrations` {$columns} ENGINE = MergeTree() ORDER BY (`id`)",
            ],
            'an engine that loses rows' => [
                ['engine' => 'Memory'],
                "CREATE TABLE `migrations` {$columns} ENGINE = MergeTree() ORDER BY (`id`)",
            ],
            'MergeTree without parentheses' => [
                ['engine' => ' MergeTree '],
                "CREATE TABLE `migrations` {$columns} ENGINE = MergeTree ORDER BY (`id`)",
            ],
            'MergeTree with a clause after it' => [
                ['engine' => 'MergeTree() SETTINGS index_granularity = 1024'],
                "CREATE TABLE `migrations` {$columns} ENGINE = MergeTree() ORDER BY (`id`)",
            ],
            'MergeTree with arguments' => [
                ['engine' => "MergeTree('a)b', \"c(d\")"],
                "CREATE TABLE `migrations` {$columns} ENGINE = MergeTree('a)b', \"c(d\") ORDER BY (`id`)",
            ],
            'a replicated engine' => [
                ['engine' => "ReplicatedMergeTree('/clickhouse/tables/{shard}/{database}/{table}', '{replica}')"],
                "CREATE TABLE `migrations` {$columns} ENGINE = ReplicatedMergeTree('/clickhouse/tables/{shard}/{database}/{table}',"
                . " '{replica}') ORDER BY (`id`)",
            ],
            'SharedMergeTree' => [
                ['engine' => 'SharedMergeTree'],
                "CREATE TABLE `migrations` {$columns} ENGINE = SharedMergeTree ORDER BY (`id`)",
            ],
            'a cluster_name' => [
                ['cluster_name' => 'company_cluster'],
                "CREATE TABLE `migrations` ON CLUSTER 'company_cluster' {$columns} ENGINE = MergeTree() ORDER BY (`id`)",
            ],
            'a cluster_name and cluster nodes' => [
                ['cluster_name' => 'company_cluster', 'cluster' => [['host' => 'a'], ['host' => 'b']], 'engine' => 'ReplacingMergeTree()'],
                "CREATE TABLE `migrations` ON CLUSTER 'company_cluster' {$columns} ENGINE = ReplicatedMergeTree() ORDER BY (`id`)"
                . ' SETTINGS replicated_deduplication_window=0',
            ],
        ];
    }

    /**
     * The migrations table keeps the columns of 3.0.0, and an engine that keeps every row: the id of every row is
     * 0, so an engine that merges the rows that have the same sorting key would keep one of them.
     *
     * @param array<string, mixed> $config
     */
    #[DataProvider('migrationsTables')]
    public function test_create_repository_keeps_the_columns_of_3_0_0_and_every_row(array $config, string $create): void
    {
        $repository = $this->repository($config);

        $repository->createRepository();

        $this->assertSame([$create], $this->sentSql());
    }

    /**
     * Without exact_integer_types, Laravel's own layout of the table compiles to the same statement, so the table
     * is the one that 3.0.0 and the earlier releases of this version made.
     */
    public function test_create_repository_compiles_as_laravels_layout_does_without_exact_integer_types(): void
    {
        $repository = $this->repository();
        $repository->createRepository();
        $ours = $this->sentSql();

        $laravel = new DatabaseMigrationRepository($repository->getConnectionResolver(), 'migrations');
        $laravel->setSource('clickhouse');
        $this->client->requests = [];
        $laravel->createRepository();

        $this->assertSame($ours, $this->sentSql());
    }

    /**
     * On any other connection the repository is Laravel's: the same table, rows, objects and SQL.
     */
    public function test_on_another_connection_the_repository_is_laravels(): void
    {
        $runs = [];
        foreach ([DatabaseMigrationRepository::class, ClickhouseMigrationRepository::class] as $class) {
            $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
            $connection->enableQueryLog();
            $repository = new $class(new ConnectionResolver(['sqlite' => $connection]), 'migrations');
            $repository->setSource('sqlite');

            $repository->createRepository();
            $repository->log(self::FIRST, 1);
            $repository->log(self::SECOND, 2);
            $results = [
                $repository->repositoryExists(),
                $repository->getRan(),
                $repository->getMigrationBatches(),
                $repository->getLast(),
                $repository->getMigrations(5),
                $repository->getMigrationsByBatch(1),
                $repository->getNextBatchNumber(),
            ];
            $repository->delete((object) ['migration' => self::SECOND]);
            $results[] = $repository->getRan();
            $repository->deleteRepository();
            $results[] = $repository->repositoryExists();

            $runs[] = [$results, array_column($connection->getQueryLog(), 'query')];
        }

        $this->assertEquals($runs[0], $runs[1]);
        $this->assertIsObject($runs[1][0][3][0]);
        $this->assertFalse($runs[1][0][8]);
    }

    /**
     * A repository on a ClickHouse connection named clickhouse, whose active node is a canned client: a read of the
     * migrations table gets the given rows, filtered as the SQL asks for the tests' cases, and anything else an
     * empty JSON result.
     *
     * @param array<string, mixed> $config
     * @param (Closure(string): list<array<string, mixed>>)|null $rows
     * @param string $table
     * @return ClickhouseMigrationRepository
     */
    private function repository(array $config = [], ?Closure $rows = null, string $table = 'migrations'): ClickhouseMigrationRepository
    {
        $this->client = new CannedAsyncClient(
            fn (string $sql): CurlerResponse => CannedAsyncClient::json($rows === null ? [] : $rows($sql))
        );
        $cluster = (new ReflectionClass(Cluster::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Cluster::class, 'nodeConfigs'))->setValue($cluster, [[]]);
        (new ReflectionProperty(Cluster::class, 'nodes'))->setValue($cluster, [$this->client]);
        (new ReflectionProperty(Cluster::class, 'activeNodeIndex'))->setValue($cluster, 0);

        $connection = new Connection(null, 'db', '', array_filter($config, fn (mixed $value): bool => $value !== null) + ['name' => 'clickhouse']);
        (new ReflectionProperty(Connection::class, 'cluster'))->setValue($connection, $cluster);

        $repository = new ClickhouseMigrationRepository(new ConnectionResolver(['clickhouse' => $connection]), $table);
        $repository->setSource('clickhouse');

        return $repository;
    }

    /**
     * Canned rows of a migrations table with one migration in batch 1 and one in batch 2, for the reads that the
     * repository sends.
     *
     * @return Closure(string): list<array<string, mixed>>
     */
    private function rowsOfTwoBatches(): Closure
    {
        $first = ['id' => 0, 'migration' => self::FIRST, 'batch' => 1];
        $second = ['id' => 0, 'migration' => self::SECOND, 'batch' => 2];

        return fn (string $sql): array => match (true) {
            str_contains($sql, 'max("batch")') => [['aggregate' => 2]],
            str_contains($sql, 'select "migration" from') => [['migration' => self::FIRST], ['migration' => self::SECOND]],
            str_contains($sql, 'select "batch", "migration" from') => [
                ['batch' => 1, 'migration' => self::FIRST],
                ['batch' => 2, 'migration' => self::SECOND],
            ],
            str_contains($sql, '"batch" = 2') => [$second],
            str_contains($sql, '"batch" = 1') => [$first],
            str_contains($sql, '"batch" >= ') => [$second, $first],
            default => [],
        };
    }

    /**
     * The SQL that the canned client got, each without the FORMAT clause that the transport adds to a read.
     *
     * @return list<string>
     */
    private function sentSql(): array
    {
        return array_map(
            fn (string $sql): string => (string) preg_replace('/\s+FORMAT JSON\s*\z/', '', $sql),
            $this->client->sentSql()
        );
    }
}
