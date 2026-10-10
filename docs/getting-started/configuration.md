# Configuration

A ClickHouse connection uses the `clickhouse` driver. The package merges its defaults into
`config('database.connections.clickhouse')`. The values of `config/clickhouse.php` override the
defaults. A connection in `config/database.php` overrides both. See [Installation](/getting-started/installation).

## Options

| Option | `.env` variable | Default | Description |
| --- | --- | --- | --- |
| `host` | `CLICKHOUSE_HOST` | `127.0.0.1` | Host of the server |
| `port` | `CLICKHOUSE_PORT` | `8123` | HTTP port of the server |
| `database` | `CLICKHOUSE_DATABASE` | `default` | Database of the connection |
| `username` | `CLICKHOUSE_USERNAME` | `default` | User name |
| `password` | `CLICKHOUSE_PASSWORD` | `''` | Password |
| `https` | `CLICKHOUSE_HTTPS` | `false` | Use HTTPS |
| `timeout_connect` | `CLICKHOUSE_TIMEOUT_CONNECT` | `2` | Seconds to wait for a connection. See [Timeouts](/advanced/timeouts-and-retries#timeouts). |
| `timeout_query` | `CLICKHOUSE_TIMEOUT_QUERY` | `2` | Seconds that a request can take. See [Timeouts](/advanced/timeouts-and-retries#timeouts). |
| `retries` | `CLICKHOUSE_RETRIES` | `0` | Number of retries of a failed request. See [Retries](/advanced/timeouts-and-retries#retries). |
| `retry_on` | `CLICKHOUSE_RETRY_ON` | `any` | `any` or `unsent`: the failed requests that the retries send again |
| `settings` | | `[]` | ClickHouse settings for each query of the connection |
| `fix_default_query_builder` | | `true` | `true`: `DB::connection()->table()` returns the package's query builder. `false`: it returns [Laravel's query builder](/query-builder/laravel-query-builder). |
| `use_lightweight_delete` | `CLICKHOUSE_USE_LIGHTWEIGHT_DELETE` | `false` | `delete()` without an argument sends a lightweight `DELETE FROM`. See [Deletions](/query-builder/writing-data#deletions). |
| `engine` | `CLICKHOUSE_ENGINE` | `null` | Engine of a table that `Schema::create()` creates without `engine()`. `null` is `MergeTree()`. |
| `exact_integer_types` | `CLICKHOUSE_EXACT_INTEGER_TYPES` | `false` | Integer types with their own width in `Schema::create()`. See [Column types](/schema/schema-builder#column-types). |
| `default_index_type` | `CLICKHOUSE_DEFAULT_INDEX_TYPE` | `null` | Type of a data-skipping index that has no type, such as `minmax` |
| `datetime_precision` | `CLICKHOUSE_DATETIME_PRECISION` | `second` | `second` or `microsecond`. See [Dates and times](/query-builder/dates). |
| `insert_format` | `CLICKHOUSE_INSERT_FORMAT` | `Values` | `Values` or `JSONEachRow`. See [JSONEachRow inserts](/models/inserting-rows#jsoneachrow-inserts). |
| `use_on_cluster` | `CLICKHOUSE_USE_ON_CLUSTER` | `false` | Send `delete()`, `update()`, `truncate()` and `optimize()` `ON CLUSTER`. It needs `cluster_name`. |
| `cluster` | | | List of nodes. It replaces `host` and `port`. See [Clusters](/advanced/clusters). |
| `cluster_name` | | | Cluster name of the server config, for `ON CLUSTER`. See [Clusters](/advanced/clusters). |

The options `datetime_precision`, `insert_format` and `use_on_cluster` are off by default.
Without them, the package writes dates, inserts and mutations as 3.x did.

## Validation

The connection checks its options when Laravel creates it, before it pings a node.
An incorrect value throws an `InvalidArgumentException`:

```text
The [datetime_precision] option of the ClickHouse connection [clickhouse] must be 'second' or 'microsecond', [millisecond] given.
```

`use_on_cluster` without `cluster_name` also throws.

::: warning
Write `datetime_precision` and `insert_format` as strings in config files.
`php artisan config:cache` cannot write an enum instance into the cached config.
:::

## Settings

The `settings` option sends ClickHouse settings with each query of the connection:

```php
'settings' => [
    'max_partitions_per_insert_block' => 300,
],
```

To set a setting for one query, use [`settings()`](/query-builder/basics#settings) of the query builder.

## Read the options

| Method | Returns |
| --- | --- |
| `getDateTimePrecision()` | `second` or `microsecond` |
| `getInsertFormat()` | `Values` or `JSONEachRow` |
| `getClusterName()`, `getDefaultCluster()` | See [Clusters](/advanced/clusters#change-the-active-node). |
| `getServerVersion()` | The version of the server of the active node, for example `24.8.14.39` |
| `getDriverTitle()` | `ClickHouse`, the name that `php artisan db:show` shows |
| `newBuilderGrammar()` | A grammar of the package's query builder that writes dates at `datetime_precision` |

Call them on the connection: `DB::connection('clickhouse')->getServerVersion()`.
`Connection::createWithClient($config)` makes a connection and its nodes from a config array. The `clickhouse` driver uses it. It checks the options before it pings a node.
