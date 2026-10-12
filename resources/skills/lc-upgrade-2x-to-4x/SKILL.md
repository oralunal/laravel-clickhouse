---
name: lc-upgrade-2x-to-4x
description: Upgrades a Laravel application from oralunal/phpclickhouse-laravel 2.x to oralunal/laravel-clickhouse 4.x. Switches the Composer package, renames the PhpClickHouseLaravel namespace to Oralunal\LaravelClickHouse in code, migrations, config and tests, refreshes Laravel's package caches, then fixes and reviews what 4.0 changes, and verifies the result. Use when the user runs /lc-upgrade-2x-to-4x or asks to upgrade phpclickhouse-laravel 2.x to laravel-clickhouse 4.
---

# phpclickhouse-laravel 2.x → laravel-clickhouse 4.x upgrade

You are upgrading this Laravel application from `oralunal/phpclickhouse-laravel`
2.x to `oralunal/laravel-clickhouse` 4.x. It is done in two parts:

1. **The rename (this skill, steps 1 to 5).** 3.0 renamed the Composer package
   and the namespace prefix:
   - `oralunal/phpclickhouse-laravel` → `oralunal/laravel-clickhouse`;
   - `PhpClickHouseLaravel\` → `Oralunal\LaravelClickHouse\`.

   3.0 also fixed one bug: on a connection with `cluster` nodes,
   `createMergeTree()` lost the replication when its callback called
   `engine()`, so such a 2.x table is not replicated. The `lc-upgrade-3x-to-4x`
   skill compares the tables of each migration with production (its step 6).

   The rename gives the 3.x names. Most of these classes no longer exist in
   4.0, so step 6 rewrites the code that uses them:

   | 2.x | 3.x (after the rename) | 4.x (after step 6) |
   | --- | --- | --- |
   | `PhpClickHouseLaravel\BaseModel` | `Oralunal\LaravelClickHouse\BaseModel` | `Oralunal\LaravelClickHouse\Eloquent\Model` |
   | `PhpClickHouseLaravel\Migration` | `Oralunal\LaravelClickHouse\Migration` | Laravel's `Migration`, with `DB` and `Schema` |
   | `PhpClickHouseLaravel\ClickhouseBuilder\…` | `Oralunal\LaravelClickHouse\ClickhouseBuilder\…` | `Oralunal\LaravelClickHouse\QueryBuilder` |
   | `PhpClickHouseLaravel\ClickhouseSchemaBuilder\…` | `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\…` | Laravel's schema builder |
   | `PhpClickHouseLaravel\Enum\Enum` | `Oralunal\LaravelClickHouse\Enum\Enum` | `Oralunal\LaravelClickHouse\Enums\…` (native enums) |
   | `use function PhpClickHouseLaravel\ClickhouseBuilder\raw;` | `use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;` | `DB::raw()` |

2. **The 4.0 changes (step 6).** 4.0 keeps the name and namespace. It removes
   the package's model, query builder, migration class and helpers, without a
   deprecation phase, and changes some behavior. The steps of the
   `lc-upgrade-3x-to-4x` skill handle this; you follow them from its step 3.

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
  (migrations, seeders, factories), `routes/`, `tests/`, `bootstrap/`, and
  non-PHP files that name classes, such as `phpstan.neon`, `psalm.xml`,
  `rector.php`, `phpunit.xml`, `.env*` or IDE helper files. Exclude `vendor/`,
  `node_modules/`, `storage/`, `bootstrap/cache/`, `.git/` and the installed
  upgrade skills (the `lc-upgrade-*` directories of the agents).
- Search with `rg -U -P -e '<pattern>'` (or `grep -rPzo`), so that a pattern
  can span the lines of a chain and start with `->`; add `--hidden
  --no-ignore` for `.env*` files.
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
   (search for `oralunal/phpclickhouse-laravel`).
   - `oralunal/phpclickhouse-laravel` 2.0.1 or 2.0.2: do every step.
   - `oralunal/phpclickhouse-laravel` 2.0.0 or 1.x: stop. Tell the user to run
     `/lc-upgrade-1x-to-4x` instead: 2.0.0 still required the separate schema
     builder and enum packages, which that skill replaces.
   - `oralunal/laravel-clickhouse` 3.x: stop. Tell the user to run
     `/lc-upgrade-3x-to-4x` instead.
   - `oralunal/laravel-clickhouse` 4.x is already installed, with 2.x code: do
     step 1's searches, then every step from step 3 on for what they find.
2. Check `git status`. If the tree has uncommitted changes that are not yours,
   tell the user and suggest committing them or working on a new branch before
   you continue.
3. Record the result of the test suite before the upgrade as the baseline
   (`php artisan test`, `vendor/bin/pest` or `vendor/bin/phpunit`, whichever
   the project uses). Tests that already failed before the upgrade are not
   yours to fix. Mention them in the final report.
   - Before step 2, run the suite once.
   - When 4.x is already installed, a run now is no baseline: the 2.x code no
     longer loads. Ask the user for a result from before the update, such as a
     saved output or the last CI run. If there is none and the user agrees,
     run the suite on a worktree of the last commit that still requires
     `oralunal/phpclickhouse-laravel`: `git worktree add ../lc-baseline <commit>`,
     then `composer install` and the test command in `../lc-baseline`, then
     `git worktree remove ../lc-baseline`. Otherwise say in the report that
     there is no baseline.
   - If the project runs PHPStan (a `phpstan.neon`, `phpstan.neon.dist` or
     `phpstan.dist.neon` file), run it as well, as the project runs it, such
     as `vendor/bin/phpstan analyse` or a Composer script, and keep its
     errors. Step 14.8 of the `lc-upgrade-3x-to-4x` skill compares them. In a
     worktree, run it there after the test suite.
4. Search for every pattern below and keep the list of matches. The patterns
   are PCRE and work with `rg`/`grep -P` or your search tool.

   - `PhpClickHouseLaravel`: every reference to the 2.x namespace, in any
     file. This also finds escaped forms such as `'PhpClickHouseLaravel\\BaseModel'`
     in strings, JSON, YAML or NEON, and grouped imports such as
     `use PhpClickHouseLaravel\{BaseModel, Migration};`.
   - `oralunal/phpclickhouse-laravel`: the requirement in `composer.json`
     (and in the `composer.json` of any local package in the project), and an
     `extra.laravel.dont-discover` entry, which step 3 renames to
     `oralunal/laravel-clickhouse`.
   - `Tinderbox\\+ClickhouseBuilder`: 1.x code. If this finds anything, stop
     and tell the user to run `/lc-upgrade-1x-to-4x` instead.
   - `PhpClickHouseSchemaBuilder\\+`: imports of the separate schema builder
     package that 2.0.0 required, in old migrations. On 2.0.1 or 2.0.2, rename
     them in step 3 as well, to `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\`,
     and remove `glushkovds/php-clickhouse-schema-builder` from `composer.json`
     in step 2.
   - `MyCLabs\\+Enum`: the application's own enums on `myclabs/php-enum`. If
     `composer.json` does not require that library, add it to the
     `composer require` of step 2, or ask the user to make them native enums.

## Step 2: Switch the dependency

```bash
composer remove oralunal/phpclickhouse-laravel --no-update
composer require oralunal/laravel-clickhouse:^4.1 --with-all-dependencies
```

`oralunal/laravel-clickhouse` conflicts with `oralunal/phpclickhouse-laravel`,
so Composer refuses to install it while anything still requires the old name.
If the error names a package of the project itself, update its
`composer.json` too. If it names a third-party package, stop and ask the user.

The `artisan` scripts that Composer runs afterwards may fail with a
class-not-found error for a `PhpClickHouseLaravel\…` class, because the
application code still uses the old namespace. That is expected until step 3;
continue. Until step 6 is done, they may also fail for a class that 4.0
removes, such as `Oralunal\LaravelClickHouse\BaseModel`.

## Step 3: Rename the namespace

Replace the prefix in every file from step 1: `use` statements, `use function`
imports, fully qualified names, PHPDoc types, and strings or config values that
hold class names. Keep the backslash style of each occurrence: in a string with
escaped backslashes, `PhpClickHouseLaravel\\` becomes
`Oralunal\\LaravelClickHouse\\`.

To replace them across the project (Perl works the same on Linux and macOS):

```bash
grep -rlIE 'PhpClickHouseLaravel|PhpClickHouseSchemaBuilder' . \
    --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=storage \
    --exclude-dir=.git --exclude-dir='lc-upgrade-*' --exclude-dir='plc-upgrade-*' \
    --exclude=composer.lock --exclude='CHANGELOG*' \
  | grep -v '^\./bootstrap/cache/' \
  | xargs perl -pi -e '
      s/PhpClickHouseSchemaBuilder(\\+)/Oralunal$1LaravelClickHouse$1ClickhouseSchemaBuilder$1/g;
      s/PhpClickHouseLaravel(\\+)/Oralunal$1LaravelClickHouse$1/g;'
```

Then search for `PhpClickHouseLaravel` again. What remains is not followed by
a backslash, such as a mention in a comment or in documentation. Change it when
it names the package's namespace. Leave text that deliberately refers to the
old name, such as a changelog entry, and list it in the report. Check
`git diff -- '*.md'` and restore the lines that describe the past.
In `composer.json`, rename `oralunal/phpclickhouse-laravel` in
`extra.laravel.dont-discover` to `oralunal/laravel-clickhouse` by hand.

`bootstrap/providers.php` and `config/app.php` normally do not list the
package's service provider, because it is auto-discovered. If one of them
lists `PhpClickHouseLaravel\ClickhouseServiceProvider`, the replacement above
renames it to `Oralunal\LaravelClickHouse\ClickhouseServiceProvider`; keep it.

## Step 4: Refresh Laravel's caches

1. Run `php artisan package:discover` and
   `php artisan optimize:clear --except=cache`, which leaves the application
   cache as it is.

   If they fail with
   `Class "PhpClickHouseLaravel\ClickhouseServiceProvider" not found`,
   Laravel's cached package manifest still lists the 2.x provider. This happens
   when Composer ran without scripts. Delete `bootstrap/cache/packages.php` and
   `bootstrap/cache/services.php` (Laravel regenerates them), then run
   `php artisan package:discover` again.

2. Values serialized with the 2.x class names cannot be unserialized by 4.x:
   cache entries, queued jobs and sessions that hold objects of the package's
   classes, such as `RawColumn` or the builder enums, which 4.0 removes. Do
   not change code for this and do not clear caches or queues yourself. Look
   for places that put such objects into the cache, a queue or the session.
   Tell the user what you found, and that a deploy of this upgrade should let
   the queues drain first and clear the application cache afterwards if any
   such values exist.

3. Parallel tests. Since 2.0.2, `php artisan test --parallel` gives each test
   process its own database on the secondary ClickHouse connections, named
   `<database>_test_<token>`. On 2.0.1, tell the user that the ClickHouse user
   of the tests needs permission to create and drop databases.

## Step 5: Check the rename

1. `composer dump-autoload` and `php artisan package:discover`. Both must
   finish without errors, or fail only with a class-not-found error for a
   class that 4.0 removes, which step 6 fixes.
2. `php -l` on every PHP file you changed.
3. Run every search from step 1 again.
   - `oralunal/phpclickhouse-laravel` must not be required anywhere.
   - `PhpClickHouseLaravel` only remains where step 3 deliberately left it.

## Step 6: Apply the 4.0 changes

Read the `lc-upgrade-3x-to-4x` skill. `php artisan clickhouse:install-skills`
installed it next to this one, in the same skills directory; the package
also ships it as
`vendor/oralunal/laravel-clickhouse/resources/skills/lc-upgrade-3x-to-4x/SKILL.md`.

Follow its rules, then its step 1 items 4 and 5 (the ClickHouse connections
and the code that uses them), and then every step from its step 3 on. Skip its
steps 1.1 to 1.3 and 2: the version check, the `git status` check, the baseline
and the dependency are done; the baseline is the result of step 1.3 of this
skill. Its step 1.4 applies to 2.x as written: the packaged config turned
`fix_default_query_builder` on for the connection named `clickhouse` only. Its verification and report replace a separate
verification and report of this skill: add to its report the package and
namespace change, the files of step 3, every `PhpClickHouseLaravel` mention
you left on purpose, and what step 4 found about serialized values, cache and
queues.
