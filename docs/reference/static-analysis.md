# Static analysis

The package returns values with the types that Laravel declares, so PHPStan and Larastan read them correctly.
The package also has a PHPStan extension for Larastan. It reads the migrations and the schema dumps of ClickHouse
connections with the types of the package, and it gives the facades the types of the package.

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
  Larastan's parsers would skip the ClickHouse dumps without an error, and read the ClickHouse migrations with the
  types of MySQL.
- The package does not register the extension for `phpstan/extension-installer`. Include it yourself.
- The extension turns off the migration cache of Larastan (`enableMigrationCache`). Larastan keys the cache by the
  migration and dump files only, but the types of the ClickHouse columns also come from the database config and
  from the version of the package. If you turn the cache on again in your `phpstan.neon`, delete the
  `larastan_migrations_*.cache` files in the `tmpDir` of PHPStan after you change the database config or update the
  package.

## The types of the rows

Each row is a `stdClass` object, and each value has the PHP type of its ClickHouse type, on every ClickHouse version.
See [Read results](/query-builder/reading-results#rows).

| ClickHouse type | PHP value | PHPStan type |
| --- | --- | --- |
| `Int8` to `Int64` | `int` | `int` |
| `UInt8` to `UInt32` | `int` | `int<0, max>` |
| `UInt64`, `Int128`, `UInt128`, `Int256`, `UInt256` | `int`, or a numeric string above `PHP_INT_MAX` | `mixed` |
| `Float32`, `Float64` | `float`, also `NAN`, `INF` and `-INF` | `float`, or `float\|null` (see below) |
| `Decimal` | a string with every digit and the scale of the column, such as `'1.50'` | `string` |
| `Bool` | `bool` | `bool` |
| `String`, `FixedString`, `UUID`, `IPv4`, `IPv6`, `Date`, `Date32`, `DateTime`, `DateTime64`, `Time` | `string` | `string` |
| `Enum8`, `Enum16` | `string` | the union of the values, such as `'done'\|'new'` |
| `Nullable(T)` | the value of `T`, or `null` | the type of `T`, or `null` |
| `LowCardinality(T)`, `SimpleAggregateFunction(f, T)` | the value of `T` | the type of `T` |
| `Array`, `Map`, `Tuple`, `Nested` | a PHP array, whose values follow these rules | `mixed` |
| `JSON`, `Variant`, `Dynamic` and the other types | the value of the JSON result | `mixed` |

- The PHPStan type is `mixed` when the types that Larastan reads cannot say the type exactly: `int` together with a
  numeric string, or an array.
- A `Float32` or `Float64` column is nullable when a ClickHouse connection of the config has the `readonly` option.
  A read-only user cannot ask ClickHouse to quote NaN and the infinities, so they come as `null`. Larastan finds the
  columns of a model by the name of its table, not by its connection, so a model can read the table through the
  read-only connection.
- A `MATERIALIZED` or `ALIAS` column is nullable. `SELECT *` does not read it, so a model that Eloquent selects with
  `*` has no value for it.
- An `EPHEMERAL` column is not a property: a select cannot read it.

## Migrations

Larastan reads the columns of the models from the migrations in `database/migrations`. Its own rules give the column
methods the types of MySQL, and read a blueprint method that it does not know as a column. The extension changes the
calls of the ClickHouse tables before Larastan reads them, so that their columns get the types of the rows above.

A `Schema::create()` or `Schema::table()` callback is a ClickHouse callback when:

- its parameter has the type `SchemaBlueprint`, or
- the call uses a ClickHouse connection: the connection of `Schema::connection('clickhouse')`, else the
  `$connection` property of the migration, else the default connection.

```php
return new class extends Migration
{
    protected $connection = 'clickhouse';

    public function up(): void
    {
        Schema::create('hits', function (SchemaBlueprint $table) {
            $table->id();                     // UInt64: mixed
            $table->decimal('price', 10, 2);  // Decimal(10, 2): string
            $table->year('year');             // UInt16: int<0, max>
            $table->enum('level', ['low' => 1, 'high' => 10]); // 'high'|'low'
            $table->string('name')->nullable(); // string|null
            $table->engine('ReplacingMergeTree');  // no column
            $table->orderBy('id');                 // no column
        });
    }
};
```

| Column method | ClickHouse type | PHPStan type |
| --- | --- | --- |
| `id()`, `bigIncrements()`, `unsignedBigInteger()`, `foreignId()`, an unsigned `bigInteger()` | `UInt64` | `mixed` |
| `morphs()`, `nullableMorphs()` | `String` and `UInt64`, or `UUID` or `FixedString(26)` | `string` and `mixed`, or `string` |
| `foreignIdFor(Model::class)` | `UInt64` for a model with an integer key, else `UUID` or `FixedString(26)` | `mixed`, else `string` |
| `decimal()` | `Decimal` | `string` |
| `float()`, `double()` | `Float32`, `Float64` | `float`, or `float\|null` |
| `year()`, an unsigned `integer()`, `tinyInteger()`, `smallInteger()` or `mediumInteger()` | `UInt16`, `UInt8` to `UInt32` | `int<0, max>` |
| `ipv4()`, `ipv6()`, `date32()`, `timestampTz()`, `timeTz()` | `IPv4`, `IPv6`, `Date32`, `DateTime`, `Time` | `string` |
| `enum()` | `Enum` | the union of the names, also when the array gives the numbers |
| `rawColumn('total', 'UInt64')` | the type as given | the type of the table above |
| `array()`, `map()`, `vector()`, `geometry()`, `geography()` | `Array`, `Map`, the geo types | `mixed` |

- The other column methods keep the types of Larastan, which agree with the package: for example `string()` is
  `string`, `integer()` is `int`, `unsignedInteger()` is `int<0, max>` and `boolean()` is `bool`.
- `engine()`, `orderBy()`, `partitionBy()`, `sampleBy()`, `ttl()`, `settings()`, `comment()`, `replicated()`,
  `ifNotExists()`, `withoutOnCluster()` and `temporary()` add no column.
- A column with `ephemeral()` is not a property. A column with `virtualAs()` or `storedAs()` is nullable. See the
  notes above.
- `change()->renameTo('heading')` renames the column. `Schema::dropSync()` and `Schema::dropIfExistsSync()` drop the
  table.
- The migrations of other connections keep the rules of Larastan.

## Schema dumps

Larastan also reads the columns of the models from the schema dumps in `database/schema`. Its own parsers cannot read
the dump of a ClickHouse connection, and Larastan skips such a file without an error. The extension reads it:

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

- The first line of a dump names its connection: `-- ClickHouse schema of the connection: clickhouse`. A dump
  without this line is read as ClickHouse when it has the `ENGINE` clause of a ClickHouse table.
- Views, materialized views and dictionaries are read as tables.
- Larastan's parser reads the other dumps, such as the dump of MySQL, as before.
- Larastan reads the migrations after the dumps. A migration that creates a table replaces the columns that the dump
  gave. The extension gives both the same types.

## The blueprint

Declare `SchemaBlueprint` as the type of the parameter of a schema callback. Its methods return the types that name
the ClickHouse modifiers, so PHPStan knows them:

| Methods | Type | ClickHouse modifiers |
| --- | --- | --- |
| The column methods, such as `string()`, `array()` and `rawColumn()` | `SchemaColumnDefinition` | `lowCardinality()`, `codec()`, `ttl()`, `ephemeral()`, `timezone()`, `renameTo()` |
| `index()`, `rawIndex()`, `fullText()`, `vectorIndex()` | `SchemaIndexDefinition` | `granularity()`, `materialize()` |
| `drop()`, `dropIfExists()` | `SchemaDropCommand` | `sync()` |

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

- **The connection of a migration must be a constant.** With `Schema::connection($name)` or a `getConnection()`
  method, the extension cannot find the connection. Declare `SchemaBlueprint` as the type of the parameter of the
  callback, so that the callback is a ClickHouse callback on any connection.
- **Larastan reads only some calls.** As for the other connections, Larastan reads the calls on the blueprint
  variable in the callback, in its `if` statements and in the callbacks of `after()`. It does not read a column that
  a loop or another method adds.
- **Table names.** Larastan keys the tables by their name only. When a ClickHouse table and a table of another
  connection have the same name, the first dump in the order of the file names wins, and then the last migration
  that creates the table.
- **Arrays.** An `Array`, a `Map` or a `Tuple` column is `mixed`. Declare its type with a `@property` tag, such as
  `@property list<string> $tags`.
