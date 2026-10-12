# Static analysis

The package returns values with the types that Laravel declares, so PHPStan and Larastan read them correctly.
The package also has a PHPStan extension for Larastan. It reads the schema dumps of ClickHouse connections, and it
gives the facades the types of the package.

## Install the extension

Include the extension after Larastan's extension in `phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/oralunal/laravel-clickhouse/extension.neon
```

- The extension needs Larastan. Without Larastan's extension, it changes no type.
- If you include it before Larastan's extension, PHPStan stops with this error:
  `The PHPStan extension of oralunal/laravel-clickhouse must be included after the extension of Larastan, ...`.
  Larastan's parser would skip the ClickHouse dumps without an error.
- The package does not register the extension for `phpstan/extension-installer`. Include it yourself.

## The types of the rows

Each row is a `stdClass` object, and each value has the PHP type of its ClickHouse type, on every ClickHouse version.
See [Read results](/query-builder/reading-results#rows).

| ClickHouse type | PHP value | PHPStan type |
| --- | --- | --- |
| `Int8` to `Int64` | `int` | `int` |
| `UInt8` to `UInt32` | `int` | `int<0, max>` |
| `UInt64`, `Int128`, `UInt128`, `Int256`, `UInt256` | `int`, or a numeric string above `PHP_INT_MAX` | `mixed` |
| `Float32`, `Float64` | `float`, also `NAN`, `INF` and `-INF` | `float` |
| `Decimal` | a string with every digit and the scale of the column, such as `'1.50'` | `string` |
| `Bool` | `bool` | `bool` |
| `String`, `FixedString`, `UUID`, `IPv4`, `IPv6`, `Date`, `DateTime`, `DateTime64` | `string` | `string` |
| `Enum8`, `Enum16` | `string` | the union of the values, such as `'done'\|'new'` |
| `Nullable(T)` | the value of `T`, or `null` | the type of `T`, or `null` |
| `LowCardinality(T)`, `SimpleAggregateFunction(f, T)` | the value of `T` | the type of `T` |
| `Array`, `Map`, `Tuple`, `Nested` | a PHP array, whose values follow these rules | `mixed` |
| `JSON`, `Variant`, `Dynamic` and the other types | the value of the JSON result | `mixed` |

The PHPStan type is `mixed` when the types that Larastan reads cannot say the type exactly: `int` together with a
numeric string, or an array.

## Schema dumps

Larastan reads the columns of the models from the schema dumps in `database/schema`. Its own parsers cannot read the
dump of a ClickHouse connection, and Larastan skips such a file without an error. The extension reads it:

```sh
php artisan schema:dump --database=clickhouse
# database/schema/clickhouse-schema.sql
```

```php
$event->counter; // Int64: int
$event->note;    // Nullable(String): string|null
$event->status;  // Enum8('new' = 1, 'done' = 2): 'done'|'new'
$event->nope;    // Access to an undefined property App\Models\Event::$nope.
```

- The first line of a dump names its connection: `-- ClickHouse schema of the connection: clickhouse`. On a
  connection with the `readonly` option, a `Float32` or `Float64` column is nullable: a read-only user cannot ask
  ClickHouse to quote NaN and the infinities, so they come as `null`.
- Views, materialized views and dictionaries are read as tables. An `EPHEMERAL` column is left out.
- Larastan's parser reads the other dumps, such as the dump of MySQL, as before.

## Facades

The extension gives these calls the types of the package when they name a ClickHouse connection of the config:

| Call | Type |
| --- | --- |
| `DB::connection('clickhouse')`, `app('db')->connection('clickhouse')` | `Oralunal\LaravelClickHouse\Connection` |
| `DB::table('events')`, `DB::query()`, when the default connection is a ClickHouse connection | `Oralunal\LaravelClickHouse\QueryBuilder` |
| `Schema::connection('clickhouse')` | `Oralunal\LaravelClickHouse\SchemaBuilder` |

So PHPStan knows the ClickHouse methods, such as `final()`, `settings()` and `hasDictionary()`.
The connection name must be a constant string. With a variable name, declare the type yourself:

```php
/** @var \Oralunal\LaravelClickHouse\Connection $clickhouse */
$clickhouse = DB::connection($name);
```

## Limits

- **Migrations come after the dumps.** Larastan reads the dumps first and the migrations after them. A migration
  that creates a table with `Schema::create()` replaces the columns that the dump gave. Run
  `php artisan schema:dump --prune` to delete the migrations, or declare the types that you need with `@property`
  tags on the model.
- **Larastan reads a migration with its own rules.** The extension does not change them:
  - `decimal()` is `float`, but the value is a string. Declare the cast `'price' => 'decimal:2'`: the value stays a
    string, and Larastan reads the cast as `numeric-string`. Larastan reads `decimal()` as `float` on MySQL too.
  - `unsignedBigInteger()` and `id()` are `int<0, max>`, but a value above `PHP_INT_MAX` is a numeric string.
    Larastan reads MySQL's `BIGINT UNSIGNED` the same way.
  - Larastan reads a method of the blueprint that it does not know as a column: `engine('MergeTree')` adds a
    property `MergeTree`, and `orderBy('id')` makes the type of `id` `mixed`.
- **Table names.** Larastan keys the tables by their name only. When a ClickHouse table and a table of another
  connection have the same name, the first dump in the order of the file names wins.
- **Arrays.** An `Array`, a `Map` or a `Tuple` column is `mixed`. Declare its type with a `@property` tag, such as
  `@property list<string> $tags`.
