---
name: lc-upgrade-1x-to-4x
description: Upgrades a Laravel application from oralunal/phpclickhouse-laravel 1.x to oralunal/laravel-clickhouse 4.x in one go. Switches the Composer packages, renames the 1.x namespaces (Tinderbox\ClickhouseBuilder, PhpClickHouseSchemaBuilder, PhpClickHouseLaravel) to Oralunal\LaravelClickHouse, imports the helper functions that are no longer global, replaces removed APIs, then fixes and reviews what 4.0 changes, and verifies the result. Use when the user runs /lc-upgrade-1x-to-4x or asks to upgrade phpclickhouse-laravel 1.x to laravel-clickhouse 4.
---

# phpclickhouse-laravel 1.x → laravel-clickhouse 4.x upgrade

You are upgrading this Laravel application from `oralunal/phpclickhouse-laravel`
1.x to `oralunal/laravel-clickhouse` 4.x, without stopping at 2.x or 3.x. It is
done in two parts:

1. **The move to the new classes (this skill, steps 1 to 8).** 2.0 bundled the
   libraries that 1.x depended on, and 3.0 renamed the package and its
   namespace. Together:

   | 1.x | 4.x |
   | --- | --- |
   | `oralunal/phpclickhouse-laravel` | `oralunal/laravel-clickhouse` |
   | `PhpClickHouseLaravel\…` (the package's own classes) | `Oralunal\LaravelClickHouse\…` |
   | `oralunal/clickhouse-builder` (`Tinderbox\ClickhouseBuilder\…`) | `Oralunal\LaravelClickHouse\ClickhouseBuilder\…` |
   | `glushkovds/php-clickhouse-schema-builder` (`PhpClickHouseSchemaBuilder\…`) | `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\…` |
   | `myclabs/php-enum` (`MyCLabs\Enum\Enum`, used by the builder enums) | `Oralunal\LaravelClickHouse\Enum\Enum` |
   | global `raw()`, `tp()`, `array_flatten()` | `Oralunal\LaravelClickHouse\ClickhouseBuilder\raw()`, `…\tp()`, `…\array_flatten()` |

   Class names and the folder layout below each prefix are the same, for
   example `PhpClickHouseSchemaBuilder\Tables\MergeTree` →
   `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Tables\MergeTree`.

2. **The 4.0 changes (step 9).** 4.0 brings breaking changes and behavior
   changes. They are handled by the steps of the `lc-upgrade-3x-to-4x` skill,
   which you follow from its step 3.

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
  `psalm.xml`, `rector.php` or IDE helper files. Exclude `vendor/`,
  `node_modules/`, `storage/`, `bootstrap/cache/` and `.git/`.
- Never run `migrate:fresh`, `db:wipe`, `migrate:rollback` or any statement
  that changes data or tables of a real ClickHouse database.
- When a change needs a decision only the user can make, stop and ask. The
  steps below say when.
- Do not commit unless the user asks you to.

## Step 1: Assess

1. Read `composer.json` and run `composer show 'oralunal/*'` to see the
   required and installed versions.
   - `oralunal/phpclickhouse-laravel` 1.x, or 2.0.0 (tagged before the schema
     builder and the enum base class were bundled): do every step.
   - `oralunal/phpclickhouse-laravel` 2.0.1 or later: stop. Tell the user to run
     `/lc-upgrade-2x-to-4x` instead.
   - `oralunal/laravel-clickhouse` 3.x: stop. Tell the user to run
     `/lc-upgrade-3x-to-4x` instead.
   - `oralunal/laravel-clickhouse` 4.x is already installed (4.x on top of 1.x
     code): do step 1's searches, then every step from step 3 on for what they
     find.
2. Check `git status`. If the tree has uncommitted changes that are not yours,
   tell the user and suggest committing them or working on a new branch before
   you continue.
3. Run the test suite once (`php artisan test`, `vendor/bin/pest` or
   `vendor/bin/phpunit`, whichever the project uses) and record the result as
   the baseline. Tests that already failed before the upgrade are not yours to
   fix. Mention them in the final report.
4. Search for every pattern below and keep the list of matches. You will work
   through it in the next steps. The patterns are PCRE and work with
   `rg`/`grep -P` or your search tool; search `*.php` files and the non-PHP
   files named in the rules, plus `composer.json` for the last one.

   - `Tinderbox\\ClickhouseBuilder`: query builder classes, enums, exceptions
     and the old Laravel integration.
   - `Tinderbox\\Clickhouse\\`: the Tinderbox HTTP client (`TempTable`,
     `File`, `Client`, …).
   - `PhpClickHouseSchemaBuilder`: schema builder classes, mostly in
     migrations.
   - `PhpClickHouseLaravel`: the package's own classes, such as `BaseModel`
     and `Migration`, in any file, also in escaped strings such as
     `'PhpClickHouseLaravel\\BaseModel'`.
   - `MyCLabs\\Enum`: the enum base class.
   - `(^|[^\w>:$\\])(raw|tp|array_flatten)\(`: calls to the formerly global
     `raw()`, `tp()` and `array_flatten()` helpers.
   - `into_memory_table\(|file_from\(|->addFile\(|->getFiles\(|->getValues\(`:
     removed helpers and builder methods.
   - `(where|orWhere|preWhere|orPreWhere|having|orHaving)(Global)?(Not)?In\(`:
     `IN` conditions to inspect in step 7.
   - `oralunal/phpclickhouse-laravel|oralunal/clickhouse-builder|glushkovds/php-clickhouse-schema-builder|myclabs/php-enum|the-tinderbox/clickhouse-php-client`:
     direct requirements in `composer.json`.

   `raw(` preceded by `->`, `::` or `$`, such as `DB::raw()` or
   `$query->raw()`, is a method call, not the helper. The pattern already
   skips those; leave them alone.

## Step 2: Switch the dependencies

1. Remove the 1.x packages and require 4.x:

   ```bash
   composer remove oralunal/phpclickhouse-laravel oralunal/clickhouse-builder glushkovds/php-clickhouse-schema-builder --no-update
   composer require oralunal/laravel-clickhouse:^4.0 --with-all-dependencies
   ```

   `composer remove` only warns about a package that `composer.json` does not
   list. `oralunal/laravel-clickhouse` conflicts with
   `oralunal/phpclickhouse-laravel`, so Composer refuses to install it while
   anything still requires the old name. If the error names a package of the
   project itself, update its `composer.json` too. If it names a third-party
   package, stop and ask the user.

2. `myclabs/php-enum` and `the-tinderbox/clickhouse-php-client` are no longer
   installed with the package. If step 1 found application code that uses
   `MyCLabs\Enum` for its own enums, or the `Tinderbox\Clickhouse\` client
   directly, require that package explicitly, for example
   `composer require myclabs/php-enum`. Otherwise do nothing.

3. If `bootstrap/providers.php` or `config/app.php` lists
   `Tinderbox\ClickhouseBuilder\Integrations\Laravel\ClickhouseServiceProvider`,
   delete that line. The class no longer exists, so the app cannot boot while
   it is listed. The package's own provider is auto-discovered.

The `artisan` scripts that Composer runs may fail with a class-not-found error
for a 1.x class until steps 3 and 4 are done; continue.

## Step 3: Rename the namespaces

Replace the prefixes in every file found in step 1: `use` statements, `use
function` imports, fully qualified names, PHPDoc types, and strings or config
values that hold class names.

| Old prefix | New prefix |
| --- | --- |
| `Tinderbox\ClickhouseBuilder\` | `Oralunal\LaravelClickHouse\ClickhouseBuilder\` |
| `PhpClickHouseSchemaBuilder\` | `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\` |
| `PhpClickHouseLaravel\` | `Oralunal\LaravelClickHouse\` |

Leave `Tinderbox\ClickhouseBuilder\Integrations\…` for step 6: those classes
have no counterpart with the same name. To replace the rest across the project
and keep the backslash style of each occurrence (Perl works the same on Linux
and macOS):

```bash
grep -rlIE 'Tinderbox\\+ClickhouseBuilder|PhpClickHouseSchemaBuilder|PhpClickHouseLaravel' . \
    --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=storage \
    --exclude-dir=.git --exclude-dir=cache --exclude=composer.lock \
  | xargs perl -pi -e '
      s/Tinderbox(\\+)ClickhouseBuilder(\\+)(?!Integrations)/Oralunal$1LaravelClickHouse$1ClickhouseBuilder$2/g;
      s/PhpClickHouseSchemaBuilder(\\+)/Oralunal$1LaravelClickHouse$1ClickhouseSchemaBuilder$1/g;
      s/PhpClickHouseLaravel(\\+)/Oralunal$1LaravelClickHouse$1/g;
    '
```

Then search for the three old prefixes again. What remains is the
`Integrations` classes of step 6, or text not followed by a backslash, such as
a mention in a comment. Change a mention when it names a namespace. Leave text
that deliberately refers to the old names, such as a changelog entry, and list
it in the report.

Migration files are the most common place for `PhpClickHouseSchemaBuilder`,
especially closures typed as `fn (MergeTree $table) => …` passed to
`static::createMergeTree()`. Fix every migration, including ones that already
ran: they are loaded again on a fresh install, in CI and by `migrate:fresh`.

`MyCLabs\Enum\Enum`: the package's enums (`Operator`, `Format`, `JoinType`,
`JoinStrict`, `OrderDirection`) now extend `Oralunal\LaravelClickHouse\Enum\Enum`.
Their constants and methods (`isValid()`, `getValue()`, `Operator::EQUALS()`
and so on) are unchanged. Only update code that type-checks a package enum
against `MyCLabs\Enum\Enum`, for example with `instanceof` or a parameter type.
Leave the application's own enums that extend `MyCLabs\Enum\Enum` alone; step
2.2 keeps that package installed for them.

## Step 4: Import the helper functions

`raw()`, `tp()` and `array_flatten()` are no longer global. They are now
`Oralunal\LaravelClickHouse\ClickhouseBuilder\raw()`, `…\tp()` and
`…\array_flatten()`.

For every file that calls one of them as a bare function, add the matching
import. Put it after the `namespace` line and with the other `use` statements;
in a file without a namespace, such as an anonymous migration or a route file,
put it right after `<?php`:

```php
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;
```

Before you add the import, check that the call really used this helper and not
some other global function with the same name. `array_flatten()` in
particular may come from another helper package. If the project defines its
own global `raw()`, `tp()` or `array_flatten()`, ask the user which one each
call means.

## Step 5: Refresh Laravel's caches

1. Run `php artisan package:discover` and `php artisan optimize:clear`.

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
| `->addFile(...)`, `->getFiles()`, `->values(...)`, `->getValues()` on the builder | Insert rows with `Model::insertAssoc()`, `Model::insertBulk()` or `Model::buffer()` |
| `into_memory_table()`, `file_from()` | Same as above |
| `Tinderbox\ClickhouseBuilder\Query\Builder` (Tinderbox-client builder) | `Model::select()` / `Model::where()` or `DB::connection('clickhouse')->table(...)` |
| `Tinderbox\ClickhouseBuilder\Integrations\Laravel\Connection` / `Builder` | `Oralunal\LaravelClickHouse\Connection` / `Oralunal\LaravelClickHouse\Builder` |

If you find any of these, explain what the code tried to do and propose the
replacement. Then **ask the user before changing it**: the right fix depends on
what the data is.

`Column::subQuery()` and `Column::getSubQuery()` now return
`Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\BaseBuilder`. Update code
that type-hints the old `Query\Builder` return value.

## Step 7: Check `IN` conditions with a string value

In 1.x, `whereIn()` and its variants (`preWhereIn()`, `whereGlobalIn()`,
`havingIn()`, the `or…` and `…NotIn` forms) treated a string as a table name
only when that same builder had added a file with that name through
`addFile()`. Every other string was already quoted as a value, exactly as it
is in 4.x:

```php
$query->whereIn('id', 'ids'); // WHERE `id` IN 'ids' in 1.x and in 4.x
```

For each match from step 1 whose second argument is a string (a literal or a
variable holding one) rather than an array or a closure:

- **The same builder called `addFile()` with that name** (step 6): the string
  named a temporary table. Handle it together with the `addFile()` call from
  step 6, and ask the user. Where the table now exists in ClickHouse, the
  equivalent is `->whereIn('id', raw('table_name'))` (import `raw` as in
  step 4), or a sub-query closure such as
  `->whereIn('id', fn ($q) => $q->select('id')->from('table_name'))`.
- **Anything else: do not change it.** The compiled SQL is the same as in 1.x.
  If the string looks like a table name, the code was probably already wrong
  in 1.x. List it in the report as a possible existing bug, with the SQL it
  produces, but leave it as it is.

## Step 8: Tell the user about the `migrate:fresh` change

Check `config/database.php` and `.env` (`DB_CONNECTION`). If the default
connection is not a ClickHouse connection (`driver` is not `clickhouse`), but
some migrations set `protected $connection` to a ClickHouse connection, then
since 2.0 `migrate:fresh` and `db:wipe` also empty those ClickHouse
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

Before you go on, check the move: `composer dump-autoload` and
`php artisan package:discover` must finish without errors, `php -l` must pass
on every PHP file you changed, and the searches of step 1 must only find what
steps 3 to 7 left on purpose (the helper calls, each with its
`use function Oralunal\LaravelClickHouse\ClickhouseBuilder\…;` import; the
application's own `MyCLabs\Enum` enums, with `myclabs/php-enum` required; the
`IN` conditions that step 7 told you to leave).

## Step 9: Apply the 4.0 changes

Read the `lc-upgrade-3x-to-4x` skill. `php artisan clickhouse:install-skills`
installed it next to this one, in the same skills directory; the package
also ships it as
`vendor/oralunal/laravel-clickhouse/resources/skills/lc-upgrade-3x-to-4x/SKILL.md`.

Follow its rules, then its step 1 items 4 and 5 (the ClickHouse connections
and the code that uses them), and then every step from its step 3 on. Skip its
steps 1.1 to 1.3 and 2: the version check, the `git status` check, the baseline
and the dependency are done. Its verification and report replace a separate
verification and report of this skill: add to its report the package changes
of step 2, the files of steps 3 and 4, every question of steps 6 and 7 with the
decision taken, every old-namespace mention you left on purpose, what step 5
found about serialized values, cache and queues, and the `migrate:fresh` notice
of step 8 if it applies.
