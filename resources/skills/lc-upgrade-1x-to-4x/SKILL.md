---
name: lc-upgrade-1x-to-4x
description: Upgrades a Laravel application from oralunal/phpclickhouse-laravel 1.x to oralunal/laravel-clickhouse 4.x in one go. Switches the Composer packages, renames the 1.x namespaces (Tinderbox\ClickhouseBuilder, PhpClickHouseSchemaBuilder, PhpClickHouseLaravel) to Oralunal\LaravelClickHouse, replaces removed APIs, then rewrites the code that 4.0 removes (BaseModel, the package's query builder, Migration, the helpers) and reviews what 4.0 changes, and verifies the result. Use when the user runs /lc-upgrade-1x-to-4x or asks to upgrade phpclickhouse-laravel 1.x to laravel-clickhouse 4.
---

# phpclickhouse-laravel 1.x → laravel-clickhouse 4.x upgrade

You are upgrading this Laravel application from `oralunal/phpclickhouse-laravel`
1.x to `oralunal/laravel-clickhouse` 4.x, without stopping at 2.x or 3.x. It is
done in two parts:

1. **The move to the new names (this skill, steps 1 to 8).** 2.0 bundled the
   libraries that 1.x depended on, and 3.0 renamed the package and its
   namespace. These steps give the 3.x names. 4.0 removes most of these
   classes, and step 9 rewrites the code that uses them:

   | 1.x | 3.x name (after step 3) |
   | --- | --- |
   | `oralunal/phpclickhouse-laravel` | `oralunal/laravel-clickhouse` |
   | `PhpClickHouseLaravel\…` (the package's own classes) | `Oralunal\LaravelClickHouse\…` |
   | `oralunal/clickhouse-builder` (`Tinderbox\ClickhouseBuilder\…`) | `Oralunal\LaravelClickHouse\ClickhouseBuilder\…` |
   | `glushkovds/php-clickhouse-schema-builder` (`PhpClickHouseSchemaBuilder\…`) | `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\…` |
   | `myclabs/php-enum` (`MyCLabs\Enum\Enum`, used by the builder enums) | `Oralunal\LaravelClickHouse\Enum\Enum` |
   | global `raw()`, `tp()`, `array_flatten()` | removed in 4.0: `DB::raw()`, `tap()`, `Arr::flatten()` (step 4) |

   Class names and the folder layout below each prefix are the same, for
   example `PhpClickHouseSchemaBuilder\Tables\MergeTree` →
   `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Tables\MergeTree`.

2. **The 4.0 changes (step 9).** 4.0 removes the package's model
   (`BaseModel`), query builder, migration class (`Migration`), schema builder
   classes, enums and helpers, without a deprecation phase, and changes some
   behavior. The steps of the `lc-upgrade-3x-to-4x` skill handle this; you
   follow them from its step 3.

Work through the steps in order. Do not skip the checks, and do not stop at the
first file you fix: the goal is zero remaining references. Once the package is
installed, the full human-readable guide is in
`vendor/oralunal/laravel-clickhouse/UPGRADE.md`; read it when something here
is unclear.

## Rules

- Only change what the upgrade requires. Do not reformat files or refactor
  unrelated code.
- Never edit anything under `vendor/`.
- Search the whole project, not just `app/`. Include `config/`, `database/`
  (migrations, seeders, factories), `routes/`, `tests/`, `bootstrap/`, any
  other directory that holds PHP code, such as `src/`, `modules/` or
  `packages/`, and non-PHP files that name classes, such as `phpstan.neon`,
  `psalm.xml`, `rector.php`, `phpunit.xml`, `.env*` or IDE helper files.
  Exclude `vendor/`, `node_modules/`, `storage/`, `bootstrap/cache/`, `.git/`
  and the installed upgrade skills (the `lc-upgrade-*` directories of the
  agents).
- Search with `rg -U -P -e '<pattern>'` (or `grep -rPzo`), so that a pattern
  can span the lines of a chain and start with `->`; add `--hidden
  --no-ignore` for `.env*` files. A name can be escaped in a string, as in
  `'Tinderbox\\ClickhouseBuilder\\Query\\Expression'`: the patterns below
  match one or more backslashes with `\\+`.
- Leave the installed upgrade skills and the changelog entries that describe
  the past as they are.
- Never run `migrate:fresh`, `db:wipe`, `migrate:rollback` or any statement
  that changes data or tables of a real ClickHouse database.
- When a change needs a decision only the user can make, stop and ask. The
  steps below say when.
- Do not commit unless the user asks you to.

## Step 1: Assess

1. Find the version that the application uses now. Read `composer.json` and
   run `composer show 'oralunal/*'`. When 4.x is already installed, read the
   version before the update from the last commit: `git show HEAD:composer.lock`
   (search for `oralunal/phpclickhouse-laravel`). Note the exact 1.x version:
   steps 8 and 9 have notes for some of them.
   - `oralunal/phpclickhouse-laravel` 1.x, or 2.0.0 (tagged before the schema
     builder and the enum base class were bundled): do every step.
   - `oralunal/phpclickhouse-laravel` 2.0.1 or later: stop. Tell the user to run
     `/lc-upgrade-2x-to-4x` instead.
   - `oralunal/laravel-clickhouse` 3.x: stop. Tell the user to run
     `/lc-upgrade-3x-to-4x` instead.
   - `oralunal/laravel-clickhouse` 4.x is already installed (4.x on top of 1.x
     code): do step 1's searches, steps 2.2 and 2.3, then every step from step
     3 on for what they find. If the searches find none of the 1.x names
     (`Tinderbox\+ClickhouseBuilder`, `PhpClickHouseSchemaBuilder`,
     `MyCLabs\+Enum`, the helpers) and the old lock file shows 2.0.1 or later,
     stop and tell the user to run `/lc-upgrade-2x-to-4x` instead.
2. Check `git status`. If the tree has uncommitted changes that are not yours,
   tell the user and suggest committing them or working on a new branch before
   you continue.
3. Record the result of the test suite before the upgrade as the baseline
   (`php artisan test`, `vendor/bin/pest` or `vendor/bin/phpunit`, whichever
   the project uses). Tests that already failed before the upgrade are not
   yours to fix. Mention them in the final report.
   - Before step 2, run the suite once.
   - When 4.x is already installed, a run now is no baseline: the 1.x code no
     longer loads. Ask the user for a result from before the update, such as a
     saved output or the last CI run. If there is none and the user agrees,
     run the suite on a worktree of the last commit that still requires
     `oralunal/phpclickhouse-laravel`: `git worktree add ../lc-baseline <commit>`,
     then `composer install` and the test command in `../lc-baseline`, then
     `git worktree remove ../lc-baseline`. Otherwise say in the report that
     there is no baseline.
4. Search for every pattern below and keep the list of matches. You will work
   through it in the next steps. The patterns are PCRE and work with
   `rg`/`grep -P` or your search tool; search `*.php` files and the non-PHP
   files named in the rules, plus `composer.json` for the last one.

   - `Tinderbox\\+ClickhouseBuilder`: query builder classes, enums, exceptions
     and the old Laravel integration.
   - `Tinderbox\\+Clickhouse\\+`: the Tinderbox HTTP client (`TempTable`,
     `File`, `Client`, …).
   - `PhpClickHouseSchemaBuilder`: schema builder classes, mostly in
     migrations.
   - `PhpClickHouseLaravel`: the package's own classes, such as `BaseModel`
     and `Migration`, in any file, also in escaped strings such as
     `'PhpClickHouseLaravel\\BaseModel'`.
   - `MyCLabs\\+Enum`: the enum base class.
   - `(^|[^\w>:$\\])\\?(raw|tp|array_flatten)\(`: calls to the formerly global
     `raw()`, `tp()` and `array_flatten()` helpers, also written as `\raw(`.
   - `into_memory_table\(|file_from\(|->addFile\(|->getFiles\(|->getValues\(`:
     removed helpers and builder methods.
   - `(where|orWhere|preWhere|orPreWhere|having|orHaving)(Global)?(Not)?In\(`:
     `IN` conditions to inspect in step 7.
   - `oralunal/phpclickhouse-laravel|oralunal/clickhouse-builder|the-tinderbox/clickhouse-builder|glushkovds/clickhouse-builder|glushkovds/php-clickhouse-schema-builder|myclabs/php-enum|the-tinderbox/clickhouse-php-client`:
     direct requirements in `composer.json`.
   - `DB::connection\(\s*['"](?!clickhouse['"])` and `DB::table\(`: queries on
     ClickHouse connections other than `clickhouse`, for step 8.

   `raw(` preceded by `->`, `::` or `$`, such as `DB::raw()` or
   `$query->raw()`, is a method call, not the helper. The pattern already
   skips those; leave them alone.

## Step 2: Switch the dependencies

1. If `bootstrap/providers.php` or `config/app.php` lists
   `Tinderbox\ClickhouseBuilder\Integrations\Laravel\ClickhouseServiceProvider`,
   delete that line first. The class no longer exists after the switch, so the
   app, and the `artisan` scripts that Composer runs, cannot boot while it is
   listed. The package's own provider is auto-discovered.

2. Remove the 1.x packages and require 4.x:

   ```bash
   composer remove oralunal/phpclickhouse-laravel oralunal/clickhouse-builder the-tinderbox/clickhouse-builder glushkovds/clickhouse-builder glushkovds/php-clickhouse-schema-builder --no-update
   composer require oralunal/laravel-clickhouse:^4.1 --with-all-dependencies
   ```

   `myclabs/php-enum` and `the-tinderbox/clickhouse-php-client` are no longer
   installed with the package. If step 1 found application code that uses
   `MyCLabs\Enum` for its own enums, or the `Tinderbox\Clickhouse\` client
   directly, add that package to the same `composer require` line, for example
   `composer require oralunal/laravel-clickhouse:^4.1 myclabs/php-enum --with-all-dependencies`.

   `composer remove` only warns about a package that `composer.json` does not
   list. `oralunal/laravel-clickhouse` conflicts with
   `oralunal/phpclickhouse-laravel`, so Composer refuses to install it while
   anything still requires the old name. If the error names a package of the
   project itself, update its `composer.json` too. If it names a third-party
   package, stop and ask the user.

3. When 4.x was already installed before this skill ran, check the two
   points above now: delete the provider line, and require `myclabs/php-enum`
   or `the-tinderbox/clickhouse-php-client` when the application uses them.

The `artisan` scripts that Composer runs may fail with a class-not-found error
for a 1.x class until steps 3 and 4 are done, and for a class that 4.0 removes,
such as `Oralunal\LaravelClickHouse\BaseModel`, until step 9 is done;
continue.

## Step 3: Rename the namespaces

Replace the prefixes in every file found in step 1: `use` statements, `use
function` imports, fully qualified names, PHPDoc types, and strings or config
values that hold class names.

| Old prefix | New prefix |
| --- | --- |
| `Tinderbox\ClickhouseBuilder\` | `Oralunal\LaravelClickHouse\ClickhouseBuilder\` |
| `PhpClickHouseSchemaBuilder\` | `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\` |
| `PhpClickHouseLaravel\` | `Oralunal\LaravelClickHouse\` |

Leave `Tinderbox\ClickhouseBuilder\Integrations\…` and
`Tinderbox\ClickhouseBuilder\Query\Builder` for step 6: those classes have no
counterpart with the same name. To replace the rest across the project and
keep the backslash style of each occurrence (Perl works the same on Linux and
macOS):

```bash
grep -rlIE 'Tinderbox\\+ClickhouseBuilder|PhpClickHouseSchemaBuilder|PhpClickHouseLaravel' . \
    --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=storage \
    --exclude-dir=.git --exclude-dir='lc-upgrade-*' --exclude-dir='plc-upgrade-*' \
    --exclude=composer.lock --exclude='CHANGELOG*' \
  | grep -v '^\./bootstrap/cache/' \
  | xargs perl -pi -e '
      s/Tinderbox(\\+)ClickhouseBuilder(\\++)(?!Integrations|Query\\+Builder\b)/Oralunal$1LaravelClickHouse$1ClickhouseBuilder$2/g;
      s/PhpClickHouseSchemaBuilder(\\+)/Oralunal$1LaravelClickHouse$1ClickhouseSchemaBuilder$1/g;
      s/PhpClickHouseLaravel(\\+)/Oralunal$1LaravelClickHouse$1/g;
    '
```

Then search for the three old prefixes again. What remains is the
`Integrations` and `Query\Builder` classes of step 6, or text not followed by a
backslash, such as a mention in a comment. Change a mention when it names a
namespace. Leave text that deliberately refers to the old names, such as a
changelog entry, and list it in the report. Check `git diff -- '*.md'` and
restore the lines that describe the past.

1.0.0 told users to list `PhpClickHouseLaravel\ClickhouseServiceProvider` in
`bootstrap/providers.php`. The replacement above renames it; keep it or delete
it, since the package's provider is auto-discovered.

Migration files are the most common place for `PhpClickHouseSchemaBuilder`,
especially closures typed as `fn (MergeTree $table) => …` passed to
`static::createMergeTree()`. Fix every migration, including ones that already
ran: they are loaded again on a fresh install, in CI and by `migrate:fresh`.

`MyCLabs\Enum\Enum`: 4.0 removes the package's enums (`Operator`, `Format`,
`JoinType`, `JoinStrict`, `OrderDirection`); step 9 replaces their uses
(operators and directions become strings, `Format` becomes the native enum
`Oralunal\LaravelClickHouse\Enums\Format`). Leave the application's own
enums that extend `MyCLabs\Enum\Enum` alone; step 2.2 keeps that package
installed for them.

## Step 4: Check the helper functions

`raw()`, `tp()` and `array_flatten()` were global functions in 1.x. 4.0 has no
such functions, so do not import them. Step 9 replaces each call:
`raw('count()')` with `DB::raw('count()')` (`Illuminate\Support\Facades\DB`),
`tp($value, $callback)` with `tap($value, $callback)`, and
`array_flatten($array)` with `Arr::flatten($array)`
(`Illuminate\Support\Arr`).

For now, check that each call from step 1 really used this package's helper
and not some other global function with the same name. `array_flatten()` in
particular may come from another helper package. If the project defines its
own global `raw()`, `tp()` or `array_flatten()`, ask the user which one each
call means, and leave the calls that are not the package's alone in step 9.

## Step 5: Refresh Laravel's caches

1. Run `php artisan package:discover` and
   `php artisan optimize:clear --except=cache`, which leaves the application
   cache as it is.

   If they or any other artisan command fail with
   `Class "Tinderbox\ClickhouseBuilder\Integrations\Laravel\ClickhouseServiceProvider" not found`
   or `Class "PhpClickHouseLaravel\ClickhouseServiceProvider" not found`,
   Laravel's cached package manifest still lists a 1.x provider. This happens
   when Composer ran without scripts. Delete `bootstrap/cache/packages.php` and
   `bootstrap/cache/services.php` (Laravel regenerates them), then run
   `php artisan package:discover` again.

2. Values serialized with the 1.x class names cannot be unserialized by 4.x:
   cache entries, queued jobs and sessions that hold objects of the package's
   or the bundled libraries' classes, such as `RawColumn`, `Expression` or the
   builder enums. Do not change code for this and do not clear caches or
   queues yourself. Look for places that put such objects into the cache, a
   queue or the session. Tell the user what you found, and that a deploy of
   this upgrade should let the queues drain first and clear the application
   cache afterwards if any such values exist.

## Step 6: Replace removed APIs

These parts of the 1.x builder only worked with the Tinderbox HTTP client.
This package never sent their files to ClickHouse, so they could not work in
1.x either.

| Removed | Replacement |
| --- | --- |
| `->addFile(...)`, `->getFiles()`, `->values(...)`, `->getValues()` on the builder | Insert rows with `Model::insert()`, `Model::buffer()`, or `insertFiles()` of the query builder |
| `into_memory_table()`, `file_from()` | Same as above |
| `Tinderbox\ClickhouseBuilder\Query\Builder` (Tinderbox-client builder) | `Model::query()` or `DB::connection('clickhouse')->table(...)` |
| `Tinderbox\ClickhouseBuilder\Integrations\Laravel\Connection` / `Builder` | `Oralunal\LaravelClickHouse\Connection` / `Oralunal\LaravelClickHouse\QueryBuilder` |
| `Tinderbox\ClickhouseBuilder\Integrations\Laravel\ClickhouseServiceProvider` in `bootstrap/providers.php` or `config/app.php` | delete the line (step 2.1) |

If you find any of these, explain what the code tried to do and propose the
replacement. Then **ask the user before changing it**: the right fix depends on
what the data is.

## Step 7: Check `IN` conditions with a string value

In 1.x, `whereIn()` and its variants (`preWhereIn()`, `whereGlobalIn()`,
`havingIn()`, the `or…` and `…NotIn` forms) treated a string as a table name
only when that same builder had added a file with that name through
`addFile()`. Every other string was quoted as one value:
`whereIn('id', 'ids')` gave ``WHERE `id` IN 'ids'``. In 4.x, `whereIn()`,
`preWhereIn()` and `whereGlobalIn()` take a list, a collection or a sub-query,
and a string throws a `TypeError`. `havingIn()` no longer exists: use
`havingRaw()`, as the `lc-upgrade-3x-to-4x` skill shows.

For each match from step 1 whose second argument is not an array, a
collection or a closure, such as a string or a number (`whereIn('id', 5)` gave
`IN 5`):

- **The same builder called `addFile()` with that name** (step 6): the string
  named a temporary table. Handle it together with the `addFile()` call from
  step 6, and ask the user. Where the table now exists in ClickHouse, the
  equivalent is a sub-query closure, such as
  `->whereIn('id', fn ($q) => $q->select('id')->from('table_name'))`, or
  `->whereRaw('id in table_name')`.
- **Anything else:** the value was one value. Write it as a list,
  `->whereIn('id', [$value])`, which gives the same rows. When a variable can
  hold one value or an array, as `$request->query()` can, write
  `Arr::wrap($value)` instead (`Illuminate\Support\Arr`): `[$value]` would
  nest an array. If the string looks like a table name, the code was probably
  already wrong in 1.x: list it in the report as a possible existing bug.

## Step 8: Tell the user about the connection and `migrate:fresh` changes

**Queries of other connections.** In 1.x and 2.0.0, `table()` and `query()` of
a ClickHouse connection with `fix_default_query_builder` on ran on the
`clickhouse` connection, whatever connection the code named. So did
`DB::table()` when the default connection is a ClickHouse connection with
another name, and the `migrations` table of such a connection, as with
`migrate --database=<name>`. 4.x runs them on the connection that the code
names. In 1.0.0 the option was off unless the connection set it; from 1.1.0, a
connection defined in `config/clickhouse.php` (the packaged or a published
file) had it on unless it set it off, and a connection defined only in
`config/database.php` had it off unless it set it on. For the matches of the
last search of step 1 whose connection had the option on, tell the user which
reads and writes now go to another server or database. If the `migrations`
table is on such a connection, read both `migrations` tables (read only) and
tell the user before anyone runs `migrate`: copy the rows from the `migrations`
table of `clickhouse`, or `migrate` runs every migration again.

**`migrate:fresh`, `db:wipe` and `RefreshDatabase`.** Check
`config/database.php` and `.env` (`DB_CONNECTION`):
- If the default connection is a ClickHouse connection, `migrate:fresh`,
  `db:wipe` and the `RefreshDatabase` trait failed on it in 1.x, which had no
  `dropAllTables()`. 4.x drops every table, view, materialized view and
  dictionary of its database, on every node.
- If the default connection is not a ClickHouse connection, but some
  migrations use a ClickHouse connection, then since 2.0.1 `migrate:fresh`
  also empties those ClickHouse databases, and so does `RefreshDatabase`.
  `db:wipe` empties only the connection it is given. In 1.x they were left
  untouched. A migration that extends the package's `Migration` without
  `$connection` used the `clickhouse` connection.

Do not change code for this. Instead:

1. Find where `migrate:fresh` runs: the `RefreshDatabase` /
   `DatabaseMigrations` traits in `tests/`, `composer.json` scripts, CI
   workflows and deploy scripts.
2. Check which ClickHouse servers and databases the testing environment
   uses: look at `phpunit.xml`, `.env.testing` and the CI environment
   variables, for example `CLICKHOUSE_HOST`, `CLICKHOUSE_DATABASE` and the
   nodes of `cluster`. A test environment that sets only the database name
   still empties that database on the hosts of `.env`.
3. Tell the user which ClickHouse databases will be emptied in those places.
   If any of them looks shared or important, for example the same database as
   local development or production, warn them clearly and suggest a dedicated
   test database. Do not run `migrate:fresh` yourself.
4. If the tests run in parallel (`--parallel` in `composer.json` scripts or
   CI, or paratest), tell the user that each test process empties its own
   database, `<database>_test_<token>` (for example `analytics_test_1`),
   rather than the configured one. The ClickHouse user of the test
   environment must be allowed to create and drop databases.
5. Version notes, to tell the user:
   - 1.0.0 to 1.4.x: `Schema::drop()` and `Schema::dropIfExists()` sent
     nothing on a ClickHouse connection. 4.x drops the table, also in the
     `down()` of a rollback, a reset or a refresh.
   - 1.1.0 to 1.3.x: the package did not read a published
     `config/clickhouse.php`. 4.x reads it, below `config/database.php`: check
     its values, which now apply.

Before you go on, check the move: `composer dump-autoload` and
`php artisan package:discover` must finish without errors, or fail only with a
class-not-found error for a class that 4.0 removes, which step 9 fixes. `php -l`
must pass on every PHP file you changed, and the searches of step 1 must only
find what steps 3 to 7 left on purpose (the helper calls, for step 9; the
application's own `MyCLabs\Enum` enums, with `myclabs/php-enum` required).

## Step 9: Apply the 4.0 changes

Read the `lc-upgrade-3x-to-4x` skill. `php artisan clickhouse:install-skills`
installed it next to this one, in the same skills directory; the package
also ships it as
`vendor/oralunal/laravel-clickhouse/resources/skills/lc-upgrade-3x-to-4x/SKILL.md`.

Follow its rules, then its step 1 items 4 and 5 (the ClickHouse connections
and the code that uses them), and then every step from its step 3 on. Skip its
steps 1.1 to 1.3 and 2: the version check, the `git status` check, the baseline
and the dependency are done; the baseline is the result of step 1.3 of this
skill. For its step 1.4, the rule for `fix_default_query_builder` is the one of
step 8 of this skill. Its step 6.3 also covers 1.x tables: `createMergeTree()`
with `engine()` made a table that is not replicated. Its verification and
report replace a separate
verification and report of this skill: add to its report the package changes
of step 2, the files of steps 3 and 4, every question of steps 6 and 7 with the
decision taken, every old-namespace mention you left on purpose, what step 5
found about serialized values, cache and queues, and the `migrate:fresh` notice
of step 8 if it applies.
