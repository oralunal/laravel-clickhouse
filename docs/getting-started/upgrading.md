# Upgrade

| From | Package before | Skill |
| --- | --- | --- |
| 3.x | `oralunal/laravel-clickhouse` | `/lc-upgrade-3x-to-4x` |
| 2.0.1, 2.0.2 | `oralunal/phpclickhouse-laravel` | `/lc-upgrade-2x-to-4x` |
| 1.x, 2.0.0 | `oralunal/phpclickhouse-laravel` | `/lc-upgrade-1x-to-4x` |

PHP 8.5 and Laravel 13 stay the requirements.
2.0.0 still required the libraries that 2.0.1 bundled, so upgrade 2.0.0 as 1.x.

From 4.0, run `composer require oralunal/laravel-clickhouse:^4.1`. 4.1 makes each row a `stdClass` object and gives
each value the PHP type of its column. See
[Upgrading from 4.0 to 4.1](https://github.com/oralunal/laravel-clickhouse/blob/master/UPGRADE.md#upgrading-from-40-to-41).
The complete guide is [UPGRADE.md](https://github.com/oralunal/laravel-clickhouse/blob/master/UPGRADE.md).
The [changelog](https://github.com/oralunal/laravel-clickhouse/blob/master/CHANGELOG.md) shows each change with the SQL before and after.

## Upgrade with a coding agent

A coding-agent skill does the upgrade, checks the result, and asks you when a change needs a decision.
It does not run `migrate:fresh` and does not change the data of a database. See [Coding-agent skills](/reference/agent-skills).

First, commit your work, run your test suite and keep its output, for example `php artisan test > tests-before-4x.txt`.
After the update the old code no longer loads, so the skill compares its run with this result.

### From 3.x

1. Update the package and install the skills:

   ```sh
   composer require oralunal/laravel-clickhouse:^4.1 --with-all-dependencies
   php artisan clickhouse:install-skills
   ```

2. Start a new session of your agent and run `/lc-upgrade-3x-to-4x`.

### From 2.x

1. Replace the package and install the skills:

   ```sh
   composer remove oralunal/phpclickhouse-laravel --no-update
   composer require oralunal/laravel-clickhouse:^4.1 --with-all-dependencies
   php artisan clickhouse:install-skills
   ```

   If `composer.json` lists `oralunal/phpclickhouse-laravel` in `extra.laravel.dont-discover`, rename it to
   `oralunal/laravel-clickhouse`.

2. Start a new session of your agent and run `/lc-upgrade-2x-to-4x`.

### From 1.x

1. Remove `Tinderbox\ClickhouseBuilder\Integrations\Laravel\ClickhouseServiceProvider` from `bootstrap/providers.php`
   or `config/app.php` if it is listed there.
2. Replace the packages and install the skills:

   ```sh
   composer remove oralunal/phpclickhouse-laravel oralunal/clickhouse-builder glushkovds/php-clickhouse-schema-builder --no-update
   composer require oralunal/laravel-clickhouse:^4.1 --with-all-dependencies
   php artisan clickhouse:install-skills
   ```

   If your own code uses `myclabs/php-enum` or `the-tinderbox/clickhouse-php-client`, add it to the same
   `composer require`.

3. Start a new session of your agent and run `/lc-upgrade-1x-to-4x`.

In an agent without slash commands for skills, ask for the skill by name, as in
"Upgrade laravel-clickhouse from 3.x to 4.x with the lc-upgrade-3x-to-4x skill."

If `php artisan` fails with a class-not-found error after the update, delete `bootstrap/cache/packages.php` and
`bootstrap/cache/services.php` and try again. If the app still does not boot, copy the skills by hand into the skills
directory of your agent, such as `.claude/skills` for Claude Code or `.agents/skills` for Codex, Zed and Gemini:

```sh
mkdir -p .claude/skills
cp -r vendor/oralunal/laravel-clickhouse/resources/skills/lc-upgrade-* .claude/skills/
```

## Changes in 4.0

4.0 uses Laravel's own APIs for everything that Laravel has: Eloquent models, Laravel's query builder and Laravel
migrations. It removes the parts of 3.x that did the same work, without a deprecation period:

| Removed | Use instead |
| --- | --- |
| `BaseModel` and its `insertAssoc()`, `insertBulk()`, `prepareAndInsert*()`, `getRows()`, `$tableForInserts` and `$tableSources` | `Oralunal\LaravelClickHouse\Eloquent\Model` with `insert()`, `create()` and `buffer()`, one model for each table. See [Define an Eloquent model](/models/defining-models). |
| The package's own `Builder`, `ClickhouseBuilder\*`, `RawColumn`, `raw()` and `InsertArray` | The `QueryBuilder` that `DB::connection('clickhouse')->table()` returns, `DB::raw()` and PHP arrays. See [Query builder basics](/query-builder/basics). |
| `format()` of the builder | `getStatement($format)->rawData()`, or `selectStatement($sql)` of the connection |
| `getCountQuery()`, `asyncWithQuery()` | `count()`, `exists()`, `forPage()` and [parallel queries](/advanced/parallel-queries) |
| `Oralunal\LaravelClickHouse\Migration` with `write()` and `createMergeTree()`, `ClickhouseSchemaBuilder\*` | Laravel migrations with `Schema::connection(...)->create()` or `DB::connection(...)->statementOnEveryNode($sql)`. See [Migrations](/schema/migrations). |
| `Enum\Enum` and the enums of the old builder | The native enums `Enums\Format` and `Enums\DateTimePrecision` |
| The package's `Grammar` | `LiteralGrammar` and `newLiteralGrammar()` |
| The option `fix_default_query_builder` | Nothing. The query builder is always the package's `QueryBuilder`. |

Each row is a `stdClass` object, as on Laravel's other connections: `get()`, `first()` and `cursor()` of the query
builder, and `select()` and `cursor()` of the connection. 3.x returned associative arrays, so a read such as
`$row['id']` fails. Read `$row->id`, or keep the arrays with `getStatement()->rows()` of a query and
`selectStatement($sql)->rows()` of the connection.

Each value has the PHP type of its ClickHouse type, on every ClickHouse version: a `UInt64` is an `int`, where 3.x
gave a string on 24.8, a whole `Float64` is a float, NaN is `NAN`, and a `Decimal` is a string with every digit, such
as `'1.50'`, where 3.x gave a float. See [Read results](/query-builder/reading-results#rows).

Some changes make the same code do something else, without an error. Among them:

- `where('id', [1, 2])` compared with `IN (1, 2)` in 3.x. Laravel compares with the first value only, so a `delete()`
  or an `update()` changes fewer rows. Use `whereIn()`.
- `whereNotIn('id', [])` matches every row, as in Laravel: as the only condition of a `delete()`, it deletes every row.
- A model ignores `$tableForInserts` and `$tableSources`, and casts no longer apply to its writes.
- `tinyInteger()`, `smallInteger()`, `integer()` and `bigInteger()` create `Int8`, `Int16`, `Int32` and `Int64`, and
  their unsigned forms `UInt8` to `UInt64`. `id()` creates a `UInt64` column. 3.x wrote `Int16` for `tinyInteger()`,
  and signed types for the unsigned columns and `id()`.
- The `delete()` of the query builder takes Laravel's `$id` first. Write `delete(lightweight: true)`. A `bool` as
  the first argument throws.
- `min()`, `max()`, `sum()`, `avg()` and `aggregate()` are Laravel's: they drop the select list and the order.
  `count()` and the `paginate()` total count the rows that `get()` returns.
- A connection with `retries` sends a write again only when it never reached the server. 3.x sent every failed
  request again. Set `retry_on` to `any` to keep that. See [Retries](/advanced/timeouts-and-retries#retries).

[UPGRADE.md](https://github.com/oralunal/laravel-clickhouse/blob/master/UPGRADE.md) has each step, with the code
before and after.
