# Upgrade Guide

## Upgrading from 1.x to 2.0

PHP (`^8.5`) and Laravel (`^13`) requirements are unchanged.

2.0 brings the query builder into this package. It used to come from the
separate `oralunal/clickhouse-builder` package. The SQL it generates is the
same, but its classes and helper functions are in a new namespace.

**Do you need to change anything?** Only if your code mentions
`Tinderbox\ClickhouseBuilder`, calls `raw()`, `tp()` or `array_flatten()`, or
uses the old builder's file and temporary-table API. Apps that only use
`BaseModel`, `Migration`, `RawColumn` and `DB::connection('clickhouse')` keep
working without changes.

To find what needs changing, run:

```sh
grep -rnE 'Tinderbox\\ClickhouseBuilder|[^>:$a-zA-Z_]raw\(|array_flatten\(|[^a-zA-Z_]tp\(|into_memory_table|file_from|addFile\(|getFiles\(' \
  app config database routes tests
```

### 1. Update the dependency

```sh
composer require oralunal/phpclickhouse-laravel:^2.0
```

If your `composer.json` lists `oralunal/clickhouse-builder` directly, remove it.
The bundled copy replaces it:

```sh
composer remove oralunal/clickhouse-builder
```

`the-tinderbox/clickhouse-php-client` is no longer installed either. If your
code uses it directly, require it yourself.

### 2. Rename the `Tinderbox\ClickhouseBuilder` namespace

The builder classes are in `PhpClickHouseLaravel\ClickhouseBuilder` now. Only
the namespace prefix changes; class names and the folder layout are the same.

| 1.x | 2.0 |
| --- | --- |
| `Tinderbox\ClickhouseBuilder\Query\Expression` | `PhpClickHouseLaravel\ClickhouseBuilder\Query\Expression` |
| `Tinderbox\ClickhouseBuilder\Query\Enums\Operator` | `PhpClickHouseLaravel\ClickhouseBuilder\Query\Enums\Operator` |
| `Tinderbox\ClickhouseBuilder\Query\TwoElementsLogicExpression` | `PhpClickHouseLaravel\ClickhouseBuilder\Query\TwoElementsLogicExpression` |
| `Tinderbox\ClickhouseBuilder\Query\BaseBuilder` | `PhpClickHouseLaravel\ClickhouseBuilder\Query\BaseBuilder` |
| `Tinderbox\ClickhouseBuilder\Query\Grammar` | `PhpClickHouseLaravel\ClickhouseBuilder\Query\Grammar` |
| `Tinderbox\ClickhouseBuilder\Exceptions\*` | `PhpClickHouseLaravel\ClickhouseBuilder\Exceptions\*` |

To replace them across your app (GNU sed; on macOS use `sed -i ''`):

```sh
grep -rlF 'Tinderbox\ClickhouseBuilder' app config database routes tests \
  | xargs sed -i 's/Tinderbox\\ClickhouseBuilder/PhpClickHouseLaravel\\ClickhouseBuilder/g'
```

### 3. Import the helper functions

`raw()`, `tp()` and `array_flatten()` used to be global functions. They are
now in the `PhpClickHouseLaravel\ClickhouseBuilder` namespace. If you call them
without importing them, PHP fails with `Call to undefined function raw()`.

```php
// 1.x
$query->select(raw('count() AS n'));

// 2.0
use function PhpClickHouseLaravel\ClickhouseBuilder\raw;

$query->select(raw('count() AS n'));
```

You can also use the classes directly:

```php
use PhpClickHouseLaravel\ClickhouseBuilder\Query\Expression;
use PhpClickHouseLaravel\RawColumn;

$query->select(new Expression('count() AS n'));
$query->select(new RawColumn('count()', 'n')); // count() AS `n`
```

### 4. Stop using the removed parts of the old builder

These parts only worked with `the-tinderbox/clickhouse-php-client`. This
package's `Builder` runs queries through `smi2/phpClickHouse`, so they never
did anything useful here. They are no longer included:

| Removed | Use instead |
| --- | --- |
| `addFile()`, `getFiles()`, `values()`, `getValues()` | Insert the rows with `insertAssoc()`, `insertBulk()` or `buffer()` |
| `into_memory_table()`, `file_from()` | Same as above |
| `Tinderbox\ClickhouseBuilder\Query\Builder` (Tinderbox-client builder) | `BaseModel::select()` / `where()`, or `DB::connection('clickhouse')->table(...)` |
| `Tinderbox\ClickhouseBuilder\Integrations\Laravel\Connection` / `Builder` | `PhpClickHouseLaravel\Connection` and `PhpClickHouseLaravel\Builder` |
| `Tinderbox\ClickhouseBuilder\Integrations\Laravel\ClickhouseServiceProvider` | `PhpClickHouseLaravel\ClickhouseServiceProvider` (auto-discovered) |

If you registered the old `ClickhouseServiceProvider` yourself in
`bootstrap/providers.php` or `config/app.php`, remove it. The class no longer
exists, so the app fails to boot while it is still listed. If an error still
mentions a `Tinderbox` provider after upgrading, run
`php artisan package:discover` (or `php artisan optimize:clear`) to rebuild
the cached provider list.

### 5. `whereIn()` with a string value

In 1.x, `whereIn()`, `preWhereIn()`, `whereGlobalIn()` and `havingIn()` turned
a string into a table reference, but only when it matched a file added with
`addFile()`. Since `addFile()` is gone, a string is now always quoted as a
value:

```php
$query->whereIn('id', 'ids');  // WHERE `id` IN 'ids'
```

To compare against a table or a sub-query, write it out explicitly:

```php
use function PhpClickHouseLaravel\ClickhouseBuilder\raw;

$query->whereIn('id', raw('allowed_ids'));                           // WHERE `id` IN allowed_ids
$query->whereIn('id', fn ($q) => $q->select('id')->from('allowed')); // WHERE `id` IN (SELECT `id` FROM `allowed`)
```

### 6. `Column::subQuery()` return type

`Column::subQuery()` and `Column::getSubQuery()` now declare `BaseBuilder` as
their return type. In 1.x they declared the Tinderbox-client `Query\Builder`,
which made column sub-queries built from this package's `Builder` throw a
`TypeError`. Only code that type-checks the return value against the old class
needs a change.
