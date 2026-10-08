---
name: lc-upgrade-2x-to-3x
description: Upgrades a Laravel application from oralunal/phpclickhouse-laravel 2.x to oralunal/laravel-clickhouse 3.x. Switches the Composer package, renames the PhpClickHouseLaravel namespace to Oralunal\LaravelClickHouse in code, migrations, config and tests, refreshes Laravel's package caches, and verifies the result. Use when the user runs /lc-upgrade-2x-to-3x or asks to upgrade phpclickhouse-laravel or laravel-clickhouse to version 3.
---

# phpclickhouse-laravel 2.x → laravel-clickhouse 3.x upgrade

You are upgrading this Laravel application from `oralunal/phpclickhouse-laravel`
2.x to `oralunal/laravel-clickhouse` 3.x. Two things change, nothing else:

- the Composer package name: `oralunal/phpclickhouse-laravel` →
  `oralunal/laravel-clickhouse`;
- the namespace prefix: `PhpClickHouseLaravel\` → `Oralunal\LaravelClickHouse\`.

Class names, the folder layout below the prefix, configuration, the generated
SQL and the behavior are the same as in 2.0.2:

| 2.x | 3.x |
| --- | --- |
| `PhpClickHouseLaravel\BaseModel` | `Oralunal\LaravelClickHouse\BaseModel` |
| `PhpClickHouseLaravel\Migration` | `Oralunal\LaravelClickHouse\Migration` |
| `PhpClickHouseLaravel\ClickhouseBuilder\…` | `Oralunal\LaravelClickHouse\ClickhouseBuilder\…` |
| `PhpClickHouseLaravel\ClickhouseSchemaBuilder\…` | `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\…` |
| `PhpClickHouseLaravel\Enum\Enum` | `Oralunal\LaravelClickHouse\Enum\Enum` |
| `use function PhpClickHouseLaravel\ClickhouseBuilder\raw;` | `use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;` |

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
  `rector.php` or IDE helper files. Exclude `vendor/`, `node_modules/`,
  `storage/`, `bootstrap/cache/` and `.git/`.
- When a change needs a decision only the user can make, stop and ask. The
  steps below say when.
- Do not commit unless the user asks you to.

## Step 1: Assess

1. Read `composer.json` and run `composer show 'oralunal/*'` to see the
   required and installed versions.
   - `oralunal/phpclickhouse-laravel` 2.x: do every step.
   - `oralunal/phpclickhouse-laravel` 1.x: stop. Tell the user to run
     `/plc-upgrade-1x-to-2x` first and to come back to this skill afterwards.
   - `oralunal/laravel-clickhouse` 3.x is already installed: do step 1's
     searches and then every step from step 3 on for what they find.
2. Check `git status`. If the tree has uncommitted changes that are not yours,
   tell the user and suggest committing them or working on a new branch before
   you continue.
3. Run the test suite once (`php artisan test`, `vendor/bin/pest` or
   `vendor/bin/phpunit`, whichever the project uses) and record the result as
   the baseline. Tests that already failed before the upgrade are not yours to
   fix. Mention them in the final report.
4. Search for every pattern below and keep the list of matches. The patterns
   are PCRE and work with `rg`/`grep -P` or your search tool.

   - `PhpClickHouseLaravel`: every reference to the 2.x namespace, in any
     file. This also finds escaped forms such as `'PhpClickHouseLaravel\\BaseModel'`
     in strings, JSON, YAML or NEON, and grouped imports such as
     `use PhpClickHouseLaravel\{BaseModel, Migration};`.
   - `oralunal/phpclickhouse-laravel`: the requirement in `composer.json`
     (and in the `composer.json` of any local package in the project).
   - `Tinderbox\\ClickhouseBuilder|PhpClickHouseSchemaBuilder\\`: 1.x code. If
     this finds anything, stop and tell the user to finish
     `/plc-upgrade-1x-to-2x` first.

## Step 2: Switch the dependency

```bash
composer remove oralunal/phpclickhouse-laravel --no-update
composer require oralunal/laravel-clickhouse:^3.0 --with-all-dependencies
```

`oralunal/laravel-clickhouse` conflicts with `oralunal/phpclickhouse-laravel`,
so Composer refuses to install it while anything still requires the old name.
If the error names a package of the project itself, update its
`composer.json` too. If it names a third-party package, stop and ask the user.

The `artisan` scripts that Composer runs afterwards may fail with a
class-not-found error for a `PhpClickHouseLaravel\…` class, because the
application code still uses the old namespace. That is expected until step 3;
continue.

## Step 3: Rename the namespace

Replace the prefix in every file from step 1: `use` statements, `use function`
imports, fully qualified names, PHPDoc types, and strings or config values that
hold class names. Keep the backslash style of each occurrence: in a string with
escaped backslashes, `PhpClickHouseLaravel\\` becomes
`Oralunal\\LaravelClickHouse\\`.

To replace them across the project (Perl works the same on Linux and macOS):

```bash
grep -rlI 'PhpClickHouseLaravel' . \
    --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=storage \
    --exclude-dir=.git --exclude-dir=cache --exclude=composer.lock \
  | xargs perl -pi -e 's/PhpClickHouseLaravel(\\+)/Oralunal$1LaravelClickHouse$1/g'
```

Then search for `PhpClickHouseLaravel` again. What remains is not followed by
a backslash, such as a mention in a comment or in documentation. Change it when
it names the package's namespace. Leave text that deliberately refers to the
old name, such as a changelog entry, and list it in the report.

`bootstrap/providers.php` and `config/app.php` normally do not list the
package's service provider, because it is auto-discovered. If one of them
lists `PhpClickHouseLaravel\ClickhouseServiceProvider`, the replacement above
renames it to `Oralunal\LaravelClickHouse\ClickhouseServiceProvider`; keep it.

## Step 4: Refresh Laravel's caches

1. Run `php artisan package:discover` and `php artisan optimize:clear`.

   If they fail with
   `Class "PhpClickHouseLaravel\ClickhouseServiceProvider" not found`,
   Laravel's cached package manifest still lists the 2.x provider. This happens
   when Composer ran without scripts. Delete `bootstrap/cache/packages.php` and
   `bootstrap/cache/services.php` (Laravel regenerates them), then run
   `php artisan package:discover` again.

2. Values serialized with the 2.x class names cannot be unserialized by 3.x:
   cache entries, queued jobs and sessions that hold objects of the package's
   classes, such as `RawColumn` or the builder enums. Do not change code for
   this and do not clear caches or queues yourself. Look for places that put
   such objects into the cache, a queue or the session. Tell the user what you
   found, and that a deploy of this upgrade should let the queues drain first
   and clear the application cache afterwards if any such values exist.

## Step 5: Verify

Run all of these and fix what they report:

1. `composer dump-autoload` and `php artisan package:discover`. Both must
   finish without errors.
2. `php -l` on every PHP file you changed.
3. Run every search from step 1 again.
   - `oralunal/phpclickhouse-laravel` must not be required anywhere.
   - `PhpClickHouseLaravel` only remains where step 3 deliberately left it.
4. `php artisan migrate:status`. This loads the migration files, so a missing
   class shows up here. If the database cannot be reached, run
   `php -l database/migrations/*.php` instead and say so in the report.
5. Run the test suite and compare it with the baseline from step 1. Every
   failure that is new must be fixed or explained.

## Step 6: Report

End with a short summary for the user:

- the package and version change in `composer.json`, and any other package you
  had to change;
- how many files changed in step 3, with the file list, and every remaining
  `PhpClickHouseLaravel` mention you left on purpose;
- what step 4 found about serialized values, cache and queues;
- every question you asked and the decision taken;
- the test results compared with the baseline, and any failures that already
  existed.
