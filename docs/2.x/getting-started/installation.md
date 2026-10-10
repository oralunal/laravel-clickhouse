# Installation

## Install the package

```sh
composer require oralunal/phpclickhouse-laravel:^2.0.2
```

Laravel finds the service provider through package auto-discovery.
When auto-discovery is off, add `PhpClickHouseLaravel\ClickhouseServiceProvider::class` to `bootstrap/providers.php`.

## Configure the connection

For one server, set these values in `.env`:

```dotenv
CLICKHOUSE_HOST=localhost
CLICKHOUSE_PORT=8123
CLICKHOUSE_DATABASE=default
CLICKHOUSE_USERNAME=default
CLICKHOUSE_PASSWORD=
# Only for an HTTPS connection
CLICKHOUSE_HTTPS=true
```

The service provider merges the default values into `config('database.connections.clickhouse')`. You do not have to change a config file.

To change other defaults, publish the config file:

```sh
php artisan vendor:publish --tag=clickhouse-config
```

The command writes `config/clickhouse.php`. Its values replace the defaults of the package. You can also add more connections to it. See [Multiple connections](/2.x/advanced/multiple-connections).

A connection in `config/database.php` replaces both:

```php
'clickhouse' => [
    'driver' => 'clickhouse',
    'host' => env('CLICKHOUSE_HOST'),
    'port' => env('CLICKHOUSE_PORT', '8123'),
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

## Options

| Option | `.env` variable | Default | Description |
| --- | --- | --- | --- |
| `host` | `CLICKHOUSE_HOST` | `127.0.0.1` | Host of the server |
| `port` | `CLICKHOUSE_PORT` | `8123` | HTTP port of the server |
| `database` | `CLICKHOUSE_DATABASE` | `default` | Database of the connection |
| `username` | `CLICKHOUSE_USERNAME` | `default` | User name |
| `password` | `CLICKHOUSE_PASSWORD` | `''` | Password |
| `https` | `CLICKHOUSE_HTTPS` | `false` | Use HTTPS |
| `timeout_connect` | `CLICKHOUSE_TIMEOUT_CONNECT` | `2` | Seconds to wait for a connection |
| `timeout_query` | `CLICKHOUSE_TIMEOUT_QUERY` | `2` | Seconds that a query can take |
| `retries` | `CLICKHOUSE_RETRIES` | `0` | Number of retries of a failed request. See [Timeouts and retries](/2.x/advanced/timeouts-and-retries). |
| `settings` | | `[]` | ClickHouse settings for each query of the connection |
| `fix_default_query_builder` | | `true` | `DB::connection('clickhouse')->table()` returns the package's query builder. See [Known limitations](/2.x/reference/known-limitations#the-default-query-builder). |
| `cluster` | | | List of nodes. It replaces `host` and `port`. See [Clusters](/2.x/advanced/clusters). |
| `cluster_name` | | | Name of a cluster of the server config, for `ON CLUSTER`. See [Clusters](/2.x/advanced/clusters). |

## Next steps

- [Define a model](/2.x/models/defining-models)
- [Migrations](/2.x/schema/migrations)
