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
installed, the complete list of changes is the `[4.0.0]` and `[4.1.0]`
sections of `vendor/oralunal/laravel-clickhouse/CHANGELOG.md`, the
human-readable guide is
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
  `packages/`, and the files that name classes outside PHP code:
  `phpstan.neon*`, `psalm.xml`, `rector.php`, `phpunit.xml*`, `.env*` and IDE
  helper files. Exclude `vendor/`, `node_modules/`, `storage/`,
  `bootstrap/cache/`, `.git/` and the installed upgrade skills (the
  `lc-upgrade-*` directories of the agents).
- Search with `rg -U -P -e '<pattern>'` (or `grep -rPzo`): a chain often has
  one call per line, and `-U` lets `\s` match the line break. `-e` lets a
  pattern start with `->`. Add `--hidden --no-ignore` for `.env*` files, and
  `-i` where a step says to ignore case. In the tables, `\|` is the markdown
  escape of `|`: remove the backslash before you search. Outside PHP code a
  name can be escaped, as in `Oralunal\\LaravelClickHouse\\Builder`: the
  patterns below match one or more backslashes between the parts of a name
  with `\\+`.
- Leave the installed upgrade skills and the changelog entries that describe
  the past as they are.
- Never run `migrate:fresh`, `db:wipe`, `migrate:rollback`, `migrate` or any
  statement that changes data or tables of a real ClickHouse database. Reading
  is fine. `php artisan migrate --pretend` pretends only on the `$connection`
  of each pending migration: a pending migration that writes to ClickHouse
  through `Schema::connection('<name>')` or `DB::connection('<name>')`, with
  another `$connection`, runs those statements for real. Before you run it,
  check that every pending migration that writes to ClickHouse sets
  `$connection` to that connection, or ask the user. When the migrations table
  does not exist, `migrate` creates it first: ask the user then too.
- When a change needs a decision only the user can make, stop and ask. The
  steps below say when. Never guess what data a write is meant to change.
- Do not commit unless the user asks you to.

## Step 1: Assess

1. Find the version that the application uses now. Read `composer.json` and
   run `composer show 'oralunal/*'`. When 4.x is already installed, read the
   version before the update from the last commit: `git show HEAD:composer.lock`
   (search for `oralunal/`). Also search the code, ignoring case, for
   `PhpClickHouseLaravel|Tinderbox\\+ClickhouseBuilder|PhpClickHouseSchemaBuilder`.
   - `oralunal/laravel-clickhouse` 3.x, and none of these names in the code:
     do every step.
   - `oralunal/phpclickhouse-laravel` 2.x, or `PhpClickHouseLaravel` in the
     code: stop and tell the user to run `/lc-upgrade-2x-to-4x` instead.
   - `oralunal/phpclickhouse-laravel` 1.x, or `Tinderbox\ClickhouseBuilder` or
     `PhpClickHouseSchemaBuilder` in the code: stop and tell the user to run
     `/lc-upgrade-1x-to-4x` instead.
   - `oralunal/laravel-clickhouse` 4.x is already installed, with 3.x code:
     skip step 2 and do every other step for what the searches find.
2. Check `git status`. If the tree has uncommitted changes that are not yours,
   tell the user and suggest committing them or working on a new branch before
   you continue.
3. Record the result of the test suite before the upgrade as the baseline
   (`php artisan test`, `vendor/bin/pest` or `vendor/bin/phpunit`, whichever
   the project uses). Tests that already failed before the upgrade are not
   yours to fix. Mention them in the final report.
   - Before step 2, run the suite once.
   - When 4.x is already installed, a run now is no baseline: the 3.x code no
     longer loads. Ask the user for a result from before the update, such as a
     saved output or the last CI run. If there is none and the user agrees,
     run the suite on a worktree of the last commit before the update:
     `git worktree add ../lc-baseline <commit>`, then `composer install` and
     the test command in `../lc-baseline`, then
     `git worktree remove ../lc-baseline`. Otherwise say in the report that
     there is no baseline.
   - If the project runs PHPStan (a `phpstan.neon`, `phpstan.neon.dist` or
     `phpstan.dist.neon` file), run it as well, as the project runs it, such
     as `vendor/bin/phpstan analyse` or a Composer script, and keep its
     errors. Step 14.8 of the `lc-upgrade-3x-to-4x` skill compares them. In a
     worktree, run it there after the test suite.
4. Find the ClickHouse connections: the entries of `config/database.php`
   (and of any config file merged into it, such as `config/clickhouse.php`)
   whose `driver` is `clickhouse`, and `DB_CONNECTION` in `.env`. For each
   one, note:
   - `fix_default_query_builder`. The connection named `clickhouse` had it on
     unless its config set it to `false`, because the packaged config set it
     to `true`. A connection under another name had it on only when its config
     set it to `true`. Search, ignoring case, `fix_default_query_builder` in
     the config, `.env*` and `phpunit.xml*` files (the variable is
     `CLICKHOUSE_FIX_DEFAULT_QUERY_BUILDER`). On, `DB::connection(...)->table()`
     and `->query()` returned the package's `Builder`, whose `get()` returned a
     `ClickHouseDB\Statement`; off, they returned Laravel's query builder,
     whose `get()` returned a collection;
   - `cluster_name` and `cluster` (the list of nodes);
   - `timeout_query`, `retries`, `settings`, and whether the connection is the
     default one.
5. Find the code that uses these connections and the package. Search for:
   - `BaseModel`: the models, and static calls such as
     `BaseModel::flushAllBuffers()`;
   - `LaravelClickHouse\\+(Builder|Migration|RawColumn|Grammar|WithClient)\b|LaravelClickHouse\\+\{|ClickhouseBuilder\\+|ClickhouseSchemaBuilder\\+|LaravelClickHouse\\+Enum\\+|Expressions\\+InsertArray|QueryGrammar::(PARAMETER_SIGN|prepareParameters)`:
     imports and names of removed classes and members; for a grouped import,
     `use Oralunal\LaravelClickHouse\{...}`, check every name in the group and
     every alias it gives;
   - `DB::connection\(|Schema::connection\(|DB::table\(`: queries and schema
     code on a ClickHouse connection (or on the default connection, when it is
     a ClickHouse connection);
   - migrations whose `$connection` is a ClickHouse connection, or every
     migration when ClickHouse is the default connection;
   - `(^|[^\w>:$\\])\\?(raw|tp|array_flatten)\(`: the package's helper
     functions (`DB::raw()` and `$x->raw()` are not matches; a fully qualified
     `\Oralunal\LaravelClickHouse\ClickhouseBuilder\raw(` is found by the
     class search above).
   Keep the list. The next steps work through it. Every query that ran on the
   3.x package builder (model queries, `new Builder(...)`, and
   `DB::connection(...)->table()` on a connection with
   `fix_default_query_builder` on) needs step 5; queries that already ran on
   Laravel's builder only need steps 9 to 12.

## Step 2: Update the dependency

```bash
composer require oralunal/laravel-clickhouse:^4.1 --with-all-dependencies
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

   A static call outside the models, such as `BaseModel::flushAllBuffers()`
   in a job or in `routes/console.php`, becomes
   `Oralunal\LaravelClickHouse\Eloquent\Model::flushAllBuffers()`.

2. Keep what BaseModel did, because Eloquent does more:
   - **Timestamps.** BaseModel never wrote timestamps. Add
     `public $timestamps = false;`, unless the user wants Eloquent's
     timestamps: ask when the table has `created_at` and `updated_at`. With
     them, `create()` and `save()` set both columns, and `update()` of a model
     query also sets `updated_at`, which fails when `updated_at` is in the
     sorting key.
   - **Mass assignment.** BaseModel ignored `$fillable` and `$guarded`:
     `fill()` and `create()` set every key. Add `protected $guarded = [];`. If
     the model sets `$fillable` or `$guarded`, tell the user that `create()`
     and `fill()` now drop the other keys without an error, and ask whether to
     use `$guarded = []`. Eloquent still leaves out a key with a dot, such as
     the Nested column `n.a`: search the model's `create(`, `make(` and `fill(`
     calls for such keys, and use `forceCreate()` or `forceFill()` there.
   - **The key.** The key is `id`, not incrementing, and a string. If the
     table has no `id` column, set `protected $primaryKey` to its key, or ask
     the user. If the key is an integer, set `protected $keyType = 'int';`. If
     the table has no single key column, keep the default and tell the user:
     `find()`, and `save()` or `delete()` of a loaded model, need a key; use
     queries for such a table.
   - Keep `$table`, `$connection`, `$casts`, accessors, mutators and events.

3. Rewrite the calls of BaseModel methods that Eloquent does not have.
   Search for each pattern:

   | Search | 3.x | 4.x |
   | --- | --- | --- |
   | `::insertAssoc\(` | `Event::insertAssoc($rows)` | `Event::insert($rows)` |
   | `::insertBulk\(` | `Event::insertBulk($rows, ['a', 'b'])` | `Event::insert(array_map(fn (array $row): array => array_combine(['a', 'b'], $row), $rows))` |
   | `::insertBulk\(` without columns | positional rows in the table's column order | ask the user for the column names, then as above |
   | `::prepareAndInsert(Assoc\|Bulk)?\(`, `prepare(Assoc)?FromRequest` | rows prepared by the model's hook | map the rows with the hook's code, then `Event::insert()` |
   | `->getRows\(` | `Event::where(...)->getRows()` | `Event::where(...)->getStatement()->rows()` (associative arrays, as before) |
   | `::getClient\(\)`, `->getThisClient\(` | `Event::getClient()` | `(new Event())->getConnection()->getClient()` |
   | `resolveConnection\(` | `$event->resolveConnection()` | `$event->getConnection()` |

   **`$tableForInserts` and `$tableSources`.** Search
   `\$table(ForInserts|Sources)|getTable(ForInserts|Sources)\(`. 4.x ignores
   both properties without an error and uses `$table`. In 3.x,
   `$tableForInserts` took `insertAssoc()`, `insertBulk()`, `create()`,
   `save()`, `buffer()` and `flushBuffer()`, and `$tableSources` took
   `optimize()`, `truncate()`, and `delete()` and `update()` of chains that
   start with `where()` or `whereRaw()`. For such a model, first find each of
   these calls of the model:
   `<Model>::(insertAssoc|insertBulk|insert|create|forceCreate|make|buffer|flushBuffer|optimize|truncate|where|whereRaw)\(`
   and `->save\(` on its instances. Ask the user which table each call must
   reach. Make a model for the other table, such as
   `class EventBuffer extends Event { protected $table = 'events_buffer'; }`,
   and use it for those calls, or run them on
   `DB::connection($model->getConnectionName())->table('<that table>')`. Then
   remove the property.

   A model query in 3.x (`Event::select()`, `Event::where()`,
   `Event::whereRaw()`) returned the package builder; in 4.x it returns
   Eloquent's builder, whose `get()` returns models. Treat the rest of each
   chain with step 5.

4. Return values and events that changed. Search for code that uses the
   result:
   - `Event::flushBuffer()` returned a `Statement` or `null`; it returns
     `true`, or `false` when nothing is buffered.
   - `Event::optimize()` returned a `Statement`; it returns a `bool`.
   - `Event::truncate()` returned a `Statement`; it returns the Eloquent builder.
   - `insertAssoc()` and `insertBulk()` returned a `Statement`; `insert()`
     returns a `bool`. Replace `->isError()` checks on these results: a
     failure throws.
   - `Event::create()` returned `false` when a `creating` listener returned
     `false`; it returns the model, which is not saved. Search `::create\(`
     results that are compared with `false` or tested with `!`, and check
     `->exists` instead.
   - Eloquent fires every model event: `save()` of a new model fires `saving`,
     `creating`, `created` and `saved`, where BaseModel fired `saved` only.
     Search the listeners and observers of the models (`creating`, `saving`,
     `created`, `observe(`, `$dispatchesEvents`) and tell the user which ones
     now also run, and that a `creating` or `saving` listener that returns
     `false` stops `save()`.
   - Code that reads a returned `Statement`, such as `->delete()->sql()`, or
     `$statement->isError()` on a variable: ask the user what it needs.

5. Casts on writes. BaseModel turned a value of a `boolean` cast into `1` or
   `0` in `insertAssoc()`, `insertBulk()` and `buffer()`, and in `create()`,
   `save()` and `fill()`, which went through `insertAssoc()`. 4.x sends the
   rows of `insert()` and `buffer()` as given, and Eloquent does not cast a
   value when it is set. A PHP `bool` is still written as `1` or `0`. For each
   model with a `boolean` cast whose values reach these calls, check where the
   values come from. If a value can be another truthy or falsy value, such as
   `'yes'`, `'on'` or `2`, propose `(int) (bool) $value` at the call, or a
   mutator on the model, such as
   `protected function isBot(): Attribute { return Attribute::make(set: fn ($value) => (int) (bool) $value); }`,
   and **ask the user**. `(new Event())->forceFill($row)->getAttributes()`
   applies the mutators and the date, JSON, enum and class casts, but not the
   `boolean`, `int` or `float` casts.

## Step 5: Queries on the package builder

Laravel's query builder replaces the package builder. In the code that ran on
the package builder (see step 1.5), rewrite each pattern. Check each
replacement against `vendor/oralunal/laravel-clickhouse/src/QueryBuilder.php`
and Laravel's `Illuminate\Database\Query\Builder`.

1. **Types and imports.**
   - A type of the package `Builder`: where it receives a model query
     (`Event::select()`, `Event::where()`, …), use
     `Oralunal\LaravelClickHouse\Eloquent\Builder`; where it receives
     `DB::connection(...)->table()` or `->query()`, use
     `Oralunal\LaravelClickHouse\QueryBuilder`; where both reach it, use
     `Illuminate\Contracts\Database\Query\Builder`.
   - `new Builder($client)` or `new Builder(...)`: build the query from the
     connection instead: `DB::connection('<name>')->query()`, and
     `(new Builder(...))->table('t')` → `DB::connection('<name>')->table('t')`.
   - A class that extends the package `Builder`: extend
     `Oralunal\LaravelClickHouse\QueryBuilder`, and create it with
     `new static($connection, $connection->getQueryGrammar(), $connection->getPostProcessor())`.
   - A closure typed with the package `Builder` or `BaseBuilder`, as in
     `where(fn (Builder $q) => ...)`, now gets Laravel's builder, or Eloquent's
     builder inside a model query. Search
     `(fn|function)\s*\(\s*\??(Builder|BaseBuilder)\s+\$` in files that import
     the package classes, and type the parameter with
     `Illuminate\Contracts\Database\Query\Builder`, or remove the type.
2. **Results.** In 3.x, `get()` returned a `ClickHouseDB\Statement`. On a
   model query, 4.x `get()` returns models, and the casts apply to what it
   reads: add `toBase()` where the code needs rows, not models. A 4.x row is a
   `stdClass` object (see step 9.6); `getStatement()->rows()` gives the
   associative arrays of 3.x, also on a model query.
   | Search | 3.x | 4.x |
   | --- | --- | --- |
   | `->get\(\)\s*->\s*rows\(` | `->get()->rows()` | `->getStatement()->rows()`: associative arrays, as before |
   | `->get\(\)\s*->\s*fetchOne\(\)` | `->get()->fetchOne()` | `->getStatement()->fetchOne()`: an associative array, as before |
   | `->get\(\)\s*->\s*fetchOne\(` with a name | `->get()->fetchOne('c')` | `->value('c')` (see step 12.3); on a model query `->toBase()->value('c')` |
   | `->get\(\)\s*->\s*(fetchRow\|rowsAsTree\|rowsGenerator\|jsonRows\|rawData\|totals\|extremes\|extremesMin\|extremesMax\|statistics\|countAll\|info\|summary\|isError\|error\|sql)\(` | `->get()->totals()` | `->getStatement()->totals()` |
   | `->format\(` | `->format('CSV')->get()->rawData()` | `->getStatement('CSV')->rawData()` |
   | `->getRows\(` | `->getRows()` | `->getStatement()->rows()`: associative arrays, as before |
   | `->(get\|getRows)\(\s*[\[$]` | `->get(['id' => 5])`, `->getRows($bindings)`: smi2 bindings | write the values with the condition: `whereRaw('id = ?', [5])`; `get()` takes columns |
   Also search `->get\(\)\s*->` and read each method: one that a collection
   does not have was a `Statement` method. Search `=\s*[^;]*->get\(\)\s*;` and
   follow the variable: `$statement = $query->get(); $statement->fetchOne('c')`
   needs `getStatement()`. A `get()` whose result is iterated with `foreach`
   still runs: a collection of `stdClass` objects, or of models on a model
   query; a read such as `$row['id']` in the loop must become `$row->id`. A
   `get()` whose result is passed on or type-hinted as `Statement` must
   change: ask the user which one the caller needs.
3. **SQL text.** `->toSql()` of the package builder held the values; Laravel's
   holds `?` placeholders. Search `->toSql\(\)`. Where the SQL is run, logged
   or compared as finished SQL, use `->toRawSql()`. Tests that compare SQL
   strings must expect 4.x's SQL: double-quoted names and lower-case keywords,
   such as `select * from "events" where "id" = 1` (`toRawSql()`).
4. **Raw SQL and values.**
   | Search | 3.x | 4.x |
   | --- | --- | --- |
   | `new\s+RawColumn\(` | `new RawColumn('count()', 'n')` | `DB::raw('count() as n')` |
   | `(^\|[^\w>:$\\])\\?raw\(` and `use function …\\raw;` | `raw('count()')` | `DB::raw('count()')`; remove the `use function` import |
   | `ClickhouseBuilder\\+Query\\+Expression`, `new\s+Expression\(` | `new Expression('now()')` | `DB::raw('now()')` (`Illuminate\Support\Facades\DB`) |
   | `InsertArray` | `new InsertArray(['a', 'b'])` | `['a', 'b']` |
   | `(^\|[^\w>:$\\])\\?tp\(` | `tp($value, $callback)` | `tap($value, $callback)` |
   | `(^\|[^\w>:$\\])\\?array_flatten\(` | `array_flatten($array)` | `Arr::flatten($array)` (`Illuminate\Support\Arr`) |
   `select(raw('a + 1 as b'))` can also be `selectRaw('a + 1 as b')`. A raw
   string that holds a `?` outside quotes, such as a ternary `a ? b : c`, is
   read as a placeholder in a query with bindings: write `??`, which is always
   sent as `?`. `new InsertArray([])` stored `['']`, an array of one empty
   string; `[]` stores an empty array: tell the user when reads count the
   empty string.
5. **Clauses with another signature.**
   | Search | 3.x | 4.x |
   | --- | --- | --- |
   | `->(limit\|take)\(`: read each call with two arguments | `->limit(10, 20)` | `->limit(10)->offset(20)`; Laravel ignores a second argument without an error |
   | `->takeBy\(` | `->takeBy(1, 'a')` | `->limitBy(1, 'a')` |
   | `->orderBy(Asc\|Desc)?\(` with a collation | `->orderBy('n', 'asc', 'tr')` | `->orderByRaw('n asc collate ?', ['tr'])` |
   | `->orderByAsc\(` | `->orderByAsc('n')` | `->orderBy('n')` |
   | `->addGroupBy\(`, `->groupBy\(` | `->addGroupBy('b')`; `groupBy()` replaced the groups | `->groupBy('b')`; `groupBy()` adds to the groups, so a chain that calls it twice now groups by both |
   | `(->\|::)(addSelect\|select)\(\s*\[[^\]]*['"]\s*=>` | `select(['name' => 'n'])` | `select('name as n')` |
   | `->cloneWithout\(` | `cloneWithout(['limit' => null])`: a map; `limit` held the offset | `cloneWithout(['limit', 'offset'])`: a list of names; on a model query `->toBase()->cloneWithout([...])` |
   | `->table\(` on a package builder, `->get(Columns\|Wheres\|PreWheres\|From\|Orders\|Groups\|Joins\|ArrayJoin\|Havings\|Limit\|LimitBy\|Sample\|Unions\|Format\|Settings\|OnCluster\|AsyncQueries)\(` | `$builder->table('t')`, `$builder->getWheres()` | `DB::connection('<name>')->table('t')`; read the public properties of Laravel's builder, such as `$wheres`, `$orders`, `$settings` and `$onCluster`. Laravel's `getColumns()` and `getLimit()` remain, but return the columns as text and the limit as an `int`, where 3.x returned objects |
   | `->onCluster\(` before `delete(` or `update(` | did nothing | sends `ON CLUSTER`, to every host: remove it to keep the 3.x statement |
   | `->final\(\s*false` | wrote `FINAL` | writes nothing: write `final()` to keep `FINAL` |
   | joins with a `USING` list: `Join\([^,]+,\s*\[` | `->anyLeftJoin('users', ['id'])` | `->anyLeftJoin('users', 'events.id', '=', 'users.id')`; with `select *`, the result now also holds the key column of the joined table: select the columns by name |
   | `->(leftJoin\|innerJoin\|rightJoin\|fullJoin\|join)\(` with a strictness or `GLOBAL` argument | `->leftJoin('users', 'ANY', ['id'], true)` | `->join('users', 'events.id', '=', 'users.id', 'global any left')` |
   | `JoinClause`, `ArrayJoinClause` closures | `->join(fn (JoinClause $j) => ...)` | the method of the join kind with an on-clause: `anyLeftJoin`, `allInnerJoin`, `asofJoin`, … (and their `…Sub` forms) |
   | `From` closures, `->from\(\s*(fn\|function)` | `->from(fn (From $f) => $f->remote($host, $db, $t, $user, $pass))` | `->fromRaw('remote(?, ?, ?, ?, ?)', [$host, $db, $t, $user, $pass])`; `merge()` → `fromRaw('merge(?, ?)', [...])` |
   | `->as\(`, `->alias\(` after `from($query)` | `->from($query)->as('s')` | `->fromSub($query, 's')` |
   | `Column` closures in `select()` | `select(fn (Column $c) => $c->name('a')->as('b'))` | `select('a as b')`, or `selectRaw()` for functions |
   | `->whereDict\(`, `->orWhereDict\(`, `->addSelectDict\(` | `->whereDict('users', 'name', $id, 'Ann')` | `->select('*')->selectRaw('dictGetString(?, ?, ?) as name', ['users', 'name', $id])->where('name', 'Ann')` |
   | `->(or)?having(Not)?In\(`, `having(Not)?BetweenColumns` | `->havingIn('c', [1, 2])` | `->havingRaw('c in (?, ?)', [1, 2])`; for a list in a variable, one `?` per value: `havingRaw('c in (' . implode(', ', array_fill(0, count($list), '?')) . ')', $list)` |
   | `asyncWithQuery\|toAsyncSqls\|toAsyncQueries\|getAsyncQueries` | queries meant to run together | `Oralunal\LaravelClickHouse\Parallel::getRows([$query, $other])`, or `Parallel::get()` for statements |
   | `getCountQuery\|setSourcesTable` | | `count()`, `exists()`, `forPage()`, `selectRaw('count() as c')`; ask the user where the intent is unclear |
   | `ClickhouseBuilder\\+Exceptions\\+`, `ClickhouseSchemaBuilder\\+Exceptions\\+` | catch blocks | ask the user what they guard: 4.x throws `InvalidArgumentException`, `RuntimeException`, `LogicException` or `Oralunal\LaravelClickHouse\Exceptions\QueryException` before a request, and `ClickHouseDB\Exception\DatabaseException` for a server error |
6. **Values, operators and enums.**
   - A `where()` with two arguments and an array value was `IN` in 3.x, also
     on `Event::where()`. Laravel compares with the first value only, without
     an error: `where('id', [1, 2])` sends `where "id" = 1`, and a `delete()`
     or `update()` then changes fewer rows. Search, ignoring case,
     `(->|::)(or)?(where|prewhere|having)\(\s*[^,()]+,\s*(\[|[^,()]+\))` and
     read each match: where the value can be an array, use `whereIn()`,
     `preWhereIn()` or `havingRaw()`.
   - Laravel's `where()` does not know `IN`, `BETWEEN` or `IS NULL` as
     operators: it compares with the string, as in `where "id" = 'IN'`.
     Search, ignoring case,
     `(->|::)(or)?(where|preWhere|having)\w*\([^;]*?,\s*['"](not\s+)?(in|between|global\s+in|global\s+not\s+in)['"]`,
     `['"]is\s+(not\s+)?null['"]` and
     `Operator::(IN|NOT_IN|BETWEEN|NOT_BETWEEN|GLOBAL_IN|GLOBAL_NOT_IN)`, and
     use `whereIn()`, `whereNotIn()`, `whereBetween()`, `whereNotBetween()`,
     `whereGlobalIn()`, `whereGlobalNotIn()`, `whereNull()` or
     `whereNotNull()`.
   - Replace the other constants with their strings: `Operator::EQUALS` →
     `'='`, `Operator::NOT_EQUALS` → `'!='`, `Operator::LIKE` → `'like'`,
     `Operator::AND`/`OR` → `'and'`/`'or'`, `OrderDirection::ASC`/`DESC` →
     `'asc'`/`'desc'`. A constant called as a method, such as
     `Operator::EQUALS()`, is the same string without the call: `'='`, never
     `'='()`. Search `(Operator|OrderDirection|JoinType|JoinStrict)::[A-Z_]+\(\)`.
     Remove the imports of `Operator`, `JoinType`, `JoinStrict` and
     `OrderDirection`.
7. **`whereIn()` and its forms take a list or a sub-query.** A string, a
   number or a raw expression, as in `whereIn('id', raw('allowed_ids'))`, now
   fails with a `TypeError`. Search
   `->(or)?(where|preWhere|whereGlobal)(Not)?In\(\s*[^,]+,\s*(['"$0-9]|raw\(|DB::raw|new\s)`
   and read each match: a variable that always holds an array is fine; one
   that can hold a single value or an array, as `$request->query()` can,
   becomes `Arr::wrap($value)`. Use `whereRaw('id in allowed_ids')`, or a
   sub-query closure such as
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
   key. On a model query the collection holds models. Search `->chunk\(`.
   Type the callback parameter as `Illuminate\Support\Collection` or remove
   the type. On the query builder, and on a model query after `toBase()`,
   the collection holds `stdClass` objects: read `$row->id`, or `(array) $row`
   where the code needs an array. In the callback, give array functions
   `$rows->all()`, and test `$rows->isEmpty()` instead of `$rows === []`. Add `orderBy()` with a
   column that the user confirms. A model whose table has no `id` column needs
   an explicit `orderBy()` too.
10. **Mutations.**
    - `->delete()` and `->update($values)` returned a `Statement`; they return
      an `int`. Search `->(delete|update)\([^;]*\)\s*->` and assignments of
      their result.
    - A lightweight delete on the query builder takes the option by name:
      search `->delete\(\s*(true|false)` on `DB::connection(...)->table()` and
      `->query()` chains, and write `->delete(lightweight: true)` (or
      `false`). `QueryBuilder::delete(true)` throws, because Laravel's first
      parameter is `$id`. 3.x had no lightweight deletes and ignored an
      argument of `delete()`: on a model query, `Event::where(...)->delete(true)`
      now sends a lightweight `DELETE FROM`. Search `->delete\(\s*true` on model
      queries, tell the user, and write `->delete()` to keep the 3.x
      `ALTER TABLE ... DELETE` unless the user wants the lightweight delete.
    - 4.x adds a partition argument: `update($values, $partition)` and
      `delete(lightweight: …, partition: …)`.
11. **`settings()`.** Calls now add up instead of replacing each other, and
    `null` removes a name. 3.x sent only the settings of the last call. Search
    `->settings\(`. To keep the 3.x query, give `null` in the last call for
    each name of an earlier call that the last call does not set.

## Step 6: Migrations

`Oralunal\LaravelClickHouse\Migration` is removed. Change every migration that
uses it, **including the ones that already ran**: a fresh install, CI and
`migrate:fresh` load them again. Search
`^use\s+\\?Oralunal\\+LaravelClickHouse\\+Migration\b|extends\s+\\?Oralunal\\+LaravelClickHouse\\+Migration\b`
(an `extends Migration` alone is Laravel's class unless the file imports the
package's), `(static|self)::(write|createMergeTree)\(` and
`ClickhouseSchemaBuilder\\+`.

1. Extend `Illuminate\Database\Migrations\Migration` and set
   `protected $connection` to the ClickHouse connection that the migration
   used (`clickhouse` when it did not set one). Set it as well on Laravel
   migrations that call `Schema::connection('<clickhouse>')` or
   `DB::connection('<clickhouse>')`, so that `migrate --pretend` pretends on
   that connection.
2. `static::write($sql)` becomes
   `DB::connection($this->connection)->statementOnEveryNode($sql)`. It sends
   the statement to every node of the connection, as `write()` did. It takes
   no bindings and sends a `?` as written; a `??` outside quotes is sent as
   `?`. For `write($sql, $bindings)`, write each value into
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
   | `integer('n', $bits, $withSign)` | the method of that size and sign, such as `integer('n', 64, false)` → `unsignedBigInteger('n')` |
   | `float('n')` (`Float32` in 3.x), `float32()`, `float('n', 32)` | `float('n', 24)` (`Float32`); Laravel's `float('n')` is `Float64` |
   | `float64()`, `float('n', 64)` | `double('n')` (`Float64`) |
   | `datetime('d')`, `datetime('d', 3, 'UTC')` | `dateTime('d')`, `dateTime('d', 3)->timezone('UTC')` |
   | `string()`, `date()`, `uuid()`, `bool()`, `enum()`, `decimal('n', 10, 2)` | `string()`, `date()`, `uuid()`, `boolean()`, `enum()`, `decimal('n', 10, 2)` |
   | `->nullable()`, `->default()`, `->comment()` | the same modifiers |
   | `->default(new Expression('now64()'))` | `->default(DB::raw('now64()'))`, or `->useCurrent()` |
   | `engine(Engine::REPLACING_MERGE_TREE, 'v')`, `engine('X', ...)` | `engine('ReplacingMergeTree(v)')` |
   | `orderBy('a', 'toDate(ts)')` | `orderBy('a', DB::raw('toDate(ts)'))`: `orderBy()` takes column names, and an expression with `DB::raw()` |
   | `partition('…')`, `settings([...])`, `ifNotExists()` | `partitionBy('…')`, `settings([...])`, `ifNotExists()` |
   | `ttl('d', '1 DAY')` | `ttl('d + INTERVAL 1 DAY')` |
   | `onCluster(...)`, `getEngine()->replicated()`, `dbName()` | nothing: the connection's `cluster_name` and `cluster` decide; `withoutOnCluster()` or `replicated(false)` opt out. See the note below. |
   **Replication.** On a connection with `cluster` nodes, 3.x
   `createMergeTree()` made a MergeTree-family table replicated, with the path
   `'/clickhouse/tables/<database>.<table>', '{replica}'` and insert
   deduplication on, and sent it to every node. 1.x and 2.x did not when the
   callback called `engine()`. 4.x makes such a table replicated only on a
   connection with a `cluster_name` and `cluster` nodes, `ON CLUSTER`, with
   ClickHouse's default path and insert deduplication off
   (`replicated_deduplication_window=0`). On a connection with `cluster` nodes
   and no `cluster_name`, `Schema::create()` creates a table that is not
   replicated, on the active node only. Compare with production
   (`SHOW CREATE TABLE` on each node, if the user lets you read the database)
   and propose to the user: `$table->replicated(false)` for a table that is not
   replicated; an engine that names the replicated engine, such as
   `engine("ReplicatedMergeTree('/clickhouse/tables/default.events', '{replica}')")`,
   to keep the path and deduplication (a replicated engine that you name gets
   no setting); a `cluster_name`; or keeping the `CREATE TABLE` with
   `statementOnEveryNode()`.
   Always call `orderBy()` with the sorting key that the old table had. Remove
   escaping that was added by hand for `createMergeTree()`, such as
   `->comment("it\\'s")`: the schema builder escapes values itself.
4. Compare the statements of each changed migration with the ones that the
   old migration wrote, or with `SHOW CREATE TABLE` if the user lets you read
   the database. `migrate --pretend` shows only the migrations that did not
   run. To see the statements of any migration without running it, pretend
   its `up()` on its connection:

   ```bash
   php artisan tinker --execute '$m = require "database/migrations/<file>.php"; $c = $m->getConnection() ?? config("database.default"); DB::setDefaultConnection($c); dump(array_column(DB::connection($c)->pretend(fn () => $m->up()), "query"));'
   ```

   It pretends only on that connection: check first that the migration does
   not write to another one. Show the user every difference: a fresh run
   should create the tables that production has. Where a table must stay
   exactly as it is, keep its SQL with `statementOnEveryNode()` instead of the
   schema builder.

## Step 7: Enums, the grammar and other classes

1. `ClickhouseBuilder\Query\Enums\Format` → `Oralunal\LaravelClickHouse\Enums\Format`,
   a native enum. Its cases are the names in StudlyCase: `Format::CSV` →
   `Format::Csv`, `JSON_EACH_ROW` → `JsonEachRow`, `TAB_SEPARATED` →
   `TabSeparated`, `XML` → `Xml`, `TSV` → `Tsv`, `TSKV` → `Tskv`, `VALUES` →
   `Values`, and so on (read `src/Enums/Format.php`). A 3.x constant was a
   string: where code needs the string, use `Format::Csv->value`.
   - A constant called as a method, `Format::CSV()`, → `Format::Csv`, without
     parentheses. Search `Format::[A-Z_]+\(\)`.
   - `new Format($value)` → `Format::from($value)`; `new Format(Format::CSV)` →
     `Format::Csv`; `->getValue()` → `->value`; `->getKey()` → `->name`,
     which is StudlyCase (`TabSeparated`, where `getKey()` gave
     `TAB_SEPARATED`): check code that builds file names or labels from it.
   - `Format::isValid($value)` → `Format::tryNamed($value) !== null`.
     `tryNamed()` ignores case, where `isValid()` did not.
   - Change `->getValue()` and `->getKey()` only on `Format` values, not on
     the application's own enums.
2. `Oralunal\LaravelClickHouse\Enum\Enum`: an application enum that extends it
   must become a native PHP enum, or extend another library. Ask the user. For
   a native backed enum, change the callers too: `X::PAID()` → `X::Paid`; the
   constant `X::PAID` used as a string → `X::Paid->value`; `->getValue()` →
   `->value`; `->getKey()` → `->name`; `X::isValid($v)` →
   `X::tryFrom($v) !== null`; `X::values()` → `X::cases()`; `X::toArray()` →
   `array_column(X::cases(), 'value', 'name')`; `$a->equals($b)` →
   `$a === $b`. Search `<Enum>::[A-Z_]+\(\)` and each method for each enum.
3. `Oralunal\LaravelClickHouse\Grammar`: to write a value as a ClickHouse
   literal, use `DB::connection('<name>')->escape($value)`, or
   `->newLiteralGrammar()->compileLiteral($value)` of the connection.
   `QueryGrammar::PARAMETER_SIGN` and `QueryGrammar::prepareParameters()` are
   removed: write `?` placeholders and give the values as bindings.
4. `Oralunal\LaravelClickHouse\WithClient` in an application class: use
   `DB::connection('<name>')->getClient()`.
5. Queued jobs and cached values that hold a 3.x object, such as a `Format`, a
   `RawColumn`, a builder or a model, cannot be read by 4.x: unserializing
   them fails. Search the job classes and cached values for such properties,
   and tell the user to let the queues drain and to clear such caches before
   the deploy.

## Step 8: Make every class load

1. Find every class that extends a class of the package: search
   `extends\s+\\?Oralunal\\+LaravelClickHouse\\+` and `extends` in files that
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
   autoloader. Anonymous migration classes are not in the class map: step 14.5
   loads them.
3. Search again for every pattern of step 1.5 and steps 4 to 7. Nothing that
   names a removed class or method may remain, except in comments or text that
   the user wants to keep. Loading a class does not find a method that does not
   exist, such as a `Statement` method on a collection: step 14.7 runs the
   changed code.

## Step 9: Fix what fails at run time

1. **`FORMAT` in `select()`.** `select()`, `selectOne()`, `scalar()`,
   `cursor()`, `selectParallelly()` and `Parallel` refuse SQL whose `FORMAT`
   clause names a format other than `JSON`, `JSONStrings`, `JSONCompact` and
   `JSONCompactStrings` (`cursor()` refuses every `FORMAT` clause). Search
   `FORMAT\s+[A-Za-z]` in SQL given to them. Remove the clause, or read the
   result with `DB::connection('<name>')->selectStatement($sql, $bindings)->rawData()`.
2. **Placeholders.** Search `#@\?`: a hand-written `#@?` marker is not a
   binding; write `?`. A query that mixes `?` with smi2 placeholders (`:0`,
   `{0}`) or query parameters (`{name:Type}`) throws before it is sent. With
   `:name`, the `?` reaches ClickHouse and fails there. Use one kind of
   placeholder per query.
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
6. **Rows are objects.** Each row that the query builder and the connection
   return is a `stdClass` object, as on Laravel's other connections: `get()`,
   `first()`, `find()`, `sole()`, `cursor()`, `lazy()`, `chunk()`, `each()`
   and `paginate()` of a query, `select()`, `selectOne()` and `cursor()` of a
   connection, and `Parallel::getRows()`. 3.x returned associative arrays, so
   a read such as `$row['id']` fails with `Cannot use object of type stdClass
   as array`. A value of an `Array`, a `Map` or a `Tuple` column stays a PHP
   array. Search `->(select|selectOne)\(` on ClickHouse connections, and
   `\$\w+(\[\d+\])?\[['"]\w+['"]\]` in the code that reads their rows and the
   rows of queries, and follow each result: a variable, a `foreach`, a
   callback, a return value. For each read:
   - read the column as a property, `$row->id`; or
   - keep the arrays: `getStatement()->rows()` of a query and
     `selectStatement($sql, $bindings)->rows()` of a connection give the
     associative arrays of 3.x; or convert one row with `(array) $row`.
   Choose the property where the code reads a few columns, and the arrays
   where the rows go on as arrays, such as into `array_column()` with a key,
   a JSON response or a function typed `array`. `array_column()` reads the
   properties of objects too.
7. **Values have the PHP types of their columns.** 3.x returned the values of
   the JSON result, which changed with the ClickHouse version. 4.x gives each
   value the type of its ClickHouse type, on every version, through the rows
   of step 9.6 (not through `getStatement()` or `selectStatement()`):
   - `UInt64` and `Int64` are ints, where 24.8 gave strings, such as `'5'`; a
     value above `PHP_INT_MAX` is a numeric string;
   - a whole `Float64` is a float, where 3.x gave an int; NaN and the
     infinities are `NAN`, `INF` and `-INF`, where 3.x gave `null`;
   - a `Decimal` is a string with the scale of its column, such as `'1.50'`,
     where 3.x gave a float, or an int for a whole value.
   Search the code that reads such values for a comparison with a numeric
   string or an int, such as `=== '0'` or `=== 0`, for `is_string(`,
   `is_int(` and `is_float(`, and for `round(`, `number_format(`, `floor(`,
   `ceil(` and `abs(` on decimal columns: in a file with
   `declare(strict_types=1)`, a decimal string given to a float parameter
   fails with a `TypeError`. Cast it with `(float)` where the code needs a
   float. Ask the user before a change of a value that is stored or sent on.

## Step 10: Review the writes with the user

These changes make a write touch other rows or store other values than in 3.x.
For each match, read the code, decide whether it is affected, and tell the
user. **Ask before you change any of them.**

1. **`whereNotIn()` with an empty list** adds `1 = 1`, as in Laravel. With
   other conditions, `->whereNotIn('id', $ids)->delete()` (or `update()`)
   with an empty `$ids` changes the rows that the other conditions match, as in
   3.x. As the only condition, it changes **every row**, where 3.x sent a
   statement that ClickHouse refused. Search `whereNotIn` and `orWhereNotIn`
   in queries that reach `delete()` or `update()`. Where the list can be empty,
   propose a guard, such as returning early when `$ids === []`.
2. **Mutations with clauses that ClickHouse ignores.** `delete()` and
   `update()` throw, and send nothing, for a query with a join, `GROUP BY`,
   `HAVING`, `LIMIT`, `LIMIT ... BY`, a set operation, a setting other than
   the mutation settings (`QueryGrammar::MUTATION_SETTINGS`), `FINAL`,
   `SAMPLE`, `ARRAY JOIN`, `WITH`, a select list with aliases or expressions,
   or an `ORDER BY` that defines an alias, calls `arrayJoin()` or limits rows.
   In 3.x some of these changed rows that the query did not select. Show the
   user each chain that reaches `delete(` or `update(` with such a clause; the
   fix is to select the keys first: `whereIn('id', $query->pluck('id'))`.
   A `preWhere()` condition now joins the where conditions of the mutation,
   where 3.x left it out, so the same delete changes fewer rows.
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
5. **Casts on writes** (step 4.5) and **rows with missing keys**: `insert()`
   and `buffer()` refuse a row without keys, `insert()` refuses a row whose
   keys differ from the first row's, in any order, and `buffer()` one whose
   keys differ from the buffered rows'. Check how the rows are built, such as
   rows that leave out a key whose value is null.
6. **Collections.** A `Collection` value of an insert or an update is written
   as an array literal, where 3.x wrote it as JSON text. Search where rows get
   collections, and pass `->toJson()` to a `String` column that must keep JSON.
7. **Laravel's insert.** `insert([])` returns `true` and sends nothing, as in
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
   `UInt32`, `UInt64`). 3.x wrote `Int16` for `tinyInteger()`, and signed
   types for the unsigned columns and `id()`. List each `Schema::create()`
   column whose type changes, and ask the user whether a fresh run should
   match production; if so, use `rawColumn('n', 'Int64')` there.
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
   tables, the `migrations` table included, with a replicated engine (step
   6.3); `migrate:fresh`, `db:wipe`, `Schema::createDatabase()` and the load of
   a schema dump also run `ON CLUSTER`, and reach every host of the cluster,
   also hosts that `cluster` does not list; an `ON CLUSTER` statement waits for
   every host, up to 180 seconds, while the packaged `timeout_query` is 2
   seconds, so propose a higher `timeout_query` for migration commands; a
   table of the active node only can be changed with
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
   `getRows()`. Check the callers of the chains that step 4 did not rewrite
   to `toBase()`.
3. **`value($column)`** on a query with a select list returns the first
   selected column, as in Laravel. Search `->value\(` on queries that call
   `select(`.
4. **`whereLike()` and `whereNotLike()`** send `ilike` and `not ilike`, which
   ignore case. On a connection that used Laravel's builder in 3.x, they sent
   `like`. Search `->(or)?where(Not)?Like\(` there, and pass
   `caseSensitive: true` where the code needs `like`.
5. **Counts and timeouts.** `affectingStatement()` and `insertUsing()` return
   the number of written rows, where 3.x returned `1`. `timeout($seconds)` is
   sent as the setting `max_execution_time`. A `timeout_query` with a
   fraction, such as `0.5`, meant no limit in 3.x and is rounded up to whole
   seconds now.
6. **Tests.** Tests that assert 3.x SQL, statements or row arrays must assert
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
4. Every search of steps 1.5 and 4 to 12 again, with `rg -U`. What remains
   must be a case you decided to leave, with the user's answer where you
   asked.
5. Load every migration file:

   ```bash
   php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); foreach (glob("database/migrations/*.php") as $file) { require $file; } echo "Migrations loaded.\n";'
   ```

   and show the statements of each changed migration with the command of step
   6.4. If the database cannot be reached, run
   `php -l database/migrations/*.php` instead and say so in the report.
6. Run the test suite and compare it with the baseline from step 1. Every
   failure that is new must be fixed or explained.
7. Run each changed query once when the test suite does not cover it: in
   `php artisan tinker`, print `toRawSql()` and read once, and run a write
   inside `DB::connection('<name>')->pretend(...)`. A method that a collection
   or a builder does not have, such as a `Statement` method, fails only when
   it runs.
8. **Static analysis.** Do this only when `composer.json` requires
   `larastan/larastan` and the project has a PHPStan config file (see step
   1.3).
   - If the config does not include
     `vendor/oralunal/laravel-clickhouse/extension.neon`, offer it to the user
     and ask before you add it. Tell the user what it does: it reads the
     schema dumps of the ClickHouse connections in `database/schema`, so that
     PHPStan knows the columns of the ClickHouse models, and it gives
     `DB::connection('<name>')`, `Schema::connection('<name>')` and
     `DB::table()` the types of the package, so that PHPStan knows their
     ClickHouse methods. See
     https://laravel-clickhouse.oralunal.com/reference/static-analysis.
   - If the user agrees, add the line to the `includes` of the config, after
     the line of `vendor/larastan/larastan/extension.neon`. When the config
     does not name Larastan's extension, because `phpstan/extension-installer`
     includes it, add the line to `includes`, and add `includes` when it is
     not there: the config of the project loads after the extensions of the
     installer. Never put the line before Larastan's line: PHPStan then stops
     with "must be included after the extension of Larastan".
   - If the user does not agree, change nothing and say so in the report.
   - Run PHPStan as in step 1.3 and compare its errors with those of the
     baseline. An error that is new in the code that you changed, or in the
     code that reads the rows of steps 9.6 and 9.7, such as an offset access
     on a `stdClass` row or a comparison that is always false, shows a case
     that the searches missed: fix it as that step says, or ask the user.
     Explain the other new errors in the report.
   - Larastan reads the ClickHouse tables from a schema dump, and reads the
     migrations after the dumps (see the page above). If the project has no
     dump of a ClickHouse connection, tell the user that
     `php artisan schema:dump --database=<name>` writes one. Do not run it
     yourself, and never with `--prune`, which deletes the migrations.

## Step 15: Report

End with a short summary for the user:

- the version change in `composer.json`, and any other package you changed;
- the files you changed in each step, with what changed;
- every question you asked and the decision taken;
- the matches of steps 10 to 12 that you left as they are, and why;
- the migrations whose fresh run differs from production, from steps 6 and 11;
- for a cluster connection, the notes of step 11.4;
- the new options of step 3.2 that the user may want, and the `retries` note
  of step 3.3;
- the queues and caches to drain or clear before the deploy (step 7.5);
- the test results compared with the baseline, and any failures that already
  existed, or that there was no baseline;
- if the ClickHouse servers run 25.8 or later, a pointer to
  https://laravel-clickhouse.oralunal.com/reference/clickhouse-versions, which
  lists what they do otherwise than 24.8 and the settings that bring back its
  behavior;
- for a project with Larastan, the answer about the PHPStan extension of step
  14.8, the PHPStan errors compared with the baseline, and the note about a
  schema dump.
