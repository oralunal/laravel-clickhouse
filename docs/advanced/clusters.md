# Clusters

## Configure the nodes

Replace `host` and `port` with a `cluster` list of nodes:

```php
'clickhouse' => [
    'driver' => 'clickhouse',
    'cluster' => [
        ['host' => 'clickhouse01', 'port' => '8123'],
        ['host' => 'clickhouse02', 'port' => '8123'],
    ],
    // Optional: the cluster of remote_servers in the server config, for ON CLUSTER.
    'cluster_name' => 'company_cluster',
    // Optional: ON CLUSTER for delete(), update(), truncate() and optimize().
    'use_on_cluster' => (bool) env('CLICKHOUSE_USE_ON_CLUSTER', false),
    'database' => env('CLICKHOUSE_DATABASE', 'default'),
    'username' => env('CLICKHOUSE_USERNAME', 'default'),
    'password' => env('CLICKHOUSE_PASSWORD', ''),
    'timeout_connect' => env('CLICKHOUSE_TIMEOUT_CONNECT', 2),
    'timeout_query' => env('CLICKHOUSE_TIMEOUT_QUERY', 2),
    'retries' => env('CLICKHOUSE_RETRIES', 0),
    'retry_on' => env('CLICKHOUSE_RETRY_ON', 'any'),
],
```

::: warning
- All nodes must have the same database name, user name and password.
- Reads and writes go to the first node that answers.
- Define the `{replica}` and `{shard}` macros on each server, in `config.xml` or `config.d/*.xml`.
:::

```xml
<macros>
    <shard>01</shard>
    <replica>clickhouse01</replica>
</macros>
```

See `tests/docker/clickhouse01/config.xml` in the repository, or the [ClickHouse macros](https://clickhouse.com/docs/en/operations/settings/settings#server_settings-macros).

The package trims `cluster_name`, so `' company_cluster '` gives `ON CLUSTER 'company_cluster'`. A blank name is no name.

## Change the active node

```php
$row = new MyTable();
echo $row->getThisClient()->getConnectHost();
// clickhouse01
$row->resolveConnection()->getCluster()->slideNode();
echo $row->getThisClient()->getConnectHost();
// clickhouse02
```

`slideNode()` tries the next nodes in turn and skips a node that does not answer. If no other node answers, it stays on the current node.
While `session()` runs, `slideNode()` throws a `LogicException`, because the session exists on the active node only.

| Method | Does |
| --- | --- |
| `Connection::getCluster()` | Returns the `Oralunal\LaravelClickHouse\Cluster` of the connection. A connection without `cluster` has a cluster of one node. |
| `Cluster::getActiveNode()` | Returns the smi2 client of the active node |
| `Cluster::slideNode()` | Makes the next node that answers the active node |
| `Cluster::pinActiveNode()`, `unpinActiveNode()` | Keep the active node. While it is pinned, `slideNode()` throws. `session()` uses them. Each `pinActiveNode()` needs one `unpinActiveNode()`. |
| `Cluster::isActiveNodePinned()` | `true` while the active node is pinned |
| `Cluster::write($sql)` | Sends a statement to all nodes, one after the other |
| `Connection::getClusterName()` | The trimmed `cluster_name`, or `null` |
| `Connection::hasClusterNodes()` | `true` when `cluster` lists nodes |
| `Connection::getDefaultCluster()` | The cluster of the `ON CLUSTER` clause of mutations: `cluster_name` when `use_on_cluster` is on, else `null` |

## Statements on all nodes

- `Migration::write()` and `createMergeTree()` run on all nodes, one after the other. If a node does not answer, the migration throws.
- `DB::connection('clickhouse')->statementOnEveryNode($sql)` sends a statement to all nodes, one after the other, and logs it one time. It stops at the first node that fails.

With `cluster_name`, add `ifNotExists()` to `createMergeTree()`. The first node creates the table on all nodes with `ON CLUSTER`, and the next nodes send the same statement:

```php
static::createMergeTree('my_table', fn (MergeTree $table) => $table
    ->ifNotExists()
    ->columns([...])
    ->orderBy('id')
);
```

## Replicated tables with a fixed path

```php
return new class extends \Oralunal\LaravelClickHouse\Migration
{
    public function up()
    {
        static::write("
            CREATE TABLE my_table (
                id UInt32,
                created_at DateTime,
                field_one String,
                field_two Int32
            )
            ENGINE = ReplicatedMergeTree('/clickhouse/tables/default.my_table', '{replica}')
            ORDER BY (id)
        ");
    }

    public function down()
    {
        static::write('DROP TABLE my_table SYNC');
    }
};
```

Drop a table with a fixed replica path with `SYNC`. Without it, an `Atomic` database removes the replica after
`database_atomic_delay_before_drop_table_sec` (480 seconds). A new `CREATE` before then fails with `REPLICA_ALREADY_EXISTS`.
In Laravel's schema builder, use `Schema::dropSync()` or `Schema::dropIfExistsSync()`.

## The schema builder on a cluster

With `cluster_name`, Laravel's schema builder sends each statement one time, to the active node, with `ON CLUSTER`. ClickHouse runs it on all hosts of the cluster.
When the connection also lists its nodes in `cluster`, a MergeTree engine becomes replicated:

```php
Schema::create('events', function (SchemaBlueprint $table) {
    $table->integer('id');
    $table->string('name');
});
// CREATE TABLE `events` ON CLUSTER 'company_cluster' (`id` Int32, `name` String)
//   ENGINE = ReplicatedMergeTree() ORDER BY (`id`)
//   SETTINGS replicated_deduplication_window=0, replicated_deduplication_window_for_async_inserts=0

Schema::table('events', fn (SchemaBlueprint $table) => $table->string('extra')->nullable());
// ALTER TABLE `events` ON CLUSTER 'company_cluster' ADD COLUMN `extra` Nullable(String)

Schema::rename('events', 'events_old');
// RENAME TABLE `events` TO `events_old` ON CLUSTER 'company_cluster'

Schema::dropIfExistsSync('events_old');
// DROP TABLE IF EXISTS `events_old` ON CLUSTER 'company_cluster' SYNC
```

| Connection | Statements | Engine |
| --- | --- | --- |
| No `cluster_name` | Active node only | As given |
| `cluster_name`, no `cluster` nodes | `ON CLUSTER` | As given. The `migrations` table is not replicated, so each host keeps its own rows. |
| `cluster_name` and `cluster` nodes | `ON CLUSTER` | MergeTree family becomes replicated |

- `ON CLUSTER` applies to `CREATE`, each `ALTER TABLE` of `Schema::table()`, `RENAME TABLE`, `DROP TABLE`, `Schema::createDatabase()`, `Schema::dropDatabaseIfExists()`, and the `DROP` statements of `Schema::dropAllTables()` and `dropAllViews()`. Laravel's `migrations` table also gets it.
- The replicated engine keeps its parameters and gets no replica path. `MergeTree()` becomes `ReplicatedMergeTree()`, and `ReplacingMergeTree(version)` becomes `ReplicatedReplacingMergeTree(version)`.
  ClickHouse uses `/clickhouse/tables/{uuid}/{shard}`. The `{uuid}` is new for each `CREATE`, so you can create a dropped table again at once.
- An engine with the name `Replicated...` or `Shared...`, and an engine outside the MergeTree family, such as `Memory`, stay as given.

### Identical inserts

A replicated table drops an insert that is identical to an earlier insert. A `MergeTree` table keeps it.
So the schema builder gives its replicated tables `replicated_deduplication_window=0` and `replicated_deduplication_window_for_async_inserts=0`.
Then the table keeps all inserts, synchronous or asynchronous, on 24.8, 26.3 and 26.8.

- `settings()` with another value overrides each setting. `null` gives the setting to the server: `$table->settings(['replicated_deduplication_window' => null])`.
- An engine that you name as replicated, and an `engine()` with its own `SETTINGS` clause, get no setting. Give such a table the two settings with `settings()` to keep identical inserts.

### Blueprint options

- `$table->replicated(false)` keeps the engine as given, for a table that each host keeps for itself.
- `$table->replicated()` makes the engine replicated on all connections. Outside `ON CLUSTER`, ClickHouse 24.8 refuses a replicated engine without a path with `BAD_ARGUMENTS`.
- `$table->withoutOnCluster()` sends the statements to the active node only, without `ON CLUSTER` and without a replicated engine, as 3.x did.
  Use it for a table that 3.x created on one node: `Schema::table('legacy', function (SchemaBlueprint $table) { $table->withoutOnCluster(); $table->drop(); })` sends ``DROP TABLE `legacy` ``.
- `change()` checks all hosts for `NULL` values before it makes a column non-nullable:
  ``SELECT 1 FROM clusterAllReplicas('company_cluster', 'default', 'visits') WHERE `referrer` IS NULL LIMIT 1 SETTINGS apply_deleted_mask = 0``.

::: warning
An `ON CLUSTER` statement waits for all hosts, up to `distributed_ddl_task_timeout` (180 seconds). The packaged `timeout_query` is 2 seconds.
Increase `timeout_query` for migrations, and set `retry_on` to `unsent`.
When one host fails, the statement throws, as in `There was an error on [clickhouse02:9000]: ...`. The other hosts keep the change.
:::

## ON CLUSTER for mutations

See [Updates and deletions](/query-builder/writing-data#on-cluster).
