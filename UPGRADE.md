# Upgrade Guide

- [Upgrading from 3.x to 4.0](#upgrading-from-3x-to-40)
- [Upgrading from 2.x to 4.0](#upgrading-from-2x-to-40) (`oralunal/phpclickhouse-laravel` 2.x)
- [Upgrading from 1.x to 4.0](#upgrading-from-1x-to-40) (`oralunal/phpclickhouse-laravel` 1.x)

Each upgrade has a coding-agent skill that does it for you, checks the result,
and asks you where a change needs your decision. `php artisan
clickhouse:install-skills` installs the three skills into every coding agent it
finds in your project: Claude Code, Cursor, GitHub Copilot, Codex, Junie,
OpenCode, Amp, Gemini/Antigravity, Kiro, Pi, Zed, Grok Build, Factory Droid and
other agents that read `AGENTS.md`. It finds them from their project files
(`.claude/`, `.cursor/`, `AGENTS.md`, …); pass `--agent=claude_code` and so on
to choose them yourself, and run `php artisan clickhouse:install-skills --help`
for the list of names. It also removes the skills of earlier releases,
`/plc-upgrade-1x-to-2x` and `/lc-upgrade-2x-to-3x`, which 4.0 no longer ships.

## Upgrading from 3.x to 4.0

PHP (`^8.5`) and Laravel (`^13`) requirements, the package name and the
`Oralunal\LaravelClickHouse` namespace are unchanged.

4.0 is a clean break. The package no longer has a model, a query builder, a
schema builder or a migration class of its own. You use Laravel's:

| 3.x | 4.0 |
| --- | --- |
| `Oralunal\LaravelClickHouse\BaseModel` | `Oralunal\LaravelClickHouse\Eloquent\Model`, an Eloquent model |
| The package's `Builder` (`ClickhouseBuilder\…`) | `Oralunal\LaravelClickHouse\QueryBuilder`, Laravel's query builder with the ClickHouse clauses |
| `Oralunal\LaravelClickHouse\Migration` (`write()`, `createMergeTree()`) | Laravel migrations, with `Schema` and `DB` |
| `ClickhouseSchemaBuilder\Tables\MergeTree` | Laravel's schema builder, `Oralunal\LaravelClickHouse\SchemaBlueprint` |
| `RawColumn`, `raw()`, `Expression`, `InsertArray` | `DB::raw()`, `selectRaw()`, a PHP array |
| `Enum\Enum` and the builder enums | Native enums: `Enums\Format`, `Enums\DateTimePrecision` |

There is no deprecation phase: the old classes are gone. The `[4.0.0]` section
of the [CHANGELOG](CHANGELOG.md) lists every change; this guide covers what you
have to change or check.

### Let your coding agent do it

1. Update the package and install the skills:

   ```sh
   composer require oralunal/laravel-clickhouse:^4.0 --with-all-dependencies
   php artisan clickhouse:install-skills
   ```

   If `php artisan` fails because a class of your app extends a removed class,
   such as `BaseModel`, copy the skill by hand instead:

   ```sh
   mkdir -p .claude/skills
   cp -r vendor/oralunal/laravel-clickhouse/resources/skills/lc-upgrade-3x-to-4x .claude/skills/
   ```

2. In your agent, run:

   ```text
   /lc-upgrade-3x-to-4x
   ```

   If your agent does not offer skills as slash commands, ask it to
   "upgrade laravel-clickhouse to 4.x".

The agent rewrites the models, the queries and the migrations for 4.0, reviews
with you the writes and the migrations whose behavior changes, checks the
result, and reports how the test suite compares with the run before the
upgrade. It never runs `migrate:fresh` or changes the data of a real database,
and it asks before it changes what a write does.

### Or upgrade by hand

To find the code that 4.0 breaks, run:

```sh
grep -rnE 'BaseModel|LaravelClickHouse\\(Builder|Migration|RawColumn|Grammar|WithClient)\b|ClickhouseBuilder|ClickhouseSchemaBuilder|Enum\\Enum|InsertArray|insertAssoc|insertBulk|prepareAndInsert|getRows\(|getThisClient|createMergeTree|static::write\(|fix_default_query_builder|asyncWithQuery|getCountQuery|(^|[^A-Za-z0-9_>:$\\])(raw|tp|array_flatten)\(' \
  app config database routes tests
```

This finds the imports and the main method names. Also search for
`resolveConnection(`, `tableForInserts`, `tableSources`, `getClient(`,
`->get()->`, `takeBy(`, `whereDict(`, `->format(` and `->limit(` or `->take(`
with two arguments: some of them change behavior without an error.

#### 1. Update the dependency

```sh
composer require oralunal/laravel-clickhouse:^4.0 --with-all-dependencies
```

#### 2. Configuration

Remove `fix_default_query_builder` from your ClickHouse connections. In 4.0,
`DB::connection('clickhouse')->table()` and `->query()` always return
`Oralunal\LaravelClickHouse\QueryBuilder`, Laravel's query builder with the
ClickHouse clauses. The packaged `config/clickhouse.php` has new, optional
options, such as `use_lightweight_delete`, `datetime_precision`,
`insert_format`, `engine` and `use_on_cluster`. Their defaults keep the 3.x
behavior, except `retry_on`: a connection with `retries` sends a write again
only when it never reached the server. 3.x sent every failed request again, so
an `INSERT` that timed out could store its rows twice. Set `retry_on` to `any`
to keep the 3.x behavior.

#### 3. Models

Extend `Oralunal\LaravelClickHouse\Eloquent\Model` instead of `BaseModel`. It
is an Eloquent model. Its key is not incrementing and is a string by default.

```php
// 3.x
use Oralunal\LaravelClickHouse\BaseModel;

class Event extends BaseModel
{
    protected $table = 'events';
}

// 4.0
use Oralunal\LaravelClickHouse\Eloquent\Model;

class Event extends Model
{
    protected $table = 'events';

    public $timestamps = false; // unless the table has created_at and updated_at

    protected $guarded = [];    // BaseModel filled every attribute
}
```

| 3.x | 4.0 |
| --- | --- |
| `Event::insertAssoc($rows)` | `Event::insert($rows)` |
| `Event::insertBulk($rows, $columns)` | `Event::insert(array_map(fn (array $row): array => array_combine($columns, $row), $rows))` |
| `Event::prepareAndInsertAssoc()`, `prepareAndInsertBulk()`, `prepareAndInsert()` | Prepare the rows yourself, then `Event::insert()` |
| `Event::select(...)->getRows()`, `Event::where(...)->getRows()` | `->toBase()->get()->all()` (arrays), or `->get()` (models) |
| `Event::where(...)->get()` (a `ClickHouseDB\Statement`) | `->get()` returns models; `->getStatement()` returns the statement |
| `Event::getClient()`, `$event->getThisClient()` | `(new Event())->getConnection()->getClient()` |
| `$event->resolveConnection()` | `$event->getConnection()` |
| `protected $tableForInserts` | `DB::connection('clickhouse')->table('events_buffer')->insert($rows)` |
| `protected $tableSources` | Run the write on `DB::connection('clickhouse')->table('events_local')` |
| `Event::optimize()` (a `Statement`) | `Event::optimize()` (a `bool`) |
| `Event::truncate()` (a `Statement`) | `Event::truncate()` (returns the Eloquent builder) |
| `Event::flushBuffer()` (a `Statement` or `null`) | `Event::flushBuffer()` (`true`, or `false` when nothing is buffered) |

What changes for a model:

- **Casts no longer apply to inserts.** `insertAssoc()`, `insertBulk()` and
  `buffer()` turned a value of a `boolean` cast into `1` or `0`. `insert()` and
  `buffer()` send the rows as given. A `bool` is still written as `1` or `0`.
  Cast other values yourself, such as `(int) (bool) $value`.
  `(new Event())->forceFill($row)->getAttributes()` applies the mutators and
  the date, JSON, enum and class casts of the model, but not the `boolean`,
  `int` or `float` casts.
- **`save()` of an existing model updates it.** BaseModel threw an exception.
  An Eloquent model loaded from a query sends `alter table ... update ...
  where "id" = ?` on `save()`, and `alter table ... delete where "id" = ?` on
  `delete()`. Set `$primaryKey` when the key is not `id`.
- **Timestamps.** Eloquent sets `created_at` and `updated_at` on `create()` and
  `save()`. Set `public $timestamps = false;` when the table has no such
  columns.
- **Mass assignment.** `create()` and `fill()` follow `$fillable` and
  `$guarded`, and leave out a key with a dot, such as the Nested column
  `n.a`. Use `forceCreate()`, `forceFill()` or `insert()` for such a key.
- **Queries return models.** `Event::where(...)->get()` returns an Eloquent
  collection, and `first()` a model.

`buffer()`, `bufferCount()`, `getBufferedRows()`, `clearBuffer()` and
`flushAllBuffers()` keep their names. Every buffered row must have the same
keys. `buffer()` throws for a row with other keys, also within one call, where
3.x filled a missing key with its position.

#### 4. Queries

The query builder is Laravel's, so its API is Laravel's API. Change these
uses of the 3.x builder:

| 3.x | 4.0 |
| --- | --- |
| `use Oralunal\LaravelClickHouse\Builder;` | `use Oralunal\LaravelClickHouse\QueryBuilder;` |
| `->get()` (a `Statement`) | `->get()` (a collection of arrays) |
| `->getRows()` | `->get()->all()` |
| `->get()->rows()`, `->get()->fetchOne()` | `->get()->all()`, `->first()` |
| `->format('CSV')->get()->rawData()` | `->getStatement('CSV')->rawData()` |
| `->get()->totals()`, `->get()->extremes()` | `->getStatement()->totals()`, `->getStatement()->extremes()` |
| `->toSql()`, which held the values | `->toRawSql()`; `toSql()` has `?` placeholders |
| `raw('count() AS n')`, `new Expression('...')`, `new RawColumn('count()', 'n')` | `DB::raw('count() AS n')`, or `selectRaw('count() AS n')` |
| `new InsertArray(['a', 'b'])` | `['a', 'b']` |
| `->limit(10, 20)`, `->take(10, 20)` | `->limit(10)->offset(20)` |
| `->takeBy(1, 'user_id')` | `->limitBy(1, 'user_id')` |
| `->orderBy('name', 'asc', 'tr')` | `->orderByRaw('name asc collate ?', ['tr'])` |
| `->orderByAsc('name')` | `->orderBy('name')` |
| `->anyLeftJoin('users', ['id'])` (a `USING` list) | `->anyLeftJoin('users', 'events.id', '=', 'users.id')` |
| `->leftJoin('users', 'ALL', ['id'], true)` (`GLOBAL`) | `->join('users', 'events.id', '=', 'users.id', 'global all left')` |
| `->from(fn (From $from) => $from->remote(...))` | `->fromRaw('remote(?, ?, ?, ?, ?)', [$host, $database, $table, $user, $password])` |
| `->from($subQuery)->as('s')` | `->fromSub($subQuery, 's')` |
| `->where('id', 'in', [1, 2])`, `Operator::IN` | `->whereIn('id', [1, 2])` |
| `->where('id', 'between', [1, 5])` | `->whereBetween('id', [1, 5])` |
| `->where('d', 'is null', null)` | `->whereNull('d')` |
| `->whereIn('id', raw('allowed_ids'))` | `->whereRaw('id in allowed_ids')` |
| `->whereDict('users', 'name', $id, 'Ann')` | `->select('*')->selectRaw('dictGetString(?, ?, ?) as name', ['users', 'name', $id])->where('name', 'Ann')` |
| `->havingIn('c', [1, 2])` | `->havingRaw('c in (?, ?)', [1, 2])` |
| `->asyncWithQuery($other)`, `toAsyncSqls()` | `Parallel::getRows([$query, $other])` |
| `->chunk(100, fn (array $rows) => ...)` | `->orderBy('id')->chunk(100, fn (Collection $rows) => ...)` |
| `->delete()`, `->update($values)` (a `Statement`) | `->delete()`, `->update($values)` (an `int`) |
| `Operator::EQUALS`, `OrderDirection::DESC` | `'='`, `'desc'` |

What else changes:

- **Aggregates take column names.** `sum('price * qty')` quotes the string as
  a column name and fails with `UNKNOWN_IDENTIFIER`. Pass SQL with
  `DB::raw()`: `sum(DB::raw('price * qty'))`.
- **`chunk()` needs an order.** Laravel's `chunk()` throws without `orderBy()`
  on the query builder, and orders by the key on a model query.
- **Closures get Laravel's builders.** A closure typed with the 3.x `Builder`
  or `BaseBuilder`, as in `where(fn (Builder $q) => ...)`, fails with a
  `TypeError`. Type it with `Illuminate\Contracts\Database\Query\Builder`, or
  leave the type out.
- **Lightweight deletes.** 3.x had no lightweight deletes, and ignored an
  argument of `delete()`. `QueryBuilder::delete()` takes Laravel's `$id` as its
  first argument, so `delete(true)` throws: pass the option by name, as in
  `delete(lightweight: true)`. On a model query, `delete(true)` now sends a
  lightweight `DELETE FROM`. To keep the 3.x statement, call `delete()`.
- **`whereIn()` takes a list or a sub-query.** A string or a raw expression
  fails with a `TypeError`.
- **`settings()` calls add up.** A later call no longer replaces the earlier
  ones. Pass `null` for a name to remove it.

#### 5. Migrations

`Oralunal\LaravelClickHouse\Migration` is removed. Extend Laravel's
`Illuminate\Database\Migrations\Migration`. Change every migration, also the
ones that already ran: a fresh install, CI and `migrate:fresh` load them again.

`write()` becomes `statementOnEveryNode()` of the migration's connection. It
sends the statement to every node of the connection, as `write()` did:

```php
// 3.x
use Oralunal\LaravelClickHouse\Migration;

return new class extends Migration
{
    public function up(): void
    {
        static::write('CREATE TABLE events (id UInt64, name String) ENGINE = MergeTree ORDER BY id');
    }
};

// 4.0
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clickhouse';

    public function up(): void
    {
        DB::connection($this->connection)->statementOnEveryNode(
            'CREATE TABLE events (id UInt64, name String) ENGINE = MergeTree ORDER BY id'
        );
    }
};
```

`statementOnEveryNode()` takes no bindings and sends a `?` as written. A `??`
outside quotes is sent as `?`. With bindings, use
`statement($sql, $bindings)`, which runs on the active node, or write each
value with `DB::connection($this->connection)->escape($value)`.

`createMergeTree()` becomes `Schema::create()`:

```php
// 3.x
static::createMergeTree('events', fn (MergeTree $table) => $table
    ->columns([
        $table->uInt64('id'),
        $table->string('name')->nullable(),
        $table->datetime('created_at', 3),
        $table->uInt32('version'),
    ])
    ->engine(Engine::REPLACING_MERGE_TREE, 'version')
    ->partition('toYYYYMM(created_at)')
    ->orderBy('id'));

// 4.0
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\SchemaBlueprint;

Schema::connection('clickhouse')->create('events', function (SchemaBlueprint $table) {
    $table->unsignedBigInteger('id');
    $table->string('name')->nullable();
    $table->dateTime('created_at', 3);
    $table->unsignedInteger('version');
    $table->engine('ReplacingMergeTree(version)');
    $table->partitionBy('toYYYYMM(created_at)');
    $table->orderBy('id');
});
```

| `MergeTree` | `SchemaBlueprint` |
| --- | --- |
| `int8()` … `int64()`, `uInt8()` … `uInt64()` | `tinyInteger()` … `bigInteger()`, `unsignedTinyInteger()` … `unsignedBigInteger()` |
| `int128()`, `uInt256()`, `decimal32()`, any other type | `rawColumn('name', 'Int128')` |
| `float32()`, `float64()` | `float('name', 24)`, `double('name')` |
| `datetime('d', 3, 'UTC')` | `dateTime('d', 3)->timezone('UTC')` |
| `bool()`, `uuid()`, `enum()`, `decimal()`, `date()`, `string()` | `boolean()`, `uuid()`, `enum()`, `decimal()`, `date()`, `string()` |
| `column('tags', 'Array(String)')` | `array('tags', 'String')`, or `rawColumn('tags', 'Array(String)')` |
| `engine(Engine::REPLACING_MERGE_TREE, 'v')` | `engine('ReplacingMergeTree(v)')` |
| `partition()`, `ttl('d', '1 DAY')` | `partitionBy()`, `ttl('d + INTERVAL 1 DAY')` |
| `ifNotExists()`, `settings()`, `orderBy()` | `ifNotExists()`, `settings()`, `orderBy()` |
| `onCluster()`, `getEngine()->replicated()` | Automatic from `cluster_name` and `cluster`; `withoutOnCluster()`, `replicated(false)` to opt out. See the cluster note in step 9. |

Run `php artisan migrate --pretend` to compare the new statements with the
tables that exist.

#### 6. Enums and helpers

| 3.x | 4.0 |
| --- | --- |
| `ClickhouseBuilder\Query\Enums\Format::CSV`, `JSON_EACH_ROW`, `TAB_SEPARATED`, … | `Enums\Format::Csv`, `JsonEachRow`, `TabSeparated`, … |
| `new Format(Format::CSV)`, `$format->getValue()` | `Format::Csv`, `$format->value` |
| `Operator`, `JoinType`, `JoinStrict`, `OrderDirection` | Strings: `'='`, `'left'`, `'any left'`, `'desc'` |
| `Enum\Enum` | A native PHP enum |
| `raw($sql)` | `DB::raw($sql)` |
| `tp($value, $callback)` | `tap($value, $callback)` |
| `array_flatten($array)` | `Illuminate\Support\Arr::flatten($array)` |
| `Oralunal\LaravelClickHouse\Grammar` | `Connection::newLiteralGrammar()`, or `escape()` of the connection |

A 3.x `Format` constant was a string. Where code passes one as a string, use
`Format::Csv->value`. `Format::named('csv')` and `Format::tryNamed('csv')`
find a case by its name in any letter case.

#### 7. Other code that fails at run time

- **Subclasses of the package's classes.** A class that extends `Connection`,
  `QueryGrammar`, `SchemaBuilder`, `SchemaGrammar` or `Cluster` may no longer
  match its parent. Load every class of your app to find the errors.
- **`FORMAT` in `select()`.** `select()`, `selectOne()`, `scalar()`,
  and the parallel queries refuse SQL whose `FORMAT` clause names a format
  other than `JSON`, `JSONStrings`, `JSONCompact` and `JSONCompactStrings`.
  `cursor()` refuses every `FORMAT` clause. Read such a result with
  `DB::connection('clickhouse')->selectStatement($sql)->rawData()`.
- **Placeholders.** A query that mixes `?` with smi2's `:0` or `{0}`
  placeholders or with `{name:Type}` query parameters throws before it is
  sent. In a query with bindings, write the ternary operator as `??`.
- **Quoted table names.** ``table('`my-table`')`` is quoted again and fails.
  Pass the name without backticks.
- **Transactions.** `beginTransaction()`, `transaction()` and `commit()` throw
  a `LogicException`. Use the `DatabaseTruncation` test trait for ClickHouse
  connections.
- **The migration repository.** `app('migration.repository')` is a
  `ClickhouseMigrationRepository`, a subclass of Laravel's
  `DatabaseMigrationRepository`. Its rows are objects:
  `$row->migration`, not `$row['migration']`.

#### 8. Writes to check

- **`whereNotIn()` with an empty list** matches every row, as in Laravel, so
  `->whereNotIn('id', $ids)->delete()` with an empty `$ids` deletes **every
  row**. Return early when the list is empty.
- **`delete()` and `update()`** throw, and send nothing, for a query with a
  clause that a ClickHouse mutation would ignore, such as a join, `LIMIT`,
  `GROUP BY`, `FINAL` or an `ORDER BY` alias. Select the keys first:
  `whereIn('id', $query->pluck('id'))`.
- **Floats** are written with every digit. A float computed in PHP is cut off
  at the scale of a `Decimal` or integer column: `4.35 * 100` stores `434.99`
  in a `Decimal(10, 2)` column. Round it, as in `round(4.35 * 100, 2)`, or pass
  a numeric string or an int. In `update()`, a float with a fraction is cut
  off even when it is rounded: `update(['price' => 19.99])` stores `19.98`.
  Pass a numeric string, such as `'19.99'`, or an int.
- **An empty row** is refused: `insert([[]])` throws `Inserting empty values
  array is not supported in ClickHouse`. `insert([])` returns `true` and sends
  nothing, as in Laravel.
- **Rows with other keys** are refused: every row of `insert()` needs the keys
  of the first row, in any order, or `QueryException::insertKeysDiffer()` is
  thrown before anything is sent. Laravel's insert stored the value of another
  key in a column of the first row. Give every row the same keys, with `null`
  for a missing value.
- **Buffered rows and model inserts** are sent as given (see step 3).

#### 9. Migrations to check

These changes only matter for migrations that run after the upgrade, as with
`migrate:fresh`, in a new environment or in tests. Existing tables are not
touched.

- **Integer types.** Laravel's schema builder gives each integer column its
  exact type: `tinyInteger()` is `Int8`, `smallInteger()` `Int16`,
  `integer()` `Int32` and `bigInteger()` `Int64`, and their unsigned forms are
  `UInt8` to `UInt64`. `id()`, `increments()` and `foreignId()` are unsigned.
  3.x wrote `Int16`, `Int32` and `Int64`, signed even for an unsigned column.
- **The schema builder writes what the blueprint declares**: `nullable()`,
  `default()`, `comment()`, `engine()`, `primary()` as the sorting key,
  `DateTime64` precisions and ClickHouse index types. `Schema::table()` runs
  added columns, `dropColumn()`, `index()`, `comment()`, `orderBy()`,
  `ttl()`, `settings()` and `Schema::rename()`, which 3.x ignored. These calls
  now fail a fresh run: a MySQL engine such as `engine('InnoDB')`, a MySQL
  index type such as `btree`, `set()`, `computed()`, and `primary()`,
  `dropPrimary()` or `renameIndex()` inside `Schema::table()`. `time()`,
  `fullText()` and `vectorIndex()` create a `Time` column and text and vector
  indexes on ClickHouse 26.3 and later, and fail on 24.8.
  To keep a table exactly as it is, write its `CREATE TABLE` with
  `statementOnEveryNode()`.
- **`Schema::hasTable()`** returns `false` for views and dictionaries. Guard
  them with `Schema::hasView()`, `Schema::hasDictionary()` or
  `CREATE ... IF NOT EXISTS`.
- **Cluster connections.** On a connection with a `cluster_name`, the schema
  builder sends its statements `ON CLUSTER`, and creates MergeTree tables with
  a replicated engine when the connection also lists its nodes in `cluster`.
  An `ON CLUSTER` statement waits for every host, so raise `timeout_query` for
  migration commands. Call `$table->withoutOnCluster()` to change a table of
  the active node only.
- **Nodes without a `cluster_name`.** On a connection that lists its nodes in
  `cluster` but has no `cluster_name`, `createMergeTree()` created a
  replicated table on every node. `Schema::create()` creates a table that is
  not replicated, on the active node only. Set `cluster_name`, or keep the
  `CREATE TABLE` with `statementOnEveryNode()`.

#### 10. Reads to check

- **Aggregates** are Laravel's: `sum()`, `min()`, `max()`, `avg()` and
  `aggregate()` replace the select list of the query. `count()` and the total
  of `paginate()` count the rows that `get()` returns, in a sub-query when the
  select list, `GROUP BY`, `distinct()`, `limitBy()` or the order changes them.
- **`value($column)`** on a query with a select list returns the first column
  of that list, as in Laravel.
- **A date with a sub-second part** is compared as a `DateTime64(6)` when the
  connection's `datetime_precision` is `microsecond`. It does not equal the
  value of a `DateTime` or `DateTime64(3)` column that has fewer digits. This
  only applies when you set the new `datetime_precision` option.

Then run `php artisan config:clear`, and `php artisan config:cache` after the
deploy if you cache the config.

## Upgrading from 2.x to 4.0

PHP (`^8.5`) and Laravel (`^13`) requirements are unchanged.

From 2.0.0, follow [Upgrading from 1.x to 4.0](#upgrading-from-1x-to-40): 2.0.0
still required `glushkovds/php-clickhouse-schema-builder` and
`myclabs/php-enum`, which 2.0.1 bundled. This section is for 2.0.1 and 2.0.2.

2.0.2 is the last release of `oralunal/phpclickhouse-laravel`, and the package
is abandoned. 3.0 renamed the package and its namespace, and nothing else:

| | 2.x | 3.0 and 4.0 |
| --- | --- | --- |
| Composer package | `oralunal/phpclickhouse-laravel` | `oralunal/laravel-clickhouse` |
| Namespace | `PhpClickHouseLaravel\` | `Oralunal\LaravelClickHouse\` |

`oralunal/laravel-clickhouse` conflicts with `oralunal/phpclickhouse-laravel`,
so Composer never installs both. After the rename, the changes of 4.0 apply, as
in [Upgrading from 3.x to 4.0](#upgrading-from-3x-to-40): most classes that
your 2.x code uses, such as `BaseModel`, `Migration` and the builder classes,
no longer exist in 4.0.

### Let your coding agent do it

1. Switch the package and install the skills:

   ```sh
   composer remove oralunal/phpclickhouse-laravel --no-update
   composer require oralunal/laravel-clickhouse:^4.0 --with-all-dependencies
   php artisan clickhouse:install-skills
   ```

2. In your agent, run:

   ```text
   /lc-upgrade-2x-to-4x
   ```

   If your agent does not offer skills as slash commands, ask it to
   "upgrade phpclickhouse-laravel 2.x to laravel-clickhouse 4.x".

The agent renames the namespace everywhere, refreshes Laravel's caches, then
does what `/lc-upgrade-3x-to-4x` does for the changes of 4.0, and reports how
the test suite compares with the run before the upgrade.

If `php artisan` fails after step 1 with a class-not-found error, your app
uses a class that 4.0 renamed or removed while it boots. Copy the skills by
hand instead of running `clickhouse:install-skills`:

```sh
mkdir -p .claude/skills
cp -r vendor/oralunal/laravel-clickhouse/resources/skills/lc-upgrade-2x-to-4x \
      vendor/oralunal/laravel-clickhouse/resources/skills/lc-upgrade-3x-to-4x .claude/skills/
```

### Or upgrade by hand

1. Switch the package:

   ```sh
   composer remove oralunal/phpclickhouse-laravel --no-update
   composer require oralunal/laravel-clickhouse:^4.0 --with-all-dependencies
   ```

   The `artisan` scripts that Composer runs may fail with a class-not-found
   error until step 4 is done.

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

4. Continue with [Upgrading from 3.x to 4.0](#upgrading-from-3x-to-40), from
   its step 2. After the rename, your code names the 3.x classes, such as
   `Oralunal\LaravelClickHouse\BaseModel`, and that section replaces them.

5. Values serialized with the 2.x class names cannot be unserialized by 4.0:
   cache entries, queued jobs and sessions that hold objects of the package's
   classes, such as `RawColumn` or the builder enums. If your app stores such
   values, let the queues drain before you deploy and clear the cache
   afterwards.

## Upgrading from 1.x to 4.0

PHP (`^8.5`) and Laravel (`^13`) requirements are unchanged.

You go straight to 4.0, without installing 2.x or 3.x. Three releases come
together:

- 2.0 bundled the libraries this package used to pull in: the query builder
  (`oralunal/clickhouse-builder`), the schema builder behind
  `Migration::createMergeTree()` (`glushkovds/php-clickhouse-schema-builder`)
  and the enum base class (`myclabs/php-enum`). Apart from Laravel, the only
  remaining dependency is `smi2/phpclickhouse`.
- 3.0 renamed the package to `oralunal/laravel-clickhouse` and its namespace
  from `PhpClickHouseLaravel\` to `Oralunal\LaravelClickHouse\`.
- 4.0 removed the bundled builders, the model and the migration class, as in
  [Upgrading from 3.x to 4.0](#upgrading-from-3x-to-40).

### Let your coding agent do it

1. Switch the packages and install the skills:

   ```sh
   composer remove oralunal/phpclickhouse-laravel oralunal/clickhouse-builder glushkovds/php-clickhouse-schema-builder --no-update
   composer require oralunal/laravel-clickhouse:^4.0 --with-all-dependencies
   php artisan clickhouse:install-skills
   ```

2. In your agent, run:

   ```text
   /lc-upgrade-1x-to-4x
   ```

   If your agent does not offer skills as slash commands, ask it to
   "upgrade phpclickhouse-laravel 1.x to laravel-clickhouse 4.x".

The agent renames the namespaces, stops to ask you before changing code whose
meaning it cannot decide alone, such as uses of the removed file and
temporary-table API, then does what `/lc-upgrade-3x-to-4x` does for the
changes of 4.0. At the end it reports what it changed and how the test suite
compares with the run before the upgrade.

If `php artisan` fails right after step 1 with a class-not-found error, such as
`Class "Tinderbox\ClickhouseBuilder\...\ClickhouseServiceProvider" not found`,
delete `bootstrap/cache/packages.php` and `bootstrap/cache/services.php` and
try again. If the app still does not boot, copy the skills by hand:

```sh
mkdir -p .claude/skills
cp -r vendor/oralunal/laravel-clickhouse/resources/skills/lc-upgrade-1x-to-4x \
      vendor/oralunal/laravel-clickhouse/resources/skills/lc-upgrade-3x-to-4x .claude/skills/
```

### Or upgrade by hand

To find what needs changing, run:

```sh
grep -rnE 'Tinderbox\\ClickhouseBuilder|Tinderbox\\Clickhouse\\|PhpClickHouseSchemaBuilder|PhpClickHouseLaravel|MyCLabs\\Enum|[^>:$a-zA-Z_]raw\(|array_flatten\(|[^a-zA-Z_]tp\(|into_memory_table|file_from|addFile\(|getFiles\(' \
  app config database routes tests
```

#### 1. Update the dependencies

```sh
composer remove oralunal/phpclickhouse-laravel oralunal/clickhouse-builder glushkovds/php-clickhouse-schema-builder --no-update
composer require oralunal/laravel-clickhouse:^4.0 --with-all-dependencies
```

`the-tinderbox/clickhouse-php-client` and `myclabs/php-enum` are no longer
installed. If your own code uses one of them, require it yourself.

If you registered the old
`Tinderbox\ClickhouseBuilder\Integrations\Laravel\ClickhouseServiceProvider`
in `bootstrap/providers.php` or `config/app.php`, remove it. The class no
longer exists, so the app fails to boot while it is listed. The package's own
provider is auto-discovered. If every artisan command fails with this class
name, delete `bootstrap/cache/packages.php` and `bootstrap/cache/services.php`;
Laravel regenerates them on the next `php artisan package:discover`.

#### 2. Rename the namespaces

Rename the prefixes to the 3.x names first. Step 6 then replaces the classes
that 4.0 removed.

| 1.x | 3.x name, then 4.0 |
| --- | --- |
| `PhpClickHouseLaravel\BaseModel`, `Migration`, `RawColumn`, … | `Oralunal\LaravelClickHouse\BaseModel`, … (step 6 replaces them) |
| `Tinderbox\ClickhouseBuilder\Query\…` | `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\…` (step 6 replaces them) |
| `PhpClickHouseSchemaBuilder\…` | `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\…` (step 6 replaces them) |
| `PhpClickHouseLaravel\Connection`, `ClickhouseServiceProvider`, … | `Oralunal\LaravelClickHouse\Connection`, `ClickhouseServiceProvider`, … |

```sh
grep -rlIE 'Tinderbox\\+ClickhouseBuilder|PhpClickHouseSchemaBuilder|PhpClickHouseLaravel' app config database routes tests \
  | xargs perl -pi -e '
      s/Tinderbox(\\+)ClickhouseBuilder(\\+)(?!Integrations)/Oralunal$1LaravelClickHouse$1ClickhouseBuilder$2/g;
      s/PhpClickHouseSchemaBuilder(\\+)/Oralunal$1LaravelClickHouse$1ClickhouseSchemaBuilder$1/g;
      s/PhpClickHouseLaravel(\\+)/Oralunal$1LaravelClickHouse$1/g;
    '
```

#### 3. Stop using the removed parts of the old builder

These parts only worked with `the-tinderbox/clickhouse-php-client`, so they
never did anything useful with this package:

| Removed | Use instead |
| --- | --- |
| `addFile()`, `getFiles()`, `values()`, `getValues()` | `Model::insert()`, `Model::buffer()`, or `insertFiles()` of the query builder |
| `into_memory_table()`, `file_from()` | Same as above |
| `Tinderbox\ClickhouseBuilder\Query\Builder` | `Model::query()`, or `DB::connection('clickhouse')->table(...)` |
| `Tinderbox\ClickhouseBuilder\Integrations\Laravel\Connection` / `Builder` | `Oralunal\LaravelClickHouse\Connection` / `QueryBuilder` |

#### 4. `whereIn()` with a string value

In 1.x, `whereIn()` turned a string into a table reference when it matched a
file added with `addFile()`, and quoted any other string as one value. In 4.0,
`whereIn()` takes a list or a sub-query, and a string fails with a `TypeError`.
Give one value as a list, `whereIn('id', [$value])`, and compare against a
table explicitly:

```php
$query->whereIn('id', fn ($q) => $q->select('id')->from('allowed')); // where "id" in (select "id" from "allowed")
$query->whereRaw('id in allowed_ids');                               // where id in allowed_ids
```

#### 5. `migrate:fresh` also empties secondary ClickHouse connections

This step only applies when MySQL or PostgreSQL holds the `migrations` table
and some migrations write to ClickHouse. In 1.x, `migrate:fresh` emptied only
the MySQL or PostgreSQL database and left ClickHouse as it was.

Since 2.0, `migrate:fresh` also empties these ClickHouse connections:

- every ClickHouse connection that a migration targets;
- every ClickHouse connection that has a dump in `database/schema`.

ClickHouse connections that no migration or dump refers to are left alone. The
command still asks for confirmation in production.

`RefreshDatabase` and the other database test traits run `migrate:fresh` too.
If you run your tests with `--parallel`, each test process gets its own
database on these ClickHouse connections, `<database>_test_<token>` (for
example `analytics_test_1`). The ClickHouse user needs permission to create
and drop databases. See
[The connection that holds the migrations table](https://laravel-clickhouse.oralunal.com/schema/migration-commands#the-connection-that-holds-the-migrations-table).

#### 6. The changes of 4.0

Continue with [Upgrading from 3.x to 4.0](#upgrading-from-3x-to-40), from its
step 2. It replaces `BaseModel`, the builder classes, `Migration`, the schema
builder classes, the enums, and the helper functions `raw()`, `tp()` and
`array_flatten()`, which 1.x had as global functions.

#### 7. Serialized values and caches

Values serialized with the 1.x class names cannot be unserialized by 4.0:
cache entries, queued jobs and sessions that hold objects of the package's
classes, such as `RawColumn`, `Expression` or the builder enums. If your app
stores such values, let the queues drain before you deploy and clear the cache
afterwards. Run `php artisan package:discover` and `php artisan optimize:clear`.
