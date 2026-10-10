# Migration commands

`migrate`, `migrate:rollback`, `migrate:refresh`, `migrate:reset`, `migrate:status`, `migrate:fresh`, `db:wipe` and `schema:dump` work on ClickHouse connections.

## The connection that holds the migrations table

Laravel's `migrate`, `migrate:fresh` and `schema:dump` act on one connection: the default connection, or the connection of `--database`.
This page calls it the *primary* connection. It holds the `migrations` table.

### ClickHouse as the primary connection

For example, `DB_CONNECTION=clickhouse`, or `--database=analytics` for a ClickHouse connection with another name:

```sh
php artisan migrate --database=analytics
php artisan schema:dump --database=analytics --prune
php artisan migrate:fresh --database=analytics
```

If the ClickHouse connection does not have the name `clickhouse`, set it on the migrations: `protected $connection = 'analytics';`.

### The migrations table on ClickHouse

The package binds `Oralunal\LaravelClickHouse\ClickhouseMigrationRepository`, a subclass of Laravel's `DatabaseMigrationRepository`.
`ClickhouseMigrationRepository::fromLaravelRepository($repository)` makes it from Laravel's repository, with the same connection resolver, table and connection.
A repository that your application or another package binds stays. On other connections, the repository works as Laravel's.

| Operation | SQL |
| --- | --- |
| Create the table (`migrate:install`, the first `migrate`) | ``CREATE TABLE `migrations` (`id` Int32, `migration` String, `batch` Int32) ENGINE = MergeTree() ORDER BY (`id`)`` |
| Log a migration | `insert into "migrations" ("migration", "batch") settings insert_deduplication_token = ?, async_insert = 0 values (?, ?)` |
| Remove a migration (rollback) | `alter table "migrations" delete where "migration" = ? settings mutations_sync = 1` |
| Drop the table before a dump loads | ``DROP TABLE `migrations` SYNC`` |

- The repository reads the table with Laravel's query builder and returns the migrations as objects, whatever `fix_default_query_builder` is.
- `exact_integer_types` does not change the columns. The `engine` option applies only when it is `MergeTree`, `ReplicatedMergeTree` or `SharedMergeTree` with its arguments.
- The insert is synchronous, so the row is there when the next command reads the table. The token keeps a row that is identical to an earlier row, in a table that removes duplicate inserts.
- The rollback waits until the active node deletes the row, so `migrate:refresh` runs all rolled-back migrations again.
  The delete usually takes less than 20 ms. On ClickHouse 26.8 with little RAM, it can take more than 2 seconds: increase `timeout_query` for migration commands.
- With a `cluster_name`, the table is created `ON CLUSTER`, and is replicated when the connection lists its nodes:
  ``CREATE TABLE `migrations` ON CLUSTER 'company_cluster' (`id` Int32, `migration` String, `batch` Int32) ENGINE = ReplicatedMergeTree() ORDER BY (`id`) SETTINGS replicated_deduplication_window=0, replicated_deduplication_window_for_async_inserts=0``.
  The log and the deletes go to the active node without `ON CLUSTER`. A replicated table sends them to its replicas.

### ClickHouse as a secondary connection

For example, MySQL is the default connection and some migrations write to ClickHouse. A ClickHouse connection is secondary when:

- a migration in `database/migrations`, or in a path of `loadMigrationsFrom()`, sets `$connection` to it, or
- `database/schema` has a dump for it.

| Command | Secondary ClickHouse connections |
| --- | --- |
| `php artisan schema:dump [--prune]` | Each one is dumped to `database/schema/<connection>-schema.sql` |
| `php artisan migrate` (the primary dump loads) | An empty connection loads its dump. Without a dump, its migrations run again. A connection with tables stays as it is. |
| `php artisan migrate:fresh` | Each one is emptied, then loaded from its dump or migrated again |

The package does not empty or load a ClickHouse connection that no migration or dump refers to.

`migrate:fresh` and `db:wipe` drop all tables, materialized views, views and dictionaries of the database, on all nodes.
With a `cluster_name`, they send one `DROP ... ON CLUSTER` for each object. Without it, they send one `DROP` on each node.
Views are dropped also without `--drop-views`, because the dump and the migrations create them again.

## Squash migrations with schema:dump

```sh
php artisan schema:dump --database=clickhouse

# Dump the schema and delete all migration files
php artisan schema:dump --database=clickhouse --prune
```

- The command writes `database/schema/clickhouse-schema.sql`. It has one `CREATE` statement for each table, dictionary, view and materialized view, in an order that ClickHouse accepts.
- The rows of the `migrations` table are at the end. `--without-migration-data` removes them.
- A `<database>.<table>` reference to the database of the connection loses its database prefix, so you can load the file into a database with another name.
- `--prune` is Laravel's behavior. It deletes all of `database/migrations`, also the migrations of other connections.

When `migrate` runs on a ClickHouse database without migrations, it loads the dump first. Then it runs only the migrations that are newer than the dump.

| Connection | How the dump loads |
| --- | --- |
| No `cluster_name` | Each statement goes to all nodes, one after the other |
| `cluster_name` | Each `CREATE` gets `ON CLUSTER '<cluster_name>'` and goes one time. Rows of a replicated table go one time. Rows of other tables go to all nodes. |

Each `INSERT INTO <table> VALUES` of the dump is sent with `SETTINGS async_insert = 0`. The dump file does not change.

`Connection::getSchemaState()` returns the `Oralunal\LaravelClickHouse\SchemaState` that writes and loads the dump. Its `dump()` and `load()` methods do the work of the commands.
With a `cluster_name`, each statement waits for all hosts, so increase `timeout_query` for the load.
