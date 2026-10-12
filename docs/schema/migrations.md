# Migrations

A ClickHouse migration is a Laravel migration. Set `protected $connection` to the ClickHouse connection, unless ClickHouse is the default connection.
See [Multiple connections](/advanced/multiple-connections).

There are two ways to create a table:

| Method | Use it for |
| --- | --- |
| `Schema::create()` | Laravel's column methods, with the ClickHouse table options. See [Laravel's schema builder](/schema/schema-builder). |
| `statement()`, `statementOnEveryNode()` | SQL that you write |

## The schema builder

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\SchemaBlueprint;

return new class extends Migration
{
    protected $connection = 'clickhouse';

    public function up(): void
    {
        Schema::create('events', function (SchemaBlueprint $table) {
            $table->unsignedInteger('id');
            $table->dateTime('created_at', 3)->default(DB::raw('now64()'));
            $table->string('name');
            $table->integer('amount');
            $table->orderBy('id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
// CREATE TABLE `events` (`id` UInt32, `created_at` DateTime64(3) DEFAULT now64(), `name` String,
//   `amount` Int32) ENGINE = MergeTree() ORDER BY (`id`)
```

- In a migration, the `Schema` facade uses the connection of the migration.
- Declare the type `SchemaBlueprint` in the callback, so that your editor knows the ClickHouse methods, such as `orderBy()`, `engine()` or `settings()`.

For another engine of the MergeTree family, give it to `engine()`:

```php
Schema::create('events', function (SchemaBlueprint $table) {
    $table->unsignedInteger('id');
    $table->unsignedBigInteger('version');
    $table->engine('ReplacingMergeTree(version)');
    $table->orderBy('id');
});
// CREATE TABLE `events` (`id` UInt32, `version` UInt64) ENGINE = ReplacingMergeTree(version) ORDER BY (`id`)
```

Read the deduplicated rows with `Event::query()->final()`, or merge them with `Event::optimize(true)`.

## Write SQL

To create a table exactly as you write it, send the SQL with the connection of the migration:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clickhouse';

    public function up(): void
    {
        DB::connection($this->connection)->statementOnEveryNode(<<<'SQL'
            CREATE TABLE events (
                id UInt32,
                created_at DateTime,
                name String,
                amount Int32
            )
            ENGINE = MergeTree()
            ORDER BY (id)
            SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statementOnEveryNode('DROP TABLE IF EXISTS events');
    }
};
```

| Method | Sends the SQL to |
| --- | --- |
| `statementOnEveryNode($sql)` | All nodes of the connection, one after the other. A connection without `cluster` has one node. |
| `statement($sql, $bindings)` | The active node |

- `statementOnEveryNode()` throws at the first node that fails. The nodes before it keep the change.
- The package sends the SQL as you write it. It adds no `ON CLUSTER` and no replicated engine.
- `statement()` writes its `?` bindings as ClickHouse literals. See [Raw SQL](/advanced/raw-sql).

## On a cluster

| Connection | `Schema::create()` | `statementOnEveryNode()` |
| --- | --- | --- |
| No `cluster_name` | The statement goes to the active node | The statement goes to all nodes, one after the other |
| `cluster_name` | The statement gets `ON CLUSTER '<cluster_name>'`. With the `cluster` nodes, a table of the MergeTree family gets a replicated engine: `ReplicatedMergeTree()`, `ReplicatedReplacingMergeTree(version)`. | The statement goes to all nodes. Write `ON CLUSTER` yourself, or use `statement()` for a statement with `ON CLUSTER`. |

- `$table->replicated(false)` keeps the engine as you give it. `$table->withoutOnCluster()` sends the statements to the active node only.
- A statement with `ON CLUSTER` reaches all hosts. Send it one time, with `statement()`. With `statementOnEveryNode()`, add `IF NOT EXISTS`, because the next nodes send the same statement.

See [The schema builder on a cluster](/advanced/clusters#the-schema-builder-on-a-cluster) and [Statements on all nodes](/advanced/clusters#statements-on-all-nodes).

## Pretend

`php artisan migrate --pretend` logs the statements of the schema builder, of `statement()` and of `statementOnEveryNode()` under the name of the migration, and sends nothing.

`change()` and `dropIndex()` of the schema builder read the server when they compile, also with `--pretend`. See [Change a column](/schema/schema-builder#change-a-column).

## Migration commands

See [Migration commands](/schema/migration-commands).
