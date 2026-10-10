# Release notes

The [changelog](https://github.com/oralunal/laravel-clickhouse/blob/master/CHANGELOG.md#202---2026-10-08) has the full notes.

## 2.0.2

Released on 2026-10-08.

- **Fixed:** Parallel test runs (`php artisan test --parallel`, `pest --parallel`) do not drop the ClickHouse tables of other processes. Each process gets its own database on each secondary ClickHouse connection. See [Parallel tests](/2.x/schema/migration-commands#parallel-tests).
- **Fixed:** Parallel test runs with ClickHouse as the default connection failed with `UNKNOWN_DATABASE`. The schema builder supports `Schema::createDatabase()` and `Schema::dropDatabaseIfExists()`.

## 2.0.1

Released on 2026-10-08. 2.0.1 completes 2.0. Upgrade from 1.x to 2.0.2.

- **Breaking:** The schema builder of `Migration::createMergeTree()` is in the package, in `PhpClickHouseLaravel\ClickhouseSchemaBuilder`. It is a copy of `glushkovds/php-clickhouse-schema-builder` 1.1.1.
- **Breaking:** The enums of the builder extend `PhpClickHouseLaravel\Enum\Enum`, a copy of `myclabs/php-enum` 1.8.5.
- **Changed:** When MySQL or PostgreSQL has the `migrations` table, `migrate:fresh` also empties the ClickHouse databases of your migrations and of the dumps in `database/schema`.
- **Added:** `php artisan clickhouse:install-skills` and the `plc-upgrade-1x-to-2x` skill.
- **Added:** `php artisan migrate:fresh` and `php artisan db:wipe` work on ClickHouse connections.
- **Added:** Secondary ClickHouse connections follow the connection of the `migrations` table for `schema:dump`, `migrate` and `migrate:fresh`.
- **Removed:** The `glushkovds/php-clickhouse-schema-builder` and `myclabs/php-enum` dependencies.
- **Fixed:** `DB::connection('<name>')->table(...)` on a ClickHouse connection other than `clickhouse` sent its queries to the `clickhouse` connection.

## 2.0.0

Released on 2026-10-08. 2.0.0 does not contain the schema builder and the enum base class. Use 2.0.2.

- **Breaking:** The query builder is in the package, in `PhpClickHouseLaravel\ClickhouseBuilder`. The `oralunal/clickhouse-builder` dependency and the `Tinderbox\ClickhouseBuilder` namespace are gone.
- **Breaking:** `raw()`, `tp()` and `array_flatten()` are in the `PhpClickHouseLaravel\ClickhouseBuilder` namespace, not global.
- **Removed:** The parts of the old builder for `the-tinderbox/clickhouse-php-client`: `addFile()`, `values()`, `getValues()`, `getFiles()` and the integration classes.
- **Fixed:** `Column::subQuery()` and `Column::getSubQuery()` return a `BaseBuilder`. A sub-query in a column threw a `TypeError`.

To upgrade from 1.x, see [Upgrade](/2.x/getting-started/upgrading#upgrade-from-1-x-to-2-0).
