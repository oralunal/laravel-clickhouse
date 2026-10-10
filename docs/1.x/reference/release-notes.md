# Release notes

The [changelog](https://github.com/oralunal/laravel-clickhouse/blob/master/CHANGELOG.md#150---2026-10-08) has the full notes.

## 1.5.0

Released on 2026-10-08.

- **Added:** `php artisan schema:dump` works on ClickHouse connections, with `--prune`, `--path` and `--without-migration-data`. It uses HTTP only.
- **Added:** `php artisan migrate` loads `database/schema/<connection>-schema.sql` into a ClickHouse database without migrations. Then it runs only the newer migrations.
- **Fixed:** `Schema::drop()` and `Schema::dropIfExists()` did nothing on a ClickHouse connection. Now they send `DROP TABLE` and `DROP TABLE IF EXISTS`.

## 1.4.0

Released on 2026-08-20.

- **Fixed:** The package reads a published `config/clickhouse.php`. The order of precedence is `config/database.php`, the published `config/clickhouse.php`, then the defaults of the package. Examine a file that you published before 1.4.0.
- **Fixed:** `insertBulk()` applies the cast of the first column.
- **Fixed:** `flushBuffer()` puts the keys of the buffered rows in the order of the first row. A row with other keys still fails.
- **Documentation:** The `cluster_name` option, and `->ifNotExists()` with it.

## 1.3.0

Released on 2026-08-20.

- **Changed:** The package shares internal code between `delete()`, `update()` and `insert()`, and between `where()` and `whereRaw()` of models. The public API and the SQL do not change.
- **Changed:** An override of `resolveConnection()` or `getThisClient()` applies to all requests of a model.

## 1.2.0

Released on 2026-05-07.

- **Added:** Inserts in a buffer in PHP memory: `buffer()`, `flushBuffer()`, `bufferCount()`, `getBufferedRows()`, `clearBuffer()` and `BaseModel::flushAllBuffers()`. See [Insert rows](/1.x/models/inserting-rows#buffer-in-php-memory).
- **Added:** The package sends the buffered rows at the end of the script.

## 1.1.0

Released on 2026-04-17.

- **Added:** Package auto-discovery. You do not have to add the service provider.
- **Added:** Many connections in the published `config/clickhouse.php`. Each entry becomes `config('database.connections.<name>')`.
- **Changed:** `fix_default_query_builder` is `true` in the packaged config.

## 1.0.0

Released on 2026-04-09.

- A fork of [glushkovds/phpclickhouse-laravel](https://github.com/glushkovds/phpclickhouse-laravel) 2.5.2.
- It needs PHP 8.5 and Laravel 13.
