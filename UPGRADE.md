# Upgrade Guide

## Upgrading from 2.x to 3.0

PHP (`^8.5`) and Laravel (`^13`) requirements are unchanged. Still on 1.x?
Follow [Upgrading from 1.x to 2.0](#upgrading-from-1x-to-20) first, then come
back here.

3.0 renames the package and its namespace:

| | 2.x | 3.0 |
| --- | --- | --- |
| Composer package | `oralunal/phpclickhouse-laravel` | `oralunal/laravel-clickhouse` |
| Namespace | `PhpClickHouseLaravel\` | `Oralunal\LaravelClickHouse\` |

Nothing else changes. Class names below the namespace, the folder layout,
configuration, the generated SQL and the behavior are the same as in 2.0.2, for
example `PhpClickHouseLaravel\ClickhouseSchemaBuilder\Tables\MergeTree` becomes
`Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Tables\MergeTree`. 2.0.2 is
the last release of `oralunal/phpclickhouse-laravel`, and the package is
abandoned. 3.0 conflicts with it, so Composer never installs both.

### Let your coding agent do it

1. Switch the package and install the skills:

   ```sh
   composer remove oralunal/phpclickhouse-laravel --no-update
   composer require oralunal/laravel-clickhouse:^3.0 --with-all-dependencies
   php artisan clickhouse:install-skills
   ```

2. In your agent, run:

   ```text
   /lc-upgrade-2x-to-3x
   ```

   If your agent does not offer skills as slash commands, ask it to
   "upgrade phpclickhouse-laravel to laravel-clickhouse 3.x".

The agent renames the namespace everywhere, refreshes Laravel's caches, checks
the result, and reports how the test suite compares with the run before the
upgrade. `clickhouse:install-skills` finds your agents from their project
files (`.claude/`, `.cursor/`, `AGENTS.md`, …); pass `--agent=claude_code` and
so on to choose them yourself.

If `php artisan` fails after step 1 with a class-not-found error for a
`PhpClickHouseLaravel\…` class, your app uses the 2.x namespace while it boots.
Copy the skill by hand instead of running `clickhouse:install-skills`, for
example:

```sh
mkdir -p .claude/skills
cp -r vendor/oralunal/laravel-clickhouse/resources/skills/lc-upgrade-2x-to-3x .claude/skills/
```

### Or upgrade by hand

1. Switch the package:

   ```sh
   composer remove oralunal/phpclickhouse-laravel --no-update
   composer require oralunal/laravel-clickhouse:^3.0 --with-all-dependencies
   ```

   The `artisan` scripts that Composer runs may fail with a class-not-found
   error until step 2 is done.

2. Rename the namespace in your code, migrations, config and tests. This keeps
   the escaping of strings such as `'PhpClickHouseLaravel\\BaseModel'`:

   ```sh
   grep -rlI 'PhpClickHouseLaravel' . \
       --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=storage \
       --exclude-dir=.git --exclude-dir=cache --exclude=composer.lock \
     | xargs perl -pi -e 's/PhpClickHouseLaravel(\\+)/Oralunal$1LaravelClickHouse$1/g'
   ```

   Then search for `PhpClickHouseLaravel` again and check what is left, such
   as mentions in comments.

3. Run `php artisan package:discover` and `php artisan optimize:clear`. If they
   fail with `Class "PhpClickHouseLaravel\ClickhouseServiceProvider" not found`,
   delete `bootstrap/cache/packages.php` and `bootstrap/cache/services.php` and
   run `php artisan package:discover` again.

4. Values serialized with the 2.x class names cannot be unserialized by 3.0:
   cache entries, queued jobs and sessions that hold objects of the package's
   classes, such as `RawColumn` or the builder enums. If your app stores such
   values, let the queues drain before you deploy and clear the cache
   afterwards.

## Upgrading from 1.x to 2.0

These steps take you to `oralunal/phpclickhouse-laravel` 2.0.2. Continue with
[Upgrading from 2.x to 3.0](#upgrading-from-2x-to-30) afterwards.

PHP (`^8.5`) and Laravel (`^13`) requirements are unchanged.

Upgrade to **2.0.2 or later**. 2.0.0 was tagged before the schema builder and
the enum base class were bundled. In 2.0.1, parallel test processes drop each
other's ClickHouse tables (see
[step 7](#7-migratefresh-also-empties-secondary-clickhouse-connections)).

2.0 bundles the libraries this package used to pull in:

- the query builder (`oralunal/clickhouse-builder`);
- the schema builder behind `Migration::createMergeTree()`
  (`glushkovds/php-clickhouse-schema-builder`);
- the enum base class (`myclabs/php-enum`).

They generate the same SQL as before, but their classes are in this package's
namespaces now. Apart from Laravel, the only remaining dependency is
`smi2/phpclickhouse`.

**Do you need to change anything?** Only in these cases:

- Your code or migrations mention `Tinderbox\ClickhouseBuilder`,
  `PhpClickHouseSchemaBuilder` or `MyCLabs\Enum`.
- Your code calls `raw()`, `tp()` or `array_flatten()`.
- Your code uses the old builder's file and temporary-table API.
- You run `migrate:fresh` while MySQL or PostgreSQL holds the `migrations`
  table (see [step 7](#7-migratefresh-also-empties-secondary-clickhouse-connections)).

Apps that only use `BaseModel`, `Migration::write()`, `RawColumn` and
`DB::connection('clickhouse')` keep working without changes.

### Let your coding agent do it

The package ships an agent skill that performs this whole upgrade and checks
the result. It works with Claude Code, Cursor, GitHub Copilot, Codex, Junie,
OpenCode, Amp, Gemini/Antigravity, Kiro, Pi, Zed, Grok Build, Factory Droid and
other agents that read `AGENTS.md`. Nothing else needs to be installed.

1. Update the package:

   ```sh
   composer require oralunal/phpclickhouse-laravel:^2.0.2 --with-all-dependencies
   ```

2. Install the skill:

   ```sh
   php artisan clickhouse:install-skills
   ```

   The command looks for each agent's files in your project (`.claude/`,
   `.cursor/`, `.github/copilot-instructions.md`, `AGENTS.md`, …) and writes
   the skill to that agent's skills directory, for example
   `.claude/skills/plc-upgrade-1x-to-2x/SKILL.md`. To choose the agents
   yourself, pass `--agent=claude_code --agent=cursor`. Run
   `php artisan clickhouse:install-skills --help` for the list of names.

3. In your agent, run:

   ```text
   /plc-upgrade-1x-to-2x
   ```

   If your agent does not offer skills as slash commands, ask it to
   "upgrade phpclickhouse-laravel to 2.x". The skill is picked up from its
   description.

The agent then follows every step below. It stops to ask you before changing
code whose meaning it cannot decide alone, such as uses of the removed
file/temporary-table API. At the end it reports what it changed and how the
test suite compares with the run before the upgrade.

If `php artisan` fails right after step 1 with
`Class "Tinderbox\ClickhouseBuilder\...\ClickhouseServiceProvider" not found`,
delete `bootstrap/cache/packages.php` and `bootstrap/cache/services.php` and
try again. If the app still does not boot, copy the skill by hand, for
example:

```sh
mkdir -p .claude/skills
cp -r vendor/oralunal/phpclickhouse-laravel/resources/skills/plc-upgrade-1x-to-2x .claude/skills/
```

### Or upgrade by hand

To find what needs changing, run:

```sh
grep -rnE 'Tinderbox\\ClickhouseBuilder|PhpClickHouseSchemaBuilder|MyCLabs\\Enum|[^>:$a-zA-Z_]raw\(|array_flatten\(|[^a-zA-Z_]tp\(|into_memory_table|file_from|addFile\(|getFiles\(' \
  app config database routes tests
```

### 1. Update the dependency

```sh
composer require oralunal/phpclickhouse-laravel:^2.0.2
```

If your `composer.json` lists `oralunal/clickhouse-builder` or
`glushkovds/php-clickhouse-schema-builder` directly, remove them. The bundled
copies replace them:

```sh
composer remove oralunal/clickhouse-builder glushkovds/php-clickhouse-schema-builder
```

`the-tinderbox/clickhouse-php-client` and `myclabs/php-enum` are no longer
installed either. If your own code uses one of them, require it yourself.

### 2. Rename the bundled namespaces

The builder classes are in `PhpClickHouseLaravel\ClickhouseBuilder` now, and
the schema builder classes are in `PhpClickHouseLaravel\ClickhouseSchemaBuilder`.
Only the namespace prefix changes; class names and the folder layout are the
same.

| 1.x | 2.0 |
| --- | --- |
| `Tinderbox\ClickhouseBuilder\Query\Expression` | `PhpClickHouseLaravel\ClickhouseBuilder\Query\Expression` |
| `Tinderbox\ClickhouseBuilder\Query\Enums\Operator` | `PhpClickHouseLaravel\ClickhouseBuilder\Query\Enums\Operator` |
| `Tinderbox\ClickhouseBuilder\Query\TwoElementsLogicExpression` | `PhpClickHouseLaravel\ClickhouseBuilder\Query\TwoElementsLogicExpression` |
| `Tinderbox\ClickhouseBuilder\Query\BaseBuilder` | `PhpClickHouseLaravel\ClickhouseBuilder\Query\BaseBuilder` |
| `Tinderbox\ClickhouseBuilder\Query\Grammar` | `PhpClickHouseLaravel\ClickhouseBuilder\Query\Grammar` |
| `Tinderbox\ClickhouseBuilder\Exceptions\*` | `PhpClickHouseLaravel\ClickhouseBuilder\Exceptions\*` |
| `PhpClickHouseSchemaBuilder\Tables\MergeTree` | `PhpClickHouseLaravel\ClickhouseSchemaBuilder\Tables\MergeTree` |
| `PhpClickHouseSchemaBuilder\Expression` | `PhpClickHouseLaravel\ClickhouseSchemaBuilder\Expression` |
| `PhpClickHouseSchemaBuilder\Engine`, `Column`, `TTL`, `Exceptions\*` | `PhpClickHouseLaravel\ClickhouseSchemaBuilder\Engine`, `Column`, `TTL`, `Exceptions\*` |

Your migration files count too. A migration that still imports
`PhpClickHouseSchemaBuilder\Tables\MergeTree` fails with `Class not found`
the next time it runs, for example on a fresh install or in CI.

To replace them across your app (GNU sed; on macOS use `sed -i ''`):

```sh
grep -rlE 'Tinderbox\\ClickhouseBuilder|PhpClickHouseSchemaBuilder\\' app config database routes tests \
  | xargs sed -i -e 's/Tinderbox\\ClickhouseBuilder/PhpClickHouseLaravel\\ClickhouseBuilder/g' \
                 -e 's/PhpClickHouseSchemaBuilder\\/PhpClickHouseLaravel\\ClickhouseSchemaBuilder\\/g'
```

### 3. Import the helper functions

`raw()`, `tp()` and `array_flatten()` used to be global functions. They are
now in the `PhpClickHouseLaravel\ClickhouseBuilder` namespace. If you call them
without importing them, PHP fails with `Call to undefined function raw()`.

```php
// 1.x
$query->select(raw('count() AS n'));

// 2.0
use function PhpClickHouseLaravel\ClickhouseBuilder\raw;

$query->select(raw('count() AS n'));
```

You can also use the classes directly:

```php
use PhpClickHouseLaravel\ClickhouseBuilder\Query\Expression;
use PhpClickHouseLaravel\RawColumn;

$query->select(new Expression('count() AS n'));
$query->select(new RawColumn('count()', 'n')); // count() AS `n`
```

### 4. Stop using the removed parts of the old builder

These parts only worked with `the-tinderbox/clickhouse-php-client`. This
package's `Builder` runs queries through `smi2/phpClickHouse`, so they never
did anything useful here. They are no longer included:

| Removed | Use instead |
| --- | --- |
| `addFile()`, `getFiles()`, `values()`, `getValues()` | Insert the rows with `insertAssoc()`, `insertBulk()` or `buffer()` |
| `into_memory_table()`, `file_from()` | Same as above |
| `Tinderbox\ClickhouseBuilder\Query\Builder` (Tinderbox-client builder) | `BaseModel::select()` / `where()`, or `DB::connection('clickhouse')->table(...)` |
| `Tinderbox\ClickhouseBuilder\Integrations\Laravel\Connection` / `Builder` | `PhpClickHouseLaravel\Connection` and `PhpClickHouseLaravel\Builder` |
| `Tinderbox\ClickhouseBuilder\Integrations\Laravel\ClickhouseServiceProvider` | `PhpClickHouseLaravel\ClickhouseServiceProvider` (auto-discovered) |

If you registered the old `ClickhouseServiceProvider` yourself in
`bootstrap/providers.php` or `config/app.php`, remove it. The class no longer
exists, so the app fails to boot while it is still listed.

If every artisan command, `package:discover` included, fails with
`Class "Tinderbox\ClickhouseBuilder\Integrations\Laravel\ClickhouseServiceProvider" not found`,
Laravel's cached package manifest still lists the 1.x provider. This happens
when Composer ran with `--no-scripts`. Delete `bootstrap/cache/packages.php`
and `bootstrap/cache/services.php`; Laravel regenerates them on the next
`php artisan package:discover`.

### 5. `whereIn()` with a string value

In 1.x, `whereIn()`, `preWhereIn()`, `whereGlobalIn()` and `havingIn()` turned
a string into a table reference, but only when it matched a file added with
`addFile()`. Since `addFile()` is gone, a string is now always quoted as a
value:

```php
$query->whereIn('id', 'ids');  // WHERE `id` IN 'ids'
```

To compare against a table or a sub-query, write it out explicitly:

```php
use function PhpClickHouseLaravel\ClickhouseBuilder\raw;

$query->whereIn('id', raw('allowed_ids'));                           // WHERE `id` IN allowed_ids
$query->whereIn('id', fn ($q) => $q->select('id')->from('allowed')); // WHERE `id` IN (SELECT `id` FROM `allowed`)
```

### 6. `Column::subQuery()` return type

`Column::subQuery()` and `Column::getSubQuery()` now declare `BaseBuilder` as
their return type. In 1.x they declared the Tinderbox-client `Query\Builder`,
which made column sub-queries built from this package's `Builder` throw a
`TypeError`. Only code that type-checks the return value against the old class
needs a change.

### 7. `migrate:fresh` also empties secondary ClickHouse connections

This step only applies when MySQL or PostgreSQL holds the `migrations` table
and some migrations write to ClickHouse. In 1.x, `migrate:fresh` emptied only
the MySQL or PostgreSQL database and left ClickHouse as it was. Migrations
written with `CREATE TABLE IF NOT EXISTS` therefore kept their ClickHouse data
across `migrate:fresh`.

In 2.0, `migrate:fresh` also empties these ClickHouse connections:

- every ClickHouse connection that a migration targets;
- every ClickHouse connection that has a dump in `database/schema`.

ClickHouse connections that no migration or dump refers to are left alone. The
command still asks for confirmation in production.

`RefreshDatabase` and the other database test traits run `migrate:fresh` too.
If you run your tests with `--parallel`, use 2.0.2 or later. From 2.0.2 on,
each test process gets its own database on these ClickHouse connections,
`<database>_test_<token>` (for example `analytics_test_1`), just as Laravel
does for the default connection. In 2.0.1 every process shared the configured
ClickHouse database, so one process's `migrate:fresh` dropped the tables that
another process was using. The ClickHouse user needs permission to create and
drop databases. Test runs without `--parallel` keep using the configured
database.

See
[Which connection holds the `migrations` table](README.md#which-connection-holds-the-migrations-table).

### 8. Enums extend the bundled `Enum` class

`Operator`, `Format`, `JoinType`, `JoinStrict` and `OrderDirection` now extend
`PhpClickHouseLaravel\Enum\Enum` instead of `MyCLabs\Enum\Enum`. Their constants
and methods (`isValid()`, `getValue()`, `Operator::EQUALS()` and so on) are
unchanged. Only code that type-checks against `MyCLabs\Enum\Enum` needs to
change.
