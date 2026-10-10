# Upgrade

## Upgrade to 4.x

4.x is the latest version. It is the `oralunal/laravel-clickhouse` package. See [Upgrade to 4.x](/getting-started/upgrading).
The `lc-upgrade-2x-to-4x` coding-agent skill of 4.x changes the package and the namespace, and does the upgrade.

## Upgrade to 3.x

3.0 renames the package and its namespace. Nothing else changes. See [Upgrade from 2.x to 3.0](/3.x/getting-started/upgrading#upgrade-from-2-x-to-3-0).

## Upgrade from 1.x to 2.0

The PHP (`^8.5`) and Laravel (`^13`) requirements do not change.
Upgrade to 2.0.2 or later. 2.0.0 does not contain the schema builder and the enum base class. In 2.0.1, parallel test processes drop the ClickHouse tables of other processes.

2.0 contains copies of the packages that 1.x installed:

- the query builder, `oralunal/clickhouse-builder`;
- the schema builder of `Migration::createMergeTree()`, `glushkovds/php-clickhouse-schema-builder`;
- the enum base class, `myclabs/php-enum`.

They write the same SQL, but their classes are in the namespace of this package. Apart from Laravel, the only dependency is `smi2/phpclickhouse`.

You must change your application only when:

- your code or migrations use `Tinderbox\ClickhouseBuilder`, `PhpClickHouseSchemaBuilder` or `MyCLabs\Enum`;
- your code calls `raw()`, `tp()` or `array_flatten()`;
- your code uses the file and temporary-table methods of the old builder;
- you run `migrate:fresh` while MySQL or PostgreSQL has the `migrations` table. See [step 7](#_7-migrate-fresh-also-empties-the-secondary-clickhouse-connections).

Applications that use only `BaseModel`, `Migration::write()`, `RawColumn` and `DB::connection('clickhouse')` work without changes.

### With a coding agent

1. Update the package:

   ```sh
   composer require oralunal/phpclickhouse-laravel:^2.0.2 --with-all-dependencies
   ```

2. Install the skill:

   ```sh
   php artisan clickhouse:install-skills
   ```

   The command finds the files of each agent in your project, such as `.claude/`, `.cursor/`, `.github/copilot-instructions.md` and `AGENTS.md`.
   It writes the skill to the skills directory of the agent, for example `.claude/skills/plc-upgrade-1x-to-2x/SKILL.md`. To select the agents, give `--agent=claude_code --agent=cursor`.

3. In your agent, run `/plc-upgrade-1x-to-2x`. If your agent does not show skills as slash commands, ask it to "upgrade phpclickhouse-laravel to 2.x".

The agent does the steps below. It asks you before it changes code that it cannot decide alone, such as the use of the removed file methods. At the end, it compares the test results with the results before the upgrade.

If `php artisan` fails after step 1 with `Class "Tinderbox\ClickhouseBuilder\...\ClickhouseServiceProvider" not found`, delete `bootstrap/cache/packages.php` and `bootstrap/cache/services.php`, and try again.
If the application does not start, copy the skill yourself:

```sh
mkdir -p .claude/skills
cp -r vendor/oralunal/phpclickhouse-laravel/resources/skills/plc-upgrade-1x-to-2x .claude/skills/
```

### By hand

To find the code that you must change, run:

```sh
grep -rnE 'Tinderbox\\ClickhouseBuilder|PhpClickHouseSchemaBuilder|MyCLabs\\Enum|[^>:$a-zA-Z_]raw\(|array_flatten\(|[^a-zA-Z_]tp\(|into_memory_table|file_from|addFile\(|getFiles\(' \
  app config database routes tests
```

#### 1. Update the package

```sh
composer require oralunal/phpclickhouse-laravel:^2.0.2
```

If your `composer.json` has `oralunal/clickhouse-builder` or `glushkovds/php-clickhouse-schema-builder`, remove them:

```sh
composer remove oralunal/clickhouse-builder glushkovds/php-clickhouse-schema-builder
```

2.0 does not install `the-tinderbox/clickhouse-php-client` and `myclabs/php-enum`. If your code uses one of them, require it yourself.

#### 2. Rename the namespaces

Only the namespace prefix changes. The class names and the folders stay the same.

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

Change your migration files too. A migration with `PhpClickHouseSchemaBuilder\Tables\MergeTree` fails with `Class not found` when it runs again, for example in CI.

To replace the names (GNU sed; on macOS, use `sed -i ''`):

```sh
grep -rlE 'Tinderbox\\ClickhouseBuilder|PhpClickHouseSchemaBuilder\\' app config database routes tests \
  | xargs sed -i -e 's/Tinderbox\\ClickhouseBuilder/PhpClickHouseLaravel\\ClickhouseBuilder/g' \
                 -e 's/PhpClickHouseSchemaBuilder\\/PhpClickHouseLaravel\\ClickhouseSchemaBuilder\\/g'
```

#### 3. Import the functions

`raw()`, `tp()` and `array_flatten()` are not global functions in 2.0. They are in the `PhpClickHouseLaravel\ClickhouseBuilder` namespace.
Without an import, PHP fails with `Call to undefined function raw()`:

```php
use function PhpClickHouseLaravel\ClickhouseBuilder\raw;

$query->select(raw('count() AS n'));
```

You can also use the classes:

```php
use PhpClickHouseLaravel\ClickhouseBuilder\Query\Expression;
use PhpClickHouseLaravel\RawColumn;

$query->select(new Expression('count() AS n'));
$query->select(new RawColumn('count()', 'n')); // count() AS `n`
```

#### 4. Stop the use of the removed methods

These parts worked only with `the-tinderbox/clickhouse-php-client`. The `Builder` of this package uses `smi2/phpClickHouse`, so they did nothing useful. 2.0 removes them:

| Removed | Use |
| --- | --- |
| `addFile()`, `getFiles()`, `values()`, `getValues()`, `into_memory_table()`, `file_from()` | `insertAssoc()`, `insertBulk()` or `buffer()` |
| `Tinderbox\ClickhouseBuilder\Query\Builder` | `BaseModel::select()` or `where()`, or `DB::connection('clickhouse')->table(...)` |
| `Tinderbox\ClickhouseBuilder\Integrations\Laravel\Connection` and `Builder` | `PhpClickHouseLaravel\Connection` and `PhpClickHouseLaravel\Builder` |
| `Tinderbox\ClickhouseBuilder\Integrations\Laravel\ClickhouseServiceProvider` | `PhpClickHouseLaravel\ClickhouseServiceProvider`, which Laravel finds |

If `bootstrap/providers.php` or `config/app.php` has the old `ClickhouseServiceProvider`, remove it. The class does not exist, so the application does not start.

If all Artisan commands fail with `Class "Tinderbox\ClickhouseBuilder\Integrations\Laravel\ClickhouseServiceProvider" not found`, the cached package manifest of Laravel still has the 1.x provider.
This occurs when Composer ran with `--no-scripts`. Delete `bootstrap/cache/packages.php` and `bootstrap/cache/services.php`, and run `php artisan package:discover`.

#### 5. whereIn() with a string

In 1.x, `whereIn()`, `preWhereIn()`, `whereGlobalIn()` and `havingIn()` read a string as a table, but only for a file of `addFile()`. In 2.0, a string is always a value:

```php
$query->whereIn('id', 'ids'); // WHERE `id` IN 'ids'
```

For a table or a sub-query, write it:

```php
use function PhpClickHouseLaravel\ClickhouseBuilder\raw;

$query->whereIn('id', raw('allowed_ids'));                           // WHERE `id` IN allowed_ids
$query->whereIn('id', fn ($q) => $q->select('id')->from('allowed')); // WHERE `id` IN (SELECT `id` FROM `allowed`)
```

#### 6. The return type of Column::subQuery()

`Column::subQuery()` and `Column::getSubQuery()` return a `BaseBuilder`. In 1.x, they declared the `Query\Builder` of the Tinderbox client, so a sub-query in a column threw a `TypeError`.
Change only code that compares the returned value with the old class.

#### 7. migrate:fresh also empties the secondary ClickHouse connections

This step applies only when MySQL or PostgreSQL has the `migrations` table and some migrations write to ClickHouse.
In 1.x, `migrate:fresh` emptied only the MySQL or PostgreSQL database. Migrations with `CREATE TABLE IF NOT EXISTS` kept their ClickHouse data.

In 2.0, `migrate:fresh` also empties:

- each ClickHouse connection that a migration uses;
- each ClickHouse connection that has a dump in `database/schema`.

The command does not touch other ClickHouse connections. In production, it asks for a confirmation.

`RefreshDatabase` and the other database test traits run `migrate:fresh` too. For tests with `--parallel`, use 2.0.2 or later.
See [Migration commands](/2.x/schema/migration-commands#parallel-tests).

#### 8. The enums extend the Enum class of the package

`Operator`, `Format`, `JoinType`, `JoinStrict` and `OrderDirection` extend `PhpClickHouseLaravel\Enum\Enum`, not `MyCLabs\Enum\Enum`.
Their constants and methods, such as `isValid()`, `getValue()` and `Operator::EQUALS()`, do not change. Change only code that compares them with `MyCLabs\Enum\Enum`.
