# Migrations

A ClickHouse migration extends `PhpClickHouseLaravel\Migration`. Its connection is `clickhouse`.
For another connection, set `protected $connection`. See [Multiple connections](/1.x/advanced/multiple-connections).

## Write SQL

```php
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

## createMergeTree()

`createMergeTree()` creates a MergeTree table from a PHP definition:

```php
use PhpClickHouseSchemaBuilder\Expression;
use PhpClickHouseSchemaBuilder\Tables\MergeTree;

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

For another engine of the MergeTree family, give its name and parameters to `engine()`:

```php
use PhpClickHouseSchemaBuilder\Engine;

static::createMergeTree('my_table', fn(MergeTree $table) => $table
    ->columns([
        $table->uInt32('id'),
        $table->uInt64('version'),
    ])
    ->orderBy('id')
    ->engine(Engine::REPLACING_MERGE_TREE, 'version')
);
```

Read the rows without duplicates with `MyTable::select()->final()`, or merge them with `MyTable::optimize(true)`.

::: warning
On a [cluster](/1.x/advanced/clusters) connection, 1.x creates a `ReplacingMergeTree` table that is not replicated, so the nodes do not share its rows. 3.0.0 makes it `ReplicatedReplacingMergeTree`.
On 1.x, write the `CREATE TABLE` statement of a replicated table with `write()`.
:::

1.x writes the names, comments, string defaults and enum values of `createMergeTree()` into the SQL without escapes. A quote, a backslash or a space in them causes a syntax error. 4.0 escapes them.

## On a cluster

- `write()` and `createMergeTree()` send the statement to all nodes, one after the other.
- With `cluster_name`, `createMergeTree()` adds `ON CLUSTER`. Add `->ifNotExists()`. See [Clusters](/1.x/advanced/clusters#on-cluster).

## Migration commands

See [Migration commands](/1.x/schema/migration-commands).
