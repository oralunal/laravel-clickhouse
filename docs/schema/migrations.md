# Migrations

A ClickHouse migration is a Laravel migration. Set `protected $connection` to the ClickHouse connection, unless ClickHouse is the default connection.
See [Multiple connections](/advanced/multiple-connections).

There are two ways to create a table:

| Method | Use it for |
| --- | --- |
| `Schema::create()` | Laravel's column methods, with the ClickHouse table options. See [Laravel's schema builder](/schema/schema-builder). |
| `statement()`, `statementOnEveryNode()` | SQL that you write |

## Create a migration

`make:clickhouse-migration` is Laravel's `make:migration` for a ClickHouse connection:

```sh
php artisan make:clickhouse-migration create_events_table
```

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\SchemaBlueprint;

return new class extends Migration
{
    /**
     * The ClickHouse connection of the migration.
     *
     * @var string|null
     */
    protected $connection = 'clickhouse';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('events', function (SchemaBlueprint $table) {
            $table->uuid('id');
            $table->timestamps();

            $table->orderBy('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
```

- The command finds the table as `make:migration` does: from `--create=events`, from `--table=events`, or from a
  name such as `create_events_table` or `add_url_to_events_table`. `--table` writes `Schema::table()` in `up()` and
  `down()`. Without a table, `up()` and `down()` are empty.
- The migration sets `$connection` to the ClickHouse connection: the connection of `--database`, else the default
  connection when it is a ClickHouse connection, else `clickhouse`. A connection that is not a ClickHouse connection
  stops the command with an error.
- The callback gets a `SchemaBlueprint`, so that your editor and PHPStan know the ClickHouse methods. See
  [Static analysis](/reference/static-analysis#migrations).
- A new table has a UUID key, which `HasUuids` of the model fills in, and the key as its sorting key. Change the
  columns and the sorting key for your table: `orderBy()` decides how ClickHouse stores and reads the rows.
- `--path` and `--realpath` work as in `make:migration`.
- `php artisan make:clickhouse-model Event --migration` writes the model and its migration. See
  [Create a model](/models/defining-models#create-a-model).
- Laravel's `make:migration` writes a migration without a connection, whose callback gets Laravel's `Blueprint`. Set
  `$connection`, and declare `SchemaBlueprint` as the type of the parameter.

### The stubs

The commands write their files from these stubs:

| Stub | Command |
| --- | --- |
| `clickhouse.model.stub` | `make:clickhouse-model` |
| `clickhouse.migration.create.stub` | `make:clickhouse-migration` with a new table |
| `clickhouse.migration.update.stub` | `make:clickhouse-migration` with `--table` |
| `clickhouse.migration.stub` | `make:clickhouse-migration` without a table |

`php artisan stub:publish` copies them into the `stubs` directory of the application, with the stubs of Laravel.
`php artisan vendor:publish --tag=clickhouse-stubs` copies only these. A stub in the `stubs` directory replaces the
stub of the package. The placeholders are those of Laravel's `model.stub` and migration stubs, and
`{{ connection }}`, the name of the ClickHouse connection.

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

`change()`, `dropIndex()`, `dropFullText()`, `dropVectorIndex()` and `dropMorphs()` of the schema builder read the server when they compile, also with `--pretend`.
So do `time()`, `timeTz()`, `fullText()` and `vectorIndex()`, which read `system.settings`, and, in `Schema::table()`, an `index()` without a type when `default_index_type` is set.
See [Change a column](/schema/schema-builder#change-a-column).

## Migration commands

See [Migration commands](/schema/migration-commands).
