# Migration commands

## Squash migrations with schema:dump

Laravel's `schema:dump` works on ClickHouse connections. You do not need another command or the `clickhouse-client` program:

```sh
php artisan schema:dump --database=clickhouse

# Dump the schema and delete all migration files
php artisan schema:dump --database=clickhouse --prune
```

- The command writes `database/schema/clickhouse-schema.sql`. It has one `CREATE` statement for each table, dictionary, view and materialized view. Each object comes after the objects that it reads.
- When the `migrations` table is on this connection, its rows are at the end. `--without-migration-data` leaves them out.
- A `<database>.<table>` reference to the database of the connection loses its database prefix. Thus you can load the file into a database with another name. The package does not change an engine argument that gives the database as a string, such as `Buffer('analytics', 'events', ...)`.
- `--prune` is Laravel's behavior. It deletes all of `database/migrations`, also the migrations of other connections.

When `php artisan migrate` runs on a ClickHouse database without migrations, it loads the dump first. Then it runs only the migrations that are newer than the dump.
On a `cluster` connection, the statements go to all nodes, as the statements of migrations do.

## The connection of the migrations table

Laravel's `migrate`, `migrate:fresh` and `schema:dump` act on one connection: the default connection, or the connection of `--database`.
This connection also has the `migrations` table. This page calls it the *primary* connection.

### ClickHouse is the primary connection

For example, `DB_CONNECTION=clickhouse`, or `--database=analytics` for a ClickHouse connection with another name. All commands work:

```sh
php artisan migrate --database=analytics
php artisan schema:dump --database=analytics --prune
php artisan migrate:fresh --database=analytics
```

If the ClickHouse connection does not have the name `clickhouse`, set it on the migrations: `protected $connection = 'analytics';`.

::: warning
On 2.x, `migrate:rollback` and `migrate:refresh --step` fail with `Call to undefined method ClickHouseDB\Statement::all()` when the `migrations` table is on a ClickHouse connection. 4.0 fixes this.
:::

### ClickHouse is a secondary connection

2.0.1 and later support secondary connections. 2.0.0 does not.

For example, MySQL is the default connection, and some migrations write to ClickHouse. A ClickHouse connection is *secondary* when:

- a migration in `database/migrations`, or in a path of `loadMigrationsFrom()`, sets `$connection` to it; or
- `database/schema` has a dump for it.

The secondary connections follow the primary connection:

| Command | Secondary ClickHouse connections |
| --- | --- |
| `php artisan schema:dump [--prune]` | Each one is dumped to `database/schema/<connection>-schema.sql` |
| `php artisan migrate`, when it loads the primary dump | An empty connection loads its dump. Without a dump, its migrations run again. A connection with tables does not change. |
| `php artisan migrate:fresh` | Each one becomes empty. Then its dump loads, or its migrations run again. |

The package does not touch a ClickHouse connection that no migration or dump refers to.

`migrate:fresh` and `db:wipe` drop all tables, materialized views, views and dictionaries of the ClickHouse database, on all nodes, in an order that ClickHouse accepts.
They also drop views without `--drop-views`, because the dump and the migrations create the views again.

## Parallel tests

This section applies to 2.0.2. In 2.0.1, all test processes use the configured ClickHouse database, so the `migrate:fresh` of one process drops the tables of another process.

With `php artisan test --parallel` or `pest --parallel`, each test process gets its own database on each secondary ClickHouse connection, as Laravel does for the default connection.

- The name of the database is `<database>_test_<token>`, for example `analytics_test_1`.
- A process creates the database for its first test case that uses `RefreshDatabase`, `LazilyRefreshDatabase`, `DatabaseMigrations`, `DatabaseTransactions` or `DatabaseTruncation`. All test cases of the process use it.
- `--recreate-databases` drops these databases before the run. `--drop-databases` drops them after the run. `--without-databases` keeps the configured database.
- When ClickHouse is the primary connection, Laravel changes its database to `<database>_test_<token>`.

The ClickHouse user must have the permission to create and drop databases. Test runs without `--parallel` use the configured database.
