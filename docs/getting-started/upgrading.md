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

4.0 uses Laravel's own APIs for everything that Laravel has: Eloquent models, Laravel's query builder and Laravel
migrations. It removes the parts of 3.x that did the same work, without a deprecation period:

| Removed | Use instead |
| --- | --- |
| `BaseModel` and its `insertAssoc()`, `insertBulk()`, `prepareAndInsert*()`, `getRows()`, `$insertFormat` and `$tableForInserts` | `Oralunal\LaravelClickHouse\Eloquent\Model` with `insert()`, `create()` and `buffer()`. See [Define an Eloquent model](/models/defining-models). |
| The package's own `Builder`, `ClickhouseBuilder\*`, `RawColumn`, `raw()` and `InsertArray` | The `QueryBuilder` that `DB::connection('clickhouse')->table()` returns, `DB::raw()` and PHP arrays. See [Query builder basics](/query-builder/basics). |
| `format()` of the builder | `getStatement($format)->rawData()`, or `selectStatement($sql)` of the connection |
| `getQueryForCount()`, `getQueryForExists()`, `getQueryForPage()`, `asyncWithQuery()` | `count()`, `exists()`, `forPage()` and [parallel queries](/advanced/parallel-queries) |
| `Oralunal\LaravelClickHouse\Migration` with `write()` and `createMergeTree()`, `ClickhouseSchemaBuilder\*` | Laravel migrations with `Schema::connection(...)->create()` or `DB::connection(...)->statementOnEveryNode($sql)`. See [Migrations](/schema/migrations). |
| `Enum\Enum` and the enums of the old builder | The native enums `Enums\Format` and `Enums\DateTimePrecision` |
| The package's `Grammar` and `newBuilderGrammar()` | `LiteralGrammar` and `newLiteralGrammar()` |
| The option `fix_default_query_builder` | Nothing. The query builder is always the package's `QueryBuilder`. |

These changes make the same code do something else:

- `tinyInteger()`, `smallInteger()`, `integer()` and `bigInteger()` create `Int8`, `Int16`, `Int32` and `Int64`, and
  their unsigned forms `UInt8` to `UInt64`. `id()` creates a `UInt64` column. 3.x wrote wider signed types.
- At the `microsecond` precision, a condition on a date with a sub-second part writes `toDateTime64('...', 6)`.
  It compares the exact date. See [Dates and times](/query-builder/dates).
- The `delete()` of the query builder takes Laravel's `$id` first. Write `delete(lightweight: true)`. A `bool` as
  the first argument throws.
- `min()`, `max()`, `sum()`, `avg()` and `aggregate()` are Laravel's: they drop the select list and the order.
  `count()` and the `paginate()` total count the rows that `get()` returns.
- A connection with `retries` sends a write again only when it never reached the server. 3.x sent every failed
  request again. Set `retry_on` to `any` to keep that. See [Retries](/advanced/timeouts-and-retries#retries).

[UPGRADE.md](https://github.com/oralunal/laravel-clickhouse/blob/master/UPGRADE.md) has each step, with the code
before and after.
