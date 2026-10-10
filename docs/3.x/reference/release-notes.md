# Release notes

The [changelog](https://github.com/oralunal/laravel-clickhouse/blob/master/CHANGELOG.md#300---2026-10-08) has the full notes.

## 3.0.0

Released on 2026-10-08.

- **Breaking:** The package is `oralunal/laravel-clickhouse`, and the repository is `oralunal/laravel-clickhouse`. 2.0.2 is the last release of `oralunal/phpclickhouse-laravel`.
- **Breaking:** The namespace is `Oralunal\LaravelClickHouse\`, not `PhpClickHouseLaravel\`. This includes `ClickhouseBuilder`, `ClickhouseSchemaBuilder`, `Enum` and the `raw()`, `tp()` and `array_flatten()` functions. The class names below the namespace do not change.
- **Added:** The `lc-upgrade-2x-to-3x` coding-agent skill. `php artisan clickhouse:install-skills` installs it.
- **Fixed:** On a `cluster` connection, `createMergeTree()` with `->engine(Engine::REPLACING_MERGE_TREE, 'version')` created a table that was not replicated. Now the table is `ReplicatedReplacingMergeTree`.

To upgrade from 2.x, see [Upgrade](/3.x/getting-started/upgrading).
