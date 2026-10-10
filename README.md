![Tests](https://github.com/oralunal/laravel-clickhouse/actions/workflows/tests.yml/badge.svg)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/oralunal/laravel-clickhouse.svg?style=flat-square)](https://packagist.org/packages/oralunal/laravel-clickhouse)
[![Total Downloads](https://img.shields.io/packagist/dt/oralunal/laravel-clickhouse.svg?style=flat-square)](https://packagist.org/packages/oralunal/laravel-clickhouse)

# laravel-clickhouse

Laravel adapter for ClickHouse, built on
[smi2/phpClickHouse](https://github.com/smi2/phpClickHouse) for HTTP transport
and query execution. Apart from Laravel, that is the only dependency. The query
builder, the schema builder and the enum base class it uses are bundled under
this package's namespace; see [Credits](#credits).

## Features

- Eloquent-flavored `BaseModel` (`create`, `save`, `insertBulk`, `insertAssoc`, `where`, `query`, pagination)
- `Oralunal\LaravelClickHouse\Migration` base class for ClickHouse DDL migrations (single-node and cluster)
- Laravel's schema builder: `Schema::create()` with ClickHouse column types, engines, sorting keys, partitions, TTLs, settings and data-skipping indexes, and `Schema::table()` with `ALTER TABLE` statements and `change()`
- `php artisan schema:dump [--prune]` support for squashing migrations into a schema file
- `php artisan clickhouse:install-skills` installs coding-agent skills: `/lc-upgrade-2x-to-3x` for the 2.x → 3.x upgrade and `/plc-upgrade-1x-to-2x` for 1.x → 2.x
- Query builder integration with `settings()`, `chunk()`, `exists()`, `count()` and the aggregates, `first()`, `value()`, `pluck()`, `insertFiles()`, and ClickHouse-specific grammar
- ClickHouse SQL in the query builder: `PREWHERE`, `WITH [RECURSIVE]`, `SAMPLE ... OFFSET`, `ARRAY JOIN` over several arrays, `SEMI`/`ANTI`/`ASOF`/`CROSS` joins, `INTERSECT`/`EXCEPT`, `IS NULL` and `empty()` checks; dates, booleans and enums as query values
- Laravel's where methods in the query builder: `whereColumn()`, `whereExists()`, `whereAll()`/`whereAny()`/`whereNone()`, `whereDate()` and the other date conditions, `whereLike()`, `when()`, `latest()` and `inRandomOrder()`
- Laravel's own query builder as an option (`fix_default_query_builder` set to `false`), and `?` bindings in raw SQL, which the package writes into the query as escaped ClickHouse literals
- Eloquent attribute casts, accessors and mutators on model instances (`$model->column`, `fill()`, `create()`, `save()`, `toArray()`); `boolean` casts on `insertAssoc()`, `insertBulk()` and `buffer()` rows
- Model events: `creating`, `created`, `saved`
- Retries of failed requests (`retries`), optionally only of the requests that never reached the server (`retry_on`)
- ClickHouse sessions: `DB::connection('clickhouse')->session(fn ...)` runs queries in one session, with temporary tables from `Schema::create()` and `$table->temporary()`
- Parallel `SELECT`s: `Parallel::getRows()` runs queries of the query builders and raw SQL at the same time, across connections, and `selectParallelly()` runs SQL on one connection
- Opt-in connection options: dates with microseconds (`datetime_precision`), inserts as JSON (`insert_format`) and `ON CLUSTER` for every mutation (`use_on_cluster`)
- Buffer engine support via `$tableForInserts` / `$tableSources`
- In-memory buffered inserts: accumulate rows with `Model::buffer()` and send them as a single HTTP request with `Model::flushBuffer()` (auto-flushed on script shutdown)
- `OPTIMIZE`, `TRUNCATE`, lightweight `DELETE FROM`, `ALTER TABLE ... DELETE` and `ALTER TABLE ... UPDATE` helpers, with `IN PARTITION` and `ON CLUSTER`
- Multi-instance and cluster-mode connections with active-node rotation; on a cluster, the schema builder sends its statements `ON CLUSTER` and creates replicated tables
- Schema introspection (`Schema::getTables()`, `getColumns()`, `getIndexes()`, …), `php artisan db:show` / `db:table`, and Laravel's `DatabaseTruncation` testing trait
- Publishable default config — `.env` is enough for most setups

Underneath, smi2/phpClickHouse handles HTTP transport (curl-only, no PDO).
More: https://github.com/smi2/phpClickHouse#features

## Prerequisites

- PHP 8.5+
- Laravel 13+
- ClickHouse server 24.x (older 20+ versions usually work but are no longer tested)

## Installation

Upgrading from 2.x? Until 2.0.2 the package was published as
`oralunal/phpclickhouse-laravel`, with the `PhpClickHouseLaravel` namespace.
Switch the package and install the coding-agent skills:

```sh
composer remove oralunal/phpclickhouse-laravel --no-update
composer require oralunal/laravel-clickhouse:^3.0 --with-all-dependencies
php artisan clickhouse:install-skills
```

Then run `/lc-upgrade-2x-to-3x` in your coding agent. On 1.x, run
`/plc-upgrade-1x-to-2x` before it. To upgrade by hand, see [UPGRADE.md](UPGRADE.md).

**1.** Install via composer:

```sh
composer require oralunal/laravel-clickhouse
```

The service provider is registered automatically via Laravel package
auto-discovery. If you have auto-discovery disabled, add
`Oralunal\LaravelClickHouse\ClickhouseServiceProvider::class` to
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
    // Seconds, and a fraction is allowed. See Timeouts below.
    'timeout_connect' => env('CLICKHOUSE_TIMEOUT_CONNECT', 2),
    'timeout_query' => env('CLICKHOUSE_TIMEOUT_QUERY', 2),
    'https' => (bool) env('CLICKHOUSE_HTTPS', null),
    'retries' => env('CLICKHOUSE_RETRIES', 0),
    // 'any' or 'unsent': which failed requests are sent again. See Retries below.
    'retry_on' => env('CLICKHOUSE_RETRY_ON', 'any'),
    'settings' => [ // optional
        'max_partitions_per_insert_block' => 300,
    ],
    // DB::connection('clickhouse')->table() returns the package's query builder
    // when true, Laravel's when false. See Laravel's query builder below.
    'fix_default_query_builder' => true,
    // delete() without an argument: lightweight DELETE FROM when true,
    // ALTER TABLE ... DELETE when false. See Deletions below.
    'use_lightweight_delete' => (bool) env('CLICKHOUSE_USE_LIGHTWEIGHT_DELETE', false),
    // The engine and the integer types of Schema::create(), and the type of
    // a data-skipping index added without one. See Laravel's schema builder below.
    'engine' => env('CLICKHOUSE_ENGINE'),
    'exact_integer_types' => (bool) env('CLICKHOUSE_EXACT_INTEGER_TYPES', false),
    'default_index_type' => env('CLICKHOUSE_DEFAULT_INDEX_TYPE'),
    // 'second' or 'microsecond': the precision of the dates the package writes.
    // See Dates with microseconds below.
    'datetime_precision' => env('CLICKHOUSE_DATETIME_PRECISION', 'second'),
    // 'Values' or 'JSONEachRow': the input format of the package's inserts.
    // See Inserting rows as JSONEachRow below.
    'insert_format' => env('CLICKHOUSE_INSERT_FORMAT', 'Values'),
    // With a cluster_name, send delete(), update(), truncate() and optimize()
    // ON CLUSTER. See Cluster mode below.
    'use_on_cluster' => (bool) env('CLICKHOUSE_USE_ON_CLUSTER', false),
],
```

The last three options are off by default: without them, the package writes
dates, inserts and mutations as 3.0.0 did. A value that an option does not
take, such as `'datetime_precision' => 'millisecond'`, throws an
`InvalidArgumentException` when Laravel creates the connection, before any
node is pinged:
`The [datetime_precision] option of the ClickHouse connection [clickhouse] must be 'second' or 'microsecond', [millisecond] given.`
Write `datetime_precision` and `insert_format` as strings in config files,
because `php artisan config:cache` cannot write an enum instance into the
cached config.

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

use Oralunal\LaravelClickHouse\BaseModel;

class MyTable extends BaseModel
{
    // Optional. Derived from class name when omitted: MyTable => my_tables.
    protected $table = 'my_table';
}
```

**2.** Add a migration:

```php
<?php

class CreateMyTable extends \Oralunal\LaravelClickHouse\Migration
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

use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Expression;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Tables\MergeTree;

class CreateMyTable extends \Oralunal\LaravelClickHouse\Migration
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
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Engine;

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

`createMergeTree()` puts a table or column name that is not a plain identifier
between backticks, and writes comments, string defaults, enum values and
string settings as escaped string literals, so pass them as they are:
`$table->string('my col')->default("it's")` compiles to
`` `my col` String DEFAULT 'it\'s' ``. A name that you quoted yourself is kept.
A dot in the table name separates the database from the table, as in
`analytics.events`; to name a table with a dot in it, quote the name yourself:
``createMergeTree('`my.events`', ...)``. [Column types](#column-types) explains
how `enum()` reads its array, such as `['active' => 1, 'inactive' => 2]`.

Or use Laravel's schema builder, with Laravel's column methods and the
ClickHouse table options that [Laravel's schema builder](#laravels-schema-builder)
describes:

```php
<?php

use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\SchemaBlueprint;

class CreateMyTable extends \Oralunal\LaravelClickHouse\Migration
{
    public function up()
    {
        Schema::create('my_table', function (SchemaBlueprint $table) {
            $table->unsignedInteger('id');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->string('field_one');
            $table->integer('field_two');
            $table->orderBy('id');
        });
        // CREATE TABLE `my_table` (`id` Int32, `created_at` DateTime64(3) DEFAULT now64(3),
        //   `field_one` String, `field_two` Int32) ENGINE = MergeTree() ORDER BY (`id`)
    }

    public function down()
    {
        Schema::dropIfExists('my_table');
    }
}
```

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

Inserts quote each column name as one identifier, with its backticks and
backslashes escaped, so a name cannot add SQL to the statement.

Every row of `insertAssoc()` needs the keys of the first row, in any order. A
row with other keys throws a `ClickHouseDB\Exception\QueryException`, such as
`Fields not match: model_name and model_name,some_param on element 1`, and
nothing is inserted; in the JSONEachRow format, an
`Oralunal\LaravelClickHouse\Exceptions\QueryException` (see
[Inserting rows as JSONEachRow](#inserting-rows-as-jsoneachrow)).
`insertAssoc()`, `insertBulk()`, the `prepareAndInsert` methods, `create()`
and `save()` refuse no rows, or an empty row, such as the
row of a model without attributes, before anything is sent:
`Inserting empty values array is not supported in ClickHouse`. `buffer()`
refuses an empty row the same way.

**4.** Query builder:

```php
$rows = MyTable::select(['field_one', new RawColumn('sum(field_two)', 'field_two_sum')])
    ->where('created_at', '>', '2020-09-14 12:47:29')
    ->groupBy('field_one')
    ->settings(['max_threads' => 3])
    ->getRows();
```

`MyTable::query()` starts a query like `MyTable::where()` without a condition,
so that any method of the query builder can come first:

```php
MyTable::query()->whereDate('created_at', '2024-01-05')->first();
// SELECT * FROM `my_table` WHERE toDate32(`created_at`) = '2024-01-05' LIMIT 1
```

Like the queries that `MyTable::where()` starts, it sends `delete()`, `update()`
and `truncate()` to the model's `$tableSources` (see
[Deletions](#deletions)).

## Known issues

[Some of the problems are described here](/docs/known_issues.md).

## Advanced usage

### Columns casting

A model instance applies every Laravel cast type in `$casts` when an attribute
is read: `$model->column`, `getAttribute()` and `toArray()`. When an attribute
is set, with `make()`, `fill()`, `$model->column = ...` or `create()`, only these
casts change the value that the model stores, and that `create()` and `save()`
insert:

- `array`, `json`, `json:unicode`, `object` and `collection` store a JSON
  string.
- An enum cast stores the value of a backed enum, or the name of a unit enum.
- `date`, `datetime`, `immutable_date` and `immutable_datetime` store a date
  string in `$dateFormat`, which defaults to `Y-m-d H:i:s` (see
  [Model attributes](#model-attributes)).
- `encrypted`, `encrypted:array`, `encrypted:collection`, `encrypted:json`,
  `encrypted:object` and `hashed` store the encrypted or hashed string, and a
  cast class stores what its `set()` method returns.

The other casts, such as `int`, `float`, `decimal:2`, `string`, `bool`,
`boolean`, `timestamp` and `datetime:Y-m-d`, store the value as it was set.

Row inserts convert only `boolean` columns, to `0` or `1`; a `bool` cast is not
converted. This applies to `insertAssoc()` and `buffer()` by column name, and to
`insertBulk()` by matching `$casts` keys against the `$columns` list you pass.
`create()` and `save()` send their row through `insertAssoc()`, so it applies to
them too. A cast column that a row does not have stays out of the insert, so
ClickHouse gives it the column's default. The rows that `select()` returns are
arrays, so no cast applies to them.

```php
namespace App\Models\Clickhouse;

use Oralunal\LaravelClickHouse\BaseModel;

class MyTable extends BaseModel
{
    /**
     * The columns that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = ['some_bool_column' => 'boolean'];
}
// Then you can insert the data like this:
MyTable::insertAssoc([
    ['some_param' => 1, 'some_bool_column' => false],
]);
```

### Model attributes

Model instances handle their attributes as Eloquent models do:

- Accessors and mutators: `getFooAttribute()` and `setFooAttribute()`, or a
  `foo(): Attribute` method.
- `$appends` adds accessors to the array form of the model, and `$hidden` and
  `$visible` leave attributes out of it. `makeHidden()` and `makeVisible()`
  change them for one instance.
- `toArray()` and `attributesToArray()` return the attributes with the casts,
  accessors, `$appends`, `$hidden` and `$visible` applied. `getAttributes()`
  returns them as stored.
- `getOriginal()` returns the original attributes, which `syncOriginal()`
  stores, and `isDirty()` and `getDirty()` compare the attributes with them.
  `wasChanged()` and `getChanges()` report the changes that `syncChanges()`
  records.
- `$dateFormat` is the format in which the `date`, `datetime`, `immutable_date`
  and `immutable_datetime` casts store a date. Without it, they store
  `Y-m-d H:i:s`, or, when the connection's `datetime_precision` is
  `microsecond`, `Y-m-d H:i:s.u` for a date with a sub-second part (see
  [Dates with microseconds](#dates-with-microseconds)). Use `Y-m-d H:i:s.u` to
  keep the microseconds of a `DateTime64` column at either precision.
  ClickHouse rejects microseconds for a `DateTime` column, so on a connection
  at `microsecond`, give a model with such a column
  `protected $dateFormat = 'Y-m-d H:i:s';`. Override `serializeDate()` to
  change how `toArray()` writes dates.

```php
use Illuminate\Database\Eloquent\Casts\Attribute;
use Oralunal\LaravelClickHouse\BaseModel;

class Visit extends BaseModel
{
    protected $casts = ['payload' => 'array'];

    protected $hidden = ['ip'];

    protected $appends = ['host'];

    public function setUrlAttribute($value)
    {
        $this->attributes['url'] = strtolower($value);
    }

    protected function host(): Attribute
    {
        return Attribute::get(fn ($value, array $attributes) => parse_url($attributes['url'], PHP_URL_HOST));
    }
}

$visit = Visit::make(['url' => 'https://Example.com/a', 'ip' => '10.0.0.1', 'payload' => ['a' => 1]]);

$visit->host;            // 'example.com'
$visit->payload;         // ['a' => 1]
$visit->getAttributes(); // ['url' => 'https://example.com/a', 'ip' => '10.0.0.1', 'payload' => '{"a":1}']
$visit->toArray();       // ['url' => 'https://example.com/a', 'payload' => ['a' => 1], 'host' => 'example.com']
```

Differences from Eloquent:

- `isset($model->column)` is `true` for a column that holds `null`.
- `save()` and `create()` call neither `syncOriginal()` nor `syncChanges()`, so
  `isDirty()` stays `true` after them. Eloquent records the changes when
  `save()` updates a model, but `save()` throws on a saved `BaseModel`, so
  `wasChanged()` and `getChanges()` report only what a call to `syncChanges()`
  records.
- There are no relationships, timestamps or primary key. A missing attribute
  reads as `null`, also when its name is that of a method of the model.
- The `casts()` method, the `#[Hidden]`, `#[Visible]`, `#[Appends]` and
  `#[DateFormat]` attributes, and `Model::preventAccessingMissingAttributes()`
  are not supported. Use the `$casts`, `$hidden`, `$visible`, `$appends` and
  `$dateFormat` properties. To throw a `MissingAttributeException` when code
  reads an attribute that a model does not have after `save()`, override
  `public static function preventsAccessingMissingAttributes()` and return
  `true`.
- A cast class receives the `BaseModel`, so declare the `$model` parameter of
  its `get()` and `set()` without a type. The class that `php artisan make:cast`
  writes type-hints `Model`, which throws a `TypeError`.
- `json_encode($model)` does not encode the attributes. Use
  `json_encode($model->toArray())`.

#### PHPStan and Larastan

PHPStan with Larastan analyses a model that extends `BaseModel` without the
`class.missingExtends` error. Two things differ from an Eloquent model:

- Larastan reads the columns of Eloquent models only, so declare the columns of
  a ClickHouse model with `@property` tags, or PHPStan reports
  `property.notFound` where you read or write them.
- Larastan gives the `$casts` and `$appends` properties a type on Eloquent
  models only. From level 6, PHPStan reports `missingType.iterableValue` on
  these properties when a model declares them without one, so declare them with
  `@var array<string, string>` and `@var list<string>`.

```php
/**
 * @property string $url
 * @property array<string, mixed> $payload
 * @property-read string $host
 */
class Visit extends BaseModel
{
    /** @var array<string, string> */
    protected $casts = ['payload' => 'array'];

    /** @var list<string> */
    protected $appends = ['host'];

    // ...
}
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

### Timeouts

`timeout_connect` limits how long the client waits for a connection to a node,
and `timeout_query` how long a request may take. Both are seconds, and a
connection config that leaves one out gets 2 seconds:

- `timeout_connect` keeps its fraction: `0.5` waits 500 ms. `0`, `''` and
  `null` hand curl a connect timeout of 0, which curl reads as its own default
  of 300 seconds.
- `timeout_query` is both the HTTP timeout and the `max_execution_time` that
  the server applies. The smi2 client takes it in whole seconds, so a fraction
  is rounded up: `0.5` becomes 1 second, and `1.5` becomes 2 seconds. `0`,
  `''` and `null` mean no limit.

A timeout is an int, a float or a numeric string, such as `0.5` from `.env`.
A negative number, `INF`, a string that is not a number, a bool or an array
throws an `InvalidArgumentException` when Laravel creates the connection:
`The [timeout_query] option of the ClickHouse connection must be a number of seconds, [abc] given.`
The timeouts apply to every node of a [cluster](#cluster-mode), and to the
pings that look for a node that answers.

### Retries

With `retries`, a request that does not get HTTP 200 is sent again, for
example after a network error.

In `.env`:

```dotenv
CLICKHOUSE_RETRIES=2
CLICKHOUSE_RETRY_ON=unsent
```

`retries` is optional; default is `0` (a single attempt, no retries). `1`
means one attempt + one retry on error (two total).

`retry_on` decides which failed requests are sent again:

- `any`, the default, sends every one again, as in 3.0.0. That includes a
  request that timed out on the client and a request that ClickHouse answered
  with an error.
- `unsent` sends a request again only when it never reached the server: the
  connection was refused, the host name was not found, or the connect timeout
  ran out.

**With `any`, a write can run more than once.** A request that timed out on the
client may still run on the server, and the retry sends it again. On ClickHouse
24.8, with `retries` set to `2`, an `INSERT` that timed out on the client after
1 second but kept running on the server stored its rows three times. Use
`unsent` on connections that write, unless running a write twice does no harm.

`retry_on` is read in any letter case, and `null` or `''` means `any`. Any
other value throws an `InvalidArgumentException` when Laravel creates the
connection, also when `retries` is `0`:
`The [retry_on] option of the ClickHouse connection must be 'any' or 'unsent', [bogus] given.`

Inside [`session()`](#sessions-and-temporary-tables), a request is sent again
only when it never reached the server, as with `unsent`, whatever `retry_on`
says. [Parallel queries](#parallel-queries) send a failed request again in a
later round, as `retries` and `retry_on` allow. The smi2 client's own
asynchronous requests, `selectAsync()` with `executeAsync()` through
`getClient()`, and the files of [`insertFiles()`](#inserting-files), are sent
once.

### Dates with microseconds

The package writes a `DateTimeInterface` value, Carbon included, in the date's
own time zone and without its sub-second part, as `'2024-01-02 03:04:05'`, as
3.0.0 did. To keep the sub-second part, set the `datetime_precision`
connection option to `microsecond`:

```dotenv
CLICKHOUSE_DATETIME_PRECISION=microsecond
```

or `'datetime_precision' => 'microsecond'` in the connection config. A date
with a sub-second part is then written as `'Y-m-d H:i:s.u'`, and a whole
second still as `'Y-m-d H:i:s'`:

```php
$at = Carbon::parse('2024-01-02 03:04:05.123456');

MyTable::query()->where('created_at', '>=', $at);
// SELECT * FROM `my_table` WHERE `created_at` >= '2024-01-02 03:04:05.123456'

MyTable::query()->whereIn('created_at', [$at, $at->copy()->startOfSecond()]);
// SELECT * FROM `my_table` WHERE `created_at` IN ('2024-01-02 03:04:05.123456', '2024-01-02 03:04:05')
```

The option applies wherever the package writes a date:

- the conditions, `update()` and `withAlias()` of the query builder, on model
  queries and on `DB::connection('clickhouse')->table()`;
- the `?` and named bindings of [raw SQL](#raw-sql-with-bindings) and of
  [Laravel's query builder](#laravels-query-builder), and
  `Connection::escape()`;
- the inserts: `insertAssoc()`, `insertBulk()`, the `prepareAndInsert`
  methods, `create()`, `save()`, the buffers and the query builder's
  `insert()`, in both [insert formats](#inserting-rows-as-jsoneachrow);
- the `date`, `datetime`, `immutable_date` and `immutable_datetime` casts of a
  model without `$dateFormat` (see [Model attributes](#model-attributes)).

A string is written as it is, at either precision. A query builder made with
only a smi2 client, `new Builder($client)`, follows no connection and writes
whole seconds.

What ClickHouse does with the sub-second part depends on the column type
(ClickHouse 24.8 checked):

| Column | A date with a sub-second part, at `microsecond` |
| --- | --- |
| `DateTime64(6)` | kept exactly, in inserts, conditions, `update()` and `delete()` |
| `DateTime64(3)` | cut to milliseconds, in inserts and in conditions alike, so `where('d3', $at)` finds the row that the same `$at` inserted |
| `DateTime` | refused: a condition fails with `TYPE_MISMATCH`, `Cannot convert string '2024-01-02 03:04:05.123456' to type DateTime`, and an insert with `CANNOT_PARSE_TEXT`, or `CANNOT_PARSE_INPUT_ASSERTION_FAILED` in the JSONEachRow format |
| `Date`, `Date32` | as at `second`: ClickHouse does not compare them with a date and a time, so pass `$date->format('Y-m-d')` |

On a connection that also writes to `DateTime` columns, pass those a string,
such as `$date->format('Y-m-d H:i:s')`, and give models with such a column
`protected $dateFormat = 'Y-m-d H:i:s';`. With
`'date_time_input_format' => 'best_effort'` in the connection's `settings`,
ClickHouse cuts the sub-second part off in inserts into a `DateTime` column,
but conditions still fail.

The option takes `second` or `microsecond` in any letter case; a missing key,
`null` and `''` mean `second`. ClickHouse cuts a date to the precision of its
column, so there is no `millisecond`. Any other value throws an
`InvalidArgumentException` when Laravel creates the connection.

### Working with huge rows

Chunk results like in Laravel:

```php
// Split the result into chunks of 30 rows
MyTable::select(['field_one', 'field_two'])
    ->orderBy('field_one')
    ->chunk(30, function (array $rows, int $page) {
        foreach ($rows as $row) {
            echo $row['field_two'] . "\n";
        }
    });
// SELECT `field_one`, `field_two` FROM `my_table` ORDER BY `field_one` ASC LIMIT 0, 30
// SELECT `field_one`, `field_two` FROM `my_table` ORDER BY `field_one` ASC LIMIT 30, 30
// ...
```

As in Laravel 13, the callback gets the rows of a page, as arrays, and the
page number, which starts at 1. Chunking stops after a page with fewer rows,
when a page is empty, without calling the callback, and when the callback
returns `false`. A size below 1 throws an `InvalidArgumentException`:
`The chunk size should be at least 1, 0 given.`

Each page is read by a copy of the query, so the builder keeps its clauses.
The `LIMIT` and `OFFSET` of the query bound the rows that `chunk()` reads:
`limit(100)->chunk(30, ...)` reads four pages, of 30, 30, 30 and 10 rows. A set
operation is read as a whole (see [UNION, INTERSECT and EXCEPT](#union-intersect-and-except)).
ClickHouse returns rows in no fixed order without an `ORDER BY`, so order the
query, or the pages can overlap or leave rows out.

### Query settings

`settings()` adds a [`SETTINGS` clause](https://clickhouse.com/docs/en/sql-reference/statements/select#settings-in-select-query)
to the `SELECT` statement. Pass an array, or a name and a value:

```php
MyTable::select()
    ->settings(['max_threads' => 3, 'optimize_move_to_prewhere' => false])
    ->settings('log_comment', "it's the monthly report")
    ->getRows();
// SELECT * FROM `my_table` FORMAT JSON
//   SETTINGS max_threads=3, optimize_move_to_prewhere=0, log_comment='it\'s the monthly report'
```

A query with settings and no `format()` is sent with `FORMAT JSON` before the
`SETTINGS` clause, as the comment shows, and the query log records that SQL.
`toSql()` leaves `FORMAT JSON` out.

Each call adds to the settings of the calls before it. A later value for the
same name replaces the earlier one, and `null` removes the setting; an empty
array changes nothing. Booleans are sent as `1` and `0`, numbers as they are,
and strings as string literals with `\` and `'` escaped. A backed enum is sent
as its value, and any other `Stringable` object, such as `Str::of()` or a
Carbon date, as a string literal. A value of another type throws an
`InvalidArgumentException`, and so does a collection or another `Arrayable` or
`Traversable` object, although a collection is `Stringable`. For a list, a map
or any other value, pass the SQL as an `Expression`, which is sent as written:

```php
MyTable::select()
    ->settings('additional_table_filters', new RawColumn("{'my_table': 'field_two > 0'}"))
    ->getRows();
```

A setting name must consist of letters, digits and underscores, and must not
start with a digit. Any other name throws an `InvalidArgumentException`.
Settings are only sent with `SELECT` queries, including `exists()` and
`count()`. `delete()` and `update()` throw a `QueryException` for a query with
settings, and `truncate()` leaves them out. Put the settings of a mutation in
the connection's `settings` (see [Deletions](#deletions)).

### Output format

`format()` sets the `FORMAT` clause. It takes a format name in any letter case
and writes the name as ClickHouse spells it. `get()` returns the smi2
statement, whose `rawData()` holds the output:

```php
MyTable::select('id')->orderBy('id')->format('jsoneachrow')->get()->rawData();
// SELECT `id` FROM `my_table` ORDER BY `id` ASC FORMAT JSONEachRow
// returns "{\"id\":1}\n{\"id\":2}\n"
```

`get()` reads the output in the format that the query names, so `rawData()`
holds the text of `CSV`, `TabSeparated`, `XML`, `Values`, a `Pretty` format and
the others: `format('XML')->get()->rawData()` starts with `<?xml`. `rows()`
and `getRows()` need `JSON`, which a query without `format()` uses, and
`rows()` throws ``Can`t find meta`` for formats without meta data, such as
`CSV`, `XML`, `JSONEachRow` and `JSONCompactEachRow`. The `rawData()` of
`JSONCompactEachRow`, and of the other formats with one JSON value per line,
holds the lines, such as `"[1]\n[2]\n"`. A name that is
not a format of `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format`,
such as `'jsonl'`, throws an `UnexpectedValueException`.

The rest of the SQL does not change how `get()` reads the output: a value,
as in `where('note', 'export as format csv please')`, or a quoted name can
hold the word `format` and a format name, and the query still returns its
rows. `count()`, `exists()` and the aggregates leave `format()` out, so they
work on a query with any format. `paginate()`, `simplePaginate()`, `chunk()`,
`first()`, `value()` and `pluck()` read rows, so they need `JSON` too.

### Checking whether rows exist

```php
MyTable::where('id', 123)->exists();      // true or false
MyTable::where('id', 123)->doesntExist();
```

`exists()` fetches at most one row: it replaces the columns with a constant and
sets `LIMIT 1`, as in ``SELECT 1 FROM `my_table` WHERE `id` = 123 LIMIT 1``.
A query that selects expressions, aliases or `withAlias()` names, orders by an
alias or a `withAlias()` name, or uses `GROUP BY`, `HAVING`, `LIMIT BY`, a set
operation such as `UNION ALL`, or `orderByRaw()`, is wrapped in a subquery
instead, `SELECT 1 FROM (...) LIMIT 1`, so the answer matches what the query
itself returns. For example, a `count()` without `GROUP BY` returns a row even
when no row matches, so `exists()` returns `true` for it. The `ORDER BY rand()`
of `inRandomOrder()` only sorts the rows, so it is left out, as an `orderBy()`
column is.

`exists()`, `count()` and `paginate()` do not support settings that act on the
result, such as `offset`, `limit` and `additional_result_filter`: they act on
the `SELECT 1` or `SELECT count()` that these methods run instead. With
`offset`, `exists()` can return `false` for a query that returns rows, and
`count()` and the total of `paginate()` return `0`. `additional_result_filter`
makes all three fail with `UNKNOWN_IDENTIFIER`.

With `fix_default_query_builder` set to `false`,
`DB::connection('clickhouse')->table(...)` is Laravel's query builder, which
counts as [Laravel's query builder](#laravels-query-builder) describes.

### Counting and aggregates

```php
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;

MyTable::where('field_two', '>', 0)->count();
// SELECT count() as `count` FROM `my_table` WHERE `field_two` > 0

MyTable::where('field_two', '>', 0)->max('field_two');
// SELECT max(`field_two`) AS `aggregate` FROM `my_table` WHERE `field_two` > 0

MyTable::query()->sum(raw('field_two * id'));
// SELECT sum(field_two * id) AS `aggregate` FROM `my_table`

MyTable::query()->aggregate('quantile(0.5)', ['field_two']);
// SELECT quantile(0.5)(`field_two`) AS `aggregate` FROM `my_table`
```

`count()`, `min()`, `max()`, `sum()`, `avg()`, `average()` and `aggregate()`
read the rows that the query returns, without its `format()`, and without its
`LIMIT` and `OFFSET`, except in a set operation, where they limit the rows of
its first query (see [UNION, INTERSECT and EXCEPT](#union-intersect-and-except)).
Without a row, `sum()` returns `0` and `avg()` `null`, and `min()` and `max()`
return the default of the column's type, such as `0` or `''`, as ClickHouse
does.

A query whose columns they cannot simply replace is counted and aggregated in
a subquery: a query with a set operation, `GROUP BY`, `HAVING` or `LIMIT BY`,
with a selected column that is not a plain column name, such as an alias, an
expression, a `DISTINCT` column or a `withAlias()` name, or with an `ORDER BY`
entry that defines an alias, that adds or multiplies rows, with `WITH FILL`
or `arrayJoin()`, or that leaves rows out, with `LIMIT`, `LIMIT ... BY`,
`OFFSET` or `FETCH` in its raw SQL, outside parentheses and quotes, or adds a
set operation, with `UNION`, `INTERSECT` or `EXCEPT` there. The raw SQL given
to a `Column` function of an `ORDER BY` entry, such as
`plus(raw('0 AS b'))`, counts as SQL of the entry:

```php
MyTable::select('field_one')->groupBy('field_one')->count();
// SELECT count() AS `count` FROM (SELECT `field_one` FROM `my_table` GROUP BY `field_one`)
```

The aggregated column must then be one that the query returns:
`select(['field_one', new RawColumn('count()', 'c')])->groupBy('field_one')->max('id')`
fails with `UNKNOWN_IDENTIFIER`, because the subquery returns `field_one` and
`c`. Select the column, as in
`select(['field_one', new RawColumn('max(id)', 'id')])->groupBy('field_one')->max('id')`,
or leave out the select list. Any other query is aggregated in place, without
its `ORDER BY`.

With `WITH FILL`, or with `LIMIT`, `LIMIT ... BY`, `OFFSET` or `FETCH` in the
raw SQL of an `ORDER BY` entry, the subquery keeps the whole `ORDER BY`, which
decides the rows that the query returns. ClickHouse reads such a limit as a
clause of the query, so it is counted, unlike `limit()` and `offset()`. For a
table `lb` with the rows `(1, 'g1')`, `(2, 'g1')` and `(3, 'g2')`, this query
returns two rows, the one with the largest `id` of each `grp`:

```php
$query = DB::connection('clickhouse')->table('lb')->select('id', 'grp')->orderByRaw('id DESC LIMIT 1 BY grp');

$query->count(); // 2
// SELECT count() AS `count` FROM (SELECT `id`, `grp` FROM `lb` ORDER BY id DESC LIMIT 1 BY grp)

$query->min('id'); // 2
// SELECT min(`id`) AS `aggregate` FROM (SELECT `id`, `grp` FROM `lb` ORDER BY id DESC LIMIT 1 BY grp)
```

`LIMIT`, `OFFSET` or `FETCH` inside parentheses, as in a sub-query, or inside
quotes does not count: `orderByRaw("grp = 'limit 1' DESC")->count()` is
counted in place.

`paginate()`, `simplePaginate()`, `chunk()`, `first()` and `value()` add their
own `LIMIT` after the `ORDER BY`. ClickHouse accepts it after `LIMIT ... BY`,
but rejects it with `SYNTAX_ERROR` after a raw `LIMIT`, `OFFSET` or `FETCH`,
as in `... ORDER BY id DESC LIMIT 2 LIMIT 1`. Read such a query as a
sub-query instead, and order its rows:
`DB::connection('clickhouse')->table($query)->orderBy('id', 'desc')->paginate()`
sends ``SELECT * FROM (<query>) ORDER BY `id` DESC LIMIT 0, 15``.

A string column is quoted as a column name, part by part at its dots, as
`select()` quotes it, so a column taken from user input cannot add SQL to the
query: `max('my_table.field_two')` gives ``max(`my_table`.`field_two`)``, and
`'*'` is written as it is. Pass SQL as `raw()`, a `RawColumn` or `DB::raw()`,
as `sum(raw('field_two * id'))` above: `sum('field_two * id')` looks for a
column of that name and fails with `UNKNOWN_IDENTIFIER`. Pass a name without
backticks, because ``max('`field_two`')`` is quoted with its backticks.

`aggregate()` takes the name of a ClickHouse aggregate function, such as
`uniqExact`, or of a parametric one whose parameters are numbers, such as
`quantile(0.5)`, `quantiles(0.5, 0.9)` or `topK(2)`. Any other name throws an
`InvalidArgumentException` before anything is sent:
``Invalid aggregate function name [sequenceMatch('(?1)(?2)')]: a function name must match [A-Za-z_][A-Za-z0-9_]*, optionally followed by a list of numbers in parentheses, such as quantile(0.5).``
Select such an aggregate with a `RawColumn` and read it with `value()`:

```php
MyTable::select(new RawColumn("sequenceMatch('(?1)(?2)')(created_at, field_two > 0, field_two < 0)", 'matched'))
    ->value('matched');
// SELECT sequenceMatch('(?1)(?2)')(created_at, field_two > 0, field_two < 0) AS `matched` FROM `my_table` LIMIT 1
```

`paginate()` takes its total from `count()` and reads the page with a copy of
the query, whose `LIMIT` and `OFFSET` replace those of the query, as in
Laravel: `MyTable::query()->orderBy('id')->paginate(15)` sends
``SELECT count() as `count` FROM `my_table` `` and
``SELECT * FROM `my_table` ORDER BY `id` ASC LIMIT 0, 15``. `simplePaginate()`
reads one row more than a page instead of the total. `getQueryForCount()`,
`getQueryForExists()` and `getQueryForPage($perPage, $page)` return the
queries that `count()`, `exists()` and `paginate()` run, without running them:
`MyTable::where('field_two', '>', 0)->getQueryForCount()->toSql()` gives
``SELECT count() as `count` FROM `my_table` WHERE `field_two` > 0``. ClickHouse
returns the count as a string, such as `'2'`, which `count()` casts to an int.

### First row, single values and collections

```php
MyTable::query()->orderBy('created_at', 'desc')->first();
// SELECT * FROM `my_table` ORDER BY `created_at` DESC LIMIT 1
// ['id' => 3, 'created_at' => '2024-01-07 10:00:00', ...], or null without a row

MyTable::where('id', 2)->value('field_one');
// SELECT `field_one` FROM `my_table` WHERE `id` = 2 LIMIT 1

MyTable::query()->orderBy('id')->pluck('field_one', 'id');
// SELECT `field_one`, `id` FROM `my_table` ORDER BY `id` ASC
// collect([1 => 'a', 2 => 'b', 3 => 'c'])

MyTable::select('id')->orderBy('id')->getCollection();
// collect([['id' => 1], ['id' => 2], ['id' => 3]])
```

The rows are associative arrays, as `getRows()` returns them, where Laravel's
query builder returns `stdClass` objects. `get()` returns the smi2 statement,
`getRows()` an array of the rows and `getCollection()` a Laravel collection of
them. Collection methods such as `pluck()`, `keyBy()` and `sum()` work on the
arrays; for `$row->id`, cast the row with `(object) $row`.

`first()` reads one row, within the query's own `LIMIT` and `OFFSET`:
`orderBy('id')->limit(10, 1)->first()` sends ``... ORDER BY `id` ASC LIMIT 1, 1``.
The columns given to `first()` replace `*` on a query that selects every
column, such as a model query or a query without a select list, and are
ignored on a query with its own select list, as in Laravel.

`value()` and `pluck()` select only their columns from a query that selects
every column, and read the values by position, so an alias, an expression such
as `raw('field_two + 1')` or `DB::raw(...)`, and an `ALIAS` or `MATERIALIZED`
column work as in `select()`. On a query with its own select list, they run it
as it is and read each column by the name that ClickHouse gives it: a column
of the query's own table without its table name, so `my_table.id` is read as
`id`, a column of a joined table with its table name, such as `users.name`, and
an aliased column by its alias, such as `name` for `'field_one as name'`.
`value()` throws an `InvalidArgumentException` for a column that the row does
not have: `The query does not select the column [field_one] that value() reads.`

`first()`, `value()`, `pluck()`, `chunk()`, `paginate()` and `simplePaginate()`
read rows with a copy of the query, so the builder keeps its clauses, and a
`delete()` or `update()` on it afterwards changes the rows that the query
selects. On a set operation, they read the whole result (see
[UNION, INTERSECT and EXCEPT](#union-intersect-and-except)).

### Parallel queries

`Parallel::getRows()` runs several queries at the same time and returns the
rows of each, keyed and ordered as you give them:

```php
use Oralunal\LaravelClickHouse\Parallel;
use Oralunal\LaravelClickHouse\RawColumn;

$results = Parallel::getRows([
    'clicks' => MyTable::where('field_one', 'click')->select(['id', 'field_two'])->orderBy('id'),
    'top' => MyTable::select(['field_one', new RawColumn('sum(field_two)', 'total')])
        ->groupBy('field_one')
        ->orderBy('field_one')
        ->settings('max_threads', 2),
    'other' => MyTable2::where('f_int', 1),
]);
// ['clicks' => [['id' => 1, 'field_two' => 10], ['id' => 3, 'field_two' => 30]],
//  'top' => [['field_one' => 'click', 'total' => '40'], ['field_one' => 'view', 'total' => '20']],
//  'other' => [['id' => 7, 'f_int' => 1]]]
```

The requests go out together over one curl multi handle, so a batch takes
about as long as its slowest query: four queries of `sleep(0.5)` took 0.51
seconds together on ClickHouse 24.8. At most 10 requests are in flight at
once; pass another number, at least 2, as the second argument. The queries may
use different connections and nodes, as `MyTable2`, a model of the
`clickhouse2` connection (see
[Working with multiple ClickHouse instances](#working-with-multiple-clickhouse-instances-in-a-project)).

An entry is one of these:

- A query of the package's query builder, such as a model query or
  `DB::connection('clickhouse')->table(...)` while `fix_default_query_builder`
  is on. It is sent as `get()` sends it, on the node that its connection talks
  to: `top` above is sent as
  ``SELECT `field_one`, sum(field_two) AS `total` FROM `my_table` GROUP BY `field_one` ORDER BY `field_one` ASC FORMAT JSON SETTINGS max_threads=2``.
  It is logged with the time of its request, as `get()` logs it, also when it
  fails.
- A Laravel query builder on a ClickHouse connection, as
  [`fix_default_query_builder`](#laravels-query-builder) set to `false` gives
  it.
- SQL, as `['sql' => 'SELECT count() AS c FROM my_table WHERE field_one = ?', 'bindings' => ['click'], 'connection' => 'clickhouse']`.
  `bindings` and `connection` may be left out; the connection is `clickhouse`
  by default.

A Laravel query builder and SQL run as `select()` runs them (see
[Raw SQL with bindings](#raw-sql-with-bindings)): their `?` bindings are
written into the SQL, the connection's `beforeExecuting()` callbacks run
before the batch is sent, only the queries that succeed are logged, and the
rows of a Laravel query builder go through its `afterQuery()` callbacks. An
Eloquent builder throws an `InvalidArgumentException`; pass
`$query->toBase()`, whose rows come back as arrays, not as models. The results
are arrays, where the upstream package laravel-clickhouse/laravel-clickhouse
returns collections of models. Every entry is compiled and checked before
anything is sent, so an entry that fails to compile, or SQL whose `FORMAT`
clause `select()` refuses, sends nothing.

`Parallel::get()` takes the same entries and returns the
`ClickHouseDB\Statement` of each, for `totals()`, `extremes()`, `countAll()`
or the `rawData()` of a `format()`. It does not throw for a failed query: its
statement throws when you read its rows.

```php
$statements = Parallel::get(['ids' => MyTable::select('id')->orderBy('id')->format('CSV')]);
$statements['ids']->rawData(); // "1\n2\n3\n"
```

To send a query of the package's query builder yourself, as `get()` and
`Parallel` send it, take the SQL of `getQueryToSend()`, which has
`FORMAT JSON` before the settings of a query with settings and no `format()`,
and the smi2 client of `getQueryClient()`:

```php
$query = MyTable::select('id')->orderBy('id')->settings('max_threads', 2);
$sql = $query->getQueryToSend()->toSql();
// SELECT `id` FROM `my_table` ORDER BY `id` ASC FORMAT JSON SETTINGS max_threads=2
$query->getQueryClient()->select($sql)->rows(); // [['id' => 1], ['id' => 2]]
```

When a query fails, `getRows()` waits for the other queries, and then throws
an `Oralunal\LaravelClickHouse\Exceptions\ParallelQueryException` with the
rows of the queries that succeeded:

```php
use Oralunal\LaravelClickHouse\Exceptions\ParallelQueryException;

try {
    $results = Parallel::getRows([
        'clicks' => MyTable::where('field_one', 'click')->select(['id']),
        'missing' => DB::connection('clickhouse')->table('no_such_table'),
    ]);
} catch (ParallelQueryException $e) {
    $e->getResults();    // ['clicks' => [['id' => 1], ['id' => 3]]]
    $e->getErrors();     // ['missing' => a ClickHouseDB\Exception\DatabaseException with the code 60]
    $e->getStatements(); // the statement of each query, keyed as given
}
```

Its message lists each error, and its `getPrevious()` is the first one:

```text
1 of 2 parallel queries failed:
[missing] ClickHouseDB\Exception\DatabaseException: Unknown table expression identifier 'no_such_table' in scope SELECT * FROM no_such_table. (UNKNOWN_TABLE)
IN:SELECT * FROM `no_such_table` FORMAT JSON
```

It extends the package's `QueryException`, so
`catch (\ClickHouseDB\Exception\QueryException $e)` catches it too.

On one connection, `selectParallelly()` takes SQL, or SQL with its bindings,
which fill `?`, `:name` and `{name}` placeholders as in `select()`. Every query
runs on that connection's active node, and a `connection` key is ignored:

```php
DB::connection('clickhouse')->selectParallelly([
    'total' => 'SELECT count() AS c FROM my_table',
    'recent' => ['sql' => 'SELECT id FROM my_table WHERE field_two >= ? ORDER BY id', 'bindings' => [20]],
]);
// ['total' => [['c' => '3']], 'recent' => [['id' => 2], ['id' => 3]]]
```

The queries that `count()`, `exists()` and `paginate()` run can share a batch.
ClickHouse returns the count as a string:

```php
$query = MyTable::where('field_two', '>', 15)->orderBy('id');

Parallel::getRows([
    'total' => $query->getQueryForCount(),
    'exists' => $query->getQueryForExists(),
    'page' => $query->getQueryForPage(15, 1),
]);
// ['total' => [['count' => '2']], 'exists' => [['1' => 1]], 'page' => [['id' => 2, ...], ['id' => 3, ...]]]
```

Limits:

- Only `SELECT`: the requests are sent with `readonly=2`, so an `INSERT` or an
  `ALTER` fails with `READONLY`.
- A request that does not get HTTP 200 is sent again in a later round, as the
  `retries` and `retry_on` of its connection allow (see [Retries](#retries)).
  There is no failover to another node.
- `timeout_query` applies to each request from when it is sent. With more
  queries than the concurrency, the later ones are sent as earlier ones end,
  so a batch can take longer than `timeout_query`.
- ClickHouse's `max_concurrent_queries` limits the queries that run on a
  server at once; a query over it fails with `TOO_MANY_SIMULTANEOUS_QUERIES`.
- Inside [`session()`](#sessions-and-temporary-tables), `Parallel` and
  `selectParallelly()` throw a `LogicException` before anything is sent,
  because ClickHouse runs one query of a session at a time.
- While a connection [pretends](#pretending), its Laravel query builders and
  SQL are not sent and return no rows, while a query of the package's query
  builder is sent, as `get()` sends it.

`asyncWithQuery()`, `getAsyncQueries()`, `toAsyncSqls()` and
`toAsyncQueries()` of the query builder are deprecated: `get()` never ran the
queries that `asyncWithQuery()` adds. Run them with
`Parallel::get([$query, $otherQuery])`, or
`Parallel::getRows($query->getAsyncQueries())` for the queries that
`asyncWithQuery()` added. The smi2 client's own `selectAsync()` and
`executeAsync()`, through `getClient()`, send each request once, whatever
`retries` says, and throw inside `session()`.

### ClickHouse query features

The query builder that `MyTable::select()` and `MyTable::where()` return
supports the ClickHouse features below, and Laravel's where methods, from
[Conditions as Laravel writes them](#conditions-as-laravel-writes-them) on. So does
`DB::connection('clickhouse')->table(...)` while `fix_default_query_builder`
is on, as it is by default. The comments show the SQL each call produces. The
examples use the `raw()` helper and `JoinClause`:

```php
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\JoinClause;
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;
```

#### Values in conditions

`where()`, `preWhere()`, `having()`, their `or`, `In`, `NotIn`, `GlobalIn` and
`Between` variants, and `update()` write these PHP values as ClickHouse
literals:

| PHP value | SQL |
| --- | --- |
| `DateTimeInterface`, Carbon included | `'2026-10-08 14:30:00'` |
| `true`, `false` | `1`, `0` |
| Float | every digit: `0.30000000000000004` for `0.1 + 0.2`; `nan`, `inf` and `-inf` for `NAN`, `INF` and `-INF` |
| Backed enum | its value: `'active'`, `3` |
| Pure enum | its name, quoted: `'Active'` |
| Value object or expression of the smi2 client, such as `UInt64`, `UUID`, `MapType` or `Raw` | a literal of its type, or the SQL of the expression: `18446744073709551615`, `'0b9c5b1c-...'`, `map('k', 1)`, `now()` |
| Other `Stringable` object, such as `Str::of()` | its string, quoted |

```php
MyTable::select()
    ->where('created_at', '>=', now()->subDay())           // `created_at` >= '2026-10-07 14:30:00'
    ->where('is_active', true)                             // `is_active` = 1
    ->whereIn('status', [Status::Active, Status::Paused]); // `status` IN ('active', 'paused')
```

Dates are written in the date's own time zone, with second precision unless
the connection's `datetime_precision` is `microsecond` (see
[Dates with microseconds](#dates-with-microseconds)):

- At `second`, the default, the sub-second part is dropped. To compare a
  `DateTime64` column to the millisecond, pass a string,
  `$date->format('Y-m-d H:i:s.v')`, or set `datetime_precision`.
- ClickHouse reads the literal in the column's time zone, or in the server's
  for a column without one. Convert the date to that zone first when the two
  differ.
- ClickHouse does not compare a `Date` or `Date32` column with a
  `'Y-m-d H:i:s'` literal: the query fails with `TYPE_MISMATCH`. Pass
  `$date->format('Y-m-d')` for these columns.

Floats are written with every digit, so a condition compares the exact value
of the PHP float: `where('ratio', 1 / 3)` compiles to
`` `ratio` = 0.3333333333333333 ``. Every insert of the package keeps every
digit too: the inserts of models, `insertAssoc()`, `insertBulk()`, the
`prepareAndInsert` methods, `create()`, `save()` and the buffers, the
query builder's `insert()`, as in
`DB::connection('clickhouse')->table('my_table')->insert(...)`, in both
[insert formats](#inserting-rows-as-jsoneachrow), the `?` bindings of
[raw SQL](#raw-sql-with-bindings) and of
[Laravel's query builder](#laravels-query-builder), its inserts included, and
the items of an `InsertArray::TYPE_DECIMAL` (see
[Helpers for inserting different data types](#helpers-for-inserting-different-data-types)).
So after `MyTable::insertAssoc([['ratio' => 1 / 3]])`, `where('ratio', 1 / 3)`
finds the row. `NAN`, `INF` and `-INF` are sent as `nan`, `inf` and `-inf`.

Named bindings such as `:ratio` in raw SQL still send a float with 14
significant digits, as every insert of 3.0.0 did. So they store `1 / 3` as
`0.33333333333333`, which is not equal to it: `where('ratio', 1 / 3)` does not
find such a row, or a row that 3.0.0 stored. Round such a float in PHP before
you insert and compare it, as in `round($ratio, 6)`, or compare with a range:
`whereBetween('ratio', [$ratio - 1e-9, $ratio + 1e-9])`.

ClickHouse reads a float in `update()` as a `Float64` and cuts it off at the
column's scale when it converts it for a `Decimal` or integer column. So
`update()` with a float can store less than you meant:
`update(['price' => 19.99])` stores `19.98` in a `Decimal(10, 2)` column, as in
3.0.0, because the `Float64` nearest to `19.99` is slightly less than `19.99`.
A float computed in PHP is cut off too: `4.35 * 100` is `434.99999999999994`
in PHP, so `update(['price' => 4.35 * 100])` stores `434.99`, and
`update(['cents' => 0.29 * 100])` stores `28` in an `Int32` column. Rounding in
PHP does not help `update()`: `round(19.99, 2)` is the same float and stores
`19.98`. Pass a numeric string or an int instead:
`update(['price' => '19.99'])`, or `number_format($price, 2, '.', '')` as the
value, stores `19.99`, and `(int) round(0.29 * 100)` stores `29`.

In a condition, rounding does help. `where('price', 4.35 * 100)` does not find
a stored `435.00`, but `where('price', round(4.35 * 100, 2))`, which is written
as `435.0`, does, and so does `where('price', '435.00')`. A float such as
`19.99` finds a stored `19.99` as it is.

The inserts store the float `19.99` as `19.99`, because ClickHouse reads a
number in the rows of an `INSERT` straight into the column's type. This holds
for `insertAssoc()`, `insertBulk()`, `create()`, `save()`, the query builder's
`insert()`, `DB::table()->insert()`, `buffer()`, a `Buffer` table, an `INSERT`
with `?` bindings and the JSONEachRow format. A float computed in PHP is cut
off at the column's scale, because every insert writes it with every digit:
`4.35 * 100`, which is `434.99999999999994` in PHP, stores `434.99` in a
`Decimal(10, 2)` column, and `0.29 * 100`, which is `28.999999999999996`,
stores `28` in an `Int32` column, where 3.0.0 stored `435` and `29`, as named
bindings, which still send 14 digits, do. In the JSONEachRow format, an
integer column refuses such a float with `CANNOT_PARSE_INPUT_ASSERTION_FAILED`.
Round such a value in PHP first, as in `round(4.35 * 100, 2)`, which stores
`435`, or pass a numeric string or an int.

ClickHouse finds no value equal to NaN, so `where('f', NAN)`, which compiles to
`` `f` = nan ``, matches no row; use `whereRaw('isNaN(f)')`.

The `In` methods take a list of values: a PHP array, a collection such as the
result of `pluck()`, or another `Arrayable`, which they convert with
`toArray()`, as Laravel does. `where()`, `preWhere()` and `having()` take a list
as a PHP array or a collection. Without an operator they compare the column
with the list using `IN`, and the `IN`, `NOT IN`, `GLOBAL IN` and
`GLOBAL NOT IN` operators take a list too. A nested array in the list is a
tuple, so you can compare several columns at once:

```php
MyTable::select()
    ->whereIn('id', collect([1, 2, 3]))                      // `id` IN (1, 2, 3)
    ->where('status', collect(['active', 'paused']))         // `status` IN ('active', 'paused')
    ->where('user_id', 'GLOBAL IN', [7, 8])                  // `user_id` GLOBAL IN (7, 8)
    ->whereIn(raw('(id, field_one)'), [[1, 'a'], [2, 'b']]); // (id, field_one) IN ((1, 'a'), (2, 'b'))
```

An empty list matches no row with `IN` and every row with `NOT IN`, as in
Laravel. ClickHouse rejects an empty `IN ()`, so the condition becomes `0 = 1`
or `1 = 1`, with the `AND` or `OR` of the call. This applies to every `In`
method and to an empty list in `where()`, `preWhere()` and `having()`:

```php
->whereIn('id', [])                                // 0 = 1
->whereNotIn('id', [])                             // 1 = 1
->where('field_two', '<', 0)->orWhereIn('id', [])  // `field_two` < 0 OR 0 = 1
->where('field_two', '<', 0)->whereNotIn('id', []) // `field_two` < 0 AND 1 = 1
```

With the `BETWEEN` and `NOT BETWEEN` operators, the list holds the lower and
the upper bound: `where('id', 'BETWEEN', [1, 10])` compiles to
`` `id` BETWEEN 1 AND 10 ``. As in Laravel, the first two values count, in
order and whatever their keys: `['from' => 1, 'to' => 10]` gives the same SQL,
and a third value is ignored. A `null` bound is written as `NULL`, which no
value is between. `whereBetween()`, `whereNotBetween()`, their `or` variants
and the `preWhere` and `having` variants read their list the same way. A list
of fewer than two values throws an `InvalidArgumentException`, and so does a
list with an operator that takes none, such as `=`. `update()` writes an array
as an array literal instead, such as `['a', 'b']`.

A value the builder cannot write, such as a `stdClass` object, throws an
`InvalidArgumentException`. So do a generator or another `Traversable` object
that is not a collection, an `Arrayable` object that is not a collection, such
as an Eloquent model, as a `where()`, `preWhere()` or `having()` value, and a
collection anywhere else, for example as an `update()` value or in
`withAlias()`. Pass a PHP array there, or `$model->getKey()` for a model.

Write raw SQL with `raw()`, `new RawColumn(...)` or Laravel's `DB::raw()`. The
builder writes `DB::raw()` as it writes `raw()`, in `select()`, the where,
prewhere and having methods, as a column or a value, `orderBy()`, `groupBy()`,
`table()` and `from()`, joins, `arrayJoin()`, `withExpression()`, `withAlias()`,
`settings()` and the values of `update()`:

```php
MyTable::where(DB::raw('field_two + 1'), 6);
// SELECT * FROM `my_table` WHERE field_two + 1 = 6
DB::connection('clickhouse')->table('my_table')->select(DB::raw('count() AS c'));
// SELECT count() AS c FROM `my_table`
```

#### NULL checks

```php
MyTable::select()
    ->whereNull('deleted_at')             // `deleted_at` IS NULL
    ->orWhereNotNull(['email', 'phone']); // OR `email` IS NOT NULL OR `phone` IS NOT NULL
```

`whereNull()`, `orWhereNull()`, `whereNotNull()`, `orWhereNotNull()` and the
same methods for `PREWHERE` and `HAVING`, such as `preWhereNull()` and
`orHavingNotNull()`, take a column name, a `raw()` expression or an array of
them. An array adds one condition per column.

A comparison with an explicit `null` compiles to the same checks, in `where()`,
`preWhere()`, `having()` and their `or` variants:

```php
->where('parent_id', null)       // `parent_id` IS NULL
->where('parent_id', '=', null)  // `parent_id` IS NULL
->where('parent_id', null, null) // `parent_id` IS NULL
->where('parent_id', '!=', null) // `parent_id` IS NOT NULL
->where('parent_id', '<>', null) // `parent_id` IS NOT NULL
```

With a `null` third argument, a second argument that is not an operator is the
value, as in Laravel: `where('processed', 0, null)` compiles to
`` `processed` = 0 ``. So a helper that passes on
`($column, $operator = null, $value = null)` builds the same condition as a
direct call. `<>` works with any value: `where('status', '<>', 'paused')`
compiles to `` `status` != 'paused' ``. The `IS NULL` and `IS NOT NULL`
operators take no value; `where('parent_id', 'IS NULL', 5)` throws an
`InvalidArgumentException`.

Only a column whose type accepts `NULL` holds it: `Nullable(...)`,
`LowCardinality(Nullable(...))`, the experimental `Variant(...)` and `Dynamic`
types, and a `SimpleAggregateFunction` over one of them. On any other column,
`IS NULL` matches no row.

#### Empty strings and arrays

```php
MyTable::select()
    ->whereEmpty('tags')          // empty(`tags`)
    ->orWhereNotEmpty('comment'); // OR notEmpty(`comment`)
```

`whereEmpty()`, `orWhereEmpty()`, `whereNotEmpty()`, `orWhereNotEmpty()` and
the same methods for `PREWHERE` and `HAVING` take the same arguments as the
NULL checks. They work on `String`, `Array` and `Map` columns.

#### Conditions as Laravel writes them

`where()`, `preWhere()`, `having()`, their `or` variants and the other
condition methods read operators and booleans in any letter case. An array of
conditions is one group in parentheses, whose entries take the boolean of the
call, as in Laravel 11 and later. `MyTable::where()` takes it too:

```php
MyTable::select()
    ->where('field_one', 'like', 'a%')                     // `field_one` LIKE 'a%'
    ->where('id', 'not in', [1, 2])                        // AND `id` NOT IN (1, 2)
    ->where(['field_two' => 2, 'user_id' => 7])            // AND (`field_two` = 2 AND `user_id` = 7)
    ->orWhere([['field_two', '>', 10], ['user_id', 8]])    // OR (`field_two` > 10 OR `user_id` = 8)
    ->whereIn('id', [3], 'or');                            // OR `id` IN (3)
```

An entry is a `[column, value]` or `[column, operator, value]` array, or a
`column => value` pair; any other entry throws an `InvalidArgumentException`.
A closure, or an array, that adds no condition is left out:
`where('id', 1)->where(fn ($query) => $query)` compiles to ``WHERE `id` = 1``.
`delete()` and `update()` with no other condition throw, as they do without a
where condition.

`when()` and `unless()` call a closure only when a value is truthy or falsy,
as in Laravel:

```php
MyTable::select()->when($userId, fn ($query, $userId) => $query->where('user_id', $userId));
```

#### Comparing columns

```php
MyTable::select()
    ->whereColumn('updated_at', 'created_at')               // `updated_at` = `created_at`
    ->whereColumn('updated_at', '>', 'created_at')          // AND `updated_at` > `created_at`
    ->orWhereColumn([['field_one', 'comment'], ['field_two', '<', 'user_id']]);
    // OR (`field_one` = `comment` OR `field_two` < `user_id`)
```

`whereColumn()`, `orWhereColumn()`, `preWhereColumn()` and
`orPreWhereColumn()` compare two columns. A string is a column name, quoted
part by part, so `whereColumn('t.a', '>', 't.b')` compiles to
`` `t`.`a` > `t`.`b` ``, and `raw()` is SQL: `whereColumn('field_two', '<', raw('user_id + 1'))`
compiles to `` `field_two` < user_id + 1 ``. A list of `[first, second]` or
`[first, operator, second]` arrays, or of `first => second` pairs, is one group
in parentheses whose comparisons take the boolean of the call.

`whereBetweenColumns()` and the other `BetweenColumns` methods take the same
kinds of values: `whereBetweenColumns('field_two', ['user_id', raw('user_id + 10')])`
compiles to `` `field_two` BETWEEN `user_id` AND user_id + 10 ``, and an
integer is written as a number. 3.0.0 quoted every value as a column name.

#### EXISTS sub-queries

```php
MyTable::select('id')->whereExists(fn ($query) => $query->from('users')->where('banned', 1));
// SELECT `id` FROM `my_table` WHERE EXISTS (SELECT * FROM `users` WHERE `banned` = 1)
```

`whereExists()`, `orWhereExists()`, `whereNotExists()` and
`orWhereNotExists()` take a closure, which receives a new query, or a query
builder of this package. The sub-query is compiled when the method is called.

ClickHouse has no correlated sub-queries: a sub-query cannot read a column of
the outer query, as Laravel code often does with
`whereColumn('orders.user_id', 'users.id')`. A select with such a sub-query
fails with `UNSUPPORTED_METHOD`. ClickHouse would accept a mutation with it,
and then fail the mutation in the background again and again, which holds back
every later mutation of the table. So `delete()` and `update()` check such a
condition first, and throw a `QueryException` without sending the mutation
(see [Deletions](#deletions)):

```php
DB::connection('clickhouse')->table('users')
    ->whereExists(fn ($query) => $query->from('orders')->whereColumn('orders.user_id', 'users.id'))
    ->delete(false);
// throws QueryException: Cannot delete with a where condition that ClickHouse cannot run: it refused
// the condition in a SELECT with UNSUPPORTED_METHOD. ... Nothing was sent. Select the keys first, and
// delete by them instead: whereIn('id', $query->pluck('id')).
```

The same holds for `whereIn()` with such a sub-query.

ClickHouse's analyzer, on by default in 24.8, compares only some of the
columns of an `INTERSECT` or `EXCEPT` query inside `EXISTS`, and gets wrong
results. So a sub-query that uses `INTERSECT` or `EXCEPT` is compiled to read
every column: `EXISTS (SELECT * FROM (<a> EXCEPT <b>) WHERE NOT ignore(*))`.
So is a sub-query that reads rows from such a query, through `from()`, a join,
or a `withExpression()` of its own or of the outer query, which must be added
before `whereExists()` is called. An `INTERSECT` or `EXCEPT` written in raw SQL
is not detected; add `->whereRaw('NOT ignore(*)')` to the sub-query that reads
it.

#### Conditions on several columns

```php
MyTable::select()
    ->whereAny(['field_one', 'comment'], 'ilike', '%error%')
    // (`field_one` ILIKE '%error%' OR `comment` ILIKE '%error%')
    ->whereAll(['field_two', 'user_id'], '>', 0)  // AND (`field_two` > 0 AND `user_id` > 0)
    ->whereNone(['field_one', 'comment'], 'x');  // AND NOT (`field_one` = 'x' OR `comment` = 'x')
```

`whereAll()`, `whereAny()`, `whereNone()`, their `or` variants, and
`preWhereAll()`, `preWhereAny()` and `preWhereNone()` with their `or` variants,
compare each column as `where()` does. Laravel adds no condition for an empty
list of columns, but these methods throw an `InvalidArgumentException`: in
`delete()` or `update()`, the missing condition would change more rows.

#### Dates and times in conditions

| Method | SQL |
| --- | --- |
| `whereDate('created_at', '2024-01-05')` | ``toDate32(`created_at`) = '2024-01-05'`` |
| `whereTime('created_at', '>=', '9:05')` | ``formatDateTime(`created_at`, '%H:%i:%S') >= '09:05:00'`` |
| `whereDay('created_at', '05')` | ``toDayOfMonth(`created_at`) = 5`` |
| `whereMonth('created_at', '>', 1)` | ``toMonth(`created_at`) > 1`` |
| `whereYear('created_at', Carbon::parse('2024-06-01'))` | ``toYear(`created_at`) = 2024`` |

Each method has `or`, `preWhere` and `orPreWhere` variants, such as
`orWhereDate()` and `preWhereYear()`.

- `whereDate()` uses `toDate32()`, which works on `Date`, `Date32`, `DateTime`
  and `DateTime64` columns and keeps the dates before 1970 and after 2149,
  where `toDate()` wraps around. It uses the primary key as `toDate()` does.
- `whereTime()` compares the time of day to the second, as text, in the
  column's time zone. A time is written as `HH:MM:SS`: `'9:05'` becomes
  `'09:05:00'`, and a fraction of a second is dropped. The hour takes one or
  two digits, and the minutes and the seconds two, so `'7:8'` and `'9:05:3'`
  throw an `InvalidArgumentException`, as any other string does; Laravel's
  query builder pads them (see [Dates and times](#dates-and-times)).
  `formatDateTime()` cannot use the primary key.
- `whereDay()`, `whereMonth()` and `whereYear()` compare with an integer,
  because ClickHouse does not compare `toDayOfMonth()` with Laravel's padded
  text, such as `'05'`. A value that is not an integer, a string of digits or
  a date throws an `InvalidArgumentException`.
- A `DateTimeInterface` value gives its date, time of day, day, month or year
  in its own time zone, while ClickHouse reads the column in the column's time
  zone, or in the server's for a column without one.
- `null` compiles to `IS NULL`, or `IS NOT NULL` with `!=`, as in `where()`:
  `whereDate('deleted_at', null)` gives ``toDate32(`deleted_at`) IS NULL``. A
  list works with `IN`, `NOT IN`, `BETWEEN` and `NOT BETWEEN`.

#### LIKE, ILIKE and the order of the rows

```php
->whereLike('field_one', 'error%')           // `field_one` ILIKE 'error%'
->whereLike('field_one', 'Error%', true)     // `field_one` LIKE 'Error%'
->whereNotLike('field_one', '%debug%')       // `field_one` NOT ILIKE '%debug%'
->latest()                                   // ORDER BY `created_at` DESC
->oldest('updated_at')                       // ORDER BY `updated_at` ASC
->inRandomOrder()                            // ORDER BY rand()
```

`whereLike()`, `orWhereLike()`, `whereNotLike()` and `orWhereNotLike()` ignore
case, as in Laravel, unless their third argument, `caseSensitive`, is `true`.
ClickHouse can use the primary key for a `LIKE 'prefix%'` pattern, but not for
`ILIKE`. The `where()` operators `ilike` and `not ilike` work too.

ClickHouse has no seeded random function, so `inRandomOrder()` with a seed
throws an `InvalidArgumentException`. For an order that a seed repeats, order
by a hash of the seed and a key: `orderByRaw('cityHash64(42, id)')`.

#### SAMPLE with an offset

```php
MyTable::select()->sample(0.1, 0.5); // SAMPLE 0.1 OFFSET 0.5
```

The table needs a `SAMPLE BY` key, or ClickHouse fails with
`SAMPLING_NOT_SUPPORTED`. `sample(0.1)`, without an offset, still compiles to
`SAMPLE 0.1`.

#### UNION, INTERSECT and EXCEPT

```php
MyTable::select('user_id')
    ->where('event', 'signup')
    ->except(MyTable::select('user_id')->where('event', 'purchase'));
// SELECT `user_id` FROM `my_table` WHERE `event` = 'signup'
// EXCEPT SELECT `user_id` FROM `my_table` WHERE `event` = 'purchase'
```

| Method | Keyword |
| --- | --- |
| `unionAll()` | `UNION ALL` |
| `unionDistinct()` | `UNION DISTINCT` |
| `intersect()` | `INTERSECT` |
| `intersectDistinct()` | `INTERSECT DISTINCT` |
| `except()` | `EXCEPT` |
| `exceptDistinct()` | `EXCEPT DISTINCT` |

Each method takes a builder, or a closure that receives a new builder. With
ClickHouse's default settings, `intersect()` and `except()` keep the duplicate
rows of the first query, and the `Distinct` variants return each row once.
This builder has no `union()`: ClickHouse rejects a bare `UNION` unless the
`union_default_mode` setting is set. Laravel's query builder sends
`union distinct` for its `union()` (see [Laravel's query builder](#laravels-query-builder)).

The queries added to one builder follow each other in call order, without
parentheses, so ClickHouse's precedence applies: `INTERSECT` is evaluated
first, then `UNION` and `EXCEPT` from left to right.
`$a->unionAll($b)->intersect($c)` returns the rows of `$a` plus the rows of
`$b` that are also in `$c`. To intersect the whole union, select from it as a
sub-query:

```php
DB::connection('clickhouse')->table($a->unionAll($b))->intersect($c);
// SELECT * FROM (<a> UNION ALL <b>) INTERSECT <c>
```

A builder or closure that has set operations of its own is one operand, in
parentheses:

```php
$a->except($b->unionAll($c));
// <a> EXCEPT (<b> UNION ALL <c>)
```

The parentheses are left out where they cannot change the result: an operand
of `unionAll()` whose own set operations are all `UNION ALL` is not put in
parentheses, unless it has its own `WITH` clause or `INTERSECT` follows it:

```php
$a->unionAll($b->unionAll($c));
// <a> UNION ALL <b> UNION ALL <c>
```

When such an operand comes last, the `SETTINGS` clause of its `settings()`
ends the whole statement, as in 3.0.0, so the settings apply to the whole
query: `$a->unionAll($b->unionAll($c)->settings(['limit' => 1]))` compiles to
`<a> UNION ALL <b> UNION ALL <c> SETTINGS limit=1`. Call `settings()` on the
outer query instead.

On ClickHouse 24.8, a query that ends with an operand in parentheses cannot
have `settings()` when you use it as a sub-query, in `table()`, `whereIn()`,
`withExpression()` or another set operation: ClickHouse rejects
`(<a> EXCEPT (<b> UNION ALL <c>) SETTINGS max_threads=1)` with a syntax error.
Call `settings()` on the outer query instead, such as
`DB::connection('clickhouse')->table($query)->settings('max_threads', 1)`. On
its own, such a query runs with its settings.

`count()` counts the rows of the whole query in a sub-query,
``SELECT count() AS `count` FROM (<query>)``, and so do `paginate()` for its
total and the aggregates (see [Counting and aggregates](#counting-and-aggregates)).
This builder adds `limit()` and `offset()` to the first query only, where they
limit the rows of that query. The sub-query keeps them, with the `ORDER BY`
that decides which rows they keep, so the count matches what `get()` returns:
`$a->orderBy('id')->limit(1)->unionAll($b)->count()` sends
``SELECT count() AS `count` FROM (<a> ORDER BY `id` ASC LIMIT 1 UNION ALL <b>)``,
and `$a->limit(1)->except($b)->count()` is `1` at most.

`paginate()`, `simplePaginate()`, `chunk()`, `first()` and `value()` read the
whole set operation as a sub-query, with the settings of the query on the
outer query: `$a->unionAll($b)->chunk(2, $callback)` sends
`SELECT * FROM (<a> UNION ALL <b>) LIMIT 0, 2`, then `LIMIT 2, 2`, and so on,
until a page has fewer rows. ClickHouse runs the
queries of a set operation in parallel, so without an `ORDER BY` the rows can
come in another order for each page, and the pages can overlap or leave rows
out. For pages in a fixed order, select from the set operation as a sub-query
and order the rows:

```php
DB::connection('clickhouse')->table($query)->orderBy('user_id')->paginate();
DB::connection('clickhouse')->table($query)->orderBy('user_id')->chunk(1000, $callback);
// SELECT * FROM (<query>) ORDER BY `user_id` ASC LIMIT 0, 1000
```

ClickHouse's analyzer, on by default in 24.8, gets wrong results when a query
reads only some columns of an `INTERSECT` or `EXCEPT` sub-query: it compares
only the columns that are read. For example, `SELECT count() FROM (<a> EXCEPT <b>)`
returned `0` for a sub-query that returns two rows. `exists()`, `count()` and
the total of `paginate()` read every column, with `WHERE NOT ignore(*)`, when
the query uses `INTERSECT` or `EXCEPT`, or reads rows from a query that does: a
sub-query in `from()` or a join, or a builder or closure given to
`withExpression()` or `withRecursiveExpression()`, at any depth. So the recipe
above gets the right total. A query of your own that selects only some columns
of such a sub-query can get wrong rows, so its `get()` can differ from
`count()` and `exists()`, and a page can report a total but hold no items.
Select every column, or add `->whereRaw('NOT ignore(*)')`:

```php
DB::connection('clickhouse')->table($query)->select('user_id')->whereRaw('NOT ignore(*)');
// SELECT `user_id` FROM (<query>) WHERE NOT ignore(*)
```

`exists()`, `count()` and `paginate()` do not look into raw SQL, such as a
`withExpression()` given a string. Add `->whereRaw('NOT ignore(*)')` to a query
that reads an `INTERSECT` or `EXCEPT` written in raw SQL.

#### WITH clause

```php
DB::connection('clickhouse')->table('recent')
    ->withExpression('recent', fn ($query) => $query
        ->from('events')
        ->where('created_at', '>=', raw('now() - INTERVAL 1 DAY')))
    ->withAlias('total', fn ($query) => $query->select(raw('count()'))->from('events'))
    ->select('user_id', 'total');
// WITH `recent` AS (SELECT * FROM `events` WHERE `created_at` >= now() - INTERVAL 1 DAY),
//      (SELECT count() FROM `events`) AS `total`
// SELECT `user_id`, `total` FROM `recent`
```

| Method | Adds |
| --- | --- |
| `withExpression('name', $query)` | `` `name` AS (<query>) ``, where `$query` is a builder, a closure, `raw()` or a string of SQL |
| `withRecursiveExpression('name', $query)` | the same, and the clause becomes `WITH RECURSIVE` |
| `withAlias('alias', $value)` | `` <value> AS `alias` ``: a builder or closure becomes a scalar sub-query, `raw()` is written as is, an array becomes an array literal such as `[1, 2]`, and any other value is written like a `where()` value |

The entries keep their call order in one `WITH` clause. A name is quoted as one
identifier, even with a dot in it, and its backticks and backslashes are
escaped: `withAlias('a.b', 1)` adds `` 1 AS `a.b` ``.
`select()` and `from()` split a name at its dots, so refer to such a name with
``raw('`a.b`')``.

```php
DB::connection('clickhouse')->table('r')
    ->withRecursiveExpression('r', fn ($query) => $query
        ->select(raw('1 AS n'))
        ->unionAll(fn ($query) => $query->select(raw('n + 1'))->from('r')->where('n', '<', 10)));
// WITH RECURSIVE `r` AS (SELECT 1 AS n UNION ALL SELECT n + 1 FROM `r` WHERE `n` < 10) SELECT * FROM `r`
```

`WITH RECURSIVE` needs ClickHouse's analyzer, which 24.8 enables by default.
With `enable_analyzer = 0` the query fails with `UNKNOWN_TABLE`.

A sub-query used in `from()`, a join, `whereIn()` or a set operation can have
its own `WITH` clause. On ClickHouse 24.8, a query in a set operation that has
its own `WITH` clause does not see the sub-queries that `withExpression()`
names in the outer query, and fails with `UNKNOWN_TABLE`; the outer
`withAlias()` values stay visible. Select such a query from a sub-query
instead: `->unionAll(fn ($query) => $query->from($queryWithItsOwnWith))`.

#### SEMI, ANTI, ASOF, RIGHT, FULL and CROSS joins

These methods take `($table, $using = null, $global = false, $alias = null)`,
like `anyLeftJoin()`:

| Method | SQL | Returns |
| --- | --- | --- |
| `semiLeftJoin()` | `SEMI LEFT JOIN` | left rows that have a match |
| `semiRightJoin()` | `SEMI RIGHT JOIN` | right rows that have a match |
| `antiLeftJoin()` | `ANTI LEFT JOIN` | left rows without a match |
| `antiRightJoin()` | `ANTI RIGHT JOIN` | right rows without a match |
| `asofJoin()` | `ASOF JOIN` | each left row with its closest match |
| `asofLeftJoin()` | `ASOF LEFT JOIN` | the same, keeping left rows without a match |

```php
MyTable::select('id')->semiLeftJoin('users', ['user_id']);
// SELECT `id` FROM `my_table` SEMI LEFT JOIN `users` USING `user_id`
```

`anyRightJoin()` and `allRightJoin()` take the same arguments and add
`ANY RIGHT JOIN` and `ALL RIGHT JOIN`. `rightJoin()` and `fullJoin()` take a
strictness like `leftJoin()` and default to `ALL`.

An ASOF join matches one condition by the closest value instead of equality:
the last `USING` column, or the inequality in `ON`:

```php
DB::connection('clickhouse')->table('events', 'e')
    ->select('e.id', 'p.price')
    ->asofLeftJoin(function (JoinClause $join) {
        $join->table('prices')->as('p')
            ->on('e.user_id', '=', 'p.user_id')
            ->on('e.created_at', '>=', 'p.ts');
    });
// SELECT `e`.`id`, `p`.`price` FROM `events` AS `e` ASOF LEFT JOIN `prices` AS `p`
//   ON `e`.`user_id` = `p`.`user_id` AND `e`.`created_at` >= `p`.`ts`
```

`crossJoin($table, $global = false, $alias = null)` adds a `CROSS JOIN`, which
has no join keys. A cross join with `USING` or `ON` keys, or with a strictness,
throws a `GrammarException`. Inside a join closure, `semi()`, `anti()`,
`asof()`, `right()`, `full()` and `cross()` set the same keywords on the
`JoinClause`.

#### ARRAY JOIN with several arrays

```php
MyTable::select(['id', 'tag', 'score'])
    ->arrayJoin(['tag' => 'tags', 'score' => 'scores']);
// SELECT `id`, `tag`, `score` FROM `my_table` ARRAY JOIN `tags` AS `tag`, `scores` AS `score`
```

A string key becomes the alias of its array, and an array listed without a key
keeps its name. The aliases are not added to the select list. The arrays must
have the same length in each row, or ClickHouse fails with
`SIZES_OF_ARRAYS_DONT_MATCH`; the `enable_unaligned_array_join` setting pads
the shorter arrays with default values instead. `leftArrayJoin()` takes the
same argument.

### Buffer engine for insert queries

See https://clickhouse.tech/docs/en/engines/table-engines/special/buffer/

```php
<?php

namespace App\Models\Clickhouse;

use Oralunal\LaravelClickHouse\BaseModel;

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

use Oralunal\LaravelClickHouse\BaseModel;

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

MyTable::flushBuffer(); // one INSERT request
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

The buffer of a model is sent as one insert, so its rows must all have the
same keys, in any order. `buffer()` refuses a row whose keys differ from those
of the first row of the call, or from those of the rows already buffered, and
buffers no row of that call. The rows buffered before and after it are flushed
as usual:

```php
MyTable::buffer(['model_name' => 'model 1', 'some_param' => 1]);
MyTable::buffer(['model_name' => 'model 2']);
// throws Oralunal\LaravelClickHouse\Exceptions\QueryException: Cannot buffer the rows of the model
// [App\Models\Clickhouse\MyTable]: the row at index 0 has the keys [model_name], and the rows already
// buffered [model_name, some_param]. ... Nothing was buffered.
```

An empty row throws `Inserting empty values array is not supported in ClickHouse`.

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
models can buffer concurrently without interfering. `BaseModel` already uses
the `HasBufferedInserts` trait, so a model does not need to add it. A model
that adds it again keeps its buffer, and its children's, in its own
properties, and `BaseModel::flushAllBuffers()` and the automatic flush send it
too. `flushAllBuffers()`, called on any model, flushes every model that has
buffered rows. With `silent: true` it reports a failing model and goes on with
the others; without it, it throws at the first failure. Either way the failing
model keeps its rows. Available helpers:

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

MyTable::optimize(true, 202401);
// OPTIMIZE TABLE my_table PARTITION 202401 FINAL
MyTable::optimize(false, "it's");
// OPTIMIZE TABLE my_table PARTITION 'it\'s'
```

The partition is written as `delete()` writes `IN PARTITION` (see
[Deletions](#deletions)): an integer as a number, a string as an escaped
string literal, and an `Expression` or `DB::raw()` as written, as in
`new RawColumn("ID '202401'")`. Only `null` leaves out the `PARTITION` clause,
so `''` and `'0'` name a partition.

### TRUNCATE Statement

Remove all data from a table:

```php
MyTable::truncate();
// TRUNCATE TABLE my_table

// or from any query builder; where conditions are ignored
DB::connection('clickhouse')->table('default.my_table')->truncate();
// TRUNCATE TABLE `default`.`my_table`
```

`truncate()`, `delete()`, `update()` and `insert()` write the table you pass to
`table()` or `from()` the way a `SELECT` does: each part of the name is quoted,
so `default.my-table` becomes `` `default`.`my-table` ``, and a `raw()`
expression is written as is. Pass the name without backticks.
`MyTable::truncate()` and the queries that `MyTable::where()` starts write the
model's table as given.

On a [cluster](#cluster-mode) whose tables are not replicated, `onCluster()`
truncates the table on every node:

```php
DB::connection('clickhouse')->table('my_table')->onCluster('company_cluster')->truncate();
// TRUNCATE TABLE `my_table` ON CLUSTER 'company_cluster'
```

On a connection with the `use_on_cluster` option, `truncate()` and
`MyTable::truncate()` get `ON CLUSTER '<cluster_name>'` without `onCluster()`
(see [ON CLUSTER for mutations](#on-cluster-for-mutations)). While the
connection [pretends](#pretending), `truncate()` is listed and not sent.

### Deletions

ClickHouse can delete rows in two ways, and `delete()` sends either one:

```php
// Lightweight delete
MyTable::where('id', 123)->delete(true);
// DELETE FROM my_table WHERE `id` = 123

// Mutation
MyTable::where('id', 123)->delete(false);
// ALTER TABLE my_table DELETE WHERE `id` = 123
```

A [lightweight delete](https://clickhouse.com/docs/en/sql-reference/statements/delete)
marks the rows as deleted. With the default `lightweight_deletes_sync = 2`, the
call waits until they are marked, so once it returns, selects no longer see
them. It only works on MergeTree-family tables; on other engines, such as
`Memory`, ClickHouse rejects it.

ClickHouse also rejects it on a table with projections, unless the
`lightweight_mutation_projection_mode` setting is `drop`. The delete then drops
the projections of the data parts it rewrites, on 24.8 also of parts without a
matching row. 24.10 also accepts `rebuild`, which rebuilds the projections
instead. `delete(false)` works on tables with projections. Where the setting
goes depends on the ClickHouse version:

- On 24.8 it is a query setting. `delete()` throws for a query with
  `settings()`, so put it in the connection's `settings`.
- From 24.9 it is a table setting. 24.10 ignores the query setting, so the
  connection's `settings` do not help there. Set it on the table:

  ```sql
  ALTER TABLE my_table MODIFY SETTING lightweight_mutation_projection_mode = 'drop';
  ```

  or add `SETTINGS lightweight_mutation_projection_mode = 'drop'` to its
  `CREATE TABLE`.

An [`ALTER TABLE ... DELETE`](https://clickhouse.com/docs/en/sql-reference/statements/alter/delete)
mutation rewrites every data part that holds a matching row. With the default
`mutations_sync = 0`, the call only queues the mutation and returns before the
rows are gone. Note! This is a heavy operation not designed for frequent use.

`delete()` without an argument sends a lightweight delete when the connection
sets `use_lightweight_delete` to `true`, and an `ALTER TABLE ... DELETE`
otherwise. The option defaults to `false`. Set it in `.env`:

```dotenv
CLICKHOUSE_USE_LIGHTWEIGHT_DELETE=true
```

or in the connection config: `'use_lightweight_delete' => true`.

To delete in one partition only, pass the partition as the second argument.
An integer is sent as a number, a string as a quoted string, and an
`Expression` as written. These examples assume that `my_table` is partitioned
by month, with `PARTITION BY toYYYYMM(created_at)`:

```php
MyTable::where('field_two', '<', 0)->delete(false, 202401);
// ALTER TABLE my_table DELETE IN PARTITION 202401 WHERE `field_two` < 0

MyTable::where('field_two', '<', 0)->delete(false, new RawColumn("ID '202401'"));
// ALTER TABLE my_table DELETE IN PARTITION ID '202401' WHERE `field_two` < 0
```

A lightweight delete in a partition, `delete(true, $partition)`, needs
ClickHouse 24.9 or later. Older servers, 24.8 included, reject
`DELETE FROM ... IN PARTITION` with a syntax error.

On a [cluster](#cluster-mode) whose tables are not replicated, each node holds
its own rows. `onCluster()` sends the delete to every node of the cluster:

```php
MyTable::where('id', 123)->onCluster('company_cluster')->delete(true);
// DELETE FROM my_table ON CLUSTER 'company_cluster' WHERE `id` = 123
```

A `Replicated*MergeTree` table does not need it: ClickHouse passes deletes,
updates and `TRUNCATE` on to the other replicas by itself. On a connection with
the `use_on_cluster` option, `delete()`, `update()` and `truncate()` get
`ON CLUSTER '<cluster_name>'` without `onCluster()`, and `withoutOnCluster()`
leaves it out (see [ON CLUSTER for mutations](#on-cluster-for-mutations)).
While the connection [pretends](#pretending), as under `migrate --pretend`,
`delete()` and `update()` are listed and not sent.

`delete()` needs at least one `where()` or `preWhere()` condition. Without one
it throws an `Oralunal\LaravelClickHouse\Exceptions\QueryException` and sends
nothing; to remove every row, use `truncate()`. Any condition counts, even one
that matches every row: `whereNotIn('id', $ids)` with an empty `$ids` adds
`1 = 1` (see [Values in conditions](#values-in-conditions)). As the only
condition, or joined with `OR`, it makes the delete remove every row. A delete
has no `PREWHERE`, so the `preWhere()` conditions join the where conditions:

```php
MyTable::where('field_two', '<', 0)->preWhere('created_at', '<', '2024-01-01 00:00:00')->delete(false);
// ALTER TABLE my_table DELETE WHERE (`created_at` < '2024-01-01 00:00:00') AND (`field_two` < 0)
```

Only the where and prewhere conditions take part. A query that uses a clause
the delete would ignore throws a `QueryException` and sends nothing, because
the delete could remove rows that the query does not select. These clauses are:

- `WITH`, `SAMPLE`, `ARRAY JOIN`, `JOIN`, `GROUP BY`, `HAVING`, `LIMIT BY`,
  `LIMIT` and the set operations, such as `UNION ALL`;
- `FINAL`, which merges rows before the conditions read them;
- a select list with anything but plain column names, such as an alias
  (`'id as x'`), a function, a `DISTINCT` column or raw SQL;
- a sub-query that `from()` gets as a builder or a closure, and raw SQL in
  `from()` that is not a table name: a sub-query such as
  `raw('(SELECT * FROM my_table WHERE id < 3)')`, a table function such as
  `raw('numbers(3)')`, `From::merge()` or `From::remote()`, or a table with
  `FINAL` or an alias, such as `raw('my_table AS t')`. A raw table name, such
  as `raw('analytics.my_table')`, is allowed; give an alias with
  `from('my_table', 't')`;
- an `ORDER BY` that calls `arrayJoin()` in `orderByRaw()`, or in `raw()` SQL
  or a sub-query given to `orderBy()`, because `arrayJoin()` drops the rows
  whose array is empty. An `orderBy()` column that is neither a name nor SQL,
  such as the position in `orderBy(1)`, is refused too. So is an `ORDER BY`
  entry that defines an alias with `AS`, such as `orderBy('a as b')`,
  `orderByRaw('a AS b')`, a `Column` with `as()` or a `RawColumn` with an
  alias: ClickHouse lets the where and prewhere conditions read an alias
  defined in `ORDER BY`, while the delete reads the table's column of that
  name. Raw SQL with `AS` in it for another reason, such as
  `CAST(x AS String)`, and a sub-query that selects an alias are refused as
  well. Raw SQL given to a `Column` function, as in
  `orderBy(fn (Column $column) => $column->name('id')->plus(raw('0 AS b')))`,
  and the condition of `sumIf()`, count as SQL of the entry. An entry that
  aggregates or takes distinct values, with `sum()`, `max()`, `sumIf()`,
  `count()` or `distinct()`, is refused, and so is raw SQL with `LIMIT`,
  `LIMIT ... BY`, `OFFSET`, `FETCH`, `UNION`, `INTERSECT` or `EXCEPT` outside
  parentheses and quotes, such as `orderByRaw('id DESC LIMIT 1 BY user_id')`;
- `SETTINGS`. Settings from `settings()` only apply to `SELECT` queries, so put
  the settings of a mutation, such as `mutations_sync`, in the connection's
  `settings`.

The delete leaves out a select list of plain column names, `format()` and any
other `ORDER BY`, `WITH FILL` included, which only adds rows that the table
does not have. `chunk()`, `paginate()`, `simplePaginate()`, `first()`,
`value()` and `pluck()` leave the builder unchanged, so a delete after them
deletes the rows that the query selects. To delete the rows that a query with
one of the clauses above selects, select their keys first:

```php
MyTable::where('field_two', '<', 0)->orderBy('created_at')->limit(2)->delete(false);
// throws QueryException: Cannot delete with a query that uses LIMIT: ...

$ids = MyTable::select('id')->where('field_two', '<', 0)->orderBy('created_at')->limit(2)->pluck('id');
MyTable::where('id', $ids)->delete(false);
// ALTER TABLE my_table DELETE WHERE `id` IN (123, 124)
```

The keys are written into the statement, so for a long list, raise
`max_query_size` (256 KiB by default) in the connection's `settings`. Passing
the query itself as a sub-query, as in `whereIn('id', $query)`, is not the
same when its rows depend on other rows of the table you delete from, as with
`limit()`, `groupBy()` or `having()`. ClickHouse can run the sub-query again
for each data part it rewrites, after other parts have changed, so the delete
can remove more rows or fewer. On 24.8, a delete by a sub-query that selected
the three smallest ids removed seven to nine rows, depending on the run, from
a table with 60 partitions.

ClickHouse accepts some conditions in a mutation that it cannot run, and then
fails the mutation in the background again and again. Such a mutation holds
back every later mutation of the table, which then changes nothing either. So
`delete()` and `update()` also refuse these conditions, with a `QueryException`,
before the mutation is sent:

- When the condition has a sub-query, or `IN` followed by a table, as in
  `whereIn('id', raw('ids'))`, the mutation first sends
  `EXPLAIN PLAN SELECT 1 FROM <table> <conditions>`. When ClickHouse refuses
  it, the call throws, and the ClickHouse error is the previous exception. A
  sub-query that reads a column of the outer query fails with
  `UNSUPPORTED_METHOD` (see [EXISTS sub-queries](#exists-sub-queries)), and a
  table that does not exist with `UNKNOWN_TABLE` or `UNKNOWN_IDENTIFIER`:

  ```text
  Cannot delete with a where condition that ClickHouse cannot run: it refused the condition in a SELECT
  with UNSUPPORTED_METHOD. A sub-query that reads a column of the table of the delete, such as
  whereExists() with whereColumn() on that table, is such a condition: ClickHouse (24.8 checked)
  accepts the mutation and then fails it again and again, which blocks every later mutation of the
  table until KILL MUTATION. Nothing was sent. Select the keys first, and delete by them instead:
  whereIn('id', $query->pluck('id')).
  ```

  The `EXPLAIN` is one more read-only request, which the package's query
  builder does not log. ClickHouse does not run an `IN` sub-query for it, but
  it runs a scalar sub-query, such as that of
  `where('id', fn ($query) => $query->selectRaw('max(id)'))`, once for the
  `EXPLAIN` and again for the mutation. Any error refuses the mutation, also
  `ACCESS_DENIED` for a user who may change the table but not read a table of
  the condition: delete by the keys instead, or grant `SELECT`. A condition
  without a sub-query or such an `IN` is left for ClickHouse to check when it
  gets the mutation.
- A lightweight delete, `delete(true)`, whose condition holds `UNION`,
  `INTERSECT` or `EXCEPT` outside quotes, as
  `whereIn('id', $a->unionAll($b))` or a `whereExists()` over an `EXCEPT` write
  it, throws, because ClickHouse 24.8 fails such a delete and leaves its
  mutation behind. `delete(false)` takes the same condition. Raw SQL with one
  of these words for another reason, such as `SELECT * EXCEPT (col)`, is
  refused too.

A mutation that fails anyway, for example one sent with `statement()`, holds
back the later mutations of the table until you kill it:

```sql
KILL MUTATION WHERE database = 'default' AND table = 'my_table';
```

With `fix_default_query_builder` set to `false`, Laravel's `delete()` and
`update()` throw the same exception for `limit()`, `groupLimit()`, which the
message names `LIMIT`, an `offset()` above `0`, `groupBy()`, `having()`, an
`orderByRaw()` or `orderBy(DB::raw(...))` that calls `arrayJoin()`, an order
whose raw SQL has `LIMIT`, `LIMIT ... BY`, `OFFSET`, `FETCH`, `UNION`,
`INTERSECT` or `EXCEPT` outside parentheses and quotes, such as
`orderByRaw('created_at desc limit 1 by user_id')`, an order that defines an
alias, such as `orderBy('a as b')`, `latest('a as b')`, `orderByRaw('a AS b')`
or a `DB::raw()` with `AS` in it, unions, joins, and a select list with an
alias or an expression, such as `selectRaw()`. As on the package's builder,
raw SQL with `AS` in it for another reason, such as `CAST(x AS String)`, is
refused too. Any other order is left out of the statement, together with its
bindings. The sub-query check and the refusal of a lightweight delete with
`UNION`, `INTERSECT` or `EXCEPT` apply as well; they run while Laravel
compiles the statement, and its `explain plan select 1 from ...` is logged
and fires `QueryExecuted`, as a `select()` does. Laravel's `first()`, `value()`,
`find()`, `sole()`, `chunk()`, `paginate()` and `simplePaginate()` leave a
`LIMIT` on the builder they run on, so start a new query for a delete or an
update. `updateOrInsert()` adds `limit(1)` to its update, so it cannot update
an existing row: it throws when the row exists. `delete($id)` works:
`DB::connection('clickhouse')->table('my_table')->delete(5)` sends
`` alter table "my_table" delete where "id" = 5 `` (see
[Laravel's query builder](#laravels-query-builder)).

On this path too, the package writes the values into the statement, so a
value cannot change the SQL around it, and a column name such as `{0}` stays a
name:

```php
DB::connection('clickhouse')->table('ph')->where('id', 1)->update(['{0}' => 'ABC']);
// alter table "ph" update "{0}" = 'ABC' where "id" = 1
```

In 3.0.0, the smi2 client replaced the `{0}` with the first value. As in
Laravel, do not take column names from user input: Laravel's grammar reads a
dot in a name as a qualifier, and a name with the word `as` in it as an alias.

Using the buffer engine with OPTIMIZE / ALTER TABLE DELETE:

```php
<?php

namespace App\Models\Clickhouse;

use Oralunal\LaravelClickHouse\BaseModel;

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
MyTable::where('id', 123)->update(['field_one' => 'new_val']);
// ALTER TABLE my_table UPDATE `field_one` = 'new_val' WHERE `id` = 123

// or an expression
MyTable::where('id', 123)
    ->update(['field_one' => new RawColumn("concat(field_one,'new_val')")]);
// ALTER TABLE my_table UPDATE `field_one` = concat(field_one,'new_val') WHERE `id` = 123
```

Values are written as in where conditions, and an array as an array literal:
`update(['tags' => ['a', 'b']])` sets `` `tags` = ['a', 'b'] ``. Each column
name is quoted as one identifier, with its backticks and backslashes escaped,
so a name cannot add SQL to the statement, and a Nested column such as `n.a`
is written as `` `n.a` ``.

Give a `Decimal` column a numeric string, such as `'19.99'`, or an int:
ClickHouse reads a float in the mutation as a `Float64`, so
`update(['price' => 19.99])` stores `19.98` in a `Decimal(10, 2)` column (see
[Values in conditions](#values-in-conditions)).

`update()` sends an `ALTER TABLE ... UPDATE` mutation, which, like
`ALTER TABLE ... DELETE`, only queues the mutation and returns by default. It
takes a partition as its second argument and follows `onCluster()` and the
`use_on_cluster` option, the same way as `delete()`. The partition example
assumes the monthly partitions from [Deletions](#deletions):

```php
MyTable::where('id', 123)->update(['field_one' => 'new_val'], '202401');
// ALTER TABLE my_table UPDATE `field_one` = 'new_val' IN PARTITION '202401' WHERE `id` = 123

MyTable::where('id', 123)->onCluster('company_cluster')->update(['field_one' => 'new_val']);
// ALTER TABLE my_table ON CLUSTER 'company_cluster' UPDATE `field_one` = 'new_val' WHERE `id` = 123
```

`update()` needs a `where()` or `preWhere()` condition too, and joins them the
same way as `delete()`; add `whereRaw('1')` to update every row. As with
`delete()`, an empty `whereNotIn()` list is a condition that matches every row,
and a query with one of the clauses that `delete()` refuses, such as a `JOIN`,
a `LIMIT`, `FINAL` or `SETTINGS`, throws a `QueryException`, and so does a
condition that ClickHouse cannot run. Update by the selected keys instead, as
shown for `delete()`:

```php
MyTable::where('id', $ids)->update(['field_one' => 'new_val']);
// ALTER TABLE my_table UPDATE `field_one` = 'new_val' WHERE `id` IN (123, 124)
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

`InsertArray` escapes the backslashes and single quotes of each string item,
with the default `InsertArray::TYPE_STRING` as with `TYPE_STRING_ESCAPE`, so
pass the values as they are: `new InsertArray(["O'Brien", 'C:\temp'])` stores
`O'Brien` and `C:\temp`. `InsertArray::TYPE_INT` writes the items as integers.
`TYPE_DECIMAL` writes them as floats with every digit, as the query builder
writes a float: `new InsertArray([1 / 3, 2], InsertArray::TYPE_DECIMAL)` writes
`[0.3333333333333333,2.0]`, and `NAN`, `INF` and `-INF` are written as `nan`,
`inf` and `-inf`. An `Array(Decimal(10, 2))` column stores the float `19.99` as
`19.99`, but it cuts a computed float off at its scale: `4.35 * 100`, which is
`434.99999999999994` in PHP, stores `434.99`, where 3.0.0 stored `435`. Round
such a value in PHP first: `round(4.35 * 100, 2)` is written as `435.0` and
stores `435`.

### Inserting rows as JSONEachRow

The inserts send their rows in ClickHouse's `Values` format by default, as
3.0.0 did. The `insert_format` connection option, or the `$insertFormat`
property of a model, sends them in the `JSONEachRow` format instead, one JSON
object per row:

```dotenv
CLICKHOUSE_INSERT_FORMAT=JSONEachRow
```

```php
class MyTable extends BaseModel
{
    protected $insertFormat = 'JSONEachRow';
}

MyTable::insertAssoc([['model_name' => 'model 1', 'some_param' => 1], ['model_name' => 'model 2', 'some_param' => 2]]);
// INSERT INTO `my_table` (`model_name`, `some_param`) FORMAT JSONEachRow
// {"model_name":"model 1","some_param":1}
// {"model_name":"model 2","some_param":2}

DB::connection('clickhouse')->table('my_table')->insert($rows, 'JSONEachRow');
```

- A model's `$insertFormat` decides for its `insertAssoc()`, `insertBulk()`,
  `prepareAndInsert` methods, `create()`, `save()` and `flushBuffer()`;
  without it, the connection's `insert_format` decides. Positional rows, those
  of `insertBulk()` and the `prepareAndInsert` methods, are sent as
  `FORMAT JSONCompactEachRow`, one JSON array per row.
- The query builder's `insert()` takes the format as its second argument,
  `'Values'` or `'JSONEachRow'` in any letter case, or `Format::JSON_EACH_ROW`;
  without it, the connection's `insert_format` decides. Any other format throws
  an `InvalidArgumentException` before anything is sent:
  `insert() takes the format 'Values' or 'JSONEachRow', [CSV] given.`
  `insert()` returns the statement of the insert, whose
  `summary('written_rows')` gives the number of rows, such as `'2'`.
- Laravel's query builder, with `fix_default_query_builder` set to `false`,
  and [`insertFiles()`](#inserting-files) keep their own formats.
- `insert_format` and `$insertFormat` take `Values` or `JSONEachRow` in any
  letter case; a missing key, `null` and `''` mean `Values`. Any other value
  throws an `InvalidArgumentException`: for `insert_format` when Laravel
  creates the connection, for `$insertFormat` when the model inserts.

The `INSERT` goes in the URL and the rows in the body of the request. So the
smi2 client never replaces placeholders such as `:name` in the data, a retry
sends every row again, and an error message names the `INSERT` and a few bytes
around the value it could not read, without the rest of the data:
``Cannot parse input: expected '"' before: 'abc"}': (while reading the value of key some_param): (at row 1) ... IN:INSERT INTO `my_table` (`model_name`, `some_param`) FORMAT JSONEachRow``.

Every row needs the keys of the first row, in any order. The column list is
taken from the first row, and ClickHouse would drop a key that the list does
not name, and give a column that a row leaves out its default. So a row with
other keys throws an `Oralunal\LaravelClickHouse\Exceptions\QueryException`
before anything is sent:
`Cannot insert the rows as JSONEachRow: the row at index 1 lacks the keys [some_param]. ...`

ClickHouse reads some values differently in the two formats (ClickHouse 24.8
checked):

| Value | `Values` | `JSONEachRow` |
| --- | --- | --- |
| A float, `NAN`, `INF` | every digit; `nan`, `inf` | the same |
| `true` into a `UInt8` column | fails: `Cannot parse string 'true' as UInt8` | `1` |
| An array with keys, such as `['x' => 1]`, into a `Map` column | fails with `TYPE_MISMATCH` | `{'x':1}` |
| An empty `Map` | `[]` | `new \stdClass()`; `[]` fails |
| A date with a time, such as a Carbon, into a `Date` column | the date | fails: pass `$date->format('Y-m-d')` |
| A float with a fraction, such as `2.5`, into an integer column | cut off: `2` | fails: cast it with `(int)` or `round()` |
| A string that is not valid UTF-8 | stored | an `InvalidArgumentException` before anything is sent |
| Raw SQL, such as `DB::raw("upper('x')")` | its SQL runs | an `InvalidArgumentException` before anything is sent |
| A pure enum | an `UnsupportedValueType` exception | its name |
| An `InsertArray` | its SQL | its items |
| A `Stringable` object with a public `value` property | its `value` | its string |

A whole float, such as `2.0`, is written as `2` and goes into an integer
column. A model's `date` cast stores `Y-m-d H:i:s`, which a `Date` column
refuses in the `JSONEachRow` format, so set a `Date` column to a
`Y-m-d` string, or keep such a model in `Values`. Store binary strings, such as
the hash of `md5($value, true)`, in `Values`.

### Inserting files

`insertFiles()` inserts the rows of files in an input format, one `INSERT` per
file, with the file as its body:

```php
$statements = DB::connection('clickhouse')->table('my_table')
    ->insertFiles(['/data/part-1.csv', '/data/part-2.csv'], 'CSVWithNames', ['id', 'field_one']);
// INSERT INTO `my_table` ( `id`,`field_one` ) FORMAT CSVWithNames, once for each file

$statements['/data/part-1.csv']->summary('written_rows'); // '2'
```

- The format is one of `TabSeparated`, `TabSeparatedWithNames`, `CSV`,
  `CSVWithNames`, `JSONEachRow`, `CSVWithNamesAndTypes` and
  `TSVWithNamesAndTypes`, in any letter case, `CSV` by default. Any other
  format throws an `InvalidArgumentException` before anything is sent.
- Each column name is quoted as one identifier, so a name taken from input
  cannot change the statement. Without columns, the list is left out.
- Every path must be a readable file. Otherwise a
  `Oralunal\LaravelClickHouse\Exceptions\QueryException` says which, and no
  file is sent: `Cannot insert the file [/data/part-3.csv]: it is not a readable file. No file was sent.`
- The files are sent together, as separate inserts, so a file that fails does
  not undo the others. The smi2 client throws for the first file that failed,
  after every file was sent.
- Each file must be sent and inserted within the connection's `timeout_query`,
  2 seconds in the packaged configuration, so raise it for large files. The
  connection's `retries` do not apply to these requests.
- On a client that uses a ClickHouse session, inside
  [`session()`](#sessions-and-temporary-tables) or after the smi2 client's
  `useSession()`, `insertFiles()` throws a `QueryException` before anything is
  sent, because the client sends the files as asynchronous requests, which
  fail in a session.
- `insertFiles()` returns the statement of each file, keyed by path, and logs
  each insert once every file is inserted. While the connection
  [pretends](#pretending), it logs each insert and sends no file.
- `MyTable::query()->insertFiles(...)` inserts into the model's `$tableSources`,
  as `delete()` and `truncate()` write to it, not into `$tableForInserts`.

### Raw SQL with bindings

`select()`, `statement()`, `insert()`, `update()`, `delete()`,
`affectingStatement()` and `cursor()` on a ClickHouse connection take
Laravel's `?` placeholders. The package writes each binding into the SQL as a
ClickHouse literal, as the query builder writes the values of conditions (see
[Values in conditions](#values-in-conditions)), and sends the query without
bindings:

```php
use Illuminate\Support\Facades\DB;

$clickhouse = DB::connection('clickhouse');

$clickhouse->select('SELECT id FROM my_table WHERE field_one = ? AND created_at >= ?', ["it's", now()->subDay()]);
// SELECT id FROM my_table WHERE field_one = 'it\'s' AND created_at >= '2026-10-07 14:30:00'

$clickhouse->insert('INSERT INTO my_table (id, field_one, ratio) VALUES (?, ?, ?)', [1, 'a', 1 / 3]);
// INSERT INTO my_table (id, field_one, ratio) VALUES (1, 'a', 0.3333333333333333)
```

A string is escaped, a date is written in its own time zone, `true` and
`false` become `1` and `0`, a float keeps every digit, an array becomes an
array literal, and `DB::raw()` is written as its SQL. In 3.0.0, the `?`
reached ClickHouse as it was, and the query failed with a syntax error.

- A `?` inside a string literal, a quoted name, a heredoc such as `$$...$$` or
  a comment is not a placeholder.
- Write a `?` that is not a placeholder, such as that of the ternary operator,
  as `??`: `$clickhouse->select('SELECT ? > 1 ?? 10 : 20 AS t', [5])` returns
  `[['t' => 10]]`. A query without bindings has its `??` written as `?` too,
  except an `INSERT`, which is sent exactly as written, so that the rows after
  its `FORMAT` keep their `??`. Write the ternary operator of such an `INSERT`
  as `?`. The pretend log and `DB::getRawQueryLog()` show the `??` of such an
  `INSERT`, and of `unprepared()`, as `?`, but the connection sends `??`.
- A query whose number of `?` placeholders differs from its number of bindings
  throws an `InvalidArgumentException` before anything is sent:
  `The query has 1 "?" placeholder, but 2 bindings were given. Write a literal "?" as "??".`
- The query log, `DB::listen()` and the `QueryExecuted` event get the SQL and
  the bindings as you passed them, with the `?` placeholders.

Named bindings keep the placeholders of the smi2 client, as in 3.0.0: `:name`
and `{name}`, and `:0` and `{0}` for a list of bindings in a query without
`?`. The smi2 client replaces them anywhere in the SQL, inside string literals
and names too, writes `{name}` without quotes, a float with 14 significant
digits, and a collection as its JSON text, so pass `$ids->all()` to an
`IN (:ids)` list. `{name:Type}` is a ClickHouse query parameter, which the
server fills in. Prefer `?`. A query with `?` placeholders and a `:0` or `{0}`
of its list of bindings, or a `{name:Type}` parameter, throws an
`InvalidArgumentException`:
`The query mixes "?" placeholders with smi2 placeholders (:0, {0}) or query parameters ({name:Type}). Use "?" for every binding.`
A `?` of the ternary operator counts as a placeholder there, so write it as
`??`. The `#@?` markers of 3.0.0 are no longer turned into `:0`, `:1` and so
on: `select("SELECT '#@?' AS a")` returns the text `#@?`, and a `#@?` outside
quotes is `#@` followed by a `?` placeholder, so
`select('SELECT #@? AS a', [5])` sends `SELECT #@5 AS a`, which ClickHouse
rejects. Write `?`.

`select()`, `cursor()`, `scalar()`, `selectOne()`, `selectParallelly()` and
Laravel's query builder read the rows of a JSON result. A `SELECT` whose
`FORMAT` clause names `JSON`, `JSONStrings`, `JSONCompact` or
`JSONCompactStrings`, in any letter case, returns its rows. Any other format
throws an `Oralunal\LaravelClickHouse\Exceptions\QueryException`, which is a
`ClickHouseDB\Exception\QueryException`, before anything is sent, and the
message names the ways that read that format:

```text
Cannot read rows from a query whose FORMAT clause names CSV: select() and parallel queries read the rows
of a JSON result (JSON, JSONStrings, JSONCompact, JSONCompactStrings). Leave the FORMAT clause out, or
read the result in CSV with the package query builder, format('CSV')->get()->rawData(), or with the
smi2 client, getClient()->select($sql)->rawData().
```

The `FORMAT` clause is the word `FORMAT` and a name at the end of the query,
before `SETTINGS` and `;` only, outside string literals, quoted names,
comments and parentheses. A column, an alias or a table named `format`, as in
`ORDER BY format DESC`, `WHERE format IN ('csv')` or
`SELECT * FROM format(CSV, '1')`, is no `FORMAT` clause. In 3.0.0, a query
with a `FORMAT` clause that is now refused was sent and failed: with
`SYNTAX_ERROR` for `XML` or `Pretty`, with ``Can`t find meta`` for `CSV` or
`TSV`, or with no rows for `JSONCompactEachRow`.

`unprepared()` sends the SQL exactly as written, `?` and `??` included, and
returns `true`. `cursor()` yields the rows of `select()` one by one, but reads
all of them first, because the smi2 client has no server-side cursor; the
`cursor()` of [Laravel's query builder](#laravels-query-builder) uses it.
`lazy()`, `lazyById()` and `chunk()` read page by page and use less memory.
In 3.0.0, `unprepared()` and `cursor()` failed with
`Call to a member function exec() on null` and
`Call to a member function prepare() on null`. `selectResultSets()` throws a
`LogicException`, because ClickHouse returns one result set per query.

`affectingStatement()`, which Laravel's `update()`, `delete()` and
`insertUsing()` call, returns for an `INSERT` the number of rows that
ClickHouse reports as written, `written_rows` of the `X-ClickHouse-Summary`
header, where 3.0.0 returned `1`. `written_rows` also counts the rows that the
materialized views of the table write, so an `INSERT` of 3 rows into a table
with one materialized view returns `6`, and it is `0` for an asynchronous
insert (`async_insert`). Any other statement returns `1`, as in 3.0.0, because
ClickHouse does not report how many rows a mutation changes. `statement()` and
`insert()` return `true`.

`escape()` writes a value as a ClickHouse literal, for SQL that you write
yourself:

```php
$clickhouse->escape("it's");            // 'it\'s'
$clickhouse->escape(0.1 + 0.2);         // 0.30000000000000004
$clickhouse->escape([1, 'a', null]);    // [1, 'a', null]
$clickhouse->escape("\xff\x00", true);  // x'ff00'
```

A string with a NUL byte or invalid UTF-8 throws a `RuntimeException` unless
you escape it as binary, which gives a hex string literal. `toRawSql()`,
`dumpRawSql()` and `DB::getRawQueryLog()` write the bindings with it. In
3.0.0, `escape()` failed with `Call to a member function quote() on null`, and
so did these methods for a string value. `QueryExecuted::toRawSql()`, which a
`DB::listen()` callback can call, writes the bindings as literals too, also
while `fix_default_query_builder` is on, where 3.0.0 failed with
`Call to undefined method Oralunal\LaravelClickHouse\Builder::getGrammar()`.

A query that ClickHouse rejects throws the exception of the smi2 client, as in
3.0.0: a `ClickHouseDB\Exception\DatabaseException`, whose code is the
ClickHouse error code, such as `60` for `UNKNOWN_TABLE`. It is not wrapped in
Laravel's `QueryException`, and the query is not added to the query log.

ClickHouse has no transactions. `beginTransaction()`, `transaction()` and
`commit()` throw a `LogicException`:
`ClickHouse does not support transactions. In tests, use the DatabaseTruncation trait for ClickHouse connections and leave them out of $connectionsToTransact.`
`transaction()` does not call its callback, `rollBack()` does nothing, and
`transactionLevel()` is `0`. See
[Testing with DatabaseTruncation](#testing-with-databasetruncation).

#### Pretending

`DB::connection('clickhouse')->pretend()` and `php artisan migrate --pretend`
send nothing through the connection. They list each statement with its
bindings written in, and the connection returns what Laravel's own drivers
return while pretending: `select()` and `cursor()` give no rows, so
`Schema::hasTable()` is `false`; `statement()`, `insert()` and `unprepared()`
return `true`; and `affectingStatement()`, `update()` and `delete()` return
`0`. `Schema::create()`, `Schema::table()`, `Migration::write()` and
`Migration::createMergeTree()` are listed and not sent either:

```php
$log = DB::connection('clickhouse')->pretend(function ($connection) {
    $connection->statement('ALTER TABLE my_table DELETE WHERE field_one = ?', ["it's"]);
    Schema::connection('clickhouse')->create('events', fn ($table) => $table->id());
});
// array_column($log, 'query'):
// ["ALTER TABLE my_table DELETE WHERE field_one = 'it\'s'",
//  'CREATE TABLE `events` (`id` Int64) ENGINE = MergeTree() ORDER BY (`id`)']
```

The log writes each `??` as `?`, also for an `INSERT` without bindings and for
`unprepared()`, which send their `??` as written (see
[Raw SQL with bindings](#raw-sql-with-bindings)): a value `what??` in the rows
after the `FORMAT` of such an `INSERT` is listed as `what?`.

The writes of models, their inserts, `truncate()`, `optimize()` and buffers,
and the writes of the package's query builder, which
`DB::connection('clickhouse')->table()` returns while
`fix_default_query_builder` is on, its `insert()`, `delete()`, `update()`,
`truncate()` and `insertFiles()`, are listed and not sent too, and so are
`Schema::createDatabase()` and `Schema::dropDatabaseIfExists()`. They return a
statement whose `isError()` is `false`, so `create()` and `save()` go on as
after an insert:

```php
$log = DB::connection('clickhouse')->pretend(function ($connection) {
    $connection->table('my_table')->insert([['id' => 20, 'field_one' => 'p']]);
    $connection->table('my_table')->where('id', 1)->delete(false);
});
// array_column($log, 'query'):
// ["INSERT INTO `my_table` (`id`,`field_one`)  VALUES  (20,'p')",
//  'ALTER TABLE `my_table` DELETE WHERE `id` = 1']
```

- A JSONEachRow insert is listed with its `INSERT ... FORMAT JSONEachRow`
  only. Its rows are not encoded, so a row that the insert would refuse, such
  as one with raw SQL, is not refused while pretending.
- `buffer()` lists the insert of its rows at once and does not buffer them.
  `flushBuffer()` lists the insert of the rows buffered before `pretend()`,
  and empties the buffer without sending them.
- The checks that `delete()` and `update()` send before a mutation, the
  `EXPLAIN` of a condition with a sub-query and the lookup of a session's
  temporary tables, are not sent.
- The reads of the package's query builder are still sent, and listed, as in
  3.0.0: `get()`, `getRows()`, `count()`, `exists()`, `first()` and the
  others return rows, and so do the entries of [`Parallel`](#parallel-queries)
  that are queries of the package's query builder. Laravel's query builder
  and SQL get no rows.
- `session()` sends the `SELECT 1` that opens the session, so that the queries
  of the callback can run in it.

`Schema::dropAllTables()` and `Schema::dropAllViews()`, which `migrate:fresh`
calls, send no `DROP`, because the query that lists the tables returns no rows
while pretending. `change()` and `dropIndex()` in `Schema::table()` read the
server while they are compiled, also while pretending (see
[Changing a column](#changing-a-column)).

### Laravel's query builder

With `'fix_default_query_builder' => false` in the connection config,
`DB::connection('clickhouse')->table()`, `query()` and the Eloquent models of
the connection get Laravel's query builder instead of the package's, as
`Oralunal\LaravelClickHouse\QueryBuilder`, a subclass of
`Illuminate\Database\Query\Builder`. Laravel's methods and macros work on it,
with the ClickHouse SQL below. A connection config that leaves the option out
gets Laravel's query builder too; the packaged `clickhouse` connection sets it
to `true`.

The values are bound with `?` and written into the SQL when the query runs, as
for [raw SQL](#raw-sql-with-bindings). `toSql()` shows the placeholders, and
`toRawSql()` the SQL that is sent:

```php
$query = DB::connection('clickhouse')->table('my_table')->where('field_one', "it's")->where('ratio', 0.1 + 0.2);
$query->toSql();    // select * from "my_table" where "field_one" = ? and "ratio" = ?
$query->toRawSql(); // select * from "my_table" where "field_one" = 'it\'s' and "ratio" = 0.30000000000000004
```

So floats keep every digit, `false` is `0`, `null` and arrays work in
`insert()`, and a `Stringable` value, such as `Str::of(...)`, is quoted. In
3.0.0, `toSql()` showed `#@?`, the query log showed `:0`, `toRawSql()` failed
with `Call to a member function quote() on null` for a string,
`where('flag', false)` sent nothing after the `=`, an insert with `null` or an
array failed, and a `Stringable` value was written without quotes:
`where('id', Str::of('0 or 1 = 1'))` matched every row. `whereRaw()`,
`selectRaw()`, `orderByRaw()`, `havingRaw()`, `groupByRaw()` and `fromRaw()`
take `?` too.

#### Counting rows

`count()` and the total of `paginate()` count the rows that `get()` returns.
A query whose select list, order or grouping changes those rows is counted in
a sub-query, without its `LIMIT` and `OFFSET`:

```php
DB::connection('clickhouse')->table('my_table')->select('id as x')->where('x', 3)->count();
// select count(*) as "aggregate" from (select "id" as "x" from "my_table" where "x" = 3) as "aggregate_table"
```

This applies to `groupBy()`, `distinct()`, `groupLimit()`, a select alias or
expression, such as `selectRaw()` or `selectSub()`, and an order that defines
an alias, calls `arrayJoin()`, adds rows with `WITH FILL`, or has `LIMIT`,
`LIMIT ... BY`, `OFFSET`, `FETCH`, `UNION`, `INTERSECT` or `EXCEPT` in its raw
SQL, such as `orderByRaw('created_at desc limit 1 by user_id')`. So
`groupBy('user_id')->count()` returns the number of groups,
and `distinct()->select('user_id')->count()` the number of distinct rows.
Laravel drops the select list and the order instead: in 3.0.0,
`groupBy()->count()` returned the number of rows of one group, and a condition
on a select alias failed with `UNKNOWN_IDENTIFIER`.

A grouped query without a select list is counted by its groups with
`having()` too: `groupBy('user_id')->havingRaw('count() > 1')->count()`
sends `select count(*) as "aggregate" from (select 1 from "my_table" group by "user_id" having count() > 1) as "aggregate_table"`
and returns the number of users with more than one row, where 3.0.0 failed
with `NOT_AN_AGGREGATE`. Its `get()`, and the page that `paginate()` reads,
still select `*` of the groups, which ClickHouse rejects with
`NOT_AN_AGGREGATE`, so give such a query a select list, such as
`select('user_id')`.

`distinct('user_id')->count()` keeps Laravel's `count(distinct "user_id")`, and
unions, other queries with `having()` and `limit()->count()` are counted as
Laravel counts them. `count($column)`, `sum()`, `avg()`, `min()` and `max()`
are Laravel's, so they drop the select list and the order. For such a query,
aggregate a sub-query:

```php
DB::connection('clickhouse')->query()->fromSub($query, 'q')->sum('x');
```

#### Dates and times

| Method | SQL |
| --- | --- |
| `whereDate('created_at', '>=', '2024-02-10')` | `toDate32("created_at") >= '2024-02-10'` |
| `whereTime('created_at', '>=', '9:05')` | `formatDateTime("created_at", '%H:%i:%S') >= '09:05:00'` |
| `whereDay('created_at', 5)` | `toDayOfMonth("created_at") = 5` |
| `whereMonth('created_at', '02')` | `toMonth("created_at") = 2` |
| `whereYear('created_at', '>=', 2025)` | `toYear("created_at") >= 2025` |

The functions are those of the package's builder (see
[Dates and times in conditions](#dates-and-times-in-conditions)). Laravel
prepares the values: a date is written as its date or its time of day in its
own time zone, and with `null` the condition matches no row, as on Laravel's
other drivers, where the package's builder writes `IS NULL`. Laravel writes a
day or a month with `sprintf('%02d')`, so `whereDay('created_at', 5.5)`
compares with `5`, where the package's builder throws. A time is padded part
by part, and each part can have one or two digits:
`whereTime('created_at', '>=', '7:8')` compares with `'07:08:00'`, and
`'9:05:3.5'` with `'09:05:03'`, where the package's builder throws. Any other
string, such as `'10:20 am'`, is compared as it is, as text. Inside a join
closure, Laravel's own `JoinClause` compiles `whereDay()` and `whereMonth()`
with Laravel's padded text, such as `'05'`, which fails with `TYPE_MISMATCH`.
In 3.0.0, `whereDay()` and `whereMonth()` failed that way everywhere,
`whereTime()` failed with `UNKNOWN_FUNCTION`, and `whereDate()` and
`whereYear()` sent `date()` and `year()`.

#### Other ClickHouse SQL

- `inRandomOrder()` sends `order by rand()`. A seed throws an
  `InvalidArgumentException`: for an order that a seed repeats, order by a hash
  of a key, as in `orderByRaw('cityHash64(?, id)', [$seed])`. 3.0.0 sent
  `RANDOM()`, which failed with `UNKNOWN_FUNCTION`.
- `union()` sends `union distinct`, which drops duplicate rows, as Laravel
  documents; `unionAll()` sends `union all`. When the connection's `settings`
  set `union_default_mode`, `union()` sends a bare `union`, which follows that
  mode. A union with its own order, limit or offset, as `orderBy()`, `first()`,
  `paginate()` and `chunk()` give it, is selected from:
  `select * from ((select ...) union distinct (select ...)) order by "id" desc limit 1`.
  In 3.0.0, `union()` failed with `EXPECTED_ALL_OR_DISTINCT`, and a union with
  an order or a limit with a syntax error.
- `whereLike()` and `whereNotLike()` send `ilike` and `not ilike`, which ignore
  case, as Laravel documents, or `like` and `not like` with
  `caseSensitive: true`. 3.0.0 sent `like`, which is case-sensitive in
  ClickHouse, and threw for `caseSensitive: true`. ClickHouse can use the
  primary key for a `like 'prefix%'` pattern, but not for `ilike`.
- `whereNullSafeEquals('comment', null)` sends `["comment"] = [null]`, which
  matches the `NULL` rows, and `whereNullSafeEquals('comment', 'x')` sends
  `"comment" = 'x'`. The `<=>` operator of `where()` and `having()` works too.
  ClickHouse has `<=>` only in the `ON` of a join, and both failed in 3.0.0.
- The bitwise operators `&`, `|`, `^`, `<<` and `>>` in `where()` and
  `having()` send bit functions: `where('field_two', '&', 4)` sends
  `bitAnd("field_two", 4) != 0`, and `&~` sends `bitAnd("field_two", 4) != "field_two"`.
  3.0.0 sent `&`, which ClickHouse rejects.
- `update()` writes an array or a collection as an array literal:
  `where('id', 1)->update(['tags' => ['a', 'b']])` sends
  `alter table "my_table" update "tags" = ['a', 'b'] where "id" = 1`. An
  associative array loses its keys, so write a `Map` with
  `DB::raw("map('k', 1)")`. `insert()` writes a collection as an array too,
  where 3.0.0 wrote its JSON text: a `String` column now stores `['a','b']`
  instead of `["a","b"]`, so call `toJson()` to store JSON.
- `delete()` and `update()` drop the name of their table from the column
  names, which ClickHouse rejects in a mutation: `delete(5)` sends
  `alter table "my_table" delete where "id" = 5`, and
  `where('my_table.id', 5)->update(['my_table.field_one' => 'x'])` sends
  `alter table "my_table" update "field_one" = 'x' where "id" = 5`. A table
  alias, as in `table('my_table as t')`, is dropped as well. So Eloquent's
  qualified key and `updated_at` column work in `delete()` and `update()`. A
  sub-query keeps its names, and `whereRaw()` is sent as written. In 3.0.0,
  `delete(5)` failed with `UNKNOWN_IDENTIFIER`. [Deletions](#deletions) lists
  the clauses and conditions that `delete()` and `update()` refuse.
- `truncate()` writes the table without its alias:
  `table('analytics.my_table as t')->truncate()` sends
  `truncate table "analytics"."my_table"`, where 3.0.0 kept the alias and
  failed with a syntax error. With the connection's `use_on_cluster` option,
  `delete()`, `update()` and `truncate()` add `on cluster '<cluster_name>'`
  (see [ON CLUSTER for mutations](#on-cluster-for-mutations)).
- `insertGetId()` inserts the row and returns its key as given, such as an int
  or a UUID string. ClickHouse has no auto-increment, so a row without its key,
  or with `null` in it, throws a `RuntimeException` before anything is sent.
  Eloquent's `create()` on a model whose `$incrementing` is `true` therefore
  needs the key; set `$incrementing` to `false` for keys such as UUIDs. In
  3.0.0, `insertGetId()` inserted the row and then failed with
  `Call to a member function lastInsertId() on null`.
- `timeout(5)` sends `settings max_execution_time = 5` with the outermost
  `select`, and `forceIndex('idx_url')` and `ignoreIndex('idx_url')` send
  `settings force_data_skipping_indices = 'idx_url'` and
  `ignore_data_skipping_indices`. A forced index that the query cannot use
  fails with `INDEX_NOT_USED`, and `useIndex()` throws an
  `InvalidArgumentException`. Mutations get no settings, and the `timeout()` of
  a query given to `fromSub()` or `joinSub()` stays inside the sub-query, where
  ClickHouse does not enforce it. The connection's `timeout_query` still ends
  the request first when it is shorter, while the server goes on with the
  query. 3.0.0 ignored `timeout()` and threw a `BadMethodCallException` for an
  index hint.
- Names are written between double quotes, with their backslashes and double
  quotes doubled, because ClickHouse reads backslash escapes there:
  `select('na\me')` sends `select "na\\me"`. 3.0.0 did not double the
  backslash, so ClickHouse read another name.

These methods throw Laravel's `RuntimeException` before anything is sent,
because ClickHouse cannot run them:

| Method | Instead |
| --- | --- |
| `upsert()` with columns to update | `insert()` into a `ReplacingMergeTree` table, or `update()` |
| `insertOrIgnore()` | `insert()`: ClickHouse has no unique constraints |
| `whereJsonContains()`, JSON paths such as `where('payload->name', 'x')` | `whereRaw("JSONExtractString(payload, 'name') = ?", ['x'])` |
| `whereFullText()` | `whereRaw('hasToken(body, ?)', ['word'])`, or `whereLike()` |

`lockForUpdate()` and `sharedLock()` add nothing, and a string given to
`lock()` is written into the SQL as it is.

### Sessions and temporary tables

`session()` runs a callback in one ClickHouse HTTP session, and returns what
the callback returns. A temporary table and a `SET` last as long as the
session, and only its queries see them:

```php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\SchemaBlueprint;

$names = DB::connection('clickhouse')->session(function (Connection $connection) {
    Schema::connection('clickhouse')->create('tmp_ids', function (SchemaBlueprint $table) {
        $table->temporary();
        $table->integer('id');
    });
    // CREATE TEMPORARY TABLE `tmp_ids` (`id` Int32)

    $connection->table('tmp_ids')->insert([['id' => 1], ['id' => 2]]);
    $connection->statement('SET max_threads = 4');

    return MyTable::query()
        ->whereIn('id', fn ($query) => $query->select('id')->from('tmp_ids'))
        ->orderBy('id')
        ->pluck('field_one')
        ->all();
    // SELECT `field_one` FROM `my_table` WHERE `id` IN (SELECT `id` FROM `tmp_ids`) ORDER BY `id` ASC
}, timeout: 120);
// ['a', 'b']
```

- Every query that the connection sends while the callback runs joins the
  session: the queries of models, of both query builders, of `Schema`, and of
  `statement()` and `select()`. Other connections, also to the same server,
  queued jobs and other processes do not, and neither does a statement that
  goes to every node of a cluster, such as one of `statementOnEveryNode()`, on
  the other nodes. After the callback, a query of the temporary table fails
  with `UNKNOWN_TABLE`, and the `SET` values are gone.
- `session()` opens the session on the active node with `SELECT 1`, which is
  not logged, and then sends every query of the callback with `session_id`, a
  random id of 32 characters, `session_timeout` and `session_check=1`. When
  the callback returns or throws, the queries stop carrying them.
- A session is not a transaction: each statement takes effect when it runs,
  and nothing is undone when the callback throws.
- ClickHouse closes the session, with its temporary tables and `SET` values,
  `timeout` seconds after its last query ends, 60 by default, and the session
  cannot be closed earlier. A timeout below 1 throws an
  `InvalidArgumentException` before anything is sent, and one above the
  server's `max_session_timeout`, 3600 by default, fails with
  `INVALID_SESSION_TIMEOUT` before the callback runs.
- After the session has closed, or the server has restarted, the next query
  throws an `Oralunal\LaravelClickHouse\Exceptions\QueryException` with the
  code 372, instead of running in a new, empty session:
  `ClickHouse no longer knows the session <id> (SESSION_NOT_FOUND). It closes a session 120 seconds after the session's last query ends, ...`.
  Its previous exception is the ClickHouse error.
- ClickHouse runs one query of a session at a time. A query that timed out on
  the client keeps running on the server, and the next query throws a
  `QueryException` with the code 373 (`SESSION_IS_LOCKED`) until it ends.
- A request is sent again only when it never reached the server (see
  [Retries](#retries)). The smi2 client's asynchronous requests,
  `getClient()->selectAsync()` with `executeAsync()`, and
  [`insertFiles()`](#inserting-files), throw a `QueryException` before
  anything is sent, because smi2 1.26 would send them again and again.
  [`Parallel`](#parallel-queries) and `selectParallelly()` throw a
  `LogicException`.
- The session exists on the active node only, so on a
  [cluster](#cluster-mode) connection `slideNode()` throws a `LogicException`
  while the callback runs.
- A nested `session()` is a separate session, which does not see the
  temporary tables of the outer one. A session id that the smi2 client's
  `useSession()` set comes back after the callback. `inSession()` tells
  whether the connection's queries carry a session id.
- Read the rows inside the callback. A lazy reader, such as the generator of
  `cursor()` or a `LazyCollection` of Laravel's `cursor()` or `lazy()`, runs
  its query when it is read, after the session has ended. So a callback that
  returns one throws a `LogicException`, after the callback has run; return
  `iterator_to_array($connection->cursor(...))` or `->all()` instead. A lazy
  reader inside another value that the callback returns is not detected.
- `buffer()` sends its rows at `flushBuffer()` or at the end of the request, so
  flush the buffer of a model of a temporary table inside the callback.
- A load balancer in front of several servers must send every request of a
  session to the same server.

#### Temporary tables

`Schema::create()` with `$table->temporary()` sends `CREATE TEMPORARY TABLE`.
ClickHouse drops a temporary table at the end of the query that creates it,
unless the query runs in a session, so outside `session()` it throws a
`QueryException` and sends nothing:
`Cannot create the temporary table tmp_ids outside a session: ...`.

- Without `$table->engine()`, the statement has no `ENGINE` clause, and the
  table gets the server's `default_temporary_table_engine`, `Memory`.
  `$table->engine('MergeTree()')` gives
  ``CREATE TEMPORARY TABLE `tmp_ids` (`id` Int32) ENGINE = MergeTree() ORDER BY (`id`)``,
  with the sorting key of a table of that engine. The connection's `engine`
  option, `ON CLUSTER` and replicated engines never apply. ClickHouse refuses
  a `Replicated`, `Shared` or `KeeperMap` engine, `ON CLUSTER` and a database
  name for a temporary table, and a `Memory` table refuses `orderBy()` and
  `partitionBy()`.
- `Schema::dropTemporary('tmp_ids')` sends ``DROP TEMPORARY TABLE `tmp_ids` ``,
  which fails with `UNKNOWN_TABLE` when the session has no such table.
  `Schema::dropTemporaryIfExists('tmp_ids')` sends
  ``DROP TEMPORARY TABLE IF EXISTS `tmp_ids` ``; outside a session, it is sent
  and logged, and ClickHouse does nothing.
- `Schema::hasTemporaryTable('tmp_ids')` sends
  ``EXISTS TEMPORARY TABLE `tmp_ids` ``, which is logged. It is `false`
  outside a session, without a query, and while the connection pretends.
  `hasTable()`, `getTables()` and `getColumns()` read the database, so they do
  not see temporary tables.
- `Schema::table()` with `$table->temporary()` changes the temporary table,
  without `ON CLUSTER`: ``ALTER TABLE `tmp_ids` ADD COLUMN `extra` Int32 DEFAULT 3``.
  Outside a session, or when the session has no temporary table of that name,
  it throws a `QueryException` before anything is sent, because ClickHouse
  would change the table of that name in the database instead. ClickHouse
  cannot rename a temporary table, so `rename()` with `temporary()` always
  throws.
- Inside a session, `change()`, `index()` and `dropIndex()` in
  `Schema::table()` read the columns and the engine of a temporary table of
  that name, which their `ALTER TABLE` reaches.

A temporary table can have the name of a table of the database. ClickHouse
24.8 then runs `SELECT`, `INSERT`, `ALTER TABLE ... DELETE` and `UPDATE`,
`TRUNCATE`, `OPTIMIZE` and `DROP TABLE` on the temporary table, but a
lightweight `DELETE FROM`, `RENAME TABLE`, `CREATE TABLE` and every statement
with `ON CLUSTER` on the table of the database, with `ON CLUSTER` on every
node. So inside a session, these throw a `QueryException` before anything is
sent when the session has a temporary table of the table's name:

- `delete(true)`, and `delete()` on a connection with `use_lightweight_delete`;
- `delete()`, `update()`, `truncate()` and `MyTable::optimize()` with
  `onCluster()` or the `use_on_cluster` option, on both query builders and on
  models;
- on a connection with a `cluster_name`, whose schema statements go
  `ON CLUSTER`, `Schema::table()`, `drop()`, `dropIfExists()`, `dropSync()`,
  `dropIfExistsSync()` and `rename()`.

```text
Cannot send DELETE FROM for my_table: the session has a temporary table named my_table, and ClickHouse
(24.8 checked) runs a lightweight DELETE on the table my_table of the database instead. Call
delete(false), which sends ALTER TABLE ... DELETE to the temporary table.
```

Each such statement sends `EXISTS TEMPORARY TABLE` first: unlogged on the
package's query builder and the schema builder, where it is sent while the
blueprint compiles, also under `toSql()` and `migrate --pretend`, and logged
on Laravel's query builder and for `MyTable::truncate()` and
`MyTable::optimize()`. A name with a database, such as
`analytics.my_table`, and raw SQL are not checked. `Schema::create()` is not
refused: it creates the table of the database, as ClickHouse does. Drop a
temporary table with `dropTemporary()` or `dropTemporaryIfExists()`:
`Schema::drop()` drops the temporary table first, and `dropIfExists()` drops
the table of the database once the temporary table is gone. Without
`ON CLUSTER`, `Schema::rename()` renames the table of the database, also when
a temporary table has its name.

ClickHouse runs a mutation of a table of the database in the background,
outside the session, where the temporary tables of the session do not exist.
So inside a session, `delete()` and `update()` of such a table send the
`EXPLAIN` of a condition with a sub-query (see [Deletions](#deletions))
outside the session too, and refuse a condition that reads a temporary table:

```text
Cannot delete with a where condition that ClickHouse cannot run: it refused the condition in a SELECT
outside the session with UNKNOWN_TABLE. ClickHouse (24.8 checked) runs the mutation outside the
session, where the temporary tables of the session do not exist, ...
```

Select the keys first, and delete by them:
`MyTable::query()->whereIn('id', $connection->table('tmp_ids')->pluck('id'))->delete(false)`
sends ``ALTER TABLE my_table DELETE WHERE `id` IN (1, 2)``. A mutation of a
temporary table with the default `Memory` engine runs in the session, so its
condition can read other temporary tables. A temporary table with a
`MergeTree` engine runs its mutations outside the session, where ClickHouse
24.8 fails a mutation with a sub-query, with `DATABASE_ACCESS_DENIED` or
`UNKNOWN_TABLE`, until the session closes; the package does not catch this,
so select the keys first there too.

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
        'retry_on' => 'any',
        'fix_default_query_builder' => true,
        'use_lightweight_delete' => false,
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

use Oralunal\LaravelClickHouse\BaseModel;

class MyTable2 extends BaseModel
{
    protected $connection = 'clickhouse2';

    protected $table = 'my_table2';
}
```

**3.** Add a migration bound to that connection:

```php
<?php

return new class extends \Oralunal\LaravelClickHouse\Migration
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
* Migrations that use `Migration::write()` or `createMergeTree()` execute on all
  nodes. If any node is unreachable, the migration throws.
  `DB::connection('clickhouse')->statementOnEveryNode($sql)` sends any other
  statement to every node the same way, one node after another, and logs it
  once; it stops at the first node that fails. With a `cluster_name`,
  `Schema::create()` and the other `Schema` methods send their statement once,
  `ON CLUSTER`, and ClickHouse runs it on every host of the cluster (see
  [The schema builder on a cluster](#the-schema-builder-on-a-cluster));
  without one, they run on the active node only.
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
    // Optional. When set, Migration::createMergeTree() and the schema
    // builder add ON CLUSTER '<name>' to the DDL they compile. It must match
    // a cluster declared in your ClickHouse server config (remote_servers).
    // Without it, Migration::write() and createMergeTree() still create the
    // table on every node, because they are dispatched to each node in turn.
    // If you set it, also call ->ifNotExists() in createMergeTree() — see below.
    'cluster_name' => 'company_cluster',
    // Optional, off by default. With a cluster_name, delete(), update(),
    // truncate() and optimize() add ON CLUSTER '<name>' — see below.
    'use_on_cluster' => (bool) env('CLICKHOUSE_USE_ON_CLUSTER', false),
    'database' => env('CLICKHOUSE_DATABASE', 'default'),
    'username' => env('CLICKHOUSE_USERNAME', 'default'),
    'password' => env('CLICKHOUSE_PASSWORD', ''),
    'timeout_connect' => env('CLICKHOUSE_TIMEOUT_CONNECT', 2),
    'timeout_query' => env('CLICKHOUSE_TIMEOUT_QUERY', 2),
    'https' => (bool) env('CLICKHOUSE_HTTPS', null),
    'retries' => env('CLICKHOUSE_RETRIES', 0),
    'retry_on' => env('CLICKHOUSE_RETRY_ON', 'any'),
    'settings' => [ // optional
        'max_partitions_per_insert_block' => 300,
    ],
    'fix_default_query_builder' => true,
    'use_lightweight_delete' => (bool) env('CLICKHOUSE_USE_LIGHTWEIGHT_DELETE', false),
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

Drop a replicated table whose replica path is fixed, such as this one or one
that `createMergeTree()` creates on a cluster, with `SYNC`. Without it, an
`Atomic` database removes the table, and its replica in Keeper, only after
`database_atomic_delay_before_drop_table_sec`, 480 seconds by default, and
creating the table again before then, as in a rollback followed by a migrate,
fails with `REPLICA_ALREADY_EXISTS`. `SYNC` waits until the data and the
replica are gone, which can take a while for a large table. In Laravel's schema
builder, use `Schema::dropSync()` or `Schema::dropIfExistsSync()` (see
[Laravel's schema builder](#laravels-schema-builder)).

You can read the current node and move to the next one that answers a ping:

```php
$row = new MyTable();
echo $row->getThisClient()->getConnectHost();
// will print 'clickhouse01'
$row->resolveConnection()->getCluster()->slideNode();
echo $row->getThisClient()->getConnectHost();
// will print 'clickhouse02'
```

`slideNode()` tries the nodes after the current one in turn, skips a node that
does not answer, and continues with the first node after the last one. When no
other node answers, it stays on the current node. While
[`session()`](#sessions-and-temporary-tables) runs on the connection,
`slideNode()` throws a `LogicException`, because the session and its temporary
tables only exist on the active node:
`Cannot switch to another node while the active node clickhouse01:8123 is pinned, as it is while session() runs on this connection: ...`.

A `cluster_name` is trimmed, so a name with spaces around it, as an `.env`
value can have, gives `ON CLUSTER 'company_cluster'`. A blank name counts as
none.

#### The schema builder on a cluster

On a connection with a `cluster_name`, Laravel's schema builder sends every
statement once, to the active node, with `ON CLUSTER`, and ClickHouse runs it
on every host of the cluster, also on hosts that the connection does not list.
When the connection also lists its nodes in `cluster`, as above, an engine of
the MergeTree family becomes replicated:

```php
Schema::create('events', function (SchemaBlueprint $table) {
    $table->integer('id');
    $table->string('name');
});
// CREATE TABLE `events` ON CLUSTER 'company_cluster' (`id` Int32, `name` String)
//   ENGINE = ReplicatedMergeTree() ORDER BY (`id`) SETTINGS replicated_deduplication_window=0

Schema::table('events', fn (SchemaBlueprint $table) => $table->string('extra')->nullable());
// ALTER TABLE `events` ON CLUSTER 'company_cluster' ADD COLUMN `extra` Nullable(String)

Schema::rename('events', 'events_old');
// RENAME TABLE `events` TO `events_old` ON CLUSTER 'company_cluster'

Schema::dropIfExistsSync('events_old');
// DROP TABLE IF EXISTS `events_old` ON CLUSTER 'company_cluster' SYNC
```

- `CREATE`, every `ALTER TABLE` of `Schema::table()`, the separate statements
  of `change()` included, `RENAME TABLE` and `DROP TABLE` get `ON CLUSTER`,
  with the name written as an escaped string literal. So do
  `Schema::createDatabase()`, `Schema::dropDatabaseIfExists()`, and the
  `DROP` statements of `Schema::dropAllTables()` and `Schema::dropAllViews()`,
  which `migrate:fresh`, `db:wipe` and parallel testing run:
  ``DROP TABLE IF EXISTS `default`.`events` ON CLUSTER 'company_cluster' SYNC``.
  Laravel's `migrations` table is created `ON CLUSTER` as well.
- The replicated engine keeps its own parameters and gets no replica path:
  `MergeTree()` becomes `ReplicatedMergeTree()`, `engine('ReplacingMergeTree(version)')`
  becomes `ReplicatedReplacingMergeTree(version)`, and so does the
  connection's `engine` option. ClickHouse then uses its default path,
  `/clickhouse/tables/{uuid}/{shard}` with the replica `{replica}`, and the
  `{uuid}` is new for each `CREATE`, so a table dropped without `SYNC`, or
  renamed, can be created again at once, unlike a table of
  `createMergeTree()`, whose path is fixed. An engine named `Replicated...` or
  `Shared...`, and an engine outside the family, such as `Memory`, are kept.
- `SETTINGS replicated_deduplication_window=0` keeps an insert that is
  identical to an earlier one, as a `MergeTree` table does, where a replicated
  table would drop it as a duplicate. This matters for Laravel's `migrations`
  table, which logs the same row again after a rollback. `settings()` with
  another value for it wins, and `null` leaves it to the server. An engine
  that you name as replicated, and an `engine()` with a `SETTINGS` clause of
  its own, get nothing. ClickHouse 26.3 (26.3.46 checked) drops such an insert
  anyway: there, only an insert with `deduplicate_insert = 'disable'` or with
  its own `insert_deduplication_token` keeps it.
- `$table->replicated(false)` keeps the engine as given, for a table that each
  host keeps for itself. `$table->replicated()` makes it replicated on any
  connection, but outside `ON CLUSTER`, ClickHouse 24.8 refuses a replicated
  engine without a path:
  `Macro 'uuid' and empty arguments of ReplicatedMergeTree are supported only for ON CLUSTER queries with Atomic database engine. (BAD_ARGUMENTS)`.
  A database of the `Replicated` engine may accept it.
- `$table->withoutOnCluster()` sends the statements of the blueprint to the
  active node only, without `ON CLUSTER` and without a replicated engine, as
  3.0.0 did:
  `Schema::table('legacy', function (SchemaBlueprint $table) { $table->withoutOnCluster(); $table->drop(); })`
  sends ``DROP TABLE `legacy` ``. Use it for a table that 3.0.0 created on one
  node: an `ON CLUSTER` statement on it changes the hosts that have it and
  then fails with `UNKNOWN_TABLE` for the others. `dropIfExists()` with
  `ON CLUSTER` is safe for such a table. `Connection::withoutOnCluster()`
  does not change the schema builder.
- `change()` checks every host for `NULL` values before it makes a column
  non-nullable:
  ``SELECT 1 FROM clusterAllReplicas('company_cluster', 'default', 'visits') WHERE `referrer` IS NULL LIMIT 1 SETTINGS apply_deleted_mask = 0``.
  A host without the table makes it fail, before anything is sent.
- An `ON CLUSTER` statement waits for every host, up to
  `distributed_ddl_task_timeout`, 180 seconds by default, while the packaged
  `timeout_query` is 2 seconds: raise `timeout_query` for migrations. A
  statement that timed out on the client keeps running on the server, and a
  retry of it fails with `TABLE_ALREADY_EXISTS` or `DUPLICATE_COLUMN`, so use
  `retry_on` set to `unsent` (see [Retries](#retries)). When one host fails,
  the statement throws, as in
  `There was an error on [clickhouse02:9000]: ...`, and the hosts that ran it
  keep the change.

Without a `cluster_name`, the schema builder sends its statements to the
active node only, without `ON CLUSTER`, and keeps the engine as given, also
when the connection lists its nodes. With a `cluster_name` but without nodes,
as with one host behind a load balancer, the tables are created `ON CLUSTER`
but not replicated, Laravel's `migrations` table included, so each migration's
row lives on the host that ran it, and `migrate` and `migrate:status` depend
on the host they reach. List the nodes in `cluster` there, or create the
`migrations` table yourself, replicated.

#### ON CLUSTER for mutations

`onCluster()` adds `ON CLUSTER` to one `delete()`, `update()` or `truncate()`.
The `use_on_cluster` connection option adds `ON CLUSTER '<cluster_name>'` to
all of them, and to `MyTable::truncate()` and `MyTable::optimize()`, on the
package's query builder, on model queries and on Laravel's query builder:

```dotenv
CLICKHOUSE_USE_ON_CLUSTER=true
```

```php
MyTable::where('id', 123)->delete(true);
// DELETE FROM my_table ON CLUSTER 'company_cluster' WHERE `id` = 123
MyTable::where('id', 123)->update(['field_one' => 'new_val']);
// ALTER TABLE my_table ON CLUSTER 'company_cluster' UPDATE `field_one` = 'new_val' WHERE `id` = 123
MyTable::optimize(true, 202401);
// OPTIMIZE TABLE my_table ON CLUSTER 'company_cluster' PARTITION 202401 FINAL
DB::connection('clickhouse')->table('my_table')->truncate();
// TRUNCATE TABLE `my_table` ON CLUSTER 'company_cluster'

MyTable::where('id', 123)->withoutOnCluster()->delete(false);
// ALTER TABLE my_table DELETE WHERE `id` = 123
DB::connection('clickhouse')->withoutOnCluster(fn () => MyTable::truncate());
// TRUNCATE TABLE my_table
```

- `onCluster('other_cluster')` names another cluster for one query.
- `withoutOnCluster()` on a query leaves `ON CLUSTER` out of that query; a
  later `onCluster()` adds it again.
- `Connection::withoutOnCluster($callback)` leaves it out of every mutation of
  the connection while the callback runs, also of queries built before, and
  returns what the callback returns. It does not change the schema builder.
- With `fix_default_query_builder` set to `false`, Laravel's `delete()`,
  `update()` and `truncate()` send
  `alter table "my_table" on cluster 'company_cluster' delete where "id" = 123`
  and `truncate table "my_table" on cluster 'company_cluster'`.
- Laravel's `DatabaseTruncation` trait truncates through `truncate()`, so with
  the option it empties every node (see
  [Testing with DatabaseTruncation](#testing-with-databasetruncation)).
- A query builder made with only a smi2 client, `new Builder($client)`, gets no
  `ON CLUSTER` from the option.

The option needs a `cluster_name`; without one, Laravel throws an
`InvalidArgumentException` when it creates the connection:
`The ClickHouse connection [clickhouse] sets use_on_cluster without a cluster_name. ...`.
It takes a boolean, or a string such as `'true'` or `'0'`, and is off by
default, because every mutation becomes distributed DDL: it waits for every
host, up to `distributed_ddl_task_timeout`, and fails while a host is down.
Use it for tables that are not replicated. A `Replicated*MergeTree` table
passes deletes, updates and `TRUNCATE` on to its replicas by itself, and
`OPTIMIZE ... ON CLUSTER` only adds the wait. Inside a session, a statement
with `ON CLUSTER` on the name of a temporary table of the session throws (see
[Sessions and temporary tables](#sessions-and-temporary-tables)).

### Laravel's schema builder

`Schema::create()` creates a ClickHouse table from Laravel's column methods
and modifiers, with the ClickHouse table options described below:

```php
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\SchemaBlueprint;

Schema::create('events', function (SchemaBlueprint $table) {
    $table->id();
    $table->string('name')->nullable()->default('x')->comment("it's");
    $table->timestamps();
});
// CREATE TABLE `events` (`id` Int64, `name` Nullable(String) DEFAULT 'x' COMMENT 'it\'s',
//   `created_at` Nullable(DateTime), `updated_at` Nullable(DateTime)) ENGINE = MergeTree() ORDER BY (`id`)
```

In a migration, the `Schema` facade uses the migration's connection: extend
`Oralunal\LaravelClickHouse\Migration`, whose connection is `clickhouse`, or
set `protected $connection` on Laravel's own `Migration` class. Elsewhere,
start with `Schema::connection('clickhouse')` when ClickHouse is not the
default connection. `Schema::drop()` and `Schema::dropIfExists()` send
`` DROP TABLE `events` `` and `` DROP TABLE IF EXISTS `events` ``.
`Schema::dropSync()` and `Schema::dropIfExistsSync()`, and `drop()->sync()` or
`dropIfExists()->sync()` in a blueprint, add `SYNC`:
`` DROP TABLE `events` SYNC ``, which waits until ClickHouse has removed the
data, and the replica of a replicated table (see [Cluster mode](#cluster-mode)).
`Schema::table()` changes a table, as
[Changing tables](#changing-tables) describes, and `Schema::rename('events', 'events_old')`
sends `` RENAME TABLE `events` TO `events_old` ``.

On a connection with a `cluster_name`, the statements go `ON CLUSTER`, and a
MergeTree table becomes replicated when the connection lists its nodes (see
[The schema builder on a cluster](#the-schema-builder-on-a-cluster)). Without
a `cluster_name`, `Schema::create()` and the other `Schema` methods send their
statement to the active node only and keep the engine you give, as in 3.0.0;
there, `Migration::createMergeTree()` and `Migration::write()` send a
statement to every node. Inside [`session()`](#sessions-and-temporary-tables),
`$table->temporary()` creates a temporary table.

The callback receives an `Oralunal\LaravelClickHouse\SchemaBlueprint`, which
is Laravel's `Blueprint` with the ClickHouse methods below. A callback that
type-hints Laravel's `Blueprint` gets the same object; type-hint
`SchemaBlueprint` so that your editor knows these methods. A blueprint
resolver applies to the schema builder you set it on: after
`$schema = Schema::connection('clickhouse')` and
`$schema->blueprintResolver($resolver)`, `$schema->create()` passes the
resolver's blueprint to the callback. The `Schema` facade gets a new schema
builder for each call, so `Schema::blueprintResolver($resolver)` followed by
`Schema::create()` still passes a `SchemaBlueprint`.

The statement has this layout. The parts in brackets appear when the
blueprint sets them:

```sql
CREATE TABLE [IF NOT EXISTS] <table> (<columns>[, INDEX <name> <expression> TYPE <type> [GRANULARITY <n>]])
ENGINE = <engine> [PARTITION BY <expression>] [PRIMARY KEY (<columns>)] ORDER BY (<columns>)
[SAMPLE BY <expression>] [TTL <expression>] [SETTINGS <name>=<value>, ...] [COMMENT '<text>']
```

Every name is quoted with backticks. A column name is one identifier, so `n.a`
is the column `` `n.a` ``, and a table name with a dot names its database, so
`analytics.events` is `` `analytics`.`events` ``.

#### Column types

| Laravel column method | ClickHouse type |
| --- | --- |
| `char()`, `ulid()`, `foreignUlid()` | `FixedString(<length>)`: 255 for `char()` without a length, 26 for a ULID. ClickHouse pads a shorter value with NUL bytes. |
| `string()`, `tinyText()`, `text()`, `mediumText()`, `longText()` | `String`; the length is ignored |
| `float()` | `Float64`, or `Float32` for a precision up to 24 |
| `double()` | `Float64` |
| `decimal($column, $total, $places)` | `Decimal(<total>, <places>)` |
| `boolean()` | `Bool` |
| `enum($column, ['new', 'done'])` | `Enum('new', 'done')`; `['low' => 1, 'high' => 10]` gives `Enum('low' = 1, 'high' = 10)` |
| `json()`, `jsonb()` | `String` |
| `date()` | `Date` |
| `dateTime()`, `dateTimeTz()`, `timestamp()`, `timestampTz()` | `DateTime`, or `DateTime64(<precision>)` for a precision above 0; `->timezone('UTC')` gives `DateTime('UTC')` or `DateTime64(3, 'UTC')` |
| `year()` | `UInt16` |
| `binary()` | `String`, or `FixedString(<length>)` with `fixed: true` |
| `uuid()`, `foreignUuid()` | `UUID` |
| `ipAddress()`, `macAddress()` | `String` |
| `geometry()`, `geography()` | `Point`, `Ring`, `LineString`, `MultiLineString`, `Polygon` or `MultiPolygon`, by the subtype; the SRID is ignored |
| `vector()` | `Array(Float32)` |
| `rawColumn($column, 'Tuple(a UInt8, b String)')` | the type as given |
| `array('tags', 'String')` | `Array(String)` |
| `map('attributes', 'String', 'UInt64')` | `Map(String, UInt64)` |
| `ipv4()`, `ipv6()` | `IPv4`, `IPv6` |
| `date32()` | `Date32` |

`array()`, `map()`, `ipv4()`, `ipv6()` and `date32()` are `SchemaBlueprint`
methods. An `IPv6` column stores the IPv4 address `127.0.0.1` as
`::ffff:127.0.0.1`. `json()` stores the document as a string, because the
`JSON` type of ClickHouse 24.8 is experimental; use
`rawColumn('payload', 'JSON')` where your server allows it.

ClickHouse makes an enum an `Enum8` or an `Enum16` by its values. An `enum()`
array with a string key maps each name to its number, and its integer keys are
names too, because PHP turns a numeric string key into an integer:
`['active' => 1, '200' => 2]` gives `Enum('active' = 1, '200' = 2)`.
An array whose keys are all integers is a list of names, written from its
values in order whatever its keys, as Laravel reads the allowed values on
other databases: `array_unique([200, 404, 200, 500])` gives
`Enum('200', '404', '500')`, and `['200' => 1, '404' => 2]` gives
`Enum('1', '2')`. To number names that are all numbers, write the type
yourself: `rawColumn('status', "Enum8('200' = 1, '404' = 2)")`. `enum()` in
`Migration::createMergeTree()` reads an array the same way; there, write the
type with `column('status', "Enum8('200' = 1, '404' = 2)")`. In an array with
a string key, each number must be an integer or a string of digits, which may
start with a minus sign. Any other value throws before anything is sent
(an `InvalidArgumentException`, or an `InvalidClickHouseDDLException` in
`createMergeTree()`), a whole float too: `['low' => 1.0]` throws, and
`['low' => (int) 1.0]` gives `Enum('low' = 1)`.

By default, the integer methods give the types of 3.0.0: `tinyInteger()` is
`Int16` and `unsigned()` is ignored. With `exact_integer_types` set on the
connection, each method gets its own width, and `unsigned()` a `UInt` type:

| Laravel column method | ClickHouse type | With `exact_integer_types` |
| --- | --- | --- |
| `tinyInteger()` | `Int16` | `Int8` |
| `unsignedTinyInteger()`, `tinyIncrements()` | `Int16` | `UInt8` |
| `smallInteger()` | `Int16` | `Int16` |
| `unsignedSmallInteger()`, `smallIncrements()` | `Int32` | `UInt16` |
| `mediumInteger()`, `integer()` | `Int32` | `Int32` |
| `unsignedMediumInteger()`, `mediumIncrements()`, `unsignedInteger()`, `increments()` | `Int32` | `UInt32` |
| `bigInteger()` | `Int64` | `Int64` |
| `unsignedBigInteger()`, `id()`, `bigIncrements()`, `foreignId()` | `Int64` | `UInt64` |

Without the option, `unsignedSmallInteger()` gives `Int32`, so that values up
to 65535 fit. Set `'exact_integer_types' => true`
(`CLICKHOUSE_EXACT_INTEGER_TYPES=true`) in a new project. In an existing one,
it changes the tables that are created after you set it, by `migrate:fresh` or
in a new environment, and ClickHouse wraps a value that does not fit the
narrower type without an error: `200` inserted into an `Int8` column reads
back as `-56`, and `-1` in a `UInt32` column as `4294967295`. Laravel's `migrations` table keeps its 3.0.0 columns without the
option: ``CREATE TABLE `migrations` (`id` Int32, `migration` String, `batch` Int32) ENGINE = MergeTree() ORDER BY (`id`)``.

ClickHouse has no auto-increment: `id()` and the `increments()` methods create
a plain integer column, and a row inserted without a value for it gets `0`.

#### Column modifiers

| Modifier | SQL |
| --- | --- |
| `nullable()` | `Nullable(<type>)` |
| `default($value)` | `DEFAULT <value>` |
| `useCurrent()` | `DEFAULT now()`, `now64(<precision>)` with a precision, `today()` on a `date()`, `toYear(now())` on a `year()` |
| `storedAs('domain(url)')` | `MATERIALIZED domain(url)` |
| `virtualAs('upper(url)')` | `ALIAS upper(url)` |
| `ephemeral()`, `ephemeral('')` | `EPHEMERAL`, `EPHEMERAL ''` |
| `comment("it's")` | `COMMENT 'it\'s'` |
| `lowCardinality()` | `LowCardinality(<type>)`, and `LowCardinality(Nullable(<type>))` with `nullable()` |
| `codec('ZSTD(3)')` | `CODEC(ZSTD(3))` |
| `ttl('created_at + INTERVAL 1 DAY')` | the column TTL `TTL created_at + INTERVAL 1 DAY` |
| `timezone('UTC')` | the time zone of a date-time type |
| `unsigned()` | a `UInt` type with `exact_integer_types` |

`lowCardinality()`, `codec()`, `ttl()`, `ephemeral()` and `timezone()` are
ClickHouse modifiers; `Oralunal\LaravelClickHouse\SchemaColumnDefinition`
describes them for your editor.

`default()` writes a string as an escaped string literal, a bool as `1` or
`0`, a float with every digit, a backed enum as its value and a pure enum as
its name, a date as `'Y-m-d H:i:s'`, an array as an array literal and
`DB::raw()` as given. A collection or another object without a literal throws
an `InvalidArgumentException`. A float default of a `Decimal` column is
written as a string, because ClickHouse cuts a float off at the column's scale:
`decimal('price', 10, 2)->default(19.99)` gives `DEFAULT '19.99'`, which
stores `19.99`, where `DEFAULT 19.99` would store `19.98`.

`timestamps()` and `softDeletes()` create `Nullable(DateTime)` columns. A
`nullable()` column stores a `NULL` that you insert, where a column without it
stores the default of its type instead, such as the empty string or
`1970-01-01 00:00:00`. After `Schema::defaultTimePrecision(3)`, `timestamps()`
gives `Nullable(DateTime64(3))` columns, whose values read back with their
milliseconds, such as `2024-01-02 03:04:05.678`.

`autoIncrement()`, `useCurrentOnUpdate()`, `charset()`, `collation()`,
`invisible()` and `from()` add nothing. `first()` and `after('id')` add nothing
in `Schema::create()`, and place a column that `Schema::table()` adds or
changes: `FIRST`, ``AFTER `id` ``. `virtualAsJson()`
and `storedAsJson()` throw a `RuntimeException`: use `virtualAs()` or
`storedAs()` with an expression such as `JSONExtractString(payload, 'name')`.

ClickHouse refuses `Nullable` around an `Array`, a `Map` or a `LowCardinality`
type: for an array of nullable strings, write `array('tags', 'Nullable(String)')`.
`LowCardinality` around a number needs the
`allow_suspicious_low_cardinality_types` setting.

#### Table options

`SchemaBlueprint` adds the clauses of a ClickHouse table:

```php
use Illuminate\Support\Facades\DB;

Schema::create('visits', function (SchemaBlueprint $table) {
    $table->unsignedBigInteger('id');
    $table->unsignedBigInteger('version');
    $table->dateTime('visited_at');
    $table->string('url');
    $table->string('country')->lowCardinality();
    $table->engine('ReplacingMergeTree(version)');
    $table->partitionBy('toYYYYMM(visited_at)');
    $table->primary(['id', DB::raw('intHash32(id)')], 'visits_primary');
    $table->orderBy('id', DB::raw('intHash32(id)'), 'visited_at');
    $table->sampleBy('intHash32(id)');
    $table->ttl('visited_at + INTERVAL 1 YEAR');
    $table->settings(['index_granularity' => 8192, 'ttl_only_drop_parts' => true]);
    $table->comment('Page visits, deduplicated by version');
    $table->index('url', null, 'bloom_filter(0.01)')->granularity(4);
});
// CREATE TABLE `visits` (`id` Int64, `version` Int64, `visited_at` DateTime, `url` String,
//   `country` LowCardinality(String), INDEX `visits_url_index` `url` TYPE bloom_filter(0.01) GRANULARITY 4)
//   ENGINE = ReplacingMergeTree(version) PARTITION BY toYYYYMM(visited_at)
//   PRIMARY KEY (`id`, intHash32(id)) ORDER BY (`id`, intHash32(id), `visited_at`)
//   SAMPLE BY intHash32(id) TTL visited_at + INTERVAL 1 YEAR
//   SETTINGS index_granularity=8192, ttl_only_drop_parts=1 COMMENT 'Page visits, deduplicated by version'
```

| Method | Clause |
| --- | --- |
| `engine('ReplacingMergeTree(version)')` | `ENGINE = ReplacingMergeTree(version)`. Without it, the connection's `engine` option (`CLICKHOUSE_ENGINE`), and without that, `MergeTree()`. |
| `orderBy('id', DB::raw('intHash32(id)'))` | ``ORDER BY (`id`, intHash32(id))``: a string is a column name and `DB::raw()` is SQL. The columns may come as an array; without any, `ORDER BY tuple()`. |
| `primary($columns, $name)` | the sorting key, or with `orderBy()` the `PRIMARY KEY` (see below) |
| `partitionBy('toYYYYMM(visited_at)')` | `PARTITION BY toYYYYMM(visited_at)` |
| `sampleBy('intHash32(id)')` | `SAMPLE BY intHash32(id)`, which must be part of the primary key |
| `ttl('visited_at + INTERVAL 1 YEAR')` | `TTL visited_at + INTERVAL 1 YEAR`, which must give a `Date` or a `DateTime`: for a `DateTime64` column, use `toDateTime(visited_at)` |
| `settings(['index_granularity' => 8192])` | `SETTINGS index_granularity=8192`; another call adds its settings |
| `comment('...')` | `COMMENT '...'` |
| `ifNotExists()` | `CREATE TABLE IF NOT EXISTS` |

`partitionBy()`, `sampleBy()` and `ttl()` take SQL. `settings()` writes the
values as the query builder's [`settings()`](#query-settings) does, and a name
that is not a plain identifier throws an `InvalidArgumentException`.
`primary()` with a `DB::raw()` column needs a name as its second argument,
because Laravel builds the default name from the column names.

For an engine of the MergeTree family, the sorting key comes from these rules:

- `orderBy()` is the sorting key. With `primary()` as well, `primary()` is the
  `PRIMARY KEY`, which ClickHouse requires to be a prefix of the sorting key:
  `primary('id')` with `orderBy('id', 'at')` gives
  ``PRIMARY KEY (`id`) ORDER BY (`id`, `at`)``.
- `primary()` alone is the sorting key: ``ORDER BY (`id`)``.
- Without either, the first column is the sorting key, as in 3.0.0.
- When the first column cannot be in a sorting key, because its type holds
  `Nullable` anywhere, as after `nullable()`, or is a `Variant`, `Dynamic`,
  `Nested` or aggregate function type, or because it is an `ALIAS` or
  `EPHEMERAL` column, a `MergeTree`, `ReplicatedMergeTree` or `SharedMergeTree`
  table gets `ORDER BY tuple()`, which means no sorting key. The other engines
  of the family, such as `ReplacingMergeTree` and `SummingMergeTree`, merge the
  rows that have the same sorting key; without one, they would merge all rows
  of a partition into one. For them `Schema::create()` throws a
  `RuntimeException` before it sends anything:
  `The first column [name] of the table [events] cannot be its sorting key, and without a sorting key the ReplacingMergeTree engine would merge the rows of each partition into one. Call orderBy() or primary() with the columns that identify a row.`
  An `orderBy()` without columns still gives `ORDER BY tuple()`, also on these
  engines.

Outside the MergeTree family, `primary()` and the first column add no key,
except for `EmbeddedRocksDB`, `KeeperMap`, `Redis` and `MaterializedPostgreSQL`,
whose key is the `PRIMARY KEY`: `$table->string('key')->primary()` with
`engine('EmbeddedRocksDB')` gives ``ENGINE = EmbeddedRocksDB PRIMARY KEY (`key`)``.
The clauses you set by name, `orderBy()`, `partitionBy()`, `sampleBy()`,
`ttl()`, `settings()` and `comment()`, are written for every engine, so that
ClickHouse accepts them, as an `S3` table takes `PARTITION BY`, or refuses
them: a `Log` table with `orderBy()` fails with `BAD_ARGUMENTS`. An engine of
another database, such as `engine('InnoDB')`, fails with `UNKNOWN_STORAGE`.

#### Data-skipping indexes

An index with a ClickHouse index type goes into the `CREATE TABLE`, and into
an `ALTER TABLE ... ADD INDEX` in `Schema::table()`. Pass the type as the third
argument of `index()`, or with `algorithm()`, and the granularity with
`granularity()`:

```php
$table->index('url', null, 'bloom_filter(0.01)')->granularity(4);
// INDEX `visits_url_index` `url` TYPE bloom_filter(0.01) GRANULARITY 4
$table->index(['country', 'id'], null, 'minmax');
// INDEX `visits_country_id_index` (`country`, `id`) TYPE minmax
$table->rawIndex('lower(url)', 'visits_lower_url')->algorithm('ngrambf_v1(3, 256, 2, 0)')->granularity(2);
// INDEX `visits_lower_url` lower(url) TYPE ngrambf_v1(3, 256, 2, 0) GRANULARITY 2
```

Without `granularity()`, ClickHouse uses a granularity of 1. A type that
ClickHouse does not know, such as MySQL's `btree`, fails with
`INCORRECT_QUERY`, and a table outside the MergeTree family, such as a
`Memory` or `Log` table, refuses data-skipping indexes.

An index without a type, such as the ones that `->index()` and `morphs()` add,
sends nothing, as in 3.0.0. The `default_index_type` connection option
(`CLICKHOUSE_DEFAULT_INDEX_TYPE`) gives such an index a type on a table of the
MergeTree family:

```php
// With 'default_index_type' => 'minmax'
Schema::create('comments', function (SchemaBlueprint $table) {
    $table->id();
    $table->morphs('commentable');
});
// CREATE TABLE `comments` (`id` Int64, `commentable_type` String, `commentable_id` Int64,
//   INDEX `comments_commentable_type_commentable_id_index` (`commentable_type`, `commentable_id`) TYPE minmax)
//   ENGINE = MergeTree() ORDER BY (`id`)
```

The option is `null` by default, and a blank value counts as not set. An index
with a type of its own keeps it. On a table outside the MergeTree family, an
index without a type still sends nothing.

In `Schema::table()`, `materialize()` also builds a new index for the rows that
the table already holds; without it, the index covers the rows inserted after
the `ALTER`:

```php
Schema::table('visits', function (SchemaBlueprint $table) {
    $table->index('url', null, 'bloom_filter(0.01)')->granularity(4)->materialize();
});
// ALTER TABLE `visits` ADD INDEX `visits_url_index` `url` TYPE bloom_filter(0.01) GRANULARITY 4
// ALTER TABLE `visits` MATERIALIZE INDEX `visits_url_index`
```

`MATERIALIZE INDEX` starts a mutation that runs in the background, unless the
connection's `settings` set `mutations_sync`, and the migration does not wait
for it. Until it is done, ClickHouse refuses to drop the index with
`BAD_ARGUMENTS`, as a rollback right after the migration would. `materialize()`
adds nothing in `Schema::create()`.

`dropIndex('visits_url_index')`, or `dropIndex(['url'])` with the columns of
the index, sends ``ALTER TABLE `visits` DROP INDEX IF EXISTS `visits_url_index` ``.
`IF EXISTS` lets the `down()` method of a migration drop an index without a
type, which was never created, as `dropMorphs()` does; a mistyped name is
silent too. A table outside the MergeTree family cannot have such indexes, so
there `dropIndex()`, and the index part of `dropMorphs()`, send nothing.
`Schema::table()` reads the engine of the table from `system.tables` for
`dropIndex()`, and for an index without a type while `default_index_type` is
set, when it compiles the statement, also under `toSql()` and
`migrate --pretend`.

#### Changing tables

`Schema::table()` sends an `ALTER TABLE` statement for each change, in the
order of the calls:

```php
Schema::table('visits', function (SchemaBlueprint $table) {
    $table->string('referrer')->nullable()->after('id');
    $table->string('country')->lowCardinality();
    $table->dropColumn('legacy');
    $table->renameColumn('payload', 'body');
    $table->comment('Page visits');
});
// ALTER TABLE `visits` ADD COLUMN `referrer` Nullable(String) AFTER `id`, ADD COLUMN `country` LowCardinality(String)
// ALTER TABLE `visits` DROP COLUMN `legacy`
// ALTER TABLE `visits` RENAME COLUMN `payload` TO `body`
// ALTER TABLE `visits` MODIFY COMMENT 'Page visits'
```

The added columns, with the modifiers of `Schema::create()` and their
`first()` or `after()`, go into one statement at the position of the first of
them. A `dropColumn()`, `renameColumn()` or `change()` between two added
columns starts another `ADD COLUMN` statement after it, so that a column
dropped and added again, for example with another type, is added after the
drop:

```php
Schema::table('orders', function (SchemaBlueprint $table) {
    $table->string('note');
    $table->dropColumn('amount');
    $table->decimal('amount', 10, 2);
});
// ALTER TABLE `orders` ADD COLUMN `note` String
// ALTER TABLE `orders` DROP COLUMN `amount`
// ALTER TABLE `orders` ADD COLUMN `amount` Decimal(10, 2)
```

| Method in `Schema::table()` | Statement |
| --- | --- |
| `dropColumn('a', 'b')`, and `dropTimestamps()`, `dropSoftDeletes()`, `dropMorphs()` and the other drops of columns | ``ALTER TABLE `t` DROP COLUMN `a`, DROP COLUMN `b` `` |
| `renameColumn('a', 'b')` | ``ALTER TABLE `t` RENAME COLUMN `a` TO `b` `` |
| `comment('Page visits')` | ``ALTER TABLE `t` MODIFY COMMENT 'Page visits'`` |
| `orderBy('id', 'shard')` | ``MODIFY ORDER BY (`id`, `shard`)``, in the statement that adds the columns |
| `sampleBy('intHash32(id)')` | ``ALTER TABLE `t` MODIFY SAMPLE BY intHash32(id)`` |
| `ttl('visited_at + INTERVAL 2 YEAR')` | ``ALTER TABLE `t` MODIFY TTL visited_at + INTERVAL 2 YEAR`` |
| `settings(['merge_with_ttl_timeout' => 3600])` | ``ALTER TABLE `t` MODIFY SETTING merge_with_ttl_timeout=3600`` |
| `rename('t_old')` | ``RENAME TABLE `t` TO `t_old` `` |

- ClickHouse takes a new sorting key only when it appends expressions of
  columns that the same `ALTER` adds, so `orderBy()` joins the statement that
  adds them: `$table->unsignedInteger('shard'); $table->orderBy('id', 'shard');`
  sends ``ALTER TABLE `visits` ADD COLUMN `shard` Int32, MODIFY ORDER BY (`id`, `shard`)``.
  When a `dropColumn()`, `renameColumn()` or `change()` splits the added
  columns into several statements, `orderBy()` throws a `RuntimeException`
  before anything is sent; call those commands before or after the added
  columns.
- `partitionBy()` throws a `RuntimeException`, because ClickHouse cannot change
  the partition key of a table.
- `ttl()` also makes ClickHouse apply the new TTL to the rows that the table
  already holds, in a mutation that runs in the background.
- A table name with a database keeps it: `Schema::rename('analytics.visits', 'visits_old')`
  sends ``RENAME TABLE `analytics`.`visits` TO `analytics`.`visits_old` ``.
- The server refuses to add a column that exists (`DUPLICATE_COLUMN`), to drop
  a column that does not, to drop a column of the sorting key, or one that a
  data-skipping index reads, unless `dropIndex()` drops that index earlier in
  the same `Schema::table()`, and to rename a table onto a name in use
  (`TABLE_ALREADY_EXISTS`). A `Log` table refuses `ADD COLUMN`.
- Each statement runs on its own, so when the server refuses one, the
  statements before it have already run.

These methods sent nothing in 3.0.0, so a migration that calls them changes
its table when it runs after the upgrade, for example under `migrate:fresh` or
in a new environment.

#### Changing a column

`change()` sends `MODIFY COLUMN` with the new definition of the column. As on
Laravel's other databases, an attribute that the new definition leaves out is
removed:

```php
// The column was `source` String DEFAULT 'web' COMMENT 'src'
Schema::table('visits', function (SchemaBlueprint $table) {
    $table->string('source')->nullable()->after('id')->renameTo('traffic_source')->change();
});
// ALTER TABLE `visits` MODIFY COLUMN `source` REMOVE DEFAULT
// ALTER TABLE `visits` MODIFY COLUMN `source` REMOVE COMMENT
// ALTER TABLE `visits` MODIFY COLUMN `source` Nullable(String) AFTER `id`
// ALTER TABLE `visits` RENAME COLUMN `source` TO `traffic_source`
```

- The `DEFAULT`, `MATERIALIZED` or `ALIAS` expression, and the comment, are
  removed unless the new definition gives them again, with `default()`,
  `useCurrent()`, `storedAs()`, `virtualAs()`, `ephemeral()` or `comment()`.
  Each `REMOVE` is a statement of its own, before the `MODIFY COLUMN`. A
  column whose `ALIAS` is removed becomes a stored column, and the rows that
  the table holds read the default of its type.
- The `CODEC` and the TTL of the column stay unless `codec()` or `ttl()` gives
  new ones, and an `EPHEMERAL` column stays `EPHEMERAL`, because ClickHouse
  24.8 cannot remove it.
- `first()` and `after()` move the column, and `renameTo()` renames it after
  the change.

To know what to remove, `change()` reads the type, the default and the comment
of the column from `system.columns` of the active node when it compiles the
statements, or, inside a [session](#sessions-and-temporary-tables), those of a
temporary table of that name. So `toSql()` and `migrate --pretend` reach the
server, and the pretend log shows the `REMOVE` statements that would run.

A new type rewrites the column in a mutation. ClickHouse changes the type of
the column first, so when it then fails to convert a value, the mutation
stays, and every later query of the table fails, until you kill the mutation
and change the type back:

```sql
KILL MUTATION WHERE database = 'default' AND table = 'visits';
ALTER TABLE visits MODIFY COLUMN referrer Nullable(String);
```

`change()` refuses the commonest case before anything of the blueprint is
sent: a column that holds `NULL` values, at the top or in the elements of an
array at any depth, and whose new type cannot hold them. It throws a
`RuntimeException`:

```text
The column [referrer] of the table [visits] holds NULL values, so its type cannot change from
Nullable(String) to String: ClickHouse would fail to convert them in a mutation, and every later
query of the table would fail until the mutation is killed. Replace the NULL values first, or keep
the column nullable(). Rows removed by a lightweight DELETE count too until a merge rewrites their
parts; ALTER TABLE `visits` APPLY DELETED MASK removes them.
```

The check runs
``SELECT 1 FROM `visits` WHERE `referrer` IS NULL LIMIT 1 SETTINGS apply_deleted_mask = 0``,
or on a connection with a `cluster_name` a read of every host with
`clusterAllReplicas()` (see
[The schema builder on a cluster](#the-schema-builder-on-a-cluster)),
within the connection's `timeout_query`: on a large table without `NULL`
values it reads the whole column, so raise the timeout for the migration if it
runs out. The rows of a lightweight delete count until a merge rewrites their
data parts; run `ALTER TABLE visits APPLY DELETED MASK SETTINGS mutations_sync = 2`
and migrate again. The check does not look into maps and tuples, or at other
values that cannot be converted, such as the text `'abc'` of a `String`
column that becomes an `Int64`. A new type for a column of the sorting key
fails with `ALTER_OF_COLUMN_IS_FORBIDDEN`, after the `REMOVE` statements
before it have run.

#### What throws, and what is not supported

These calls throw before anything of the blueprint is sent, also the commands
before them:

- `time()`, `timeTz()`, `set()` and `computed()`: a `RuntimeException`,
  because ClickHouse 24.8 has no such types. The message names a replacement,
  such as `rawColumn('flags', "Array(Enum('a', 'b'))")` for `set()`.
- `geometry()` or `geography()` without one of the subtypes in the type table:
  a `RuntimeException`.
- `virtualAsJson()` and `storedAsJson()`: a `RuntimeException`.
- `enum()` with an array that has a string key and a value that is not an
  integer or a string of digits, such as `['low' => 1.5]`, `['low' => 1.0]`,
  `['low' => '+1']` or the list entry of `['a', 'b' => 2]`, a `default()`
  without a literal and an invalid setting name: an `InvalidArgumentException`.
- A table of an engine such as `ReplacingMergeTree` whose first column cannot
  be its sorting key, without `orderBy()` or `primary()`: a `RuntimeException`
  (see [Table options](#table-options)).
- `fullText()`, `dropFullText()`, `vectorIndex()` and `dropVectorIndex()`: a
  `RuntimeException`, because ClickHouse has no such indexes. Instead of a
  full-text index, add a data-skipping index of a token or n-gram type, such
  as `index('body', null, 'tokenbf_v1(512, 3, 0)')`, and give `index()` a
  vector similarity index type that your server supports as its type.
- In `Schema::table()`: `primary()`, also on a column that it adds,
  `dropPrimary()`, `renameIndex()` and `partitionBy()`, because ClickHouse
  cannot change the primary or the partition key of a table or rename an
  index, and `orderBy()` and `change()` in the cases above. A
  `RuntimeException` says what to do instead, such as
  `ClickHouse cannot rename the index [a] of the table [visits]. Drop it with dropIndex() and add it again with index().`

ClickHouse has no foreign keys, no unique constraints and no spatial indexes.
`foreign()`, `constrained()`, `dropForeign()`, `unique()`, `dropUnique()`,
`spatialIndex()` and `dropSpatialIndex()` send nothing, so
`foreignId('user_id')->constrained()` adds only the column, and
`dropConstrainedForeignId('user_id')` only drops it:
``ALTER TABLE `posts` DROP COLUMN `user_id` ``. `temporary()` creates a
temporary table inside `session()`, and throws outside it (see
[Sessions and temporary tables](#sessions-and-temporary-tables)).

### Schema introspection

Laravel's schema inspection methods work on ClickHouse connections:

```php
use Illuminate\Support\Facades\Schema;

Schema::getTables();                            // tables of the connection's database
Schema::getTableListing();                      // ['default.my_table', ...]
Schema::getViews();                             // views and materialized views
Schema::getColumns('my_table');
Schema::getColumnListing('my_table');           // ['id', 'created_at', ...]
Schema::hasColumn('my_table', 'field_one');     // true
Schema::getColumnType('my_table', 'field_one'); // 'String'
Schema::getIndexes('my_table');
Schema::hasIndex('my_table', 'primary');        // true
Schema::hasTable('my_table');                   // true for a table, false for a view or a dictionary
Schema::hasView('my_view');                     // views and materialized views
Schema::hasDictionary('my_dictionary');         // dictionaries
```

They read the connection's database. To read another one, pass its name, or a
list of names, to `getTables()`, `getTableListing()` and `getViews()`, and a
`<database>.<table>` name to the other methods:
`Schema::getTables('analytics')`, `Schema::getColumns('analytics.events')`. If
ClickHouse is not your default connection, start with
`Schema::connection('clickhouse')`.

- `getTables()` lists the tables, with the database as `schema`,
  `total_bytes` as `size`, the table comment and the engine. The `size` of a
  `Merge` table is `NULL`, because its `total_bytes` repeats the bytes of its
  source tables. Views, materialized views with their `.inner` tables, and
  dictionaries are left out. `getViews()` lists views and materialized views,
  with their `SELECT` as `definition`.
- `hasTable()` is `true` for a table only. Like `getTables()`, it leaves out
  views, materialized views and dictionaries, so use `hasView()` for a view or
  a materialized view, and `hasDictionary()` for a dictionary. `hasDictionary()`
  also takes a `<database>.<dictionary>` name.
- `getColumns()` returns the full ClickHouse type, such as
  `LowCardinality(Nullable(String))`, as both `type` and `type_name`. A column is
  `nullable` when its type accepts `NULL`: `Nullable(...)`,
  `LowCardinality(Nullable(...))`, `Variant(...)`, `Dynamic`, or a
  `SimpleAggregateFunction` over one of them. An `Array(Nullable(String))`
  column is not. `default` holds the `DEFAULT` expression as ClickHouse prints
  it, for example `'it\'s'` or `now()`.
  `MATERIALIZED` columns have a `stored` generation and `ALIAS` columns a
  `virtual` one, with the expression. An `EPHEMERAL` column is listed with its
  expression as the default; ClickHouse does not store it, so a `SELECT` cannot
  read it.
- `getIndexes()` returns the primary key, named `primary`, if the table has
  one, followed by the data-skipping indices. Their `columns` are the elements
  of the key or index expression: `ORDER BY (id, intHash32(id))` gives
  `['id', 'intHash32(id)']`. `type` is `null` for the primary key, as on
  Laravel's SQLite driver, and the index type, such as `minmax`, `set` or
  `bloom_filter`, for a data-skipping index; `primary` is `true` for the
  primary key only. Index names are lowercased, as Laravel's other drivers do,
  and no ClickHouse index is unique.
- `getForeignKeys()` returns an empty array, because ClickHouse has no foreign
  keys. For the same reason `Schema::disableForeignKeyConstraints()`,
  `enableForeignKeyConstraints()` and `withoutForeignKeyConstraints()` send no
  query.

`php artisan db:show` prints the server version and the tables, with their row
counts if you add `--counts` and the views if you add `--views`. Its Open
Connections are the client connections to the node that the connection talks
to, this one included: the TCP, HTTP, MySQL and PostgreSQL connections that
`system.metrics` counts. A user who may not read the `system` tables gets an
error there.
`php artisan db:table` prints the columns and indexes of one table, and lists
the attributes of the primary key as `primary`, or `compound, primary` for a
key of several columns:

```sh
php artisan db:show --database=clickhouse --counts --views
php artisan db:table my_table --database=clickhouse
```

`--counts` counts the rows of every table, and `--views` those of every view.
So `--counts` fails on a table that ClickHouse cannot read, such as a `Kafka`
or `Set` table (see [Testing with DatabaseTruncation](#testing-with-databasetruncation)),
and `--views` fails on a parameterized view, one whose query has `{name:Type}`
parameters.

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
are sent to every node, the same way migrations are, without `ON CLUSTER`. So
a dump of a connection with a `cluster_name` and nodes, whose schema builder
creates replicated tables, does not load: such a table, Laravel's `migrations`
table included, is dumped as
`ENGINE = ReplicatedMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}')`,
which ClickHouse 24.8 refuses without `ON CLUSTER` with `BAD_ARGUMENTS`, after
`migrate` has dropped the `migrations` table to load the dump. Do not squash
the migrations of such a connection.

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
node: one `DROP ... ON CLUSTER` for each on a connection with a
`cluster_name`, which also reaches hosts that the connection does not list,
and one `DROP` on each listed node otherwise. They drop the objects that the
active node lists. Views are dropped even without `--drop-views`: the dump and
the migrations recreate them, and that would fail while they still exist.

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

The ClickHouse user needs permission to create and drop databases. On a
connection with a `cluster_name`, these databases are created and dropped
`ON CLUSTER`, on every host of the cluster. Test runs without `--parallel` use
the configured database.

### Testing with DatabaseTruncation

`RefreshDatabase`, `LazilyRefreshDatabase` and `DatabaseTransactions` undo
each test by rolling back a database transaction, which they start on every
connection in `$connectionsToTransact`, by default the default connection.
ClickHouse has no transactions, so with ClickHouse as the default connection,
or listed there, every test fails with a `LogicException`:
`ClickHouse does not support transactions. In tests, use the DatabaseTruncation trait for ClickHouse connections and leave them out of $connectionsToTransact.`
These traits keep working for your other connections when the ClickHouse
connections are not in `$connectionsToTransact`. For the ClickHouse
connections, use Laravel's `DatabaseTruncation` trait, which empties the
tables between tests:

```php
use Illuminate\Foundation\Testing\DatabaseTruncation;

class EventReportTest extends TestCase
{
    use DatabaseTruncation;

    // Without this property, only the default connection is truncated.
    protected $connectionsToTruncate = ['mysql', 'clickhouse'];
}
```

The first test that uses the trait runs `migrate:fresh`. Before each later
test, every table that holds rows is truncated, except the `migrations` table.
The trait takes the tables from `Schema::getTables()`, so views, materialized
views and dictionaries are left alone. A view reads from tables that are
truncated anyway, and a materialized view created with `TO my_table` writes to
a table that is truncated like the others.

Before it truncates a table, the trait reads one row from it, and it only
truncates a table that returns a row. Two kinds of tables make this fail:

- ClickHouse cannot read the table of a stream engine such as `Kafka`, a `Set`
  table, or a `File` table that has no data file yet. The read also fails, or
  hangs, on a table whose endpoint cannot be reached, such as a `URL`, `S3`,
  `MySQL` or `PostgreSQL` table. Such a table makes every test fail, even when
  it is empty.
- ClickHouse refuses `TRUNCATE` for `Buffer`, `Merge`, `Null`, `URL`, `Kafka`
  and `GenerateRandom` tables, for integration engines such as `MySQL` and
  `PostgreSQL`, and for an `S3` table whose path has globs. Such a table makes
  the test fail with `Truncate is not supported by storage ...` as soon as it
  returns a row.

`TRUNCATE` also deletes data that lives outside ClickHouse: on an `S3` table,
ClickHouse deletes the object from the bucket. Other engines that store their
data elsewhere, such as `AzureBlobStorage` and `HDFS`, are likely to do the
same. List all these tables in `$exceptTables`, the external ones unless your
tests may wipe their storage:

```php
protected $exceptTables = ['my_table_buffer'];
```

Laravel also accepts a list per connection, such as
`['mysql' => [], 'clickhouse' => ['my_table_buffer']]`, but then every
connection that is truncated needs its own key, the default connection
included. A connection without a key gets the whole array, and the trait fails
with `Array to string conversion`. Prefer table names to `<database>.<table>`:
under `--parallel`, the database becomes `<database>_test_<token>`, so the
qualified name stops matching.

Three kinds of objects keep rows that the truncation does not reach:

- a materialized view created with its own `ENGINE`, rather than with
  `TO my_table`, keeps its rows in an inner table;
- a `Buffer` table can hold rows that it has not written to its destination
  table yet, and it writes them later, during another test;
- a dictionary keeps the rows it loaded from its source table until it reloads
  them, which a dictionary with `LIFETIME(0)` never does by itself.

Empty the first two in `beforeTruncatingDatabase()`. It also runs before the
first `migrate:fresh`, when they may not exist yet, so check them first: a
`Buffer` table with `hasTable()`, and a materialized view with `hasView()`,
because `hasTable()` is `false` for a view. Flush the `Buffer` table before you
truncate the materialized view: the flushed rows reach the destination table,
and from there the materialized views that read it.

```php
protected function beforeTruncatingDatabase(): void
{
    $clickhouse = DB::connection('clickhouse');

    if ($clickhouse->getSchemaBuilder()->hasTable('my_table_buffer')) {
        // Writes the buffered rows to my_table, which is truncated next.
        $clickhouse->statement('OPTIMIZE TABLE my_table_buffer');
    }

    if ($clickhouse->getSchemaBuilder()->hasView('my_materialized_view')) {
        $clickhouse->table('my_materialized_view')->truncate();
    }
}
```

Reload a dictionary in `afterTruncatingDatabase()`, which runs after the
truncation and the seeding. A reload before the truncation would load the old
rows again:

```php
protected function afterTruncatingDatabase(): void
{
    DB::connection('clickhouse')->statement('SYSTEM RELOAD DICTIONARY my_dictionary');
}
```

On a [cluster](#cluster-mode) connection, the trait truncates the tables on the
active node only, unless the connection sets
[`use_on_cluster`](#on-cluster-for-mutations): then each `TRUNCATE` goes
`ON CLUSTER` and empties every node, which takes longer. A
`Replicated*MergeTree` table passes the `TRUNCATE` on to its other replicas,
but a table that is not replicated keeps its rows on the other nodes. To empty
such a table on every node without the option, truncate it with `onCluster()`
in `beforeTruncatingDatabase()`:

```php
if ($clickhouse->getSchemaBuilder()->hasTable('my_table')) {
    $clickhouse->table('my_table')->onCluster('company_cluster')->truncate();
}
```

## Credits

This package bundles code from these MIT-licensed projects. Each bundled
directory keeps the original license next to the code.

| Bundled as | Origin | License |
| --- | --- | --- |
| `Oralunal\LaravelClickHouse\ClickhouseBuilder` (`src/ClickhouseBuilder`) | [the-tinderbox/ClickhouseBuilder](https://github.com/the-tinderbox/ClickhouseBuilder), via the [glushkovds](https://github.com/glushkovds/ClickhouseBuilder) and [oralunal](https://github.com/oralunal/ClickhouseBuilder) forks (v1.0.0) | `src/ClickhouseBuilder/LICENSE` |
| `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder` (`src/ClickhouseSchemaBuilder`) | [glushkovds/php-clickhouse-schema-builder](https://github.com/glushkovds/php-clickhouse-schema-builder) v1.1.1 by Denis Glushkov | `src/ClickhouseSchemaBuilder/LICENSE` |
| `Oralunal\LaravelClickHouse\Enum\Enum` (`src/Enum`) | [myclabs/php-enum](https://github.com/myclabs/php-enum) 1.8.5 by My C-Labs | `src/Enum/LICENSE` |

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
