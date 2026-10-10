<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\SchemaBlueprint;
use RuntimeException;

/**
 * Laravel's schema builder on the clickhouse-cluster connection, which has a cluster_name and lists both nodes: every
 * CREATE, ALTER, RENAME and DROP goes ON CLUSTER and reaches both nodes, a table of the MergeTree family is created
 * replicated on the server's default replica path and keeps every insert, change() checks every host for NULL values,
 * withoutOnCluster() keeps a blueprint on the active node, dropAllTables() reaches every host, pretend() sends nothing,
 * and inside a session an ON CLUSTER statement on the name of a temporary table of the session is refused.
 *
 * Each test uses table names with a suffix of its own, and drops its tables with SYNC on both nodes. Counts are
 * selected as strings, since ClickHouse 24.8 quotes a UInt64 in JSON and 25.8 and later do not.
 */
class SchemaBlueprintClusterTest extends TestCase
{
    private const CLUSTER = 'clickhouse-cluster';

    /** The connections that read each node of the cluster on their own. */
    private const NODES = ['clickhouse', 'clickhouse2'];

    /** The suffix of this test's table names. */
    private string $suffix;

    /**
     * The tables that this test created.
     *
     * @var list<string>
     */
    private array $tables = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('CLICKHOUSE_CLUSTER_AVAILABLE')) {
            $this->markTestSkipped('company_cluster config requires docker-compose services');
        }

        $this->suffix = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->suffix)) {
            foreach ($this->tables as $table) {
                DB::connection(self::CLUSTER)->statement(
                    "DROP TABLE IF EXISTS `{$table}` ON CLUSTER 'company_cluster' SYNC"
                );
            }
        }

        parent::tearDown();
    }

    public function testCreateAlterRenameAndDropReachEveryNode(): void
    {
        $events = $this->table('events');
        $renamed = $this->table('events_renamed');
        $schema = Schema::connection(self::CLUSTER);

        $schema->create($events, function (SchemaBlueprint $table) {
            $table->integer('id');
            $table->string('name');
        });

        foreach ($this->engineFullOnEveryNode($events) as $rows) {
            $this->assertSame(
                [[
                    'engine_full' => "ReplicatedMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}') ORDER BY id"
                        . ' SETTINGS replicated_deduplication_window = 0, replicated_deduplication_window_for_async_inserts = 0,'
                        . ' index_granularity = 8192',
                ]],
                $rows
            );
        }

        DB::connection(self::CLUSTER)->statement("INSERT INTO `{$events}` VALUES (1, 'a'), (2, 'b')");
        DB::connection('clickhouse2')->statement("SYSTEM SYNC REPLICA `{$events}`");
        $this->assertSame([['c' => '2']], DB::connection('clickhouse2')->select("SELECT toString(count()) AS c FROM `{$events}`"));

        $schema->table($events, function (SchemaBlueprint $table) {
            $table->integer('extra')->default(7);
            $table->comment('events');
        });

        foreach ($this->onEveryNode("SELECT name, type FROM system.columns WHERE database = currentDatabase() AND table = '{$events}' ORDER BY position") as $rows) {
            $this->assertSame(
                [['name' => 'id', 'type' => 'Int32'], ['name' => 'name', 'type' => 'String'], ['name' => 'extra', 'type' => 'Int32']],
                $rows
            );
        }
        foreach ($this->onEveryNode("SELECT comment FROM system.tables WHERE database = currentDatabase() AND name = '{$events}'") as $rows) {
            $this->assertSame([['comment' => 'events']], $rows);
        }

        $schema->rename($events, $renamed);

        foreach ($this->onEveryNode("SELECT name FROM system.tables WHERE database = currentDatabase() AND name IN ('{$events}', '{$renamed}')") as $rows) {
            $this->assertSame([['name' => $renamed]], $rows);
        }

        $uuid = (string) DB::connection('clickhouse')->scalar(
            "SELECT toString(uuid) FROM system.tables WHERE database = currentDatabase() AND name = '{$renamed}'"
        );
        $schema->dropIfExistsSync($renamed);

        foreach ($this->onEveryNode("SELECT name FROM system.tables WHERE database = currentDatabase() AND name = '{$renamed}'") as $rows) {
            $this->assertSame([], $rows);
        }
        $this->assertSame(
            [['c' => '0']],
            DB::connection('clickhouse')->select("SELECT toString(count()) AS c FROM system.zookeeper WHERE path = '/clickhouse/tables/{$uuid}'"),
            'drop()->sync() leaves no replica of the table in ZooKeeper.'
        );
    }

    public function testAnEngineOfTheFamilyKeepsItsParameters(): void
    {
        $versions = $this->table('versions');

        Schema::connection(self::CLUSTER)->create($versions, function (SchemaBlueprint $table) {
            $table->engine('ReplacingMergeTree(version)');
            $table->integer('id');
            $table->unsignedBigInteger('version');
        });

        foreach ($this->engineFullOnEveryNode($versions) as $rows) {
            $this->assertSame(
                [[
                    'engine_full' => "ReplicatedReplacingMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}', version)"
                        . ' ORDER BY id SETTINGS replicated_deduplication_window = 0,'
                        . ' replicated_deduplication_window_for_async_inserts = 0, index_granularity = 8192',
                ]],
                $rows
            );
        }
    }

    /**
     * A replicated table drops an insert identical to a recent one as a duplicate, while the MergeTree table of 3.0.0
     * kept it. The schema builder's replicated tables keep it, synchronous or asynchronous: Laravel's migrator logs the
     * same row in its migrations table again after a rollback, which a deduplicating table would silently drop.
     *
     * The first two inserts go with the server's default settings: asynchronous on ClickHouse 26.3 and 26.8
     * (async_insert = 1), synchronous on 24.8. The next two are asynchronous with async_insert_deduplicate = 1 on every
     * version. With replicated_deduplication_window = 0 alone, 26.3 dropped the second insert of each pair and 24.8
     * the second of the asynchronous pair, while 26.8 kept every insert; with
     * replicated_deduplication_window_for_async_inserts = 0 as well, each version keeps them all (24.8.14, 26.3.46 and
     * 26.8.21 checked; see SchemaGrammar::getReplicatedTableSettings()).
     */
    public function testAReplicatedTableKeepsAnInsertIdenticalToAnEarlierOne(): void
    {
        $logged = $this->table('logged');
        $cluster = DB::connection(self::CLUSTER);

        Schema::connection(self::CLUSTER)->create($logged, function (SchemaBlueprint $table) {
            $table->integer('id');
            $table->string('migration');
        });

        $insert = "INSERT INTO `{$logged}` VALUES (0, 'create_things')";
        $asynchronousInsert = "INSERT INTO `{$logged}` SETTINGS async_insert = 1, async_insert_deduplicate = 1,"
            . " wait_for_async_insert = 1 VALUES (1, 'create_things')";

        foreach ([$insert, $insert, $asynchronousInsert, $asynchronousInsert] as $statement) {
            $cluster->statement($statement);
        }
        DB::connection('clickhouse2')->statement("SYSTEM SYNC REPLICA `{$logged}`");

        foreach ($this->onEveryNode("SELECT id, toString(count()) AS c FROM `{$logged}` GROUP BY id ORDER BY id") as $rows) {
            $this->assertSame([['id' => 0, 'c' => '2'], ['id' => 1, 'c' => '2']], $rows);
        }

        $cluster->statement("ALTER TABLE `{$logged}` DELETE WHERE 1 SETTINGS mutations_sync = 2");
        $cluster->statement($insert);
        DB::connection('clickhouse2')->statement("SYSTEM SYNC REPLICA `{$logged}`");

        foreach ($this->onEveryNode("SELECT toString(count()) AS c FROM `{$logged}`") as $rows) {
            $this->assertSame([['c' => '1']], $rows, 'The row logged again after the delete is kept.');
        }
    }

    /**
     * withoutOnCluster() sends the blueprint's statements to the active node only, as 3.0.0 did, so a table that
     * 3.0.0's Schema::create() put on one node can still be changed and dropped.
     */
    public function testWithoutOnClusterChangesATableOfTheActiveNodeOnly(): void
    {
        $legacy = $this->table('legacy');
        $schema = Schema::connection(self::CLUSTER);

        DB::connection(self::CLUSTER)->statement("CREATE TABLE `{$legacy}` (`id` Int32) ENGINE = MergeTree() ORDER BY id");

        $schema->table($legacy, function (SchemaBlueprint $table) {
            $table->withoutOnCluster();
            $table->integer('extra');
        });

        $columns = array_filter($this->onEveryNode(
            "SELECT name FROM system.columns WHERE database = currentDatabase() AND table = '{$legacy}' ORDER BY position"
        ));
        $this->assertSame([[['name' => 'id'], ['name' => 'extra']]], array_values($columns), 'Only the active node has the table.');

        $schema->table($legacy, function (SchemaBlueprint $table) {
            $table->withoutOnCluster();
            $table->drop()->sync();
        });

        foreach ($this->onEveryNode("SELECT name FROM system.tables WHERE database = currentDatabase() AND name = '{$legacy}'") as $rows) {
            $this->assertSame([], $rows);
        }
    }

    /**
     * A connection with a cluster_name that lists only one node still creates its tables ON CLUSTER on every host,
     * so dropAllTables(), which db:wipe and migrate:fresh use, drops them ON CLUSTER too, and the next CREATE works.
     * createDatabase() and dropDatabaseIfExists() go ON CLUSTER as well.
     */
    public function testDropAllTablesReachesEveryHostOfTheCluster(): void
    {
        $database = "schema_cluster_wipe_{$this->suffix}";
        $cluster = Schema::connection(self::CLUSTER);
        $config = config('database.connections.' . self::CLUSTER);
        config(['database.connections.cluster-first-node' => array_merge($config, [
            'database' => $database,
            'cluster' => array_slice($config['cluster'], 0, 1),
        ])]);

        $cluster->createDatabase($database);

        try {
            foreach ($this->onEveryNode("SELECT name FROM system.databases WHERE name = '{$database}'") as $rows) {
                $this->assertSame([['name' => $database]], $rows);
            }

            $schema = Schema::connection('cluster-first-node');
            $create = fn () => $schema->create('events', fn (SchemaBlueprint $table) => $table->integer('id'));
            $tables = "SELECT name FROM system.tables WHERE database = '{$database}'";

            $create();
            foreach ($this->onEveryNode($tables) as $rows) {
                $this->assertSame([['name' => 'events']], $rows);
            }

            $schema->dropAllTables();
            foreach ($this->onEveryNode($tables) as $rows) {
                $this->assertSame([], $rows);
            }

            $create();
            foreach ($this->onEveryNode($tables) as $rows) {
                $this->assertSame([['name' => 'events']], $rows);
            }
        } finally {
            DB::purge('cluster-first-node');
            $cluster->dropDatabaseIfExists($database);
        }

        foreach ($this->onEveryNode("SELECT name FROM system.databases WHERE name = '{$database}'") as $rows) {
            $this->assertSame([], $rows);
        }
    }

    /**
     * While the cluster connection pretends, the schema builder's ON CLUSTER statements are logged and reach neither
     * node; change() still reads both nodes for its NULL check.
     */
    public function testPretendSendsNothingToEitherNode(): void
    {
        $existing = $this->table('pretend_existing');
        $created = $this->table('pretend_created');
        $renamed = $this->table('pretend_renamed');
        $schema = Schema::connection(self::CLUSTER);
        $schema->create($existing, function (SchemaBlueprint $table) {
            $table->integer('id');
            $table->string('name')->nullable();
        });

        $log = DB::connection(self::CLUSTER)->pretend(function () use ($schema, $existing, $created, $renamed): void {
            $schema->create($created, fn (SchemaBlueprint $table) => $table->integer('id'));
            $schema->table($existing, function (SchemaBlueprint $table) {
                $table->integer('extra');
                $table->string('name')->change();
            });
            $schema->rename($existing, $renamed);
            $schema->dropIfExistsSync($existing);
        });

        $onCluster = "ON CLUSTER 'company_cluster'";
        $this->assertSame(
            [
                "CREATE TABLE `{$created}` {$onCluster} (`id` Int32) ENGINE = ReplicatedMergeTree() ORDER BY (`id`)"
                . ' SETTINGS replicated_deduplication_window=0, replicated_deduplication_window_for_async_inserts=0',
                "ALTER TABLE `{$existing}` {$onCluster} ADD COLUMN `extra` Int32",
                "ALTER TABLE `{$existing}` {$onCluster} MODIFY COLUMN `name` String",
                "RENAME TABLE `{$existing}` TO `{$renamed}` {$onCluster}",
                "DROP TABLE IF EXISTS `{$existing}` {$onCluster} SYNC",
            ],
            array_column($log, 'query')
        );

        foreach ($this->onEveryNode("SELECT name FROM system.tables WHERE database = currentDatabase() AND name IN ('{$created}', '{$renamed}')") as $rows) {
            $this->assertSame([], $rows);
        }
        foreach ($this->onEveryNode("SELECT name, type FROM system.columns WHERE database = currentDatabase() AND table = '{$existing}' ORDER BY position") as $rows) {
            $this->assertSame([['name' => 'id', 'type' => 'Int32'], ['name' => 'name', 'type' => 'Nullable(String)']], $rows);
        }
    }

    public function testReplicatedFalseKeepsTheEngineOnEveryNode(): void
    {
        $local = $this->table('local');
        $schema = Schema::connection(self::CLUSTER);

        $schema->create($local, function (SchemaBlueprint $table) {
            $table->replicated(false);
            $table->integer('id');
        });
        $schema->table($local, fn (SchemaBlueprint $table) => $table->string('name')->nullable());

        foreach ($this->onEveryNode("SELECT engine FROM system.tables WHERE database = currentDatabase() AND name = '{$local}'") as $rows) {
            $this->assertSame([['engine' => 'MergeTree']], $rows);
        }
        foreach ($this->onEveryNode("SELECT name FROM system.columns WHERE database = currentDatabase() AND table = '{$local}' ORDER BY position") as $rows) {
            $this->assertSame([['name' => 'id'], ['name' => 'name']], $rows);
        }
    }

    /**
     * A table that is not replicated holds other rows on each node, so change() checks every host before it sends a
     * type that refuses NULL. The change that runs gives a default(), which ClickHouse 26.3 and 26.8 require when a
     * MODIFY COLUMN turns a Nullable type into one without NULL.
     */
    public function testChangeRefusesNullValuesOfAnyNode(): void
    {
        $local = $this->table('null_check');
        $schema = Schema::connection(self::CLUSTER);

        $schema->create($local, function (SchemaBlueprint $table) {
            $table->replicated(false);
            $table->integer('id');
            $table->string('name')->nullable();
        });
        DB::connection('clickhouse')->statement("INSERT INTO `{$local}` VALUES (1, 'a')");
        DB::connection('clickhouse2')->statement("INSERT INTO `{$local}` VALUES (2, NULL)");

        try {
            $schema->table($local, fn (SchemaBlueprint $table) => $table->string('name')->change());
            $this->fail('change() should refuse the NULL value that node2 holds.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith("The column [name] of the table [{$local}] holds NULL values", $exception->getMessage());
        }

        foreach ($this->onEveryNode("SELECT type FROM system.columns WHERE database = currentDatabase() AND table = '{$local}' AND name = 'name'") as $rows) {
            $this->assertSame([['type' => 'Nullable(String)']], $rows);
        }

        DB::connection('clickhouse2')->statement("ALTER TABLE `{$local}` UPDATE name = 'b' WHERE name IS NULL SETTINGS mutations_sync = 2");
        $schema->table($local, fn (SchemaBlueprint $table) => $table->string('name')->default('')->change());

        foreach ($this->onEveryNode("SELECT type FROM system.columns WHERE database = currentDatabase() AND table = '{$local}' AND name = 'name'") as $rows) {
            $this->assertSame([['type' => 'String']], $rows);
        }
    }

    /**
     * The {uuid} of the default replica path is new for every CREATE, so a table dropped without SYNC can be created
     * again at once.
     */
    public function testATableDroppedWithoutSyncCanBeCreatedAgainAtOnce(): void
    {
        $again = $this->table('again');
        $schema = Schema::connection(self::CLUSTER);
        $create = fn () => $schema->create($again, fn (SchemaBlueprint $table) => $table->integer('id'));

        $create();
        $schema->drop($again);
        $create();

        foreach ($this->onEveryNode("SELECT engine FROM system.tables WHERE database = currentDatabase() AND name = '{$again}'") as $rows) {
            $this->assertSame([['engine' => 'ReplicatedMergeTree']], $rows);
        }
    }

    /**
     * ClickHouse runs an ON CLUSTER statement outside the session, so DROP TABLE ... ON CLUSTER would drop the
     * database table of every node instead of the temporary table of the session.
     */
    public function testAnOnClusterStatementOnATemporaryTableOfTheSessionIsRefused(): void
    {
        $shadowed = $this->table('shadowed');
        $schema = Schema::connection(self::CLUSTER);
        $schema->create($shadowed, fn (SchemaBlueprint $table) => $table->integer('id'));

        $messages = DB::connection(self::CLUSTER)->session(function (Connection $connection) use ($schema, $shadowed): array {
            $schema->create($shadowed, function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('id');
            });
            $connection->statement("INSERT INTO `{$shadowed}` VALUES (1)");

            $messages = [];
            foreach ([
                fn () => $schema->drop($shadowed),
                fn () => $schema->rename($shadowed, $shadowed . '_renamed'),
                fn () => $schema->table($shadowed, fn (SchemaBlueprint $table) => $table->integer('extra')),
            ] as $statement) {
                try {
                    $statement();
                    $messages[] = null;
                } catch (QueryException $exception) {
                    $messages[] = $exception->getMessage();
                }
            }

            $schema->table($shadowed, function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('extra');
            });
            $messages[] = $connection->select("SELECT name FROM system.columns WHERE database = '' AND table = '{$shadowed}' ORDER BY position");
            $schema->dropTemporary($shadowed);

            return $messages;
        });

        foreach (['DROP TABLE', 'RENAME TABLE', 'ALTER TABLE'] as $index => $statement) {
            $this->assertStringStartsWith(
                "Cannot send {$statement} ... ON CLUSTER for {$shadowed}: the session has a temporary table named {$shadowed}",
                (string) $messages[$index]
            );
        }
        $this->assertSame([['name' => 'id'], ['name' => 'extra']], $messages[3]);

        foreach ($this->onEveryNode("SELECT name FROM system.columns WHERE database = currentDatabase() AND table = '{$shadowed}'") as $rows) {
            $this->assertSame([['name' => 'id']], $rows);
        }
    }

    /**
     * A temporary table goes to the active node of the session, without ON CLUSTER, which ClickHouse refuses for one.
     */
    public function testATemporaryTableOnAClusterConnection(): void
    {
        $temporary = $this->table('temporary');
        $connection = DB::connection(self::CLUSTER);
        $connection->enableQueryLog();

        $rows = $connection->session(function (Connection $connection) use ($temporary): array {
            Schema::connection(self::CLUSTER)->create($temporary, function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('id');
            });
            $connection->statement("INSERT INTO `{$temporary}` VALUES (1)");

            return $connection->select("SELECT id FROM `{$temporary}`");
        });

        $this->assertSame([['id' => 1]], $rows);
        $this->assertContains("CREATE TEMPORARY TABLE `{$temporary}` (`id` Int32)", array_column($connection->getQueryLog(), 'query'));
        foreach ($this->onEveryNode("SELECT name FROM system.tables WHERE name = '{$temporary}'") as $rows) {
            $this->assertSame([], $rows);
        }
    }

    /**
     * ClickHouse creates the table of the database, with or without ON CLUSTER, also when a temporary table of the
     * session has its name, so Schema::create() inside a session is not refused.
     */
    public function testACreateInASessionCreatesTheTableOfTheDatabaseOnEveryNode(): void
    {
        $name = $this->table('created_in_session');
        $schema = Schema::connection(self::CLUSTER);

        DB::connection(self::CLUSTER)->session(function () use ($schema, $name): void {
            $schema->create($name, function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('id');
            });
            $schema->create($name, function (SchemaBlueprint $table) {
                $table->integer('id');
                $table->string('name');
            });
        });

        foreach ($this->onEveryNode("SELECT name FROM system.columns WHERE database = currentDatabase() AND table = '{$name}' ORDER BY position") as $rows) {
            $this->assertSame([['name' => 'id'], ['name' => 'name']], $rows);
        }
    }

    /**
     * Name a table of this test, and remember it so that tearDown() drops it.
     */
    private function table(string $name): string
    {
        $table = "schema_cluster_{$name}_{$this->suffix}";
        $this->tables[] = $table;

        return $table;
    }

    /**
     * Read the engine_full of a table on each node. A sorting key of one column is read without its parentheses:
     * ClickHouse 26.8 keeps those of the ORDER BY (`id`) that the schema builder writes, as ORDER BY (id), where 24.8
     * and 26.3 print ORDER BY id.
     *
     * @return array<string, array<int, array{engine_full: string}>> The rows, by connection name
     */
    private function engineFullOnEveryNode(string $table): array
    {
        return array_map(
            fn (array $rows): array => array_map(
                fn (array $row): array => ['engine_full' => preg_replace('/ ORDER BY \((\w+)\)/', ' ORDER BY $1', $row['engine_full'])],
                $rows
            ),
            $this->onEveryNode("SELECT engine_full FROM system.tables WHERE database = currentDatabase() AND name = '{$table}'")
        );
    }

    /**
     * Run a select on each node of the cluster, through its own connection.
     *
     * @return array<string, array<int, array<string, mixed>>> The rows, by connection name
     */
    private function onEveryNode(string $sql): array
    {
        $rows = [];
        foreach (self::NODES as $node) {
            $rows[$node] = DB::connection($node)->select($sql);
        }

        return $rows;
    }
}
