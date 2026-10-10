<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Oralunal\LaravelClickHouse\Cluster;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\SchemaState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * How SchemaState::load() sends the statements of a schema dump to a connection with two nodes, whose clients get
 * canned responses, so no server is needed. Without a cluster_name each statement goes to every node, as in 3.0.0;
 * with one, each CREATE goes once to the active node with ON CLUSTER, and so do the rows of a replicated table. Either
 * way, each INSERT INTO <table> VALUES is sent with SETTINGS async_insert = 0. tests/SchemaDumpTest.php and
 * tests/SchemaDumpClusterTest.php load dumps into ClickHouse.
 */
class SchemaStateTest extends TestCase
{
    /**
     * A replicated migrations table, as SHOW CREATE TABLE writes the one that the schema builder makes on a
     * connection with a cluster_name and cluster nodes.
     */
    private const REPLICATED_MIGRATIONS = "CREATE TABLE migrations\n(\n    `id` Int32,\n    `migration` String,\n"
        . "    `batch` Int32\n)\nENGINE = ReplicatedMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}')\n"
        . "ORDER BY id\nSETTINGS replicated_deduplication_window = 0, replicated_deduplication_window_for_async_inserts = 0,"
        . ' index_granularity = 8192';

    /**
     * The migrations table that 3.0.0, or replicated(false), makes: a MergeTree table on each node.
     */
    private const MERGE_TREE_MIGRATIONS = "CREATE TABLE migrations\n(\n    `id` Int32,\n    `migration` String,\n"
        . "    `batch` Int32\n)\nENGINE = MergeTree\nORDER BY id\nSETTINGS index_granularity = 8192";

    private const EVENTS = "CREATE TABLE events\n(\n    `id` UInt64\n)\n"
        . "ENGINE = ReplicatedMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}')\nORDER BY id\n"
        . 'SETTINGS index_granularity = 8192';

    private const EVENTS_VIEW = "CREATE VIEW events_view\n(\n    `id` UInt64\n)\nAS SELECT id\nFROM events";

    private const MIGRATION_ROWS = "INSERT INTO `migrations` VALUES\n(0,'2024_01_01_000000_create_events_table',1),\n"
        . "(0,'2024_01_02_000000_create_events_view',2)";

    /**
     * MIGRATION_ROWS as load() sends it: synchronously, whatever the connection's async_insert says.
     */
    private const MIGRATION_ROWS_SENT = "INSERT INTO `migrations` SETTINGS async_insert = 0 VALUES\n"
        . "(0,'2024_01_01_000000_create_events_table',1),\n(0,'2024_01_02_000000_create_events_view',2)";

    /** @var list<CannedAsyncClient> */
    private array $nodes = [];

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/phpch-schema-state-' . bin2hex(random_bytes(4)) . '.sql';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->delete($this->path);

        parent::tearDown();
    }

    /**
     * Without a cluster_name, every statement goes to every node, as 3.0.0 loaded a dump; the rows are inserted
     * synchronously.
     */
    public function test_without_a_cluster_name_every_statement_goes_to_every_node(): void
    {
        $this->load([self::EVENTS, self::EVENTS_VIEW, self::MERGE_TREE_MIGRATIONS, self::MIGRATION_ROWS], [
            'cluster' => [['host' => 'a'], ['host' => 'b']],
        ]);

        $sent = [self::EVENTS, self::EVENTS_VIEW, self::MERGE_TREE_MIGRATIONS, self::MIGRATION_ROWS_SENT];
        $this->assertSame([$sent, $sent], $this->sentSql());
    }

    /**
     * Each INSERT INTO <table> VALUES statement, with or without a column list, gets SETTINGS async_insert = 0 before
     * VALUES; any other INSERT is sent as it is. The key is the statement of the dump, the value the statement sent.
     *
     * @return array<string, array{string, string}>
     */
    public static function inserts(): array
    {
        return [
            'the rows of the migrations table' => [self::MIGRATION_ROWS, self::MIGRATION_ROWS_SENT],
            'lower case, TABLE and a qualified name' => [
                'insert into table reports.events values (1)',
                'insert into table reports.events SETTINGS async_insert = 0 values (1)',
            ],
            'comments before the statement' => [
                "-- the rows\n/* kept */ INSERT INTO `migrations` VALUES (0,'m',1)",
                "-- the rows\n/* kept */ INSERT INTO `migrations` SETTINGS async_insert = 0 VALUES (0,'m',1)",
            ],
            'a name with an escaped backtick' => [
                'INSERT INTO `a\\`b` VALUES(1)',
                'INSERT INTO `a\\`b` SETTINGS async_insert = 0 VALUES(1)',
            ],
            'a column list' => [
                "INSERT INTO migrations (`migration`, batch) VALUES ('m', 1)",
                "INSERT INTO migrations (`migration`, batch) SETTINGS async_insert = 0 VALUES ('m', 1)",
            ],
            'settings of its own' => [
                'INSERT INTO events SETTINGS async_insert = 1 VALUES (1)',
                'INSERT INTO events SETTINGS async_insert = 1 VALUES (1)',
            ],
            'a format' => ['INSERT INTO events FORMAT Values (1)', 'INSERT INTO events FORMAT Values (1)'],
            'a select' => ['INSERT INTO events SELECT 1', 'INSERT INTO events SELECT 1'],
        ];
    }

    /**
     * The rows of a dump are inserted synchronously, also on a connection whose settings say async_insert = 1, the
     * default of ClickHouse 26.x, and wait_for_async_insert = 0: such an insert would return before the rows are
     * written, and migrate, which reads the migrations table right after the load, would run the migrations of the
     * dump again.
     */
    #[DataProvider('inserts')]
    public function test_an_insert_is_sent_synchronously(string $statement, string $sent): void
    {
        $this->load([$statement], [
            'cluster' => [['host' => 'a'], ['host' => 'b']],
            'settings' => ['async_insert' => 1, 'wait_for_async_insert' => 0],
        ]);

        $this->assertSame([[$sent], [$sent]], $this->sentSql());
    }

    /**
     * With a cluster_name, a CREATE goes once to the active node with ON CLUSTER, and so do the rows of a migrations
     * table that the dump creates replicated, which the table passes on to its other replicas.
     */
    public function test_with_a_cluster_name_a_replicated_dump_goes_once_to_the_active_node(): void
    {
        $this->load([self::REPLICATED_MIGRATIONS, self::EVENTS, self::EVENTS_VIEW, self::MIGRATION_ROWS], [
            'cluster' => [['host' => 'a'], ['host' => 'b']],
            'cluster_name' => 'company_cluster',
        ]);

        $this->assertSame([
            [
                $this->onCluster('CREATE TABLE migrations', self::REPLICATED_MIGRATIONS),
                $this->onCluster('CREATE TABLE events', self::EVENTS),
                $this->onCluster('CREATE VIEW events_view', self::EVENTS_VIEW),
                self::MIGRATION_ROWS_SENT,
            ],
            [],
        ], $this->sentSql());
    }

    /**
     * The rows of a migrations table that is not replicated, as in a dump of 3.0.0, still reach the table of each
     * node, while each CREATE goes once with ON CLUSTER, which creates the table on every host.
     */
    public function test_with_a_cluster_name_the_rows_of_a_table_that_is_not_replicated_go_to_every_node(): void
    {
        $this->load([self::MERGE_TREE_MIGRATIONS, self::MIGRATION_ROWS], ['cluster_name' => 'company_cluster']);

        $this->assertSame([
            [
                $this->onCluster('CREATE TABLE migrations', self::MERGE_TREE_MIGRATIONS),
                self::MIGRATION_ROWS_SENT,
            ],
            [self::MIGRATION_ROWS_SENT],
        ], $this->sentSql());
    }

    /**
     * An INSERT into a table that the dump does not create, and any statement other than a CREATE or an INSERT, go
     * to every node, as without a cluster_name.
     */
    public function test_with_a_cluster_name_other_statements_go_to_every_node(): void
    {
        $statements = [
            "INSERT INTO other VALUES (1)",
            'ALTER TABLE events ADD COLUMN n UInt8',
            'CREATE DATABASE IF NOT EXISTS reports',
        ];

        $this->load([self::REPLICATED_MIGRATIONS, ...$statements], ['cluster_name' => 'company_cluster']);

        $sent = $this->sentSql();
        $expected = ['INSERT INTO other SETTINGS async_insert = 0 VALUES (1)', $statements[1], $statements[2]];
        $this->assertSame($expected, array_slice($sent[0], 1));
        $this->assertSame($expected, $sent[1]);
    }

    /**
     * Each statement with {on} where ON CLUSTER goes; a statement without {on} is sent as it is.
     *
     * @return array<string, array{string}>
     */
    public static function createStatements(): array
    {
        return [
            'a table' => ["CREATE TABLE events{on}\n(\n    `id` UInt64\n)\nENGINE = MergeTree\nORDER BY id"],
            'a quoted name' => ["CREATE TABLE `my events`{on}\n(\n    `id` UInt64\n)"],
            'a name with an escaped backtick' => ['CREATE TABLE `a\\`b`{on}(`id` UInt64)'],
            'a double-quoted name' => ['CREATE TABLE "a\\"b"{on} (id UInt64)'],
            'a qualified name' => ['CREATE TABLE reports.events{on} (id UInt64)'],
            'a quoted qualified name' => ['CREATE TABLE `re.ports`.`ev ents`{on} (id UInt64)'],
            'if not exists' => ['create table if not exists events{on} (id UInt64)'],
            'or replace' => ['CREATE OR REPLACE TABLE events{on} (id UInt64)'],
            'a uuid' => ["CREATE TABLE events UUID '0193f1b2-0000-7000-8000-000000000000'{on} (id UInt64)"],
            'a view' => ["CREATE VIEW events_view{on}\n(\n    `id` UInt64\n)\nAS SELECT id\nFROM events"],
            'a materialized view to a table' => [
                "CREATE MATERIALIZED VIEW to_agg{on} TO agg\n(\n    `id` UInt64\n)\nAS SELECT id\nFROM src",
            ],
            'a materialized view with an engine' => [
                "CREATE MATERIALIZED VIEW with_inner{on}\n(\n    `id` UInt64\n)\nENGINE = MergeTree\nORDER BY id\n"
                . "AS SELECT id\nFROM src",
            ],
            'a dictionary' => [
                "CREATE DICTIONARY dict{on}\n(\n    `id` UInt64\n)\nPRIMARY KEY id\nSOURCE(CLICKHOUSE(DB 'x' TABLE 'src'))\n"
                . "LIFETIME(MIN 0 MAX 0)\nLAYOUT(FLAT())",
            ],
            'comments before the statement' => ["-- the events\n/* kept */ CREATE TABLE events{on} (id UInt64)"],
            'an ON CLUSTER of its own' => ['CREATE TABLE events ON CLUSTER other (id UInt64)'],
        ];
    }

    /**
     * ON CLUSTER goes right after the object's name and its UUID clause, where ClickHouse expects it.
     */
    #[DataProvider('createStatements')]
    public function test_on_cluster_follows_the_name_of_the_created_object(string $statement): void
    {
        $this->load(
            [str_replace('{on}', '', $statement)],
            ['cluster_name' => 'company_cluster', 'cluster' => [['host' => 'a'], ['host' => 'b']]]
        );

        $this->assertSame([[str_replace('{on}', " ON CLUSTER 'company_cluster'", $statement)], []], $this->sentSql());
    }

    /**
     * The cluster's name is trimmed, as the connection reads it, and escaped as the schema grammar escapes a string.
     */
    public function test_the_cluster_name_is_trimmed_and_escaped(): void
    {
        $this->load(['CREATE TABLE events (id UInt64)', 'CREATE VIEW v AS SELECT 1'], ['cluster_name' => " it's\\here "]);

        $this->assertSame([[
            "CREATE TABLE events ON CLUSTER 'it\\'s\\\\here' (id UInt64)",
            "CREATE VIEW v ON CLUSTER 'it\\'s\\\\here' AS SELECT 1",
        ], []], $this->sentSql());
    }

    /**
     * A replicated table and its rows are matched by name, however the dump quotes it.
     */
    public function test_the_rows_of_a_replicated_table_are_matched_however_its_name_is_quoted(): void
    {
        $create = "CREATE TABLE `app's migrations`\n(\n    `id` Int32\n)\n"
            . "ENGINE = ReplicatedMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}')\nORDER BY id";
        $statements = [
            $create,
            "INSERT INTO `app's migrations` VALUES (1)",
            "insert into table `app\\'s migrations` VALUES (2)",
            "INSERT INTO `app's` VALUES (3)",
        ];

        $this->load($statements, ['cluster_name' => 'company_cluster']);

        $sent = $this->sentSql();
        $this->assertSame([
            "INSERT INTO `app's migrations` SETTINGS async_insert = 0 VALUES (1)",
            "insert into table `app\\'s migrations` SETTINGS async_insert = 0 VALUES (2)",
            "INSERT INTO `app's` SETTINGS async_insert = 0 VALUES (3)",
        ], array_slice($sent[0], 1));
        $this->assertSame(["INSERT INTO `app's` SETTINGS async_insert = 0 VALUES (3)"], $sent[1]);
    }

    /**
     * Write the statements as a dump, one per paragraph with a semicolon at the end of its last line, as
     * SchemaState::dump() writes them, and load it into a connection with two nodes and the given options.
     *
     * @param list<string> $statements
     * @param array<string, mixed> $config
     */
    private function load(array $statements, array $config): void
    {
        file_put_contents(
            $this->path,
            implode(PHP_EOL . PHP_EOL, array_map(fn (string $statement): string => $statement . ';', $statements)) . PHP_EOL
        );

        $this->nodes = [new CannedAsyncClient(), new CannedAsyncClient()];
        $cluster = (new ReflectionClass(Cluster::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Cluster::class, 'nodeConfigs'))->setValue($cluster, [[], []]);
        (new ReflectionProperty(Cluster::class, 'nodes'))->setValue($cluster, $this->nodes);
        (new ReflectionProperty(Cluster::class, 'activeNodeIndex'))->setValue($cluster, 0);

        $connection = new Connection(null, 'db', '', $config + ['name' => 'clickhouse']);
        (new ReflectionProperty(Connection::class, 'cluster'))->setValue($connection, $cluster);

        (new SchemaState($connection, new Filesystem()))->load($this->path);
    }

    /**
     * Put ON CLUSTER 'company_cluster' after the head of a statement, such as CREATE TABLE events.
     */
    private function onCluster(string $head, string $statement): string
    {
        return str_replace($head, $head . " ON CLUSTER 'company_cluster'", $statement);
    }

    /**
     * The SQL that each node got, in order.
     *
     * @return list<list<string>>
     */
    private function sentSql(): array
    {
        return array_map(fn (CannedAsyncClient $node): array => $node->sentSql(), $this->nodes);
    }
}
