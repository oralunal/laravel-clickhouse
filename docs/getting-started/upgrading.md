# Upgrade

| From | Package before | Skill |
| --- | --- | --- |
| 3.x | `oralunal/laravel-clickhouse` | `/lc-upgrade-3x-to-4x` |
| 2.x | `oralunal/phpclickhouse-laravel` | `/lc-upgrade-2x-to-4x` |
| 1.x | `oralunal/phpclickhouse-laravel` | `/lc-upgrade-1x-to-4x` |

PHP 8.5 and Laravel 13 stay the requirements.
The complete guide is [UPGRADE.md](https://github.com/oralunal/laravel-clickhouse/blob/master/UPGRADE.md).
The [changelog](https://github.com/oralunal/laravel-clickhouse/blob/master/CHANGELOG.md) shows each change with the SQL before and after.

## Upgrade with a coding agent

A coding-agent skill does the upgrade, checks the result, and asks you when a change needs a decision.
It does not run `migrate:fresh` and does not change the data of a database. See [Coding-agent skills](/reference/agent-skills).

### From 3.x

1. Update the package and install the skills:

   ```sh
   composer require oralunal/laravel-clickhouse:^4.0 --with-all-dependencies
   php artisan clickhouse:install-skills
   ```

2. In your agent, run `/lc-upgrade-3x-to-4x`.

### From 2.x

1. Replace the package and install the skills:

   ```sh
   composer remove oralunal/phpclickhouse-laravel --no-update
   composer require oralunal/laravel-clickhouse:^4.0 --with-all-dependencies
   php artisan clickhouse:install-skills
   ```

2. In your agent, run `/lc-upgrade-2x-to-4x`.

### From 1.x

1. Replace the packages and install the skills:

   ```sh
   composer remove oralunal/phpclickhouse-laravel oralunal/clickhouse-builder glushkovds/php-clickhouse-schema-builder --no-update
   composer require oralunal/laravel-clickhouse:^4.0 --with-all-dependencies
   php artisan clickhouse:install-skills
   ```

2. In your agent, run `/lc-upgrade-1x-to-4x`.

If `php artisan` fails with a class-not-found error after step 1, copy the skills by hand:

```sh
mkdir -p .claude/skills
cp -r vendor/oralunal/laravel-clickhouse/resources/skills/lc-upgrade-* .claude/skills/
```

## Changes in 4.0

These changes can make 3.x code fail:

| Change | What to do |
| --- | --- |
| `min()`, `max()`, `sum()`, `avg()`, `average()` and `aggregate()` quote a string as a column name. | Pass SQL with `raw()`: `sum(raw('price * qty'))`. |
| New signatures: `Builder::insert()`, `delete()`, `update()`, `settings()`, `BaseBuilder::sample()`, `BaseModel::optimize()`, `SchemaBuilder::build()`. | Make the overrides of subclasses compatible. |
| `BaseModel` uses `Oralunal\LaravelClickHouse\Concerns\HasAttributes`. `usesTimestamps()`, `getRelationValue()`, `relationResolver()` and `relationLoaded()` are gone. | Use `$casts` for date columns. Use accessors for computed keys. |
| A hand-written `#@?` marker is not a binding. | Write `?`. |
| `select()` refuses a `FORMAT` clause other than `JSON`, `JSONStrings`, `JSONCompact` and `JSONCompactStrings`. | Remove the clause, or read the result as the exception message tells. |
| Transactions throw a `LogicException`. | Use the `DatabaseTruncation` test trait. |

These changes do not make code fail, but they change what a write does:

- `whereNotIn()` with an empty list matches all rows, as in Laravel. Before `delete()`, check that the list is not empty.
- `delete()` and `update()` refuse clauses that a ClickHouse mutation ignores, such as `JOIN` and `LIMIT`.
- The package writes floats with all digits. A float that PHP calculates is cut at the scale of a `Decimal` column. Round it first.
- `insertAssoc()` and `buffer()` refuse a row whose keys are not the keys of the first row.
- On a fresh database, `Schema::create()` and `Schema::table()` write all of the blueprint. 3.x wrote names and types only.
