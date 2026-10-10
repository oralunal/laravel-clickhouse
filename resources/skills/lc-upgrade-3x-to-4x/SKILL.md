---
name: lc-upgrade-3x-to-4x
description: Upgrades a Laravel application from oralunal/laravel-clickhouse 3.x to 4.x. Updates the dependency, finds and fixes the code that 4.0's breaking changes affect (SQL passed to the aggregates as a string, subclasses whose overrides no longer fit, removed BaseModel methods, hand-written #@? markers, FORMAT clauses in select()), reviews with the user the behavior changes that touch writes, migrations and cluster setups, and verifies the result. Use when the user runs /lc-upgrade-3x-to-4x or asks to upgrade laravel-clickhouse to version 4.
---

# laravel-clickhouse 3.x → 4.x upgrade

You are upgrading this Laravel application from `oralunal/laravel-clickhouse`
3.x to 4.x. The package name, the `Oralunal\LaravelClickHouse` namespace and
the PHP (`^8.5`) and Laravel (`^13`) requirements stay the same. What changes:

- a few **breaking changes** that make code fail to load or fail at run time,
  which this skill finds and fixes;
- many **behavior changes**. Most only fix what 3.x did wrong, but some change
  which rows a write touches, which values it stores, or which tables a
  migration creates. This skill finds the code that they reach and reviews it
  with the user.

Work through the steps in order. Do not skip the checks. Once 4.x is
installed, the complete list of changes is the `[4.0.0]` section of
`vendor/oralunal/laravel-clickhouse/CHANGELOG.md` (the `[Unreleased]` section
before the release), and the human-readable guide is
`vendor/oralunal/laravel-clickhouse/UPGRADE.md`. Read the matching entry
whenever a step below is not enough to decide a case.

## Rules

- Only change what the upgrade requires. Do not reformat files or refactor
  unrelated code.
- Never edit anything under `vendor/`.
- Search the whole project, not just `app/`. Include `config/`, `database/`
  (migrations, seeders, factories), `routes/`, `tests/`, `bootstrap/` and any
  other directory that holds PHP code, such as `src/`, `modules/` or
  `packages/`. Exclude `vendor/`, `node_modules/`, `storage/`,
  `bootstrap/cache/` and `.git/`.
- Never run `migrate:fresh`, `db:wipe`, `migrate:rollback` or any statement
  that changes data or tables of a real ClickHouse database. Reading is fine.
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
   (and of any config file merged into it) whose `driver` is `clickhouse`, and
   `DB_CONNECTION` in `.env`. For each one, note:
   - `fix_default_query_builder`: `true`, the default of the packaged config,
     means `DB::connection(...)->table()` returns the package's query builder;
     `false` means it returns Laravel's query builder;
   - `cluster_name` and `cluster` (the list of nodes);
   - `timeout_query` and `timeout_connect`;
   - `settings`, `engine`, `use_lightweight_delete`, and whether the
     connection is the default one.
5. Find the code that uses these connections: classes that extend
   `Oralunal\LaravelClickHouse\BaseModel`, `DB::connection('<name>')`,
   `Schema::connection('<name>')`, migrations whose `$connection` is a
   ClickHouse connection (or every migration when ClickHouse is the default
   connection), classes that extend `Oralunal\LaravelClickHouse\Migration`,
   and `new \Oralunal\LaravelClickHouse\Builder(...)`. The searches of the
   next steps only matter where they hit this code.

## Step 2: Update the dependency

```bash
composer require oralunal/laravel-clickhouse:^4.0 --with-all-dependencies
```

If Composer refuses because another package of the project or a third-party
package requires `oralunal/laravel-clickhouse:^3.0`, update the project's own
packages too. If it is a third-party package, stop and ask the user.

## Step 3: Fix what no longer loads

Subclasses of the package's classes fail to load when an override no longer
matches the parent method (`Declaration of ... must be compatible with ...`).

1. Find every class that extends a class of the package: search for
   `extends` in files that import from `Oralunal\LaravelClickHouse\`, and for
   `extends\s+\\?Oralunal\\LaravelClickHouse\\`.
2. In each, compare the overridden methods with the parent class in
   `vendor/oralunal/laravel-clickhouse/src/` and fix the signatures. These
   changed:

   | Class | 3.x | 4.x |
   | --- | --- | --- |
   | `Builder` | `insert(array $values)`, returned nothing | `insert(array $values, ?string $format = null): Statement`; return the parent's statement |
   | `Builder` | `delete(): Statement` | `delete(?bool $lightweight = null, int\|string\|Expression\|ExpressionContract\|null $partition = null): Statement` |
   | `Builder` | `update(array $values): Statement` | `update(array $values, int\|string\|Expression\|ExpressionContract\|null $partition = null): Statement` |
   | `Builder` | `settings(array $settings): self` | `settings(array\|string $settings, mixed $value = null): self` |
   | `BaseBuilder` | `sample(float $coefficient)` | `sample(float $coefficient, ?float $offset = null)` |
   | `BaseModel` | `optimize(bool $final = false, ?string $partition = null): Statement` | `optimize(bool $final = false, int\|string\|Expression\|ExpressionContract\|null $partition = null): Statement` |
   | `SchemaBuilder` | `build(Blueprint $blueprint)` | `build(Blueprint $blueprint): void` |

   Here `Expression` is `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression`
   and `ExpressionContract` is `Illuminate\Contracts\Database\Query\Expression`.

3. 4.0 adds public and protected members to `BaseBuilder`, `Builder`,
   `Connection`, `QueryGrammar`, `SchemaBuilder`, `SchemaGrammar`,
   `SchemaState`, `Cluster` and `BaseModel` (the CHANGELOG entry "Breaking for
   code that extends the package" lists them). A subclass member of the same
   name must now be compatible. Load every class of the project to find these
   errors:

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

   A fatal error names the class and the method. Rename a project method that
   only shares its name with a new package method by accident, and ask the
   user before you rename a public one that other code calls. Run
   `composer dump-autoload` without `--optimize` afterwards if the project
   does not use an optimized autoloader.

4. Overrides that still load but no longer do what they did:
   - A `BaseBuilder`/`Builder` subclass that overrides `where()` to see every
     condition: `orWhere()`, `orPreWhere()`, `orHaving()`, `whereDict()` and
     `orWhereDict()` no longer call `where()`; every condition goes through
     the protected `addCondition()`. Ask the user, and move the logic to an
     override of `addCondition()`.
   - A subclass that overrides `onceWithColumns()` no longer changes
     `pluck()`.
   - A `QueryGrammar` subclass that overrides `parameter()`: 4.0 no longer
     calls it for bindings; the connection writes `?` bindings into the SQL.
     Ask the user what the override was for.

## Step 4: Fix what fails at run time

1. **Removed `BaseModel` methods.** Search model classes that extend
   `BaseModel` for `usesTimestamps`, `getRelationValue`, `relationResolver`,
   `relationLoaded`, `isRelation`, `relationsToArray`,
   `initializeHasAttributes`, `getRelationshipFromMethod`,
   `getAttributesForInsert` and `getDirtyForUpdate`. They came from
   Eloquent's `HasAttributes` trait, which `BaseModel` no longer uses
   (Larastan reported `class.missingExtends` on every model because of it).
   - A call, as in `$model->usesTimestamps()` or `parent::relationLoaded($key)`,
     now fails with `Call to undefined method`. Remove it, or replace it with
     what it meant: in 3.x, `usesTimestamps()` returned `false`,
     `getRelationValue()` and `relationResolver()` `null`, and
     `relationLoaded()` `false`.
   - A model that declares one of them still loads, but the package no longer
     calls it. A model whose `usesTimestamps()` returns `true` had the columns
     named by its `getCreatedAtColumn()` and `getUpdatedAtColumn()` as date
     columns: add them to `$casts`, as in `'created_at' => 'datetime'`, and
     remove the three methods. A model that overrides `getRelationValue()`,
     `relationLoaded()` or `relationResolver()` to compute a value for a key:
     ask the user, and replace it with an accessor.
   - `class_uses()` or `class_uses_recursive()` of a model lists
     `Oralunal\LaravelClickHouse\Concerns\HasAttributes` instead of
     `Illuminate\Database\Eloquent\Concerns\HasAttributes`. Update code that
     checks for Eloquent's trait.
2. **SQL in the aggregates.** On the package's query builder (model queries
   such as `MyModel::where(...)`, `MyModel::query()`, `new Builder(...)`, and
   `DB::connection('<name>')->table(...)` on a connection with
   `fix_default_query_builder` on), `min()`, `max()`, `sum()`, `avg()`,
   `average()` and `aggregate()` now quote a string as a column name. Search
   for `->(min|max|sum|avg|average)\(` and `->aggregate\(` in that code.
   - A plain or dotted column name, such as `'amount'` or `'orders.amount'`,
     needs no change.
   - SQL in a string, such as `sum('price * qty')`, `max('toDate(created_at)')`,
     `aggregate('count', ['DISTINCT user_id'])`, the condition of
     `aggregate('sumIf', ['a', 'a > 1'])`, a name between backticks or an
     integer column: wrap it in `raw()`, as in `sum(raw('price * qty'))`, with
     `use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;`
     (or `DB::raw()`). `'*'` is written as it is.
   - A function name of `aggregate()` with parameters that are not numbers,
     such as `sequenceMatch('(?1)(?2)')`, now throws: select it with a
     `RawColumn` and read it with `value()`, as the CHANGELOG shows.
   - A variable, as in `->max($request->metric)`: 3.x pasted it into the SQL
     (an injection hole), 4.x quotes it as a name. If the variable can hold
     SQL on purpose, ask the user; never wrap user input in `raw()`.
   - Laravel's query builder (`fix_default_query_builder` off) already quoted
     these names; nothing changes there.
3. **`FORMAT` in `select()`.** `select()`, `selectOne()`, `scalar()`,
   `cursor()`, `selectParallelly()` and `Parallel` refuse SQL whose `FORMAT`
   clause names a format other than `JSON`, `JSONStrings`, `JSONCompact` and
   `JSONCompactStrings` (`cursor()` refuses every `FORMAT` clause). Search for
   `FORMAT\s+[A-Za-z]` in SQL given to them. Remove the clause, or read the
   result with the method that the exception message names, such as
   `getClient()->select($sql)->rawData()` for `CSV` or `TSV`, or the query
   builder's `format('XML')->get()->rawData()`. Ask the user which one the
   caller needs.
4. **Placeholders.** Search for `#@\?`: a hand-written `#@?` marker is no
   longer turned into a binding; write `?`. Search raw SQL given to the
   connection for smi2 placeholders (`:0`, `{0}`) or query parameters
   (`{name:Type}`) in the same query as a `?`: such a query now throws before
   it is sent. Write the ternary operator as `??`, or use `?` for every
   binding.
5. **Quoted table names.** Search for `` ->(table|from)\(\s*['"]` ``: a table
   name that already has backticks is quoted again and fails. Pass it without
   them.
6. **`settings()`.** Search for `->settings(`. Calls now add up instead of
   replacing each other, `settings([])` changes nothing (pass `null` for a
   name to remove it), and an `Expression` or `raw()` value is written
   without quotes. Fix a chain that relied on the last call replacing the
   others, and a value such as `new Expression('auto')` (pass `'auto'`).
7. **Transactions.** `beginTransaction()`, `transaction()` and `commit()` on
   a ClickHouse connection throw a `LogicException`. Search for them on
   ClickHouse connections, and for test classes whose
   `$connectionsToTransact` lists a ClickHouse connection or that use
   `RefreshDatabase`, `LazilyRefreshDatabase` or `DatabaseTransactions` while
   ClickHouse is the default connection: in 3.x these failed too, with a PHP
   error. Use the `DatabaseTruncation` trait for ClickHouse connections, and
   ask the user before you change application code that called
   `transaction()`.
8. **The migration repository.** Search for `migration.repository` and
   `DatabaseMigrationRepository`. The package binds
   `Oralunal\LaravelClickHouse\ClickhouseMigrationRepository`, a subclass of
   Laravel's, so `instanceof` still works, but a comparison of the exact class
   does not. With `fix_default_query_builder` off, its `getLast()`,
   `getMigrations()` and `getMigrationsByBatch()` return objects: code that
   reads `$row['migration']` must read `$row->migration`.
9. **Configuration.** If a ClickHouse connection sets `datetime_precision`,
   `insert_format`, `use_on_cluster` or `default_index_type`, the connection
   now checks the value when it is created (see the CHANGELOG for the values
   it takes). A `timeout_query` or `timeout_connect` with a fraction is now
   applied with its fraction (`timeout_query` rounded up to whole seconds),
   where 3.x cut it to an integer, so `0.5` meant no query timeout: tell the
   user if one has a fraction.

## Step 5: Review the writes with the user

These changes make a write touch other rows or store other values than in 3.x.
For each match, read the code, decide whether it is affected, and tell the
user. **Ask before you change any of them.**

1. **`whereNotIn()` with an empty list.** It now adds `1 = 1`, as in Laravel,
   so `->whereNotIn('id', $ids)->delete()` (or `update()`) with an empty
   `$ids` changes **every row**, where 3.x sent an invalid statement. Search
   for `whereNotIn` and `orWhereNotIn` in queries that reach `delete()` or
   `update()`. Where the list can be empty, propose a guard, such as returning
   early when `$ids === []`.
2. **Mutations with clauses that ClickHouse ignores.** `delete()` and
   `update()` now throw, and send nothing, for a query with `JOIN`,
   `GROUP BY`, `HAVING`, `LIMIT`, `LIMIT ... BY`, a set operation,
   `SETTINGS`, `FINAL`, `SAMPLE`, `ARRAY JOIN`, `WITH`, a sub-query or table
   function in `from()`, a select list with anything but plain column names,
   or an `ORDER BY` that defines an alias, aggregates or calls `arrayJoin()`.
   In 3.x these changed rows that the query did not select. Search for chains
   that reach `delete(` or `update(` and show the user each one that uses such
   a clause; the fix is to select the keys first and mutate by them, as in
   `whereIn('id', $query->pluck('id'))`.
3. **Sub-queries in mutation conditions.** A `delete()` or `update()` whose
   condition has a sub-query is now checked with an `EXPLAIN` first, and is
   refused when ClickHouse cannot run the condition, for example a correlated
   `whereExists()`. A lightweight delete (`delete(true)`, or
   `use_lightweight_delete`) whose condition holds `UNION`, `INTERSECT` or
   `EXCEPT` is refused too; `delete(false)` takes it. Point out the matches.
4. **Floats.** Every write now sends a float with every digit, where 3.x sent
   14 significant digits. A float computed in PHP is cut off at the scale of a
   `Decimal` or integer column instead of being rounded: `4.35 * 100` stores
   `434.99` in a `Decimal(10, 2)` column and `0.29 * 100` stores `28` in an
   `Int32` column, where 3.x stored `435.00` and `29`. Look for arithmetic on
   floats, such as `* 100` or division, whose result is inserted or updated,
   and for conditions that compare with such a float. Find the column types
   in the migrations, or with `DESCRIBE TABLE` if the user lets you read the
   database. Propose `round(..., <scale>)` or an int or numeric string.
   `update()` needs a numeric string or an int, because rounding does not help
   a value with a fraction there.
5. **Rows with missing keys.** `insertAssoc()`, `prepareAndInsertAssoc()` and
   `buffer()` refuse a row whose keys differ from the first row's (3.x filled
   a missing key with a made-up value, such as `'1'`). Search for them and
   check how the rows are built: a key that is only set under a condition, or
   rows filtered with `array_filter()`. Propose giving every row every key,
   with an explicit default.
6. **Laravel's query builder (`fix_default_query_builder` off) only.**
   `whereLike()` now sends `ilike`, which ignores case; `insert()` writes a
   collection value as an array (`->toJson()` stores JSON); `timeout()` now
   stops the query on the server; `affectingStatement()` and `insertUsing()`
   return the rows written instead of `1`; `insertGetId()` returns the key
   given and refuses a row without one. Point out the uses that rely on the
   3.x behavior.

## Step 6: Review the migrations with the user

These changes only matter when migrations run after the upgrade, as with
`migrate:fresh`, in a new environment, in CI or in tests. Existing tables are
not touched. Do not edit a migration that already ran in production without
the user's approval: the goal is that a fresh run creates the tables that
production has.

1. **`hasTable()` guards.** `Schema::hasTable()` now returns `false` for
   views, materialized views and dictionaries. A migration that guards
   `CREATE VIEW`, `CREATE MATERIALIZED VIEW` or `CREATE DICTIONARY` with
   `if (! Schema::hasTable(...))` would run it again and fail. Replace the
   guard with `Schema::hasView()`, `Schema::hasDictionary()` or a
   `CREATE ... IF NOT EXISTS` statement. This fix is safe; make it.
2. **Laravel's schema builder** (`Schema::create()`, `Schema::table()` on a
   ClickHouse connection). 4.0 writes what the blueprint declares, where 3.x
   wrote names and types only, always `MergeTree()`, sorted by the first
   column, and sent nothing for most of `Schema::table()`. For each such
   migration, list for the user what a fresh run now does differently:
   - `nullable()`, `default()`, `comment()`, `storedAs()`, `virtualAs()` and
     `useCurrent()` are written; `timestamps()` and `softDeletes()` create
     `Nullable` columns;
   - `engine()` and the connection's `engine` option are used;
   - `primary()` is the sorting key, not the first column; a nullable first
     column gives `ORDER BY tuple()`;
   - a precision, as in `timestamp('seen_at', 3)`, gives `DateTime64(3)`;
   - `index()` with a ClickHouse index type creates the index;
   - `Schema::table()` now runs added columns, `dropColumn()`, `index()`,
     `dropIndex()`, `comment()`, `orderBy()`, `sampleBy()`, `ttl()`,
     `settings()` and `Schema::rename()`, which 3.x ignored. A fresh run can
     then give tables that differ from production's, or fail.
   These now **fail** a fresh run and must change: `engine('InnoDB')` or
   another MySQL engine, a MySQL index type such as `btree`, `time()`,
   `timeTz()`, `set()`, `computed()`, `fullText()`, and `primary()`,
   `dropPrimary()` or `renameIndex()` inside `Schema::table()`. Ask the user
   how to change each one. To keep a table exactly as 3.x created it, the
   migration can write its `CREATE TABLE` with `Migration::write()`.
3. **`Migration::createMergeTree()`** now escapes comments, string defaults,
   enum values and setting strings itself. Search the migrations that call it
   for values escaped by hand (`\\'` in a `comment()` or `default()`), setting
   strings quoted by hand (`"'hot_cold'"`), and `enum()` arrays that mix list
   entries and string keys or have values that are not integers (`1.0`,
   `true`, `'+1'`). Remove the hand escaping, and fix the arrays as the
   CHANGELOG says; this keeps what the migration meant. Show the user each
   change.
4. **Cluster connections.** If a ClickHouse connection sets `cluster_name`,
   tell the user:
   - Laravel's schema builder now sends its statements `ON CLUSTER`, and, when
     the connection also lists its nodes in `cluster`, creates MergeTree
     tables, the `migrations` table included, with a replicated engine. 3.x
     sent them to the active node only.
   - A table that 3.x created on the active node only fails an `ON CLUSTER`
     change on the other hosts; a later migration can call
     `$table->withoutOnCluster()` to change it on the active node only.
   - A 3.x `migrations` table that exists on the active node only makes the
     next `migrate` fail loudly after a failover, with `TABLE_ALREADY_EXISTS`,
     where 3.x created an empty one and ran every migration again.
   - An `ON CLUSTER` statement waits for every host, up to 180 seconds, while
     the packaged `timeout_query` is 2 seconds: propose a higher
     `timeout_query` for migration commands.
   Do not change migrations for this without the user's approval.

## Step 7: Refresh Laravel's caches

Run `php artisan package:discover` and `php artisan config:clear`. If the
project caches its config in production, tell the user to run
`php artisan config:cache` after the deploy: the connection checks its new
options when it is created, and `config:cache` cannot write an enum instance,
so `datetime_precision` and `insert_format` must be strings in config files.

## Step 8: Verify

Run all of these and fix what they report:

1. `composer dump-autoload` and `php artisan package:discover`. Both must
   finish without errors.
2. The class-loading check of step 3.
3. `php -l` on every PHP file you changed.
4. Every search of steps 3 to 6 again. What remains must be a case you
   decided to leave, with the user's answer where you asked.
5. `php artisan migrate:status`. This loads the migration files. If the
   database cannot be reached, run `php -l database/migrations/*.php` instead
   and say so in the report.
6. Run the test suite and compare it with the baseline from step 1. Every
   failure that is new must be fixed or explained. A test that asserted 3.x
   behavior that 4.0 changed on purpose, such as the SQL of an aggregate, is
   updated to the 4.x behavior; say which ones.

## Step 9: Report

End with a short summary for the user:

- the version change in `composer.json`, and any other package you changed;
- the files you changed in each step, with what changed;
- every question you asked and the decision taken;
- the matches of steps 5 and 6 that you left as they are, and why;
- for a cluster connection, the notes of step 6.4;
- the test results compared with the baseline, and any failures that already
  existed;
- if the ClickHouse servers run 25.8 or later, a pointer to
  https://laravel-clickhouse.oralunal.com/reference/clickhouse-versions, which
  lists what they do otherwise than 24.8 and the settings that bring back its
  behavior.
