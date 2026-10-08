# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-10-08

Everything the package used to pull in from other ClickHouse and enum
libraries now ships inside it, under its own namespaces. Apart from Laravel,
the only remaining dependency is `smi2/phpclickhouse`.

### Changed

- **Breaking:** The query builder now ships inside this package under the `PhpClickHouseLaravel\ClickhouseBuilder` namespace. It no longer comes from the `oralunal/clickhouse-builder` dependency, and the `Tinderbox\ClickhouseBuilder` namespace is gone. The SQL it generates is unchanged.
- **Breaking:** The schema builder used by `Migration::createMergeTree()` now ships inside this package under the `PhpClickHouseLaravel\ClickhouseSchemaBuilder` namespace. It is copied from `glushkovds/php-clickhouse-schema-builder` 1.1.1, which is no longer installed. The DDL it generates is unchanged. Migrations that import `PhpClickHouseSchemaBuilder\Tables\MergeTree` or `PhpClickHouseSchemaBuilder\Expression` must switch to the new namespace.
- **Breaking:** The builder's enums (`Operator`, `Format`, `JoinType`, `JoinStrict`, `OrderDirection`) now extend `PhpClickHouseLaravel\Enum\Enum`, which is copied from `myclabs/php-enum` 1.8.5. They used to extend `MyCLabs\Enum\Enum`, and `myclabs/php-enum` is no longer installed. Their constants and methods are unchanged.
- **Breaking:** The helper functions `raw()`, `tp()` and `array_flatten()` are no longer global. They now live in the `PhpClickHouseLaravel\ClickhouseBuilder` namespace.
- **Behavior change:** if MySQL or PostgreSQL holds the `migrations` table, `migrate:fresh` now also empties the ClickHouse databases your migrations write to, along with any ClickHouse database that has a dump in `database/schema`. Before, it left them untouched. Migrations written with `CREATE TABLE IF NOT EXISTS` therefore kept their ClickHouse data across `migrate:fresh`; that data is now dropped. The command keeps its production confirmation.

### Added

- `php artisan migrate:fresh` and `php artisan db:wipe` now work on ClickHouse connections. Previously they failed with `This database driver does not support dropping all tables`. `Schema::dropAllTables()` drops every table, materialized view, view and dictionary on every node, ordering the drops so that ClickHouse accepts them. Views are dropped too, so the dump and the migrations can recreate them. `Schema::dropAllViews()` drops only views and materialized views.
- Secondary ClickHouse connections now follow the connection that holds the `migrations` table. A ClickHouse connection is secondary when a migration in the migrator's paths targets it or when `database/schema` has a dump for it.
  - `schema:dump` also writes `database/schema/<connection>-schema.sql` for each secondary connection.
  - When `migrate` loads the primary dump, each empty secondary connection is loaded from its own dump. If a secondary has no dump, its migrations are marked as pending again and run.
  - `migrate:fresh` empties the secondary connections as well.
  - ClickHouse connections that no migration or dump refers to are never touched.

### Removed

- **Breaking:** The parts of the old builder that needed the `the-tinderbox/clickhouse-php-client` HTTP client are not included, and that client is no longer installed. This removes `BaseBuilder::addFile()`, `values()`, `getValues()` and `getFiles()`, the `into_memory_table()` and `file_from()` helpers, the client-based `Query\Builder`, and the old builder's Laravel integration. This package's own `Builder` never sent those files to ClickHouse, so these methods could not work here. `whereIn()`, `preWhereIn()`, `whereGlobalIn()` and `havingIn()` used to turn a string that matched a temporary file into a table reference. They now always treat a string as a value. Because no file could be added, that branch never ran.
- The old builder's auto-discovered `ClickhouseServiceProvider` no longer gets installed. It registered a second `clickhouse` database driver with its own `Connection` class. This package's driver only took effect because its provider happened to boot later.
- The `oralunal/clickhouse-builder`, `glushkovds/php-clickhouse-schema-builder` and `myclabs/php-enum` dependencies.

### Fixed

- `DB::connection('<name>')->table(...)` on a ClickHouse connection other than `clickhouse` ran its queries against the `clickhouse` connection. Models with a non-default `$connection` logged their queries there as well. Because of this, `migrate --database=<name>` read and wrote the wrong `migrations` table. Query builders are now bound to the connection that created them.
- `Column::subQuery()` and `Column::getSubQuery()` declared the client-based `Query\Builder` as their return type. Building a column sub-query from this package's `Builder` therefore threw a `TypeError`. They now return `BaseBuilder`.

### Upgrade note

- See [UPGRADE.md](UPGRADE.md) for the full guide.
- Replace `Tinderbox\ClickhouseBuilder\` with `PhpClickHouseLaravel\ClickhouseBuilder\`, and `PhpClickHouseSchemaBuilder\` with `PhpClickHouseLaravel\ClickhouseSchemaBuilder\`, in your `use` statements and migrations.
- If you called the global `raw()`, import it with `use function PhpClickHouseLaravel\ClickhouseBuilder\raw;`, or use `new RawColumn(...)` or `new Expression(...)` instead.
- If your app requires `oralunal/clickhouse-builder` or `glushkovds/php-clickhouse-schema-builder` directly, remove it; the bundled copies replace them. If your own code uses `myclabs/php-enum`, require it yourself.

## [1.5.0] - 2026-10-08

### Added

- `php artisan schema:dump` now works on ClickHouse connections, including `--prune`, `--path` and `--without-migration-data`. Previously it failed with a `BadMethodCallException` because the connection had no schema state. The dump uses HTTP only, so no `clickhouse-client` binary is needed. It lists tables, dictionaries, views and materialized views in dependency order and appends the rows of the migrations table. References written as `<database>.<table>` lose the database prefix when they point at the connection's own database, so you can load the file into a database with a different name.
- `php artisan migrate` loads `database/schema/<connection>-schema.sql` into a ClickHouse database where no migrations have run yet, then runs only the newer migrations. On a `cluster` connection, the statements go to every node, the same way `Migration::write()` does. `Connection::getSchemaState()` exposes the same loader for apps where ClickHouse is not the default connection.

### Fixed

- `Schema::drop()` and `Schema::dropIfExists()` on a ClickHouse connection used to do nothing, without any error, because the schema grammar could not compile them. They now run `DROP TABLE` and `DROP TABLE IF EXISTS`. A migration whose `down()` relied on these calls now really drops the table.

## [1.4.0] - 2026-08-20

### Fixed

- A published `config/clickhouse.php` is now actually read. The service provider only ever loaded its own bundled copy, so `php artisan vendor:publish --tag=clickhouse-config` followed by editing the file silently did nothing — including the documented way to declare a second ClickHouse connection. Precedence is now `config/database.php` > published `config/clickhouse.php` > packaged defaults.
- `insertBulk()` no longer drops the cast for the first column. `array_search()` returns index `0` for it, which the truthiness check treated as "not found", so a `boolean` cast on the first column was skipped and the raw PHP value reached ClickHouse (typically failing with `Cannot parse string 'false' as UInt8`).
- `flushBuffer()` no longer throws `Fields not match` when rows were buffered one at a time carrying the same keys in different orders. Buffered rows are reordered to the first row's key order at flush time. A row whose key *set* differs is left untouched, so a genuinely mismatched buffer still fails loudly instead of shipping fabricated column values.

### Upgrade note

- If you published `config/clickhouse.php` before this release and edited it, those edits had no effect and may have been worked around elsewhere (for example in `.env` or `config/database.php`). They now take effect, ranking above the packaged defaults and below `config/database.php`. Review the published file before deploying.

### Documentation

- Corrected the derived table name (`MyTable` resolves to `my_tables`, not `my_table`), the events section (only `create()` fires `creating`/`created`; `save()` fires just `saved`), the `InsertArray` example (`insertAssoc()` takes `column => value` rows), and the `$tableSources` comment (`UPDATE` and `TRUNCATE` target it as well).
- Added the missing `PhpClickHouseSchemaBuilder` imports to the Schema Builder migration example, which fataled when copied as written.
- Documented the `cluster_name` connection key, which `Migration::createMergeTree()` reads to emit `ON CLUSTER` and which appeared in no documentation — including the fact that it requires `->ifNotExists()`, since migrations are dispatched to each node in turn and `ON CLUSTER` already creates the table everywhere on the first dispatch.

## [1.3.0] - 2026-08-20

### Changed

- Consolidated duplicated internal scaffolding into shared helpers; public API and generated SQL are unchanged:
  - `Builder`: `delete()`, `update()`, and `insert()` resolve their target table through one protected `getTableForWrites()` helper (previously three copies, one with a divergent string cast).
  - `BaseModel`: `where()` and `whereRaw()` build their entry query through a shared protected static `newSourcedQuery()` helper; `select()` deliberately keeps its previous behavior (no sources table attached).
  - `Migration::createMergeTree()` reads the database name and cluster settings from the resolved `Connection` instead of re-fetching them from the global `config()` repository.
  - `WithClient::getThisClient()` now routes through `resolveConnection()`, and the deprecated static `getClient()` delegates to `getThisClient()`. For subclasses that override `resolveConnection()` or `getThisClient()`, the override now consistently applies to every client acquisition; previously some internal paths bypassed it.

## [1.2.0] - 2026-05-07

### Added

- In-memory buffered inserts on `BaseModel`: `buffer()` accumulates rows per-model and `flushBuffer()` sends them to ClickHouse as a single `insertAssocBulk` HTTP request. The `$casts` pipeline used by `insertAssoc()` is reused, so cast behavior is identical. On flush failure the buffer is preserved so the caller can retry.
- Helper methods alongside the buffer API: `bufferCount()`, `getBufferedRows()`, `clearBuffer()`, and `BaseModel::flushAllBuffers()`.
- Auto-flush on script shutdown: the service provider registers a Laravel `terminating` callback plus a `register_shutdown_function` fallback for non-HTTP scripts. Errors during auto-flush are reported via `report()` rather than thrown.

### Changed

- Extracted the row-normalization + cast loop from `insertAssoc()` into a shared `prepareAssocRowsForInsert()` helper so manual and buffered inserts go through the same path. Public behavior of `insertAssoc()` is unchanged.

## [1.1.0] - 2026-04-17

### Added

- Package auto-discovery — the service provider registers itself via `extra.laravel.providers`, so no manual entry in `config/app.php` / `bootstrap/providers.php` is needed.
- Multi-connection support in the publishable `config/clickhouse.php`. Each entry is merged into `config('database.connections.<name>')`; user-supplied values always win.
- Unit-level test coverage for `QueryGrammar`, `SchemaGrammar`, and `Builder` SQL generation — these run without Docker / ClickHouse.
- GitHub Actions CI workflow against real ClickHouse service containers, with cluster tests gated off in CI.

### Changed

- `fix_default_query_builder` is enabled by default in the shipped config.
- Development flow migrated to **Orchestra Testbench** + `workbench/` skeleton. `vendor/bin/testbench <artisan-command>` works in the repo root, and **Laravel Boost** is wired via `composer boost`.
- Replaced the `tests.bootstrap.sh` + `bitnami/laravel` container flow with a slimmed-down `docker-compose.test.yaml` that only runs ClickHouse + Zookeeper.

### Removed

- Legacy `.travis.yml` and `.github/workflows/test.yml`.

## [1.0.0] - 2026-04-09

### Changed

- Forked from [glushkovds/phpclickhouse-laravel](https://github.com/glushkovds/phpclickhouse-laravel) at 2.5.2.
- Minimum PHP 8.5, Laravel 13+ only.

[Unreleased]: https://github.com/oralunal/phpclickhouse-laravel/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/oralunal/phpclickhouse-laravel/compare/v1.5.0...v2.0.0
[1.5.0]: https://github.com/oralunal/phpclickhouse-laravel/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/oralunal/phpclickhouse-laravel/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/oralunal/phpclickhouse-laravel/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/oralunal/phpclickhouse-laravel/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/oralunal/phpclickhouse-laravel/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/oralunal/phpclickhouse-laravel/releases/tag/v1.0.0
