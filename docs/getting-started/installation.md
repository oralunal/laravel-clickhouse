# Installation

To upgrade from an earlier version, see [Upgrade](/getting-started/upgrading).

## Install the package

1. Install the package with Composer:

   ```sh
   composer require oralunal/laravel-clickhouse
   ```

2. Set the connection values in `.env`:

   ```dotenv
   CLICKHOUSE_HOST=localhost
   CLICKHOUSE_PORT=8123
   CLICKHOUSE_DATABASE=default
   CLICKHOUSE_USERNAME=default
   CLICKHOUSE_PASSWORD=
   # Only for an HTTPS connection:
   CLICKHOUSE_HTTPS=true
   ```

Laravel finds the service provider through package auto-discovery. The provider adds the
`clickhouse` connection to `config('database.connections')`. A single-node setup needs no other change.

If you disabled auto-discovery, add `Oralunal\LaravelClickHouse\ClickhouseServiceProvider::class`
to `bootstrap/providers.php`.

## Change the defaults

To change more than the `.env` values, publish the config file:

```sh
php artisan vendor:publish --tag=clickhouse-config
```

This command writes `config/clickhouse.php`. The values in this file override the packaged defaults.
Each top-level key of the file is one connection, so you can add more connections there.

You can also define the connection in `config/database.php`. This definition has the highest priority:

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
    'https' => (bool) env('CLICKHOUSE_HTTPS', false),
    'retries' => env('CLICKHOUSE_RETRIES', 0),
    'settings' => [
        'max_partitions_per_insert_block' => 300,
    ],
],
```

[Configuration](/getting-started/configuration) lists all options.

## Use the connection

The connection works with Laravel's `DB` facade:

```php
use Illuminate\Support\Facades\DB;

$rows = DB::connection('clickhouse')->select('SELECT * FROM summing_url_views LIMIT 2');
```

To use the smi2/phpClickHouse client directly, get it from the connection:

```php
/** @var \ClickHouseDB\Client $client */
$client = DB::connection('clickhouse')->getClient();
$statement = $client->select('SELECT * FROM summing_url_views LIMIT 2');
```

For the client API, see the [smi2/phpClickHouse README](https://github.com/smi2/phpClickHouse/blob/master/README.md).

## Next steps

- [Define a model](/models/defining-models).
- [Write a migration](/schema/migrations).
- [Query with the query builder](/query-builder/basics).
