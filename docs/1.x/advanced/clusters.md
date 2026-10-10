# Clusters

## Configure the nodes

- All ClickHouse nodes must have the same database name, user name and password.
- Reads and writes go to the first node that answers.
- Migrations run on all nodes. If a node does not answer, the migration throws.
- `ReplicatedMergeTree` uses the `{replica}` and `{shard}` macros. Define them in the configuration of each ClickHouse server, in `config.xml` or `config.d/*.xml`, not in this package:

  ```xml
  <macros>
      <shard>01</shard>
      <replica>clickhouse01</replica>
  </macros>
  ```

  See `tests/docker/clickhouse01/config.xml` in the repository, or the [ClickHouse macros](https://clickhouse.com/docs/en/operations/settings/settings#server_settings-macros).

Give the nodes in `cluster`:

```php
'clickhouse' => [
    'driver' => 'clickhouse',
    'cluster' => [
        ['host' => 'clickhouse01', 'port' => '8123'],
        ['host' => 'clickhouse02', 'port' => '8123'],
    ],
    // Optional. Migration::createMergeTree() adds ON CLUSTER '<name>'.
    'cluster_name' => 'company_cluster',
    'database' => env('CLICKHOUSE_DATABASE', 'default'),
    'username' => env('CLICKHOUSE_USERNAME', 'default'),
    'password' => env('CLICKHOUSE_PASSWORD', ''),
    'timeout_connect' => env('CLICKHOUSE_TIMEOUT_CONNECT', 2),
    'timeout_query' => env('CLICKHOUSE_TIMEOUT_QUERY', 2),
    'https' => (bool) env('CLICKHOUSE_HTTPS', null),
    'retries' => env('CLICKHOUSE_RETRIES', 0),
    'settings' => [ // Optional
        'max_partitions_per_insert_block' => 300,
    ],
    'fix_default_query_builder' => true,
],
```

`cluster_name` must be a cluster of the server configuration (`remote_servers`).

## ON CLUSTER

With `cluster_name`, `createMergeTree()` adds `ON CLUSTER '<cluster_name>'`. Then add `->ifNotExists()`:

```php
static::createMergeTree('my_table', fn(MergeTree $table) => $table
    ->ifNotExists()
    ->columns([...])
    ->orderBy('id')
);
```

The package sends a migration to each node in turn. `ON CLUSTER` creates the table on all nodes at the first node. Without `IF NOT EXISTS`, the statement of the second node fails with `TABLE_ALREADY_EXISTS`.

Without `cluster_name`, each node creates the table, because the migration goes to each node.

## Replicated tables

```php
return new class extends \PhpClickHouseLaravel\Migration
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
        static::write('DROP TABLE my_table');
    }
};
```

## Change the active node

```php
$row = new MyTable();
echo $row->getThisClient()->getConnectHost();
// clickhouse01
$row->resolveConnection()->getCluster()->slideNode();
echo $row->getThisClient()->getConnectHost();
// clickhouse02
```

On 1.x, `slideNode()` stays on the current node when the next node does not answer. On a cluster of three or more nodes, it does not reach the nodes after a node that is down. 4.0 tries the next nodes in turn.
