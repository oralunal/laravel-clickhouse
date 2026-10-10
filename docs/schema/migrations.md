# Migrations

A ClickHouse migration extends `Oralunal\LaravelClickHouse\Migration`. Its connection is `clickhouse`.
For another connection, set `protected $connection`. See [Multiple connections](/advanced/multiple-connections).

There are three ways to create a table:

| Method | Use it for |
| --- | --- |
| `Migration::write()` | SQL that you write |
| `Migration::createMergeTree()` | A MergeTree table from a PHP definition |
| `Schema::create()` | Laravel's column methods. See [Laravel's schema builder](/schema/schema-builder). |

## Write SQL

```php
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

To create a table exactly as you write it, use `write()`.

## createMergeTree()

```php
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

For another engine of the MergeTree family, give its name and parameters to `engine()`:

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

On a cluster connection, the engine becomes `ReplicatedReplacingMergeTree`. Read the deduplicated rows with `MyTable::select()->final()`, or merge them with `MyTable::optimize(true)`.

### Column types

| Method | Type |
| --- | --- |
| `int8()`, `int16()`, `int32()`, `int64()`, `int128()`, `int256()` | `Int8` … `Int256` |
| `uInt8()`, `uInt16()`, `uInt32()`, `uInt64()`, `uInt128()`, `uInt256()` | `UInt8` … `UInt256` |
| `integer($name, $bits = 32, $withSign = true)` | `Int<bits>`, or `UInt<bits>` without a sign |
| `float32()`, `float64()`, `float($name, $bits = 32)` | `Float32`, `Float64` |
| `decimal($name, $precision, $scale)` | `Decimal(precision, scale)` |
| `decimal32()`, `decimal64()`, `decimal128()`, `decimal256()` with `($name, $scale)` | `Decimal32(scale)` … `Decimal256(scale)` |
| `bool()` | `Bool` |
| `string()` | `String` |
| `uuid()` | `UUID` |
| `date()` | `Date` |
| `datetime($name, $precision = null, $timezone = null)` | `DateTime`, or `DateTime64(precision)` with a precision above 0. A time zone is added: `DateTime64(3, 'UTC')`. |
| `enum($name, $values)` | `Enum('new' = 1, 'done' = 2)` for `['new' => 1, 'done' => 2]` |
| `column($name, $type, $typeParams = [])` | Another type, as given: `column('tags', 'Array(String)')`, `column('city', 'LowCardinality(String)')` |

- `$typeParams` are string literals: `column('at', 'DateTime', ['UTC'])` gives `DateTime('UTC')`. For a type in a type, write the full type in `$type`.
- `nullable()` makes the type `Nullable(...)`. `default($value)` adds `DEFAULT`. A string is a string literal, and `new Expression('now()')` is SQL. `comment($text)` adds `COMMENT`.

### Table methods

| Method | Adds |
| --- | --- |
| `columns(array $columns)` | The columns |
| `orderBy(...$columns)` | `ORDER BY (...)` |
| `partition($expression)` | `PARTITION BY expression` |
| `ttl($column, $interval)` | `TTL column + INTERVAL interval`: `ttl('at', '1 YEAR')` |
| `settings(array $settings)` | `SETTINGS name = value, ...`. Strings are string literals, booleans are `1` and `0`, and `null` leaves a setting out. |
| `engine($engine, ...$params)` | `ENGINE = <engine>(params)`. The parameters are SQL. `Engine::MERGE_TREE` is the default, and `Engine::REPLACING_MERGE_TREE` is the other constant. |
| `ifNotExists()` | `IF NOT EXISTS` |
| `onCluster($cluster)` | `ON CLUSTER 'cluster'`. `createMergeTree()` sets it from `cluster_name`. |
| `dbName($database)` | The database of the replica path. `createMergeTree()` sets it from the connection. |

`compile()` of the table returns its `CREATE TABLE` statement, and `compile()` of a column returns its definition.
`orderBy()`, `partition()` and `ttl()` take SQL. `getEngine()` returns the `Engine`. In the callback, `$table->getEngine()->replicated(false)` keeps a plain engine on a cluster connection.
A replicated engine on a cluster gets this replica path: `ReplicatedMergeTree('/clickhouse/tables/analytics.events', '{replica}')`.

### Names and values

- A table or column name that is not a plain identifier goes between backticks. A name that you quoted stays as it is.
- A name that ClickHouse reads as a keyword, such as `id`, `name` or `date`, goes between double quotes: `"id" UInt64`.
- A dot in the table name separates the database: `analytics.events`. For a table name with a dot, quote it: ``createMergeTree('`my.events`', ...)``.
- The package escapes comments, string defaults, enum values and string settings. Give them without escapes:
  `$table->string('my col')->default("it's")` gives `` `my col` String DEFAULT 'it\'s' ``.
- `enum()` reads its array as in [Laravel's schema builder](/schema/schema-builder#enums). For names that are all numbers, write the type: `column('status', "Enum8('200' = 1, '404' = 2)")`.

## On a cluster

- `write()` and `createMergeTree()` send the statement to all nodes, one after the other.
- With `cluster_name`, `createMergeTree()` adds `ON CLUSTER`. Add `->ifNotExists()`, because the next nodes send the same statement. See [Clusters](/advanced/clusters#statements-on-all-nodes).

## Pretend

`php artisan migrate --pretend` logs the statements of `write()` and `createMergeTree()` under the name of the migration and sends nothing.
They return a statement whose `isError()` is `false` and whose `rows()` is `[]`.

## Migration commands

See [Migration commands](/schema/migration-commands).
