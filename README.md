![Tests](https://github.com/oralunal/phpclickhouse-laravel/actions/workflows/tests.yml/badge.svg)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/oralunal/phpclickhouse-laravel.svg?style=flat-square)](https://packagist.org/packages/oralunal/phpclickhouse-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/oralunal/phpclickhouse-laravel.svg?style=flat-square)](https://packagist.org/packages/oralunal/phpclickhouse-laravel)

# phpClickHouse-laravel

Laravel adapter for ClickHouse, built on
[smi2/phpClickHouse](https://github.com/smi2/phpClickHouse) for HTTP transport
and query execution. Apart from Laravel, that is the only dependency. The query
builder, the schema builder and the enum base class it uses are bundled under
this package's namespace; see [Credits](#credits).

## Features

- Eloquent-flavored `BaseModel` (`create`, `save`, `insertBulk`, `insertAssoc`, `where`, pagination)
- `PhpClickHouseLaravel\Migration` base class for ClickHouse DDL migrations (single-node and cluster)
- `php artisan schema:dump [--prune]` support for squashing migrations into a schema file
- `php artisan clickhouse:install-skills` installs coding-agent skills, such as `/plc-upgrade-1x-to-2x` for the 1.x → 2.x upgrade
- Query builder integration with `settings()`, `chunk()`, and ClickHouse-specific grammar
- Column casts (currently `boolean`) applied on insert
- Model events: `creating`, `created`, `saved`
- Retry-on-network-error support (`retries` config key)
- Buffer engine support via `$tableForInserts` / `$tableSources`
- In-memory buffered inserts: accumulate rows with `Model::buffer()` and send them as a single HTTP request with `Model::flushBuffer()` (auto-flushed on script shutdown)
- `OPTIMIZE`, `TRUNCATE`, `ALTER TABLE ... DELETE`, `ALTER TABLE ... UPDATE` helpers
- Multi-instance and cluster-mode connections with active-node rotation
- Publishable default config — `.env` is enough for most setups

Underneath, smi2/phpClickHouse handles HTTP transport (curl-only, no PDO).
More: https://github.com/smi2/phpClickHouse#features

## Prerequisites

- PHP 8.5+
- Laravel 13+
- ClickHouse server 24.x (older 20+ versions usually work but are no longer tested)

## Installation

Upgrading from 1.x? Run `php artisan clickhouse:install-skills`, then `/plc-upgrade-1x-to-2x` in your coding agent. To upgrade by hand, see [UPGRADE.md](UPGRADE.md).

**1.** Install via composer:

```sh
composer require oralunal/phpclickhouse-laravel
```

The service provider is registered automatically via Laravel package
auto-discovery. If you have auto-discovery disabled, add
`PhpClickHouseLaravel\ClickhouseServiceProvider::class` to
`bootstrap/providers.php` (Laravel 11+) or `config/app.php` (Laravel 10 and below).

**2.** Configure the connection.

The simplest setup — just set these in your `.env`:

```dotenv
CLICKHOUSE_HOST=localhost
CLICKHOUSE_PORT=8123
CLICKHOUSE_DATABASE=default
CLICKHOUSE_USERNAME=default
CLICKHOUSE_PASSWORD=
# only if you use an https connection
CLICKHOUSE_HTTPS=true
```

The service provider merges sensible defaults into
`config('database.connections.clickhouse')` for you. No config edits needed
for a single-node setup.

If you want to customize defaults beyond what env vars cover, publish
the config:

```sh
php artisan vendor:publish --tag=clickhouse-config
```

That drops a `config/clickhouse.php` into your app. Values you set there
override the packaged defaults, and you can add further connections to the
same file. Alternatively, you can define the connection yourself in
`config/database.php`, which outranks both:

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
    'settings' => [ // optional
        'max_partitions_per_insert_block' => 300,
    ],
    'fix_default_query_builder' => true,
],
```

## Usage

You can use smi2/phpClickHouse directly:

```php
/** @var \ClickHouseDB\Client $db */
$db = DB::connection('clickhouse')->getClient();
$statement = $db->select('SELECT * FROM summing_url_views LIMIT 2');
```

More about `$db`: https://github.com/smi2/phpClickHouse/blob/master/README.md

#### Or use the Eloquent-like ORM

**1.** Add a model:

```php
<?php

namespace App\Models\Clickhouse;

use PhpClickHouseLaravel\BaseModel;

class MyTable extends BaseModel
{
    // Optional. Derived from class name when omitted: MyTable => my_tables.
    protected $table = 'my_table';
}
```

**2.** Add a migration:

```php
<?php

class CreateMyTable extends \PhpClickHouseLaravel\Migration
{
    public function up()
    {
        static::write('
            CREATE TABLE my_table (
                id UInt32,
                created_at DateTime,
                field_one String,
                field_two Int32
            )
            ENGINE = MergeTree()
            ORDER BY (id)
        ');
    }

    public function down()
    {
        static::write('DROP TABLE my_table');
    }
}
```

Or use the Schema Builder:

```php
<?php

use PhpClickHouseLaravel\ClickhouseSchemaBuilder\Expression;
use PhpClickHouseLaravel\ClickhouseSchemaBuilder\Tables\MergeTree;

class CreateMyTable extends \PhpClickHouseLaravel\Migration
{
    public function up()
    {
        static::createMergeTree('my_table', fn(MergeTree $table) => $table
            ->columns([
                $table->uInt32('id'),
                $table->datetime('created_at', 3)->default(new Expression('now64()')),
                $table->string('field_one'),
                $table->int32('field_two'),
            ])
            ->orderBy('id')
        );
    }

    public function down()
    {
        static::write('DROP TABLE my_table');
    }
}
```

For another engine of the MergeTree family, such as `ReplacingMergeTree`,
pass its name and parameters to `engine()`:

```php
use PhpClickHouseLaravel\ClickhouseSchemaBuilder\Engine;

static::createMergeTree('my_table', fn(MergeTree $table) => $table
    ->columns([
        $table->uInt32('id'),
        $table->uInt64('version'),
    ])
    ->orderBy('id')
    ->engine(Engine::REPLACING_MERGE_TREE, 'version')
);
```

On a [`cluster`](#cluster-mode) connection the engine becomes
`ReplicatedReplacingMergeTree`. Read the deduplicated rows with
`MyTable::select()->final()`, or merge them with `MyTable::optimize(true)`.

**3.** Insert data.

One row:

```php
$model = MyTable::create(['model_name' => 'model 1', 'some_param' => 1]);
# or
$model = MyTable::make(['model_name' => 'model 1']);
$model->some_param = 1;
$model->save();
# or
$model = new MyTable();
$model->fill(['model_name' => 'model 1', 'some_param' => 1])->save();
```

Bulk insert:

```php
# Non-assoc
MyTable::insertBulk([['model 1', 1], ['model 2', 2]], ['model_name', 'some_param']);
# Assoc
MyTable::insertAssoc([['model_name' => 'model 1', 'some_param' => 1], ['some_param' => 2, 'model_name' => 'model 2']]);
```

**4.** Query builder:

```php
$rows = MyTable::select(['field_one', new RawColumn('sum(field_two)', 'field_two_sum')])
    ->where('created_at', '>', '2020-09-14 12:47:29')
    ->groupBy('field_one')
    ->settings(['max_threads' => 3])
    ->getRows();
```

## Known issues

[Some of the problems are described here](/docs/known_issues.md).

## Advanced usage

### Columns casting

Before insertion, the column is converted to the data type specified in
`$casts`. This only applies to inserts, not selects. Supported: `boolean`.

Casts apply to `insertAssoc()` / `buffer()` by column name, and to
`insertBulk()` by matching `$casts` keys against the `$columns` list you pass.

```php
namespace App\Models\Clickhouse;

use PhpClickHouseLaravel\BaseModel;

class MyTable extends BaseModel
{
    /**
     * The columns that should be cast.
     *
     * @var array
     */
    protected $casts = ['some_bool_column' => 'boolean'];
}
// Then you can insert the data like this:
MyTable::insertAssoc([
    ['some_param' => 1, 'some_bool_column' => false],
]);
```

### Events

Events are dispatched under the same names as
[Eloquent model events](https://laravel.com/docs/eloquent#events), but only a
subset is fired, and which ones depends on how you insert:

| Call | Events fired |
| --- | --- |
| `MyTable::create([...])` | `creating`, `saved`, `created` |
| `MyTable::make([...])->save()` | `saved` |

Returning `false` from a `creating` listener cancels `create()`. `save()` does
not fire `creating`, so it cannot be cancelled that way. Observers and the
`$dispatchesEvents` map are Eloquent-only and are not supported.

### Retries

You can retry requests on non-200 responses (e.g., transient network errors).

In `.env`:

```dotenv
CLICKHOUSE_RETRIES=2
```

`retries` is optional; default is `0` (a single attempt, no retries). `1`
means one attempt + one retry on error (two total).

### Working with huge rows

Chunk results like in Laravel:

```php
// Split the result into chunks of 30 rows
$rows = MyTable::select(['field_one', 'field_two'])
    ->chunk(30, function ($rows) {
        foreach ($rows as $row) {
            echo $row['field_two'] . "\n";
        }
    });
```

### Buffer engine for insert queries

See https://clickhouse.tech/docs/en/engines/table-engines/special/buffer/

```php
<?php

namespace App\Models\Clickhouse;

use PhpClickHouseLaravel\BaseModel;

class MyTable extends BaseModel
{
    // Optional; derived from class name when omitted.
    protected $table = 'my_table';
    // All inserts go to $tableForInserts, selects read from $table.
    protected $tableForInserts = 'my_table_buffer';
}
```

If you also want to read from the buffer table, set its name as `$table`:

```php
<?php

namespace App\Models\Clickhouse;

use PhpClickHouseLaravel\BaseModel;

class MyTable extends BaseModel
{
    protected $table = 'my_table_buffer';
}
```

### In-memory buffered inserts

Different from the *Buffer table engine* above — this is a process-local
row buffer kept in PHP memory. Useful when you want to coalesce many
small writes into a single HTTP request without setting up a Buffer
table on the ClickHouse side.

```php
MyTable::buffer(['model_name' => 'model 1', 'some_param' => 1]);
MyTable::buffer(['model_name' => 'model 2', 'some_param' => 2]);
// ... add as many as you like, possibly from different code paths ...

MyTable::flushBuffer(); // single insertAssocBulk HTTP request
```

`buffer()` accepts either a single associative row or an array of rows:

```php
MyTable::buffer([
    ['model_name' => 'model 1', 'some_param' => 1],
    ['model_name' => 'model 2', 'some_param' => 2],
]);
```

The cast pipeline used by `insertAssoc()` is applied at buffer time, so
`$casts` keeps working for buffered rows too.

If you forget to call `flushBuffer()`, the package flushes every model's
buffer automatically at script shutdown (via Laravel's
`Application::terminating()` hook plus a `register_shutdown_function`
fallback for non-HTTP scripts). Errors during auto-flush are logged via
`report()` rather than thrown, since the response has typically already
been sent.

If a manual `flushBuffer()` call fails (network error, schema mismatch,
etc.), the exception bubbles up and **the buffer is preserved** so you
can retry:

```php
try {
    MyTable::flushBuffer();
} catch (\Throwable $e) {
    // rows are still in MyTable::getBufferedRows() — fix the issue and retry
}
```

Each model class has its own buffer keyed by class name, so different
models can buffer concurrently without interfering. Available helpers:

| Method | Purpose |
|---|---|
| `MyTable::buffer($rowOrRows)` | Append a row (or rows) to the buffer |
| `MyTable::flushBuffer()` | Send the buffer; returns `Statement` or `null` if empty |
| `MyTable::bufferCount()` | Number of rows currently buffered for this model |
| `MyTable::getBufferedRows()` | Snapshot of buffered rows (debug / inspection) |
| `MyTable::clearBuffer()` | Discard the buffer without sending |
| `BaseModel::flushAllBuffers(silent: false)` | Flush every model that has buffered rows |

### OPTIMIZE Statement

See https://clickhouse.com/docs/en/sql-reference/statements/optimize/

```php
MyTable::optimize($final = false, $partition = null);
```

### TRUNCATE Statement

Remove all data from a table:

```php
MyTable::truncate();
```

### Deletions

See https://clickhouse.com/docs/en/sql-reference/statements/alter/delete/

```php
MyTable::where('field_one', 123)->delete();
```

Using the buffer engine with OPTIMIZE / ALTER TABLE DELETE:

```php
<?php

namespace App\Models\Clickhouse;

use PhpClickHouseLaravel\BaseModel;

class MyTable extends BaseModel
{
    // SELECT and INSERT on $table
    protected $table = 'my_table_buffer';
    // OPTIMIZE, TRUNCATE, and where()->update() / where()->delete() on $tableSources
    protected $tableSources = 'my_table';
}
```

### Updates

See https://clickhouse.com/docs/en/sql-reference/statements/alter/update/

```php
MyTable::where('field_one', 123)->update(['field_two' => 'new_val']);
// or an expression
MyTable::where('field_one', 123)
    ->update(['field_two' => new RawColumn("concat(field_two,'new_val')")]);
```

### Helpers for inserting different data types

```php
// Array data type
MyTable::insertAssoc([
    ['id' => 1, 'field_one' => 'str', 'field_array' => new InsertArray(['a', 'b'])],
]);
```

`insertAssoc()` takes `column => value` rows. For positional rows, use
`insertBulk()` with an explicit column list:

```php
MyTable::insertBulk([[1, 'str', new InsertArray(['a', 'b'])]], ['id', 'field_one', 'field_array']);
```

### Working with multiple ClickHouse instances in a project

`config/clickhouse.php` is a map from connection name to connection
config. The service provider merges every entry into
`config('database.connections.<name>')`, so you can declare additional
ClickHouse connections alongside the default one in a single file.

**1.** Publish the config if you haven't already:

```sh
php artisan vendor:publish --tag=clickhouse-config
```

Then add a second connection in `config/clickhouse.php`:

```php
return [
    'clickhouse' => [
        // ... default connection
    ],

    'clickhouse2' => [
        'driver' => 'clickhouse',
        'host' => env('CLICKHOUSE2_HOST', '127.0.0.1'),
        'port' => env('CLICKHOUSE2_PORT', '8123'),
        'database' => 'default',
        'username' => 'default',
        'password' => '',
        'timeout_connect' => 2,
        'timeout_query' => 2,
        'https' => false,
        'retries' => 0,
        'fix_default_query_builder' => true,
    ],
];
```

Precedence, highest first: `config/database.php`'s `connections` array, then
your published `config/clickhouse.php`, then the packaged defaults. So adding
the same shape to `config/database.php` works too, and overrides both.

**2.** Add a model pointing at it:

```php
<?php

namespace App\Models\Clickhouse;

use PhpClickHouseLaravel\BaseModel;

class MyTable2 extends BaseModel
{
    protected $connection = 'clickhouse2';

    protected $table = 'my_table2';
}
```

**3.** Add a migration bound to that connection:

```php
<?php

return new class extends \PhpClickHouseLaravel\Migration
{
    protected $connection = 'clickhouse2';

    public function up()
    {
        static::write('CREATE TABLE my_table2 ...');
    }

    public function down()
    {
        static::write('DROP TABLE my_table2');
    }
};
```

### Cluster mode

**Important!**
* Each ClickHouse node must share the same database name, username, and password.
* Reads and writes go to the first reachable node.
* Migrations execute on all nodes. If any node is unreachable, the migration throws.
* `ReplicatedMergeTree` uses the `{replica}` and `{shard}` macros — those
  must be defined on each ClickHouse server (in `config.xml` or
  `config.d/*.xml`), **not** in this package. Example config:
  ```xml
  <macros>
      <shard>01</shard>
      <replica>clickhouse01</replica>
  </macros>
  ```
  See `tests/docker/clickhouse01/config.xml` in this repo for a working
  example, or the ClickHouse docs:
  https://clickhouse.com/docs/en/operations/settings/settings#server_settings-macros

Your `config/database.php` should look like:

```php
'clickhouse' => [
    'driver' => 'clickhouse',
    'cluster' => [
        [
            'host' => 'clickhouse01',
            'port' => '8123',
        ],
        [
            'host' => 'clickhouse02',
            'port' => '8123',
        ],
    ],
    // Optional. When set, Migration::createMergeTree() adds
    // ON CLUSTER '<name>' to the DDL it compiles. It must match a cluster
    // declared in your ClickHouse server config (remote_servers).
    // Without it the table is still created on every node, because
    // migrations are dispatched to each node in turn.
    // If you set it, also call ->ifNotExists() in createMergeTree() — see below.
    'cluster_name' => 'company_cluster',
    'database' => env('CLICKHOUSE_DATABASE', 'default'),
    'username' => env('CLICKHOUSE_USERNAME', 'default'),
    'password' => env('CLICKHOUSE_PASSWORD', ''),
    'timeout_connect' => env('CLICKHOUSE_TIMEOUT_CONNECT', 2),
    'timeout_query' => env('CLICKHOUSE_TIMEOUT_QUERY', 2),
    'https' => (bool) env('CLICKHOUSE_HTTPS', null),
    'retries' => env('CLICKHOUSE_RETRIES', 0),
    'settings' => [ // optional
        'max_partitions_per_insert_block' => 300,
    ],
    'fix_default_query_builder' => true,
],
```

With `cluster_name` set, add `->ifNotExists()` to `createMergeTree()`:

```php
static::createMergeTree('my_table', fn(MergeTree $table) => $table
    ->ifNotExists()
    ->columns([...])
    ->orderBy('id')
);
```

Migrations are dispatched to each node in turn, and `ON CLUSTER` already
creates the table on every node from the first dispatch — so without
`IF NOT EXISTS` the second node's identical statement fails with
`TABLE_ALREADY_EXISTS`.

Migration:

```php
<?php

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

You can read the current node and rotate to the next:

```php
$row = new MyTable();
echo $row->getThisClient()->getConnectHost();
// will print 'clickhouse01'
$row->resolveConnection()->getCluster()->slideNode();
echo $row->getThisClient()->getConnectHost();
// will print 'clickhouse02'
```

### Squashing migrations with `schema:dump`

Laravel's built-in `schema:dump` command works on ClickHouse connections.
You don't need a separate command or the `clickhouse-client` binary:

```sh
php artisan schema:dump --database=clickhouse

# Dump the schema and delete all existing migration files
php artisan schema:dump --database=clickhouse --prune
```

This writes `database/schema/clickhouse-schema.sql`. The file has one
`CREATE` statement for each table, dictionary, view and materialized view in
the connection's database, ordered so that each object comes after the ones it
reads from. If the `migrations` table is on this connection, its rows are
appended at the end; pass `--without-migration-data` to leave them out.
References written as `<database>.<table>` lose the database prefix when they
point at the connection's own database, so you can load the file into a
database with a different name. Engine arguments that name the database as a
separate string, such as `Buffer('analytics', 'events', ...)`, are left as they
are.

When `php artisan migrate` runs against a ClickHouse database where no
migrations have run yet, it loads the dump first. After that, it runs only the
migrations created after the dump. On a `cluster` connection, the statements
are sent to every node, the same way migrations are.

`--prune` is Laravel's own behavior. It deletes the whole `database/migrations`
directory, including migrations for your other connections.

#### Which connection holds the `migrations` table

Laravel's `migrate`, `migrate:fresh` and `schema:dump` act on one connection:
the default one, or the one you pass with `--database`. That connection also
holds the `migrations` table. In this section it is called the *primary*
connection. The package also works when ClickHouse is not the primary.

**ClickHouse is the primary connection.** For example, `DB_CONNECTION=clickhouse`,
or `--database=analytics` for a ClickHouse connection with another name. All
commands work directly:

```sh
php artisan migrate --database=analytics
php artisan schema:dump --database=analytics --prune
php artisan migrate:fresh --database=analytics
```

If your ClickHouse connection has a name other than `clickhouse`, set it on the
migrations: `protected $connection = 'analytics';`.

**ClickHouse is a secondary connection.** For example, MySQL is the default
connection and some migrations write to ClickHouse. A ClickHouse connection
counts as secondary when one of these is true:

- a migration in `database/migrations`, or in a path registered with
  `loadMigrationsFrom()`, sets `$connection` to it;
- `database/schema` has a dump for it.

Secondary connections follow the primary one:

| Command | What happens to secondary ClickHouse connections |
| --- | --- |
| `php artisan schema:dump [--prune]` | Each one is dumped to `database/schema/<connection>-schema.sql`, next to the primary dump. |
| `php artisan migrate` (primary dump gets loaded) | An empty one is loaded from its dump. If it has no dump, its migrations are run again. A connection that still holds tables is left as it is. |
| `php artisan migrate:fresh` | Each one is emptied too. It is then rebuilt from its dump if the primary dump gets loaded, otherwise by running the migrations again. |

ClickHouse connections that no migration or dump refers to are never emptied
or loaded.

`migrate:fresh` and `db:wipe` drop every table, materialized view, view and
dictionary in the ClickHouse database, in an order ClickHouse accepts, on every
node. Views are dropped even without `--drop-views`: the dump and the
migrations recreate them, and that would fail while they still exist.

**Parallel tests.** With `php artisan test --parallel` or `pest --parallel`,
each test process gets its own database on every secondary ClickHouse
connection, the way Laravel does it for the default connection. The database
is named `<database>_test_<token>`, for example `analytics_test_1`. A process
creates it for its first test case that uses `RefreshDatabase`,
`LazilyRefreshDatabase`, `DatabaseMigrations`, `DatabaseTransactions` or
`DatabaseTruncation`, and all of that process's test cases use it. So the
`migrate:fresh` of one process never drops the tables of another.

- `--recreate-databases` drops these databases before the run, and
  `--drop-databases` drops them after it.
- `--without-databases` keeps the configured database.
- When ClickHouse is the primary connection, Laravel itself switches it to
  `<database>_test_<token>`.

The ClickHouse user needs permission to create and drop databases. Test runs
without `--parallel` use the configured database.

## Credits

This package bundles code from these MIT-licensed projects. Each bundled
directory keeps the original license next to the code.

| Bundled as | Origin | License |
| --- | --- | --- |
| `PhpClickHouseLaravel\ClickhouseBuilder` (`src/ClickhouseBuilder`) | [the-tinderbox/ClickhouseBuilder](https://github.com/the-tinderbox/ClickhouseBuilder), via the [glushkovds](https://github.com/glushkovds/ClickhouseBuilder) and [oralunal](https://github.com/oralunal/ClickhouseBuilder) forks (v1.0.0) | `src/ClickhouseBuilder/LICENSE` |
| `PhpClickHouseLaravel\ClickhouseSchemaBuilder` (`src/ClickhouseSchemaBuilder`) | [glushkovds/php-clickhouse-schema-builder](https://github.com/glushkovds/php-clickhouse-schema-builder) v1.1.1 by Denis Glushkov | `src/ClickhouseSchemaBuilder/LICENSE` |
| `PhpClickHouseLaravel\Enum\Enum` (`src/Enum`) | [myclabs/php-enum](https://github.com/myclabs/php-enum) 1.8.5 by My C-Labs | `src/Enum/LICENSE` |

The package itself is a fork of
[glushkovds/phpclickhouse-laravel](https://github.com/glushkovds/phpclickhouse-laravel).

## Contributing

The package is developed against **Orchestra Testbench** with a local
ClickHouse in Docker. To run the test suite locally:

1. `docker compose -f docker-compose.test.yaml up -d`
2. `composer install`
3. `composer test`

See [docs/howto_run_local_test.md](docs/howto_run_local_test.md) for
prerequisites, cluster-test notes, and using `vendor/bin/testbench` /
Laravel Boost during development.

See [CONTRIBUTING.md](CONTRIBUTING.md) for branch and commit conventions, the
`CHANGELOG.md` policy, and the release process.
