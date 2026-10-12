---
name: lc-upgrade-3x-to-4x
description: Upgrades a Laravel application from oralunal/laravel-clickhouse 3.x to 4.x. Updates the dependency, rewrites the code that 4.0 removes (BaseModel to the package's Eloquent model, the package's query builder, RawColumn, raw() and the builder enums to Laravel's query builder, the Migration class with write() and createMergeTree() to Laravel migrations and the schema builder, the fix_default_query_builder option), reviews with the user the behavior changes that touch writes, reads and migrations, and verifies the result. Use when the user runs /lc-upgrade-3x-to-4x or asks to upgrade laravel-clickhouse to version 4.
---

# laravel-clickhouse 3.x → 4.x upgrade

You are upgrading this Laravel application from `oralunal/laravel-clickhouse`
3.x to 4.x. The package name, the `Oralunal\LaravelClickHouse` namespace and
the PHP (`^8.5`) and Laravel (`^13`) requirements stay the same.

4.0 is a clean break, without a deprecation phase. The package no longer has a
model, a query builder, a schema builder or a migration class of its own:

| 3.x | 4.x |
| --- | --- |
| `Oralunal\LaravelClickHouse\BaseModel` | `Oralunal\LaravelClickHouse\Eloquent\Model` (an Eloquent model) |
| `Oralunal\LaravelClickHouse\Builder`, `ClickhouseBuilder\Query\…` | `Oralunal\LaravelClickHouse\QueryBuilder` (Laravel's query builder with the ClickHouse clauses) |
| `Oralunal\LaravelClickHouse\Migration` (`write()`, `createMergeTree()`) | Laravel's `Migration`, with `DB` and `Schema` |
| `ClickhouseSchemaBuilder\…` (`Tables\MergeTree`, `Engine`, `Column`) | Laravel's schema builder (`Oralunal\LaravelClickHouse\SchemaBlueprint`) |
| `RawColumn`, `raw()`, `ClickhouseBuilder\Query\Expression`, `Expressions\InsertArray` | `DB::raw()`, `selectRaw()`, a PHP array |
| `Enum\Enum`, `ClickhouseBuilder\Query\Enums\…` | `Oralunal\LaravelClickHouse\Enums\Format` (a native enum); strings for operators and directions |
| `Oralunal\LaravelClickHouse\Grammar`, `WithClient` | `Connection::newLiteralGrammar()`, `escape()`, `$model->getConnection()` |
| `tp()`, `array_flatten()` | `tap()`, `Illuminate\Support\Arr::flatten()` |
| the `fix_default_query_builder` option | removed: `DB::connection(...)->table()` always returns `QueryBuilder` |

This skill rewrites every use of the removed code, then reviews with the user
the behavior changes that alter which rows a write touches, which values it
stores, what a read returns, or which tables a migration creates.

Work through the steps in order. Do not skip the checks. Once 4.x is
installed, the complete list of changes is the `[4.0.0]` section of
`vendor/oralunal/laravel-clickhouse/CHANGELOG.md` (the `[Unreleased]` section
before the release), the human-readable guide is
`vendor/oralunal/laravel-clickhouse/UPGRADE.md`, and every public class and
signature of 4.x is in
`vendor/oralunal/laravel-clickhouse/docs/reference/api.md` and under
`vendor/oralunal/laravel-clickhouse/src/`. Read them whenever a step below is
not enough to decide a case. Never invent an API: check that a method exists
before you write a call to it.

## Rules

- Only change what the upgrade requires. Do not reformat files or refactor
  unrelated code.
- Never edit anything under `vendor/`.
- Search the whole project, not just `app/`. Include `config/`, `database/`
  (migrations, seeders, factories), `routes/`, `tests/`, `bootstrap/` and any
  other directory that holds PHP code, such as `src/`, `modules/` or
  `packages/`. Exclude `vendor/`, `node_modules/`, `storage/`,
  `bootstrap/cache/` and `.git/`. The search patterns below are PCRE and work
  with `rg`/`grep -P` or your search tool.
- Never run `migrate:fresh`, `db:wipe`, `migrate:rollback`, `migrate` or any
  statement that changes data or tables of a real ClickHouse database. Reading
  is fine. `php artisan migrate --pretend` sends nothing and is fine.
- When a change needs a decision only the user can make, stop and ask. The
  steps below say when. Never guess what data a write is meant to change.
- Do not commit unless the user asks you to.

## Step 1: Assess

1. Read `composer.json` and run `composer show 'oralunal/*'` to see the
   required and installed versions.
   - `oralunal/laravel-clickhouse` 3.x: do every step.
   - `oralunal/phpclickhouse-laravel` 2.x: stop and tell the user to run
     `/lc-upgrade-2x-to-4x` instead.
   - `oralunal/phpclickhouse-laravel` 1.x: stop and tell the user to run
     `/lc-upgrade-1x-to-4x` instead.
   - `oralunal/laravel-clickhouse` 4.x is already installed: skip step 2 and do
     every other step for what the searches find.
2. Check `git status`. If the tree has uncommitted changes that are not yours,
   tell the user and suggest committing them or working on a new branch before
   you continue.
3. Run the test suite once (`php artisan test`, `vendor/bin/pest` or
   `vendor/bin/phpunit`, whichever the project uses) and record the result as
   the baseline. Tests that already failed before the upgrade are not yours to
   fix. Mention them in the final report.
4. Find the ClickHouse connections: the entries of `config/database.php`
   (and of any config file merged into it, such as `config/clickhouse.php`)
   whose `driver` is `clickhouse`, and `DB_CONNECTION` in `.env`. For each
   one, note:
   - `fix_default_query_builder`: `true`, the default of the 3.x packaged
     config, means that in 3.x `DB::connection(...)->table()` and `->query()`
     returned the package's `Builder`, whose `get()` returned a
     `ClickHouseDB\Statement`; `false` means they returned Laravel's query
     builder, whose `get()` returned a collection;
   - `cluster_name` and `cluster` (the list of nodes);
   - `timeout_query`, `settings`, and whether the connection is the default
     one.
5. Find the code that uses these connections and the package. Search for:
   - `BaseModel`: the models;
   - `LaravelClickHouse\\(Builder|Migration|RawColumn|Grammar|WithClient)\b|ClickhouseBuilder\\|ClickhouseSchemaBuilder\\|LaravelClickHouse\\Enum\\|Expressions\\InsertArray`:
     imports and names of removed classes;
   - `DB::connection\(|Schema::connection\(|DB::table\(`: queries and schema
     code on a ClickHouse connection (or on the default connection, when it is
     a ClickHouse connection);
   - migrations whose `$connection` is a ClickHouse connection, or every
     migration when ClickHouse is the default connection;
   - `(^|[^\w>:$\\])(raw|tp|array_flatten)\(`: the package's helper functions
     (`DB::raw()` and `$x->raw()` are not matches).
   Keep the list. The next steps work through it. Every query that ran on the
   3.x package builder (model queries, `new Builder(...)`, and
   `DB::connection(...)->table()` on a connection with
   `fix_default_query_builder` on) needs step 5; queries that already ran on
   Laravel's builder only need steps 9 to 12.

## Step 2: Update the dependency

```bash
composer require oralunal/laravel-clickhouse:^4.0 --with-all-dependencies
```

If Composer refuses because another package of the project or a third-party
package requires `oralunal/laravel-clickhouse:^3.0`, update the project's own
packages too. If it is a third-party package, stop and ask the user.

The `artisan` scripts that Composer runs afterwards may fail with a
class-not-found error, for example for `Oralunal\LaravelClickHouse\BaseModel`,
because the application code still uses removed classes. That is expected
until the next steps are done; continue.

## Step 3: Configuration

1. Remove `fix_default_query_builder` from every ClickHouse connection, in
   `config/database.php`, `config/clickhouse.php`, `.env*` files and test
   config such as `phpunit.xml`. 4.x ignores it.
2. Do not add the new options of 4.x (`use_lightweight_delete`,
   `datetime_precision`, `insert_format`, `engine`, `default_index_type`,
   `use_on_cluster`, `retry_on`) unless the user asks: their defaults keep the
   3.x behavior, except `retry_on`. Mention them in the report.
3. If a connection sets `retries` above `0`, tell the user in the report that
   4.x sends a write again only when it never reached the server (`retry_on`
   `reads`, the default), where 3.x sent every failed request again. Do not set
   `retry_on` to `any` unless the user asks.

## Step 4: Models

For every class that extends `BaseModel`:

1. Replace the parent:

   ```php
   // 3.x
   use Oralunal\LaravelClickHouse\BaseModel;
   class Event extends BaseModel

   // 4.x
   use Oralunal\LaravelClickHouse\Eloquent\Model;
   class Event extends Model
   ```

2. Keep what BaseModel did, because Eloquent does more:
   - **Timestamps.** Eloquent sets `created_at` and `updated_at` on `create()`
     and `save()`. Read the table's columns (the migration that creates it, or
     `DESCRIBE TABLE` if the user lets you read the database). If the table
     lacks either column, add `public $timestamps = false;`.
   - **Mass assignment.** BaseModel's `fill()` and `create()` set every key.
     Add `protected $guarded = [];` unless the model sets `$fillable` or
     `$guarded`. Eloquent still leaves out a key with a dot, such as the
     Nested column `n.a`: search the model's `create(`, `make(` and `fill(`
     calls for such keys, and use `forceCreate()` or `forceFill()` there.
   - **The key.** The key is `id`, not incrementing, and a string. If the
     table has no `id` column, set `protected $primaryKey` to its key, or ask
     the user. If the key is an integer, set `protected $keyType = 'int';`.
   - Keep `$table`, `$connection`, `$casts`, accessors, mutators and events.

3. Rewrite the calls of BaseModel methods that Eloquent does not have.
   Search for each pattern:

   | Search | 3.x | 4.x |
   | --- | --- | --- |
   | `::insertAssoc\(` | `Event::insertAssoc($rows)` | `Event::insert($rows)` |
   | `::insertBulk\(` | `Event::insertBulk($rows, ['a', 'b'])` | `Event::insert(array_map(fn (array $row): array => array_combine(['a', 'b'], $row), $rows))` |
   | `::insertBulk\(` without columns | positional rows in the table's column order | ask the user for the column names, then as above |
   | `::prepareAndInsert(Assoc\|Bulk)?\(`, `prepare(Assoc)?FromRequest` | rows prepared by the model's hook | map the rows with the hook's code, then `Event::insert()` |
   | `->getRows\(` | `Event::where(...)->getRows()` | `Event::where(...)->toBase()->get()->all()` (arrays, as before) |
   | `->getCollection\(` | `Event::where(...)->getCollection()` | `Event::where(...)->toBase()->get()` |
   | `::getClient\(\)`, `->getThisClient\(` | `Event::getClient()` | `(new Event())->getConnection()->getClient()` |
   | `resolveConnection\(` | `$event->resolveConnection()` | `$event->getConnection()` |
   | `\$tableForInserts`, `getTableForInserts\(` | inserts go to another table | `DB::connection($model->getConnectionName())->table('<that table>')->insert($rows)` |
   | `\$tableSources`, `getTableSources\(` | writes go to another table | run the write on `DB::connection(...)->table('<that table>')` |

   A model query in 3.x (`Event::select()`, `Event::where()`,
   `Event::whereRaw()`) returned the package builder; in 4.x it returns
   Eloquent's builder, whose `get()` returns models. Treat the rest of each
   chain with step 5.

4. Return values that changed. Search for code that uses the result:
   - `Event::flushBuffer()` returned a `Statement` or `null`; it returns
     `true`, or `false` when nothing is buffered.
   - `Event::optimize()` returned a `Statement`; it returns a `bool`.
   - `Event::truncate()` returned a `Statement`; it returns nothing.
   - `insertAssoc()` and `insertBulk()` returned a `Statement`; `insert()`
     returns a `bool`. Replace `->isError()` checks on these results: a
     failure throws.

5. Casts on inserts. `insertAssoc()`, `insertBulk()` and `buffer()` turned a
   value of a `boolean` cast into `1` or `0`; 4.x sends the rows as given. A
   PHP `bool` is still written as `1` or `0`. For each model with a `boolean`
   cast whose rows reach `insert()` or `buffer()`, check where the values come
   from. If a value can be another truthy or falsy value, such as `'yes'` or
   `2`, propose `(int) (bool) $value` and **ask the user**. To apply every
   cast and mutator, a row can be built with
   `(new Event())->forceFill($row)->getAttributes()`.

## Step 5: Queries on the package builder

Laravel's query builder replaces the package builder. In the code that ran on
the package builder (see step 1.5), rewrite each pattern. Check each
replacement against `vendor/oralunal/laravel-clickhouse/src/QueryBuilder.php`
and Laravel's `Illuminate\Database\Query\Builder`.

1. **Types and imports.**
   - `Oralunal\LaravelClickHouse\Builder` → `Oralunal\LaravelClickHouse\QueryBuilder`.
   - `new Builder($client)` or `new Builder(...)`: build the query from the
     connection instead: `DB::connection('<name>')->query()`.
   - A closure typed with the package `Builder` or `BaseBuilder`, as in
     `where(fn (Builder $q) => ...)`, now gets Laravel's builder, or Eloquent's
     builder inside a model query. Search
     `(fn|function)\s*\(\s*\??(Builder|BaseBuilder)\s+\$` in files that import
     the package classes, and type the parameter with
     `Illuminate\Contracts\Database\Query\Builder`, or remove the type.
2. **Results.** In 3.x, `get()` returned a `ClickHouseDB\Statement`.
   | Search | 3.x | 4.x |
   | --- | --- | --- |
   | `->get\(\)\s*->\s*rows\(` | `->get()->rows()` | `->get()->all()` |
   | `->get\(\)\s*->\s*fetchOne\(\)` | `->get()->fetchOne()` | `->first()` |
   | `->get\(\)\s*->\s*fetchOne\(` with a name | `->get()->fetchOne('c')` | `->value('c')` (see step 12.3) |
   | `->get\(\)\s*->\s*(rawData\|totals\|extremes\|statistics\|countAll\|info\|summary\|isError)\(` | `->get()->totals()` | `->getStatement()->totals()` |
   | `->format\(` | `->format('CSV')->get()->rawData()` | `->getStatement('CSV')->rawData()` |
   | `->getRows\(` | `->getRows()` | `->get()->all()` |
   | `->getCollection\(` | `->getCollection()` | `->get()` |
   A `get()` whose result is iterated with `foreach` still works: a
   collection of arrays, or of models on a model query. A `get()` whose result
   is passed on or type-hinted as `Statement` must change: ask the user which
   one the caller needs.
3. **SQL text.** `->toSql()` of the package builder held the values; Laravel's
   holds `?` placeholders. Search `->toSql\(\)`. Where the SQL is run, logged
   or compared as finished SQL, use `->toRawSql()`. Tests that compare SQL
   strings must expect 4.x's SQL: double-quoted names and lower-case keywords,
   such as `select * from "events" where "id" = 1` (`toRawSql()`).
4. **Raw SQL and values.**
   | Search | 3.x | 4.x |
   | --- | --- | --- |
   | `new\s+RawColumn\(` | `new RawColumn('count()', 'n')` | `DB::raw('count() as n')` |
   | `(^\|[^\w>:$\\])raw\(` and `use function …\\raw;` | `raw('count()')` | `DB::raw('count()')`; remove the `use function` import |
   | `ClickhouseBuilder\\Query\\Expression`, `new\s+Expression\(` | `new Expression('now()')` | `DB::raw('now()')` (`Illuminate\Support\Facades\DB`) |
   | `InsertArray` | `new InsertArray(['a', 'b'])` | `['a', 'b']` |
   | `(^\|[^\w>:$\\])tp\(` | `tp($value, $callback)` | `tap($value, $callback)` |
   | `(^\|[^\w>:$\\])array_flatten\(` | `array_flatten($array)` | `Arr::flatten($array)` (`Illuminate\Support\Arr`) |
   `select(raw('a + 1 as b'))` can also be `selectRaw('a + 1 as b')`. A raw
   string that holds a `?` outside quotes, such as a ternary `a ? b : c`, is
   now read as a placeholder: write `??`.
5. **Clauses with another signature.**
   | Search | 3.x | 4.x |
   | --- | --- | --- |
   | `->(limit\|take)\(\s*[^,()]+,` | `->limit(10, 20)` | `->limit(10)->offset(20)` |
   | `->takeBy\(` | `->takeBy(1, 'a')` | `->limitBy(1, 'a')` |
   | `->orderBy(Asc\|Desc)?\(` with a collation | `->orderBy('n', 'asc', 'tr')` | `->orderByRaw('n asc collate ?', ['tr'])` |
   | `->orderByAsc\(` | `->orderByAsc('n')` | `->orderBy('n')` |
   | joins with a `USING` list: `Join\([^,]+,\s*\[` | `->anyLeftJoin('users', ['id'])` | `->anyLeftJoin('users', 'events.id', '=', 'users.id')` |
   | `->(leftJoin\|innerJoin\|rightJoin\|fullJoin\|join)\(` with a strictness or `GLOBAL` argument | `->leftJoin('users', 'ANY', ['id'], true)` | `->join('users', 'events.id', '=', 'users.id', 'global any left')` |
   | `->crossJoin\([^)]*,` | `->crossJoin('t', false, 'x')` | `->crossJoin('t as x')`, or `->crossJoinSub($query, 'x')` |
   | `JoinClause`, `ArrayJoinClause` closures | `->join(fn (JoinClause $j) => ...)` | the method of the join kind with an on-clause: `anyLeftJoin`, `allInnerJoin`, `asofJoin`, … (and their `…Sub` forms) |
   | `From` closures, `->from\(\s*(fn\|function)` | `->from(fn (From $f) => $f->remote($host, $db, $t, $user, $pass))` | `->fromRaw('remote(?, ?, ?, ?, ?)', [$host, $db, $t, $user, $pass])`; `merge()` → `fromRaw('merge(?, ?)', [...])` |
   | `->as\(`, `->alias\(` after `from($query)` | `->from($query)->as('s')` | `->fromSub($query, 's')` |
   | `Column` closures in `select()` | `select(fn (Column $c) => $c->name('a')->as('b'))` | `select('a as b')`, or `selectRaw()` for functions |
   | `->whereDict\(`, `->orWhereDict\(`, `->addSelectDict\(` | `->whereDict('users', 'name', $id, 'Ann')` | `->select('*')->selectRaw('dictGetString(?, ?, ?) as name', ['users', 'name', $id])->where('name', 'Ann')` |
   | `->(or)?having(Not)?In\(`, `having(Not)?BetweenColumns` | `->havingIn('c', [1, 2])` | `->havingRaw('c in (?, ?)', [1, 2])` |
   | `asyncWithQuery\|toAsyncSqls\|toAsyncQueries\|getAsyncQueries` | queries meant to run together | `Oralunal\LaravelClickHouse\Parallel::getRows([$query, $other])`, or `Parallel::get()` for statements |
   | `getQueryFor(Count\|Exists\|Page)\|getQueryToSend\|getQueryClient\|getCountQuery\|setSourcesTable\|followConnectionOptions` | | `count()`, `exists()`, `forPage()`, `selectRaw('count() as c')`; ask the user where the intent is unclear |
6. **Operators and enums.** Laravel's `where()` does not know `in`,
   `between` or `is null` as operators: it compares with the string, as in
   `where "id" = 'in'`. Search, ignoring case,
   `->(or)?(where|preWhere|having)\w*\([^;]*?,\s*['"](not\s+)?(in|between|global\s+in|global\s+not\s+in)['"]`,
   `['"]is\s+(not\s+)?null['"]` and `Operator::(IN|NOT_IN|BETWEEN|NOT_BETWEEN|GLOBAL_IN|GLOBAL_NOT_IN)`,
   and use `whereIn()`, `whereNotIn()`, `whereBetween()`, `whereNotBetween()`,
   `whereGlobalIn()`, `whereGlobalNotIn()`, `whereNull()` or `whereNotNull()`.
   Replace the other constants with their strings: `Operator::EQUALS` → `'='`,
   `Operator::NOT_EQUALS` → `'!='`, `Operator::LIKE` → `'like'`,
   `Operator::AND`/`OR` → `'and'`/`'or'`, `OrderDirection::ASC`/`DESC` →
   `'asc'`/`'desc'`. Remove the imports of `Operator`, `JoinType`,
   `JoinStrict` and `OrderDirection`.
7. **`whereIn()` and its forms take a list or a sub-query.** A string or a raw
   expression, as in `whereIn('id', raw('allowed_ids'))`, now fails with a
   `TypeError`. Search
   `->(or)?(where|preWhere|whereGlobal)(Not)?In\(\s*[^,]+,\s*(['"$]|raw\(|DB::raw|new\s)`
   and read each match: a variable that holds an array is fine. Use
   `whereRaw('id in allowed_ids')`, or a sub-query closure such as
   `whereIn('id', fn ($q) => $q->select('id')->from('allowed_ids'))`.
8. **Aggregates.** `min()`, `max()`, `sum()`, `avg()`, `average()` and
   `aggregate()` quote a string as a column name. Search
   `->(min|max|sum|avg|average|aggregate)\(`. A plain or dotted column name,
   such as `'amount'` or `'orders.amount'`, needs no change. SQL in a string,
   such as `sum('price * qty')` or `aggregate('count', ['DISTINCT user_id'])`,
   now fails with `UNKNOWN_IDENTIFIER`: wrap it in `DB::raw()`. If the value
   comes from a variable that can hold SQL on purpose, ask the user; never
   wrap user input in `DB::raw()`.
9. **`chunk()`.** Laravel's `chunk()` passes a collection, not an array, and
   needs an order: on the query builder it throws `You must specify an orderBy
   clause when using this function`, and on a model query it orders by the
   key. Search `->chunk\(`. Type the callback parameter as
   `Illuminate\Support\Collection` or remove the type, and add `orderBy()`
   with a column that the user confirms. A model whose table has no `id`
   column needs an explicit `orderBy()` too.
10. **Mutations.**
    - `->delete()` and `->update($values)` returned a `Statement`; they return
      an `int`. Search `->(delete|update)\([^;]*\)\s*->` and assignments of
      their result.
    - A lightweight delete on the query builder takes the option by name:
      search `->delete\(\s*(true|false)` on `DB::connection(...)->table()` and
      `->query()` chains, and write `->delete(lightweight: true)` (or
      `false`). `QueryBuilder::delete(true)` throws, because Laravel's first
      parameter is `$id`. On a model query, `Event::where(...)->delete(true)`
      is unchanged.
    - `update($values, $partition)` and `delete(lightweight: …, partition: …)`
      take a partition as before.
11. **`settings()`.** Calls now add up instead of replacing each other, and
    `null` removes a name. Search `->settings\(` and fix a chain that relied on
    the last call replacing the others.

## Step 6: Migrations

`Oralunal\LaravelClickHouse\Migration` is removed. Change every migration that
uses it, **including the ones that already ran**: a fresh install, CI and
`migrate:fresh` load them again. Search
`extends\s+\\?(Oralunal\\LaravelClickHouse\\)?Migration\b`,
`(static|self)::(write|createMergeTree)\(` and `ClickhouseSchemaBuilder\\`.

1. Extend `Illuminate\Database\Migrations\Migration` and set
   `protected $connection` to the ClickHouse connection that the migration
   used (`clickhouse` when it did not set one).
2. `static::write($sql)` becomes
   `DB::connection($this->connection)->statementOnEveryNode($sql)`. It sends
   the statement to every node of the connection, as `write()` did. It takes
   no bindings, and reads a `?` outside quotes as a placeholder: write `??`
   for a question mark. For `write($sql, $bindings)`, write each value into
   the SQL with `DB::connection($this->connection)->escape($value)`, or use
   `statement($sql, $bindings)`, which runs on the active node only: ask the
   user which one when the connection lists several nodes.
3. `static::createMergeTree($name, fn (MergeTree $table) => ...)` becomes
   `Schema::connection($this->connection)->create($name, function (SchemaBlueprint $table) { ... })`
   (`Illuminate\Support\Facades\Schema`, `Oralunal\LaravelClickHouse\SchemaBlueprint`):
   | `MergeTree` | `SchemaBlueprint` |
   | --- | --- |
   | `columns([...])`, `column('n', 'Type')` | one call per column; `rawColumn('n', 'Type')` |
   | `int8()`, `int16()`, `int32()`, `int64()` | `tinyInteger()`, `smallInteger()`, `integer()`, `bigInteger()` |
   | `uInt8()`, `uInt16()`, `uInt32()`, `uInt64()` | `unsignedTinyInteger()`, `unsignedSmallInteger()`, `unsignedInteger()`, `unsignedBigInteger()` |
   | `int128()`, `int256()`, `uInt128()`, `uInt256()`, `decimal32()` … `decimal256()` | `rawColumn('n', 'Int128')`, `rawColumn('n', 'Decimal64(4)')`, … |
   | `integer('n', $bits, $withSign)` | the method of that size and sign |
   | `float32()`, `float64()`, `float('n', 32)` | `float('n', 24)` (`Float32`), `double('n')` (`Float64`) |
   | `datetime('d')`, `datetime('d', 3, 'UTC')` | `dateTime('d')`, `dateTime('d', 3)->timezone('UTC')` |
   | `string()`, `date()`, `uuid()`, `bool()`, `enum()`, `decimal('n', 10, 2)` | `string()`, `date()`, `uuid()`, `boolean()`, `enum()`, `decimal('n', 10, 2)` |
   | `->nullable()`, `->default()`, `->comment()` | the same modifiers |
   | `engine(Engine::REPLACING_MERGE_TREE, 'v')`, `engine('X', ...)` | `engine('ReplacingMergeTree(v)')` |
   | `orderBy(...)`, `partition('…')`, `settings([...])`, `ifNotExists()` | `orderBy(...)`, `partitionBy('…')`, `settings([...])`, `ifNotExists()` |
   | `ttl('d', 'INTERVAL 1 DAY')` | `ttl('d + INTERVAL 1 DAY')` |
   | `onCluster(...)`, `getEngine()->replicated()`, `dbName()` | nothing: the connection's `cluster_name` and `cluster` decide; `withoutOnCluster()` or `replicated(false)` opt out |
   Always call `orderBy()` with the sorting key that the old table had. Remove
   escaping that was added by hand for `createMergeTree()`, such as
   `->comment("it\\'s")`: the schema builder escapes values itself.
4. Run `php artisan migrate --pretend` (it sends nothing) and compare each
   `CREATE TABLE` with the one that the old migration wrote, or with
   `SHOW CREATE TABLE` if the user lets you read the database. Show the user
   every difference: a fresh run should create the tables that production
   has. Where a table must stay exactly as it is, keep its SQL with
   `statementOnEveryNode()` instead of the schema builder.

## Step 7: Enums, the grammar and other classes

1. `ClickhouseBuilder\Query\Enums\Format` → `Oralunal\LaravelClickHouse\Enums\Format`,
   a native enum. Its cases are the names in StudlyCase: `Format::CSV` →
   `Format::Csv`, `JSON_EACH_ROW` → `JsonEachRow`, `TAB_SEPARATED` →
   `TabSeparated`, `XML` → `Xml`, `TSV` → `Tsv`, `TSKV` → `Tskv`, `VALUES` →
   `Values`, and so on (read `src/Enums/Format.php`). A 3.x constant was a
   string: where code needs the string, use `Format::Csv->value`.
   `new Format(Format::CSV)` → `Format::Csv`; `->getValue()` → `->value`;
   `Format::isValid($name)` → `Format::tryNamed($name) !== null`.
2. `Oralunal\LaravelClickHouse\Enum\Enum`: an application enum that extends it
   must become a native PHP enum, or extend another library. Ask the user.
3. `Oralunal\LaravelClickHouse\Grammar`: to write a value as a ClickHouse
   literal, use `DB::connection('<name>')->escape($value)`, or
   `->newLiteralGrammar()->compileLiteral($value)` of the connection.
4. `Oralunal\LaravelClickHouse\WithClient` in an application class: use
   `DB::connection('<name>')->getClient()`.

## Step 8: Make every class load

1. Find every class that extends a class of the package: search
   `extends\s+\\?Oralunal\\LaravelClickHouse\\` and `extends` in files that
   import from `Oralunal\LaravelClickHouse\`. `Connection`, `QueryGrammar`,
   `SchemaBuilder`, `SchemaGrammar`, `SchemaState` and `Cluster` still exist
   but changed; compare each override with the parent in
   `vendor/oralunal/laravel-clickhouse/src/` and fix the signature. A subclass
   of a removed class is handled by steps 4 to 7.
2. Load every class of the project:

   ```bash
   composer dump-autoload --optimize
   php -r '
   require "vendor/autoload.php";
   foreach (require "vendor/composer/autoload_classmap.php" as $class => $file) {
       if (!str_contains($file, "/vendor/")) {
           class_exists($class) || interface_exists($class) || trait_exists($class);
       }
   }
   echo "All classes loaded.\n";'
   ```

   A fatal error names the class and the method. Run `composer dump-autoload`
   without `--optimize` afterwards if the project does not use an optimized
   autoloader.
3. Search again for every pattern of step 1.5 and steps 4 to 7. Nothing that
   names a removed class or method may remain, except in comments or text that
   the user wants to keep.

## Step 9: Fix what fails at run time

1. **`FORMAT` in `select()`.** `select()`, `selectOne()`, `scalar()`,
   `cursor()`, `selectParallelly()` and `Parallel` refuse SQL whose `FORMAT`
   clause names a format other than `JSON`, `JSONStrings`, `JSONCompact` and
   `JSONCompactStrings` (`cursor()` refuses every `FORMAT` clause). Search
   `FORMAT\s+[A-Za-z]` in SQL given to them. Remove the clause, or read the
   result with `DB::connection('<name>')->selectStatement($sql, $bindings)->rawData()`.
2. **Placeholders.** Search `#@\?`: a hand-written `#@?` marker is not a
   binding; write `?`. A query that mixes `?` with smi2 placeholders (`:0`,
   `{0}`, `:name`) or query parameters (`{name:Type}`) throws before it is
   sent. Use one kind of placeholder per query.
3. **Quoted table names.** Search `` ->(table|from)\(\s*['"]` `` and
   `` DB::table\(\s*['"]` ``: a name that already has backticks is quoted again
   and fails. Pass it without them.
4. **Transactions.** `beginTransaction()`, `transaction()` and `commit()` on a
   ClickHouse connection throw a `LogicException`. Search for them on
   ClickHouse connections, and for test classes whose
   `$connectionsToTransact` lists a ClickHouse connection or that use
   `RefreshDatabase`, `LazilyRefreshDatabase` or `DatabaseTransactions` while
   ClickHouse is the default connection. Use the `DatabaseTruncation` trait for
   ClickHouse connections, and ask the user before you change application code
   that called `transaction()`.
5. **The migration repository.** Search `migration.repository` and
   `DatabaseMigrationRepository`. The package binds
   `Oralunal\LaravelClickHouse\ClickhouseMigrationRepository`, a subclass of
   Laravel's. Its `getLast()`, `getMigrations()` and `getMigrationsByBatch()`
   return objects: code that reads `$row['migration']` must read
   `$row->migration`.

## Step 10: Review the writes with the user

These changes make a write touch other rows or store other values than in 3.x.
For each match, read the code, decide whether it is affected, and tell the
user. **Ask before you change any of them.**

1. **`whereNotIn()` with an empty list** adds `1 = 1`, as in Laravel, so
   `->whereNotIn('id', $ids)->delete()` (or `update()`) with an empty `$ids`
   changes **every row**. Search `whereNotIn` and `orWhereNotIn` in queries
   that reach `delete()` or `update()`. Where the list can be empty, propose a
   guard, such as returning early when `$ids === []`.
2. **Mutations with clauses that ClickHouse ignores.** `delete()` and
   `update()` throw, and send nothing, for a query with a join, `GROUP BY`,
   `HAVING`, `LIMIT`, `LIMIT ... BY`, a set operation, `SETTINGS`, `FINAL`,
   `SAMPLE`, `ARRAY JOIN`, `WITH`, a select list with aliases or expressions,
   or an `ORDER BY` that defines an alias, calls `arrayJoin()` or limits rows.
   In 3.x some of these changed rows that the query did not select. Show the
   user each chain that reaches `delete(` or `update(` with such a clause; the
   fix is to select the keys first: `whereIn('id', $query->pluck('id'))`.
3. **`save()` and `delete()` of a loaded model.** BaseModel's `save()` threw
   for a model that exists. An Eloquent model loaded from a query sends `alter
   table ... update ... where "id" = ?` on `save()`, and `alter table ...
   delete where "id" = ?` on `delete()`. Search `->save\(` and `->delete\(`
   on loaded models of ClickHouse models, and `updateOrCreate`,
   `firstOrCreate`, `increment`, `decrement`, `touch`. Tell the user which
   writes now run as mutations.
4. **Floats.** Every write sends a float with every digit. A float computed in
   PHP is cut off at the scale of a `Decimal` or integer column instead of
   being rounded: `4.35 * 100` stores `434.99` in a `Decimal(10, 2)` column.
   Look for arithmetic on floats whose result is inserted or updated. Propose
   `round(..., <scale>)` or an int or numeric string. `update()` needs a
   numeric string or an int.
5. **Casts on inserts** (step 4.5) and **rows with missing keys**: `insert()`
   and `buffer()` refuse a row without keys, `insert()` refuses a row whose
   keys differ from the first row's, in any order, and `buffer()` one whose
   keys differ from the buffered rows'. Check how the rows are built, such as
   rows that leave out a key whose value is null.
6. **Laravel's insert.** `insert([])` returns `true` and sends nothing, as in
   Laravel; 3.x threw. Point out code that relied on the exception.

## Step 11: Review the migrations with the user

These changes only matter when migrations run after the upgrade, as with
`migrate:fresh`, in a new environment, in CI or in tests. Existing tables are
not touched. Do not edit a migration that already ran in production without
the user's approval: the goal is that a fresh run creates the tables that
production has.

1. **Integer types.** The schema builder gives each integer column its exact
   type: `tinyInteger()` → `Int8`, `smallInteger()` → `Int16`, `integer()` →
   `Int32`, `bigInteger()` → `Int64`, and `UInt8` to `UInt64` for the unsigned
   forms. `id()`, `increments()` and `foreignId()` are unsigned (`UInt64`,
   `UInt32`, `UInt64`). 3.x wrote `Int16`, `Int32` and `Int64`, signed even
   for an unsigned column. List each `Schema::create()` column whose type
   changes, and ask the user whether a fresh run should match production; if
   so, use `rawColumn('n', 'Int64')` there.
2. **`hasTable()` guards.** `Schema::hasTable()` returns `false` for views,
   materialized views and dictionaries. A migration that guards
   `CREATE VIEW`, `CREATE MATERIALIZED VIEW` or `CREATE DICTIONARY` with
   `if (! Schema::hasTable(...))` would run it again and fail. Replace the
   guard with `Schema::hasView()`, `Schema::hasDictionary()` or a
   `CREATE ... IF NOT EXISTS` statement. This fix is safe; make it.
3. **Laravel's schema builder** (`Schema::create()`, `Schema::table()` that
   existed in 3.x). 4.x writes what the blueprint declares, where 3.x wrote
   names and types only, always `MergeTree()`, sorted by the first column, and
   sent nothing for most of `Schema::table()`. For each such migration, list
   for the user what a fresh run does differently: `nullable()`, `default()`,
   `comment()`, `storedAs()`, `virtualAs()`, `useCurrent()`, `engine()`, the
   connection's `engine` option, `primary()` as the sorting key, `DateTime64`
   precisions, ClickHouse index types, and the `Schema::table()` statements
   (added columns, `dropColumn()`, `index()`, `dropIndex()`, `comment()`,
   `orderBy()`, `sampleBy()`, `ttl()`, `settings()`, `Schema::rename()`).
   These **fail** a fresh run and must change: `engine('InnoDB')` or another
   MySQL engine, a MySQL index type such as `btree`, `set()`, `computed()`,
   and `primary()`, `dropPrimary()` or `renameIndex()` inside
   `Schema::table()`. `time()`, `timeTz()`, `fullText()` and `vectorIndex()`
   create a `Time` column and text and vector indexes on ClickHouse 26.3 and
   later, and fail on 24.8: check the server version of the user before you
   keep them. Ask the user how to change each one.
4. **Cluster connections.** If a ClickHouse connection sets `cluster_name`,
   tell the user: the schema builder sends its statements `ON CLUSTER` and,
   when the connection also lists its nodes in `cluster`, creates MergeTree
   tables, the `migrations` table included, with a replicated engine; an
   `ON CLUSTER` statement waits for every host, up to 180 seconds, while the
   packaged `timeout_query` is 2 seconds, so propose a higher `timeout_query`
   for migration commands; a table of the active node only can be changed with
   `$table->withoutOnCluster()`. Do not change migrations for this without the
   user's approval.

## Step 12: Review the reads with the user

1. **Aggregates** are Laravel's: `sum()`, `min()`, `max()`, `avg()` and
   `aggregate()` replace the select list of the query and drop its order.
   `count()` and the total of `paginate()` count the rows that `get()`
   returns, in a sub-query when the select list, `GROUP BY`, `distinct()`,
   `limitBy()`, `arrayJoin()`, `withAlias()` or the order changes them. Point
   out aggregates of grouped queries and of queries with select aliases.
2. **Model queries return models.** `Event::where(...)->get()` returns an
   Eloquent collection and `first()` a model, where 3.x returned arrays from
   `getRows()` and `first()`. Check the callers of the chains that step 4 did
   not rewrite to `toBase()`.
3. **`value($column)`** on a query with a select list returns the first
   selected column, as in Laravel; 3.x read the column by its name. Search
   `->value\(` on queries that call `select(`.
4. **Tests.** Tests that assert 3.x SQL, statements or row arrays must assert
   4.x's: update them to the 4.x behavior and say which ones.

## Step 13: Refresh Laravel's caches

Run `php artisan package:discover` and `php artisan config:clear`. If the
project caches its config in production, tell the user to run
`php artisan config:cache` after the deploy.

## Step 14: Verify

Run all of these and fix what they report:

1. `composer dump-autoload` and `php artisan package:discover`. Both must
   finish without errors.
2. The class-loading check of step 8.
3. `php -l` on every PHP file you changed.
4. Every search of steps 1.5 and 4 to 12 again. What remains must be a case
   you decided to leave, with the user's answer where you asked.
5. `php artisan migrate:status` and `php artisan migrate --pretend`. These
   load the migration files and send nothing. If the database cannot be
   reached, run `php -l database/migrations/*.php` instead and say so in the
   report.
6. Run the test suite and compare it with the baseline from step 1. Every
   failure that is new must be fixed or explained.

## Step 15: Report

End with a short summary for the user:

- the version change in `composer.json`, and any other package you changed;
- the files you changed in each step, with what changed;
- every question you asked and the decision taken;
- the matches of steps 10 to 12 that you left as they are, and why;
- the migrations whose fresh run differs from production, from steps 6 and 11;
- for a cluster connection, the notes of step 11.4;
- the new options of step 3.2 that the user may want;
- the test results compared with the baseline, and any failures that already
  existed;
- if the ClickHouse servers run 25.8 or later, a pointer to
  https://laravel-clickhouse.oralunal.com/reference/clickhouse-versions, which
  lists what they do otherwise than 24.8 and the settings that bring back its
  behavior.
