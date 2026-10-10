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

4.0 makes the package write what Laravel's APIs declare and refuse what
ClickHouse would get wrong. Most of it fixes what 3.x did wrong, but it has a
few breaking changes, and some behavior changes alter which rows a write
touches, which values it stores, or which tables a migration creates. The
`[4.0.0]` section of the [CHANGELOG](CHANGELOG.md) lists every change with the
SQL before and after; this guide covers what you may have to change or check.

### Let your coding agent do it

1. Update the package and install the skills:

   ```sh
   composer require oralunal/laravel-clickhouse:^4.0 --with-all-dependencies
   php artisan clickhouse:install-skills
   ```

2. In your agent, run:

   ```text
   /lc-upgrade-3x-to-4x
   ```

   If your agent does not offer skills as slash commands, ask it to
   "upgrade laravel-clickhouse to 4.x".

The agent fixes the code that 4.0 breaks, reviews with you the writes and the
migrations whose behavior changes, checks the result, and reports how the test
suite compares with the run before the upgrade. It never runs
`migrate:fresh` or changes the data of a real database, and it asks before it
changes a write or a migration that already ran.

### Or upgrade by hand

#### 1. Update the dependency

```sh
composer require oralunal/laravel-clickhouse:^4.0 --with-all-dependencies
```

#### 2. Subclasses of the package's classes

A subclass whose override no longer matches its parent fails to load with
`Declaration of ... must be compatible with ...`. These signatures changed:

| Class | 3.x | 4.0 |
| --- | --- | --- |
| `Builder` | `insert(array $values)`, returned nothing | `insert(array $values, ?string $format = null): Statement` |
| `Builder` | `delete(): Statement` | `delete(?bool $lightweight = null, int\|string\|Expression\|ExpressionContract\|null $partition = null): Statement` |
| `Builder` | `update(array $values): Statement` | `update(array $values, int\|string\|Expression\|ExpressionContract\|null $partition = null): Statement` |
| `Builder` | `settings(array $settings): self` | `settings(array\|string $settings, mixed $value = null): self` |
| `BaseBuilder` | `sample(float $coefficient)` | `sample(float $coefficient, ?float $offset = null)` |
| `BaseModel` | `optimize(bool $final = false, ?string $partition = null): Statement` | `optimize(bool $final = false, int\|string\|Expression\|ExpressionContract\|null $partition = null): Statement` |
| `SchemaBuilder` | `build(Blueprint $blueprint)` | `build(Blueprint $blueprint): void` |

`Expression` is `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression`
and `ExpressionContract` is `Illuminate\Contracts\Database\Query\Expression`.
4.0 also adds public and protected members to the package's classes; a
subclass member of the same name must be compatible with it. The CHANGELOG
entry "Breaking for code that extends the package" lists them.

A `BaseBuilder` subclass that overrides `where()` to see every condition must
override the protected `addCondition()` instead: `orWhere()`, `orPreWhere()`,
`orHaving()`, `whereDict()` and `orWhereDict()` no longer call `where()`.

#### 3. Removed `BaseModel` methods

`BaseModel` no longer uses Eloquent's `HasAttributes` trait, which made
Larastan report `class.missingExtends` on every model. It uses the package's
own `Oralunal\LaravelClickHouse\Concerns\HasAttributes`, which keeps the
attribute API: `$model->column`, `fill()`, `getAttributes()`, `$casts`,
accessors, mutators and `toArray()`.

The stubs that 3.x had only for Eloquent's trait are gone:
`usesTimestamps()`, `getRelationValue()`, `relationResolver()`,
`relationLoaded()`, `isRelation()` and `relationsToArray()`, and Eloquent's
protected methods such as `initializeHasAttributes()`, `casts()`,
`getAttributesForInsert()` and `getDirtyForUpdate()`. A call to one of them
fails with `Call to undefined method`, and the package no longer calls an
override of one of them:

- A model whose `usesTimestamps()` returned `true` had the columns of its
  `getCreatedAtColumn()` and `getUpdatedAtColumn()` as date columns. Cast them
  instead: `protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime'];`.
- A model whose `getRelationValue()`, `relationLoaded()` or
  `relationResolver()` computed a value for a key: use an accessor.

#### 4. SQL in the aggregates

`min()`, `max()`, `sum()`, `avg()`, `average()` and `aggregate()` on the
package's query builder now quote a string as a column name, as `select()`
does. In 3.x, a column taken from user input, as in
`->max($request->metric)`, could add SQL to the query. Plain and dotted names
work as before. Pass SQL with `raw()`:

```php
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;

MyTable::where('active', 1)->sum('price * qty');      // 3.x: sum(price * qty); 4.0: fails with UNKNOWN_IDENTIFIER
MyTable::where('active', 1)->sum(raw('price * qty')); // sum(price * qty)
```

The same goes for `aggregate('count', ['DISTINCT user_id'])` and the
condition of `aggregate('sumIf', ['a', 'a > 1'])`. Laravel's query builder
(`fix_default_query_builder` set to `false`) already quoted these names.

#### 5. Other code that fails at run time

- **`FORMAT` in `select()`.** `select()`, `selectOne()`, `scalar()`,
  `cursor()` and the parallel queries refuse SQL whose `FORMAT` clause names a
  format other than `JSON`, `JSONStrings`, `JSONCompact` and
  `JSONCompactStrings` (`cursor()` refuses every `FORMAT` clause). In 3.x such
  queries failed or returned no rows. Leave the clause out, or read the result
  with `getClient()->select($sql)->rawData()` or the query builder's
  `format('CSV')->get()->rawData()`, as the exception message says.
- **Placeholders.** A hand-written `#@?` marker is no longer turned into a
  binding; write `?`. A query that mixes `?` with smi2's `:0` or `{0}`
  placeholders or with `{name:Type}` query parameters throws before it is
  sent; write the ternary operator as `??`.
- **Quoted table names.** ``table('`my-table`')`` is quoted again and fails;
  pass the name without backticks.
- **`settings()`.** Calls add up instead of replacing each other,
  `settings([])` changes nothing (pass `null` for a name to remove it), and an
  `Expression` value is written without quotes: pass `'auto'`, not
  `new Expression('auto')`.
- **Transactions.** `beginTransaction()`, `transaction()` and `commit()` throw
  a `LogicException`; in 3.x they failed with a PHP error or did nothing. Use
  the `DatabaseTruncation` test trait for ClickHouse connections.
- **The migration repository.** `app('migration.repository')` is a
  `ClickhouseMigrationRepository`, a subclass of Laravel's
  `DatabaseMigrationRepository`, so `instanceof` still works but a comparison
  of the exact class does not. With `fix_default_query_builder` set to
  `false`, its rows are objects instead of arrays.
- **Configuration.** The connection checks `datetime_precision`,
  `insert_format`, `use_on_cluster` and `default_index_type` when they are
  set, and applies a `timeout_query` or `timeout_connect` with its fraction,
  where 3.x cut it to an integer, so `0.5` meant no query timeout.

#### 6. Writes to check

These behavior changes alter what a write does. Check the code that they reach:

- **`whereNotIn()` with an empty list** now matches every row, as in
  Laravel, so `->whereNotIn('id', $ids)->delete()` with an empty `$ids`
  deletes **every row**. In 3.x it sent an invalid statement. Return early
  when the list is empty.
- **`delete()` and `update()`** throw, and send nothing, for a query with a
  clause that a ClickHouse mutation would ignore, such as `JOIN`, `LIMIT`,
  `GROUP BY` or an `ORDER BY` alias. In 3.x these changed rows that the query
  did not select. Select the keys first: `whereIn('id', $query->pluck('id'))`.
  A condition with a sub-query that ClickHouse cannot run, and a lightweight
  delete whose condition holds `UNION`, `INTERSECT` or `EXCEPT`, are refused
  too, because ClickHouse would leave a mutation that blocks every later one.
- **Floats** are written with every digit, where 3.x wrote 14 significant
  digits. A float computed in PHP is cut off at the scale of a `Decimal` or
  integer column: `4.35 * 100` stores `434.99` in a `Decimal(10, 2)` column,
  where 3.x stored `435.00`. Round such a value, as in
  `round(4.35 * 100, 2)`, or pass a numeric string or an int; `update()` needs
  a numeric string or an int.
- **`insertAssoc()` and `buffer()`** refuse a row whose keys differ from the
  first row's. 3.x filled a missing key with a made-up value. Give every row
  every key.
- With `fix_default_query_builder` set to `false`: `whereLike()` sends
  `ilike`, `insert()` writes a collection as an array, `timeout()` stops the
  query on the server, and `affectingStatement()` returns the rows written.

#### 7. Migrations to check

These changes only matter for migrations that run after the upgrade, as with
`migrate:fresh`, in a new environment or in tests. Existing tables are not
touched.

- **`Schema::hasTable()`** returns `false` for views, materialized views and
  dictionaries. Guard them with `Schema::hasView()`,
  `Schema::hasDictionary()` or `CREATE ... IF NOT EXISTS`.
- **Laravel's schema builder** writes what the blueprint declares:
  `nullable()`, `default()`, `comment()`, `engine()`, `primary()` as the
  sorting key, `DateTime64` precisions and ClickHouse index types. 3.x wrote
  names and types only, always `MergeTree()`, sorted by the first column.
  `Schema::table()` now sends the statements of added columns, `dropColumn()`,
  `index()`, `comment()`, `orderBy()`, `ttl()`, `settings()` and
  `Schema::rename()`, which 3.x ignored. A fresh run can therefore create
  tables that differ from the ones 3.x created, and these calls now fail it:
  a MySQL engine such as `engine('InnoDB')`, a MySQL index type such as
  `btree`, `time()`, `set()`, `computed()`, `fullText()`, and `primary()`,
  `dropPrimary()` or `renameIndex()` inside `Schema::table()`. To keep a
  table exactly as 3.x created it, write its `CREATE TABLE` with
  `Migration::write()`.
- **`Migration::createMergeTree()`** escapes comments, string defaults, enum
  values and setting strings itself: remove escaping that you added by hand,
  such as `->comment("it\\'s")` or `'storage_policy' => "'hot_cold'"`.
- **Cluster connections.** On a connection with a `cluster_name`, Laravel's
  schema builder sends its statements `ON CLUSTER`, and creates MergeTree
  tables, the `migrations` table included, with a replicated engine when the
  connection also lists its nodes. An `ON CLUSTER` statement waits for every
  host, so raise `timeout_query` for migration commands. A table that 3.x
  created on the active node only can be changed there with
  `$table->withoutOnCluster()`.

Then run `php artisan config:clear`, and `php artisan config:cache` after the
deploy if you cache the config.

## Upgrading from 2.x to 4.0

PHP (`^8.5`) and Laravel (`^13`) requirements are unchanged.

2.0.2 is the last release of `oralunal/phpclickhouse-laravel`, and the package
is abandoned. 3.0 renamed the package and its namespace, and nothing else:

| | 2.x | 3.0 and 4.0 |
| --- | --- | --- |
| Composer package | `oralunal/phpclickhouse-laravel` | `oralunal/laravel-clickhouse` |
| Namespace | `PhpClickHouseLaravel\` | `Oralunal\LaravelClickHouse\` |

Class names below the namespace and the folder layout are the same, for example
`PhpClickHouseLaravel\ClickhouseSchemaBuilder\Tables\MergeTree` becomes
`Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Tables\MergeTree`.
`oralunal/laravel-clickhouse` conflicts with `oralunal/phpclickhouse-laravel`,
so Composer never installs both. After the rename, the changes of 4.0 apply,
as in [Upgrading from 3.x to 4.0](#upgrading-from-3x-to-40).

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

If `php artisan` fails after step 1 with a class-not-found error for a
`PhpClickHouseLaravel\…` class, your app uses the 2.x namespace while it boots.
Copy the skills by hand instead of running `clickhouse:install-skills`, for
example:

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

4. Values serialized with the 2.x class names cannot be unserialized by 4.0:
   cache entries, queued jobs and sessions that hold objects of the package's
   classes, such as `RawColumn` or the builder enums. If your app stores such
   values, let the queues drain before you deploy and clear the cache
   afterwards.

5. Continue with [Upgrading from 3.x to 4.0](#upgrading-from-3x-to-40), from
   its step 2.

## Upgrading from 1.x to 4.0

PHP (`^8.5`) and Laravel (`^13`) requirements are unchanged.

You go straight to 4.0, without installing 2.x or 3.x. Three releases come
together:

- 2.0 bundled the libraries this package used to pull in: the query builder
  (`oralunal/clickhouse-builder`), the schema builder behind
  `Migration::createMergeTree()` (`glushkovds/php-clickhouse-schema-builder`)
  and the enum base class (`myclabs/php-enum`). They generate the same SQL as
  before, but their classes are in this package's namespaces. Apart from
  Laravel, the only remaining dependency is `smi2/phpclickhouse`.
- 3.0 renamed the package to `oralunal/laravel-clickhouse` and its namespace
  from `PhpClickHouseLaravel\` to `Oralunal\LaravelClickHouse\`.
- 4.0 brings the changes of [Upgrading from 3.x to 4.0](#upgrading-from-3x-to-40).

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

The agent renames the namespaces, imports the helper functions, stops to ask
you before changing code whose meaning it cannot decide alone, such as uses of
the removed file/temporary-table API, then does what `/lc-upgrade-3x-to-4x`
does for the changes of 4.0. At the end it reports what it changed and how the
test suite compares with the run before the upgrade.

If `php artisan` fails right after step 1 with a class-not-found error, such as
`Class "Tinderbox\ClickhouseBuilder\...\ClickhouseServiceProvider" not found`,
delete `bootstrap/cache/packages.php` and `bootstrap/cache/services.php` and
try again. If the app still does not boot, copy the skills by hand, for
example:

```sh
mkdir -p .claude/skills
cp -r vendor/oralunal/laravel-clickhouse/resources/skills/lc-upgrade-1x-to-4x \
      vendor/oralunal/laravel-clickhouse/resources/skills/lc-upgrade-3x-to-4x .claude/skills/
```

### Or upgrade by hand

To find what needs changing, run:

```sh
grep -rnE 'Tinderbox\\ClickhouseBuilder|PhpClickHouseSchemaBuilder|PhpClickHouseLaravel|MyCLabs\\Enum|[^>:$a-zA-Z_]raw\(|array_flatten\(|[^a-zA-Z_]tp\(|into_memory_table|file_from|addFile\(|getFiles\(' \
  app config database routes tests
```

### 1. Update the dependencies

```sh
composer remove oralunal/phpclickhouse-laravel oralunal/clickhouse-builder glushkovds/php-clickhouse-schema-builder --no-update
composer require oralunal/laravel-clickhouse:^4.0 --with-all-dependencies
```

`the-tinderbox/clickhouse-php-client` and `myclabs/php-enum` are no longer
installed. If your own code uses one of them, require it yourself.

### 2. Rename the namespaces

Only the namespace prefixes change; class names and the folder layout are the
same.

| 1.x | 4.0 |
| --- | --- |
| `PhpClickHouseLaravel\BaseModel`, `Migration`, `RawColumn`, … | `Oralunal\LaravelClickHouse\BaseModel`, `Migration`, `RawColumn`, … |
| `Tinderbox\ClickhouseBuilder\Query\Expression` | `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression` |
| `Tinderbox\ClickhouseBuilder\Query\Enums\Operator` | `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Operator` |
| `Tinderbox\ClickhouseBuilder\Query\TwoElementsLogicExpression` | `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\TwoElementsLogicExpression` |
| `Tinderbox\ClickhouseBuilder\Query\BaseBuilder` | `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\BaseBuilder` |
| `Tinderbox\ClickhouseBuilder\Query\Grammar` | `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Grammar` |
| `Tinderbox\ClickhouseBuilder\Exceptions\*` | `Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions\*` |
| `PhpClickHouseSchemaBuilder\Tables\MergeTree` | `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Tables\MergeTree` |
| `PhpClickHouseSchemaBuilder\Expression` | `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Expression` |
| `PhpClickHouseSchemaBuilder\Engine`, `Column`, `TTL`, `Exceptions\*` | `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Engine`, `Column`, `TTL`, `Exceptions\*` |

Your migration files count too. A migration that still imports
`PhpClickHouseSchemaBuilder\Tables\MergeTree` fails with `Class not found`
the next time it runs, for example on a fresh install or in CI.

To replace them across your app and keep the escaping of strings such as
`'PhpClickHouseLaravel\\BaseModel'` (the classes of
`Tinderbox\ClickhouseBuilder\Integrations\` are left for step 4):

```sh
grep -rlIE 'Tinderbox\\+ClickhouseBuilder|PhpClickHouseSchemaBuilder|PhpClickHouseLaravel' app config database routes tests \
  | xargs perl -pi -e '
      s/Tinderbox(\\+)ClickhouseBuilder(\\+)(?!Integrations)/Oralunal$1LaravelClickHouse$1ClickhouseBuilder$2/g;
      s/PhpClickHouseSchemaBuilder(\\+)/Oralunal$1LaravelClickHouse$1ClickhouseSchemaBuilder$1/g;
      s/PhpClickHouseLaravel(\\+)/Oralunal$1LaravelClickHouse$1/g;
    '
```

### 3. Import the helper functions

`raw()`, `tp()` and `array_flatten()` used to be global functions. They are
now in the `Oralunal\LaravelClickHouse\ClickhouseBuilder` namespace. If you
call them without importing them, PHP fails with
`Call to undefined function raw()`.

```php
// 1.x
$query->select(raw('count() AS n'));

// 4.0
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;

$query->select(raw('count() AS n'));
```

You can also use the classes directly:

```php
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\RawColumn;

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
| `Tinderbox\ClickhouseBuilder\Integrations\Laravel\Connection` / `Builder` | `Oralunal\LaravelClickHouse\Connection` and `Oralunal\LaravelClickHouse\Builder` |
| `Tinderbox\ClickhouseBuilder\Integrations\Laravel\ClickhouseServiceProvider` | `Oralunal\LaravelClickHouse\ClickhouseServiceProvider` (auto-discovered) |

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
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;

$query->whereIn('id', raw('allowed_ids'));                           // WHERE `id` IN allowed_ids
$query->whereIn('id', fn ($q) => $q->select('id')->from('allowed')); // WHERE `id` IN (SELECT `id` FROM `allowed`)
```

### 6. `Column::subQuery()` return type

`Column::subQuery()` and `Column::getSubQuery()` now declare
`Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\BaseBuilder` as their
return type. In 1.x they declared the Tinderbox-client `Query\Builder`, which
made column sub-queries built from this package's `Builder` throw a
`TypeError`. Only code that type-checks the return value against the old class
needs a change.

### 7. `migrate:fresh` also empties secondary ClickHouse connections

This step only applies when MySQL or PostgreSQL holds the `migrations` table
and some migrations write to ClickHouse. In 1.x, `migrate:fresh` emptied only
the MySQL or PostgreSQL database and left ClickHouse as it was. Migrations
written with `CREATE TABLE IF NOT EXISTS` therefore kept their ClickHouse data
across `migrate:fresh`.

Since 2.0, `migrate:fresh` also empties these ClickHouse connections:

- every ClickHouse connection that a migration targets;
- every ClickHouse connection that has a dump in `database/schema`.

ClickHouse connections that no migration or dump refers to are left alone. The
command still asks for confirmation in production.

`RefreshDatabase` and the other database test traits run `migrate:fresh` too.
If you run your tests with `--parallel`, each test process gets its own
database on these ClickHouse connections, `<database>_test_<token>` (for
example `analytics_test_1`), just as Laravel does for the default connection.
The ClickHouse user needs permission to create and drop databases. Test runs
without `--parallel` keep using the configured database.

See
[The connection that holds the migrations table](https://laravel-clickhouse.oralunal.com/schema/migration-commands#the-connection-that-holds-the-migrations-table).

### 8. Enums extend the bundled `Enum` class

`Operator`, `Format`, `JoinType`, `JoinStrict` and `OrderDirection` now extend
`Oralunal\LaravelClickHouse\Enum\Enum` instead of `MyCLabs\Enum\Enum`. Their
constants and methods (`isValid()`, `getValue()`, `Operator::EQUALS()` and so
on) are unchanged. Only code that type-checks against `MyCLabs\Enum\Enum` needs
to change.

### 9. Serialized values and caches

Values serialized with the 1.x class names cannot be unserialized by 4.0:
cache entries, queued jobs and sessions that hold objects of the package's
classes, such as `RawColumn`, `Expression` or the builder enums. If your app
stores such values, let the queues drain before you deploy and clear the cache
afterwards. Run `php artisan package:discover` and `php artisan optimize:clear`.

### 10. The changes of 4.0

Continue with [Upgrading from 3.x to 4.0](#upgrading-from-3x-to-40), from its
step 2.
