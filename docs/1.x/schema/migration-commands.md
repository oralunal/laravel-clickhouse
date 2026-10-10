# Migration commands

`migrate`, `migrate:rollback`, `migrate:status` and the other migration commands run the migrations of ClickHouse connections.

::: warning
On 1.x:

- `migrate:fresh` and `db:wipe` fail on ClickHouse connections with `This database driver does not support dropping all tables`. 2.0.1 adds them.
- Before 1.5.0, `Schema::drop()` and `Schema::dropIfExists()` do nothing on a ClickHouse connection. In a migration, use `static::write('DROP TABLE ...')`.
- `migrate:rollback` and `migrate:refresh --step` fail with `Call to undefined method ClickHouseDB\Statement::all()` when the `migrations` table is on a ClickHouse connection. 4.0 fixes this.
:::

## Squash migrations with schema:dump

From 1.5.0, Laravel's `schema:dump` works on ClickHouse connections. You do not need another command or the `clickhouse-client` program:

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

`migrate` loads only the dump of the connection that has the `migrations` table: the default connection, or the connection of `--database`.

When MySQL or PostgreSQL has the `migrations` table, run `schema:dump` for both connections. Then load the ClickHouse dump after the main schema. The ClickHouse database must be empty at that time:

```php
use Illuminate\Database\Events\SchemaLoaded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

Event::listen(function (SchemaLoaded $event) {
    $path = database_path('schema/clickhouse-schema.sql');

    if ($event->connection->getName() !== 'clickhouse' && is_file($path)) {
        DB::connection('clickhouse')->getSchemaState()->load($path);
    }
});
```

2.0.1 does this for you, and also empties these ClickHouse connections in `migrate:fresh`. See [the 2.x documentation](/2.x/schema/migration-commands#the-connection-of-the-migrations-table).
