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

### Names and values

- A table or column name that is not a plain identifier goes between backticks. A name that you quoted stays as it is.
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
