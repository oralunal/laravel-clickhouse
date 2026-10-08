---
name: plc-upgrade-1x-to-2x
description: Upgrades a Laravel application from oralunal/phpclickhouse-laravel 1.x to 2.x. Updates the dependency, renames the bundled namespaces (Tinderbox\ClickhouseBuilder, PhpClickHouseSchemaBuilder, MyCLabs\Enum), imports the helper functions that are no longer global, replaces removed APIs, and verifies the result. Use when the user runs /plc-upgrade-1x-to-2x or asks to upgrade phpclickhouse-laravel to version 2.
---

# phpclickhouse-laravel 1.x → 2.x upgrade

You are upgrading this Laravel application from `oralunal/phpclickhouse-laravel`
1.x to 2.x. The SQL and DDL that the package generates do not change. What
changes is where the classes live, because 2.0 bundles the libraries it used to
depend on:

| 1.x dependency | 2.x namespace inside the package |
| --- | --- |
| `oralunal/clickhouse-builder` (`Tinderbox\ClickhouseBuilder\…`) | `PhpClickHouseLaravel\ClickhouseBuilder\…` |
| `glushkovds/php-clickhouse-schema-builder` (`PhpClickHouseSchemaBuilder\…`) | `PhpClickHouseLaravel\ClickhouseSchemaBuilder\…` |
| `myclabs/php-enum` (`MyCLabs\Enum\Enum`, used by the builder enums) | `PhpClickHouseLaravel\Enum\Enum` |

Work through the steps in order. Do not skip the checks, and do not stop at the
first file you fix: the goal is zero remaining references. If the package is
installed, the full human-readable guide is in
`vendor/oralunal/phpclickhouse-laravel/UPGRADE.md`; read it when something here
is unclear.

## Rules

- Only change what the upgrade requires. Do not reformat files or refactor
  unrelated code.
- Never edit anything under `vendor/`.
- Search the whole project, not just `app/`. Include `config/`, `database/`
  (migrations, seeders, factories), `routes/`, `tests/`, `bootstrap/` and any
  other directory that holds PHP code, such as `src/`, `modules/` or
  `packages/`. Exclude `vendor/`, `node_modules/` and `storage/`.
- When a change needs a decision only the user can make, stop and ask. The
  steps below say when.
- Do not commit unless the user asks you to.

## Step 1: Assess

1. Read `composer.json` and run `composer show oralunal/phpclickhouse-laravel`
   to see the required and installed versions. The target is 2.0.2 or later.
   2.0.0 lacks the bundled schema builder, so treat an installed 2.0.0 like
   1.x and do every step. With 2.0.1 installed, parallel test processes drop
   each other's ClickHouse tables: do step 2 to update and step 7, and the
   other steps only for what step 1's searches find.
2. Check `git status`. If the tree has uncommitted changes that are not yours,
   tell the user and suggest committing them or working on a new branch before
   you continue.
3. Run the test suite once (`php artisan test`, `vendor/bin/pest` or
   `vendor/bin/phpunit`, whichever the project uses) and record the result as
   the baseline. Tests that already failed before the upgrade are not yours to
   fix. Mention them in the final report.
4. Search for every pattern below and keep the list of matches. You will work
   through it in the next steps. The patterns are PCRE and work with
   `rg`/`grep -P` or your search tool; search `*.php` files, plus
   `composer.json` for the last one.

   - `Tinderbox\\ClickhouseBuilder`: query builder classes, enums, exceptions
     and the old Laravel integration.
   - `Tinderbox\\Clickhouse\\`: the Tinderbox HTTP client (`TempTable`,
     `File`, `Client`, …).
   - `PhpClickHouseSchemaBuilder`: schema builder classes, mostly in
     migrations.
   - `MyCLabs\\Enum`: the enum base class.
   - `(^|[^\w>:$\\])(raw|tp|array_flatten)\(`: calls to the formerly global
     `raw()`, `tp()` and `array_flatten()` helpers.
   - `into_memory_table\(|file_from\(|->addFile\(|->getFiles\(|->getValues\(`:
     removed helpers and builder methods.
   - `(where|orWhere|preWhere|orPreWhere|having|orHaving)(Global)?(Not)?In\(`:
     `IN` conditions to inspect in step 6.
   - `oralunal/clickhouse-builder|glushkovds/php-clickhouse-schema-builder|myclabs/php-enum|the-tinderbox/clickhouse-php-client`:
     direct requirements in `composer.json`.

   `raw(` preceded by `->`, `::` or `$`, such as `DB::raw()` or
   `$query->raw()`, is a method call, not the helper. The pattern already
   skips those; leave them alone.

## Step 2: Update the dependency

1. Set the constraint and update:

   ```bash
   composer require oralunal/phpclickhouse-laravel:^2.0.2 --with-all-dependencies
   ```

2. Remove these packages if `composer.json` requires them directly. The bundled
   copies replace them:

   ```bash
   composer remove oralunal/clickhouse-builder glushkovds/php-clickhouse-schema-builder
   ```

3. `myclabs/php-enum` and `the-tinderbox/clickhouse-php-client` are no longer
   installed with the package. If step 1 found application code that uses
   `MyCLabs\Enum` for its own enums, or the `Tinderbox\Clickhouse\` client
   directly, require that package explicitly, for example
   `composer require myclabs/php-enum`. Otherwise do nothing.

4. If `bootstrap/providers.php` or `config/app.php` lists
   `Tinderbox\ClickhouseBuilder\Integrations\Laravel\ClickhouseServiceProvider`,
   delete that line. The class no longer exists, so the app cannot boot while
   it is listed. `PhpClickHouseLaravel\ClickhouseServiceProvider` is
   auto-discovered and stays.

5. Run `php artisan package:discover` and `php artisan optimize:clear`.

   If these or any other artisan command fail with
   `Class "Tinderbox\ClickhouseBuilder\Integrations\Laravel\ClickhouseServiceProvider" not found`,
   Laravel's cached package manifest still lists the 1.x provider. This happens
   when Composer ran without scripts. Delete `bootstrap/cache/packages.php` and
   `bootstrap/cache/services.php` (Laravel regenerates them), then run
   `php artisan package:discover` again.

## Step 3: Rename the namespaces

Replace the prefixes in every PHP file found in step 1: `use` statements, fully
qualified names, PHPDoc types and strings that hold class names.

| Old prefix | New prefix |
| --- | --- |
| `Tinderbox\ClickhouseBuilder\` | `PhpClickHouseLaravel\ClickhouseBuilder\` |
| `PhpClickHouseSchemaBuilder\` | `PhpClickHouseLaravel\ClickhouseSchemaBuilder\` |

Only the prefix changes. Class names and the folder layout below it are
identical, for example:

- `PhpClickHouseSchemaBuilder\Tables\MergeTree` → `PhpClickHouseLaravel\ClickhouseSchemaBuilder\Tables\MergeTree`
- `PhpClickHouseSchemaBuilder\Expression` → `PhpClickHouseLaravel\ClickhouseSchemaBuilder\Expression`
- `Tinderbox\ClickhouseBuilder\Query\Expression` → `PhpClickHouseLaravel\ClickhouseBuilder\Query\Expression`
- `Tinderbox\ClickhouseBuilder\Query\Enums\Operator` → `PhpClickHouseLaravel\ClickhouseBuilder\Query\Enums\Operator`

Migration files are the most common place for `PhpClickHouseSchemaBuilder`,
especially closures typed as `fn (MergeTree $table) => …` passed to
`static::createMergeTree()`. Fix every migration, including ones that already
ran: they are loaded again on a fresh install, in CI and by `migrate:fresh`.

`MyCLabs\Enum\Enum`: the package's enums (`Operator`, `Format`, `JoinType`,
`JoinStrict`, `OrderDirection`) now extend `PhpClickHouseLaravel\Enum\Enum`.
Their constants and methods are unchanged. Only update code that type-checks a
package enum against `MyCLabs\Enum\Enum`, for example with `instanceof` or a
parameter type. Leave the application's own enums that extend
`MyCLabs\Enum\Enum` alone; step 2.3 keeps that package installed for them.

## Step 4: Import the helper functions

`raw()`, `tp()` and `array_flatten()` are no longer global. They are now
`PhpClickHouseLaravel\ClickhouseBuilder\raw()`, `…\tp()` and
`…\array_flatten()`.

For every file that calls one of them as a bare function, add the matching
import. Put it after the `namespace` line and with the other `use` statements;
in a file without a namespace, such as an anonymous migration or a route file,
put it right after `<?php`:

```php
use function PhpClickHouseLaravel\ClickhouseBuilder\raw;
```

Before you add the import, check that the call really used this helper and not
some other global function with the same name. `array_flatten()` in
particular may come from another helper package. If the project defines its
own global `raw()`, `tp()` or `array_flatten()`, ask the user which one each
call means.

## Step 5: Replace removed APIs

These parts of the 1.x builder only worked with the Tinderbox HTTP client.
This package never sent their files to ClickHouse, so they could not work in
1.x either.

| Removed | Replacement |
| --- | --- |
| `->addFile(...)`, `->getFiles()`, `->values(...)`, `->getValues()` on the builder | Insert rows with `Model::insertAssoc()`, `Model::insertBulk()` or `Model::buffer()` |
| `into_memory_table()`, `file_from()` | Same as above |
| `Tinderbox\ClickhouseBuilder\Query\Builder` (Tinderbox-client builder) | `Model::select()` / `Model::where()` or `DB::connection('clickhouse')->table(...)` |
| `Tinderbox\ClickhouseBuilder\Integrations\Laravel\Connection` / `Builder` | `PhpClickHouseLaravel\Connection` / `PhpClickHouseLaravel\Builder` |

If you find any of these, explain what the code tried to do and propose the
replacement. Then **ask the user before changing it**: the right fix depends on
what the data is.

`Column::subQuery()` and `Column::getSubQuery()` now return
`PhpClickHouseLaravel\ClickhouseBuilder\Query\BaseBuilder`. Update code that
type-hints the old `Query\Builder` return value.

## Step 6: Check `IN` conditions with a string value

In 1.x, `whereIn()` and its variants (`preWhereIn()`, `whereGlobalIn()`,
`havingIn()`, the `or…` and `…NotIn` forms) treated a string as a table name
only when that same builder had added a file with that name through
`addFile()`. Every other string was already quoted as a value, exactly as it
is in 2.x:

```php
$query->whereIn('id', 'ids'); // WHERE `id` IN 'ids' in 1.x and in 2.x
```

For each match from step 1 whose second argument is a string (a literal or a
variable holding one) rather than an array or a closure:

- **The same builder called `addFile()` with that name** (step 5): the string
  named a temporary table. Handle it together with the `addFile()` call from
  step 5, and ask the user. Where the table now exists in ClickHouse, the
  equivalent is `->whereIn('id', raw('table_name'))` (import `raw` as in
  step 4), or a sub-query closure such as
  `->whereIn('id', fn ($q) => $q->select('id')->from('table_name'))`.
- **Anything else: do not change it.** The compiled SQL is the same as in 1.x.
  If the string looks like a table name, the code was probably already wrong
  in 1.x. List it in the report as a possible existing bug, with the SQL it
  produces, but leave it as it is.

## Step 7: Tell the user about the `migrate:fresh` change

Check `config/database.php` and `.env` (`DB_CONNECTION`). If the default
connection is not a ClickHouse connection (`driver` is not `clickhouse`), but
some migrations set `protected $connection` to a ClickHouse connection, then
from 2.0 on `migrate:fresh` and `db:wipe` also empty those ClickHouse
databases. So does the `RefreshDatabase` test trait. In 1.x they were left
untouched.

Do not change code for this. Instead:

1. Find where `migrate:fresh` runs: the `RefreshDatabase` /
   `DatabaseMigrations` traits in `tests/`, `composer.json` scripts, CI
   workflows and deploy scripts.
2. Check which ClickHouse database the testing environment uses: look at
   `phpunit.xml`, `.env.testing` and the CI environment variables, for example
   `CLICKHOUSE_DATABASE`.
3. Tell the user which ClickHouse databases will be emptied in those places.
   If any of them looks shared or important, for example the same database as
   local development or production, warn them clearly and suggest a dedicated
   test database. Do not run `migrate:fresh` yourself.
4. If the tests run in parallel (`--parallel` in `composer.json` scripts or
   CI, or paratest), tell the user that each test process empties its own
   database, `<database>_test_<token>` (for example `analytics_test_1`),
   rather than the configured one. The ClickHouse user of the test
   environment must be allowed to create and drop databases.

## Step 8: Verify

Run all of these and fix what they report:

1. `composer dump-autoload` and `php artisan package:discover`. Both must
   finish without errors.
2. `php -l` on every file you changed.
3. Run every search from step 1 again.
   - The `Tinderbox\ClickhouseBuilder`, `PhpClickHouseSchemaBuilder`,
     removed-API and `composer.json` searches must return nothing.
   - The helper search still lists `raw(`, `tp(` and `array_flatten(` calls.
     Each file with such a call must now contain the matching
     `use function PhpClickHouseLaravel\ClickhouseBuilder\…;` import.
   - The only remaining `MyCLabs\Enum` matches are the application's own
     enums, with `myclabs/php-enum` required in `composer.json`.
   - The remaining IN conditions are the ones step 6 told you to leave.
4. `php artisan migrate:status`. This loads the migration files, so a missing
   class shows up here. If the database cannot be reached, run
   `php -l database/migrations/*.php` instead and say so in the report.
5. Run the test suite and compare it with the baseline from step 1. Every
   failure that is new must be fixed or explained.

## Step 9: Report

End with a short summary for the user:

- the version change in `composer.json`, and the packages you added or
  removed;
- how many files changed in each step, with the file list;
- every question you asked and the decision taken;
- the `migrate:fresh` notice from step 7, if it applies;
- the test results compared with the baseline, and any failures that already
  existed.
