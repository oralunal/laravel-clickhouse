![Tests](https://github.com/oralunal/laravel-clickhouse/actions/workflows/tests.yml/badge.svg)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/oralunal/laravel-clickhouse.svg?style=flat-square)](https://packagist.org/packages/oralunal/laravel-clickhouse)
[![Total Downloads](https://img.shields.io/packagist/dt/oralunal/laravel-clickhouse.svg?style=flat-square)](https://packagist.org/packages/oralunal/laravel-clickhouse)

# laravel-clickhouse

ClickHouse for Laravel: Eloquent models, Laravel's query builder, the schema builder, migrations, sessions, parallel queries and clusters.
The package sends queries over HTTP with [smi2/phpClickHouse](https://github.com/smi2/phpClickHouse). It does not use PDO.

**Documentation: [laravel-clickhouse.oralunal.com](https://laravel-clickhouse.oralunal.com)**

## Requirements

- PHP 8.5
- Laravel 13
- ClickHouse 24.8 or later. The tests run on 24.8, 26.3 and 26.8.

## Installation

```sh
composer require oralunal/laravel-clickhouse
```

Set the connection in `.env`:

```dotenv
CLICKHOUSE_HOST=localhost
CLICKHOUSE_PORT=8123
CLICKHOUSE_DATABASE=default
CLICKHOUSE_USERNAME=default
CLICKHOUSE_PASSWORD=
```

See [Installation](https://laravel-clickhouse.oralunal.com/getting-started/installation) and [Configuration](https://laravel-clickhouse.oralunal.com/getting-started/configuration).

## Quick start

Create the table in a Laravel migration:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\SchemaBlueprint;

return new class extends Migration
{
    protected $connection = 'clickhouse';

    public function up(): void
    {
        Schema::connection($this->connection)->create('my_table', function (SchemaBlueprint $table) {
            $table->unsignedInteger('id');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->string('field_one');
            $table->integer('field_two');
            $table->orderBy('id');
        });
        // CREATE TABLE `my_table` (`id` UInt32, `created_at` DateTime64(3) DEFAULT now64(3),
        //   `field_one` String, `field_two` Int32) ENGINE = MergeTree() ORDER BY (`id`)
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('my_table');
    }
};
```

Define an Eloquent model:

```php
use Oralunal\LaravelClickHouse\Eloquent\Model;

class MyTable extends Model
{
    protected $table = 'my_table';

    protected $guarded = [];

    public $timestamps = false;
}
```

Insert, read, update and delete:

```php
MyTable::insert([
    ['id' => 1, 'field_one' => 'click', 'field_two' => 10],
    ['id' => 2, 'field_one' => 'view', 'field_two' => 20],
]);
// insert into "my_table" ("field_one", "field_two", "id") values ('click', 10, 1), ('view', 20, 2)

MyTable::where('field_two', '>', 0)->count();
// select count(*) as "aggregate" from "my_table" where "field_two" > 0

MyTable::query()->orderBy('id')->pluck('field_one', 'id');
// select "field_one", "id" from "my_table" order by "id" asc

MyTable::where('id', 123)->update(['field_one' => 'new_val']);
// alter table "my_table" update "field_one" = 'new_val' where "id" = 123

MyTable::where('id', 123)->delete(true);
// delete from "my_table" where "id" = 123
```

The query builder of the connection is Laravel's, with the ClickHouse clauses:

```php
DB::connection('clickhouse')->table('my_table')
    ->preWhere('field_one', 'click')
    ->where('field_two', '>', 5)
    ->settings('max_threads', 2)
    ->get();
// select * from "my_table" prewhere "field_one" = 'click' where "field_two" > 5 settings max_threads=2
```

`final()` adds `FINAL` for a table whose engine merges rows, such as `ReplacingMergeTree`.

## Features

| Area | Documentation |
| --- | --- |
| Eloquent models with casts, events, relations and timestamps | [Define a model](https://laravel-clickhouse.oralunal.com/models/defining-models) |
| Batch inserts, in-memory buffers, Buffer tables, JSONEachRow and file inserts | [Insert rows](https://laravel-clickhouse.oralunal.com/models/inserting-rows) |
| Queries, mutations and parallel queries of a model | [Eloquent models](https://laravel-clickhouse.oralunal.com/models/eloquent) |
| Laravel's query builder with ClickHouse SQL: `FINAL`, `PREWHERE`, `SAMPLE`, `WITH`, `ARRAY JOIN`, ClickHouse joins, `INTERSECT`, `EXCEPT`, `SETTINGS` | [Query builder](https://laravel-clickhouse.oralunal.com/query-builder/basics) |
| `count()`, aggregates, `exists()`, `first()`, `pluck()`, `chunk()`, pagination, results in any format | [Read results](https://laravel-clickhouse.oralunal.com/query-builder/reading-results) |
| Lightweight `DELETE`, mutations, `OPTIMIZE`, `TRUNCATE`, `IN PARTITION`, `ON CLUSTER` | [Updates and deletions](https://laravel-clickhouse.oralunal.com/query-builder/writing-data) |
| `Schema::create()` and `Schema::table()` with ClickHouse types, engines, keys and indexes | [Laravel's schema builder](https://laravel-clickhouse.oralunal.com/schema/schema-builder) |
| `migrate`, `migrate:rollback`, `migrate:status`, `migrate:fresh`, `schema:dump` | [Migration commands](https://laravel-clickhouse.oralunal.com/schema/migration-commands) |
| `?` bindings, `cursor()`, pretending | [Raw SQL](https://laravel-clickhouse.oralunal.com/advanced/raw-sql) |
| Sessions and temporary tables | [Sessions](https://laravel-clickhouse.oralunal.com/advanced/sessions) |
| Queries at the same time, across connections | [Parallel queries](https://laravel-clickhouse.oralunal.com/advanced/parallel-queries) |
| Node rotation, retries, `ON CLUSTER` DDL, replicated tables | [Clusters](https://laravel-clickhouse.oralunal.com/advanced/clusters) |
| `DatabaseTruncation`, SQLite with ClickHouse, parallel test databases | [Tests](https://laravel-clickhouse.oralunal.com/advanced/testing) |
| Rows and values with the types that Laravel declares, a PHPStan extension for Larastan that reads ClickHouse schema dumps | [Static analysis](https://laravel-clickhouse.oralunal.com/reference/static-analysis) |

## Upgrading

| From | Coding-agent skill |
| --- | --- |
| `oralunal/laravel-clickhouse` 3.x | `/lc-upgrade-3x-to-4x` |
| `oralunal/phpclickhouse-laravel` 2.x | `/lc-upgrade-2x-to-4x` |
| `oralunal/phpclickhouse-laravel` 1.x | `/lc-upgrade-1x-to-4x` |

`php artisan clickhouse:install-skills` installs the skills. See [Upgrade](https://laravel-clickhouse.oralunal.com/getting-started/upgrading),
[UPGRADE.md](UPGRADE.md) and the [CHANGELOG](CHANGELOG.md).

## Contributing

See [Contribute](https://laravel-clickhouse.oralunal.com/reference/contributing) and [CONTRIBUTING.md](CONTRIBUTING.md).

## Credits

The package is a fork of [glushkovds/phpclickhouse-laravel](https://github.com/glushkovds/phpclickhouse-laravel).
Version 1.x required these MIT-licensed projects through Composer, and versions 2.x and 3.x bundled their code (2.0.0 only the query builder):
[the-tinderbox/ClickhouseBuilder](https://github.com/the-tinderbox/ClickhouseBuilder),
[glushkovds/php-clickhouse-schema-builder](https://github.com/glushkovds/php-clickhouse-schema-builder) and
[myclabs/php-enum](https://github.com/myclabs/php-enum). Version 4 bundles none of them.
See [Credits](https://laravel-clickhouse.oralunal.com/getting-started/introduction#credits).

## License

MIT. See [LICENSE](LICENSE).
