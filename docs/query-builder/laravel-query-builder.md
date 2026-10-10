# Laravel's query builder

## Choose the query builder

The `fix_default_query_builder` option selects the query builder of `DB::connection('clickhouse')->table()`, `query()` and the Eloquent models of the connection:

| Value | Query builder | `get()` returns |
| --- | --- | --- |
| `true` (the packaged default) | The package's `Oralunal\LaravelClickHouse\Builder` | The smi2 `Statement`. Use `getRows()` or `->get()->rows()` for an array. |
| `false` | `Oralunal\LaravelClickHouse\QueryBuilder`, a subclass of `Illuminate\Database\Query\Builder` | A collection of `stdClass` objects |

A connection config without the option gets Laravel's query builder.
Laravel's methods and macros work on it, with the ClickHouse SQL on this page.

## Bindings

Laravel's query builder binds values with `?`. The package writes them into the SQL when the query runs.
`toSql()` shows the placeholders. `toRawSql()` shows the SQL that the package sends:

```php
$query = DB::connection('clickhouse')->table('my_table')->where('field_one', "it's")->where('ratio', 0.1 + 0.2);
$query->toSql();    // select * from "my_table" where "field_one" = ? and "ratio" = ?
$query->toRawSql(); // select * from "my_table" where "field_one" = 'it\'s' and "ratio" = 0.30000000000000004
```

Floats keep all digits, `false` is `0`, `null` and arrays work in `insert()`, and a `Stringable` value is quoted.
`whereRaw()`, `selectRaw()`, `orderByRaw()`, `havingRaw()`, `groupByRaw()` and `fromRaw()` also take `?`.

## Count rows

`count()` and the total of `paginate()` count the rows that `get()` returns.
A query whose select list, order or grouping changes these rows is counted in a sub-query, without `LIMIT` and `OFFSET`:

```php
DB::connection('clickhouse')->table('my_table')->select('id as x')->where('x', 3)->count();
// select count(*) as "aggregate" from (select "id" as "x" from "my_table" where "x" = 3) as "aggregate_table"
```

- These queries are counted in a sub-query: `groupBy()`, `distinct()`, `groupLimit()`, a select alias or expression, and an order with an alias, `arrayJoin()`, `WITH FILL`, a raw limit or a set operation.
- `groupBy('user_id')->count()` returns the number of groups. `distinct()->select('user_id')->count()` returns the number of distinct rows.
- A grouped query without a select list is counted by its groups:
  `groupBy('user_id')->havingRaw('count() > 1')->count()` sends
  `select count(*) as "aggregate" from (select 1 from "my_table" group by "user_id" having count() > 1) as "aggregate_table"`.
  Give such a query a select list for `get()`, such as `select('user_id')`.
- `distinct('user_id')->count()` sends Laravel's `count(distinct "user_id")`.
- `count($column)`, `sum()`, `avg()`, `min()` and `max()` are Laravel's methods. They remove the select list and the order. For such a query, aggregate a sub-query:

  ```php
  DB::connection('clickhouse')->query()->fromSub($query, 'q')->sum('x');
  ```

## Dates and times

| Method | SQL |
| --- | --- |
| `whereDate('created_at', '>=', '2024-02-10')` | `toDate32("created_at") >= '2024-02-10'` |
| `whereTime('created_at', '>=', '9:05')` | `formatDateTime("created_at", '%H:%i:%S') >= '09:05:00'` |
| `whereDay('created_at', 5)` | `toDayOfMonth("created_at") = 5` |
| `whereMonth('created_at', '02')` | `toMonth("created_at") = 2` |
| `whereYear('created_at', '>=', 2025)` | `toYear("created_at") >= 2025` |

These are the functions of the package's query builder, but Laravel prepares the values:

- With `null`, the condition matches no row, as on the other drivers of Laravel.
- Laravel writes a day or a month with `sprintf('%02d')`: `whereDay('created_at', 5.5)` compares with `5`.
- Laravel pads each part of a time: `'7:8'` becomes `'07:08:00'`. Another string, such as `'10:20 am'`, is compared as text.
- In a join closure, Laravel's `JoinClause` writes `whereDay()` and `whereMonth()` with padded text, such as `'05'`, which fails with `TYPE_MISMATCH`.

## ClickHouse SQL

| Method | SQL or behavior |
| --- | --- |
| `inRandomOrder()` | `order by rand()`. A seed throws. Use `orderByRaw('cityHash64(?, id)', [$seed])`. |
| `union()` | `union distinct`. With `union_default_mode` in the connection `settings`, a bare `union`. `unionAll()` sends `union all`. |
| `whereLike()`, `whereNotLike()` | `ilike`, `not ilike`. With `caseSensitive: true`, `like`, `not like`. |
| `whereNullSafeEquals('comment', null)` | `["comment"] = [null]`, which matches the `NULL` rows. The `<=>` operator also works. |
| `where('field_two', '&', 4)` | `bitAnd("field_two", 4) != 0`. `\|`, `^`, `<<`, `>>` and `&~` also work. |
| `update(['tags' => ['a', 'b']])` | `update "tags" = ['a', 'b']`. A collection is an array too. For a `Map`, use `DB::raw("map('k', 1)")`. |
| `insert()` with a collection | An array literal. To store JSON, call `toJson()`. |
| `delete(5)` | `alter table "my_table" delete where "id" = 5`. Mutations remove the table name and alias from column names. |
| `truncate()` | `truncate table "analytics"."my_table"`, without the alias |
| `insertGetId()` | Inserts the row and returns its key as you gave it. A row without its key throws a `RuntimeException`. |
| `insert($rows, 'JSONEachRow')` | The JSONEachRow format. Without a format, the `insert_format` option applies. |
| `cursor()` | Reads through the connection's [`cursor()`](/advanced/raw-sql#read-rows-one-by-one), one row at a time |
| `timeout(5)` | `settings max_execution_time = 5` on the outer `select` |
| `forceIndex('idx_url')`, `ignoreIndex('idx_url')` | `settings force_data_skipping_indices = 'idx_url'`, `ignore_data_skipping_indices`. `useIndex()` throws. |
| Names | Double quotes, with backslashes and double quotes doubled: `select('na\me')` sends `select "na\\me"` |

ClickHouse has no auto-increment. For Eloquent's `create()` on a model with `$incrementing = true`, give the key. For keys such as UUIDs, set `$incrementing` to `false`.
For a model with ClickHouse defaults, see [Eloquent models](/models/eloquent).

## ClickHouse clauses

The query builder has the ClickHouse clauses of the package's query builder. Their names are the same, but their arguments follow Laravel's methods.
The examples use a query on `DB::connection('clickhouse')->table('t')`.

### FINAL and SAMPLE

```php
$query->final();                       // select * from "t" final
DB::connection('clickhouse')->table('t', 'x', true); // select * from "t" as "x" final
$query->from('t', null, true);         // select * from "t" final
$query->sample(0.1, 0.5);              // select * from "t" sample 0.1 offset 0.5
$query->sample(1_000_000);             // select * from "t" sample 1000000
```

- `final(false)` removes `FINAL`. A query on a sub-query cannot have `FINAL`: `toSql()` throws a `LogicException`.
- `sample()` needs a coefficient above 0. A coefficient above 1 is a number of rows. The offset is from 0 to 1. The table needs a `SAMPLE BY` key.

### ARRAY JOIN

```php
$query->select('id', 'tag')->arrayJoin('tags', 'tag');
// select "id", "tag" from "t" array join "tags" as "tag"

$query->arrayJoin(['tag' => 'tags', 'scores']);
// select * from "t" array join "tags" as "tag", "scores"

$query->leftArrayJoin('tags');
// select * from "t" left array join "tags"

$query->arrayJoinSub(fn ($q) => $q->from('u')->selectRaw('groupArray(id)'), 'ids');
// select * from "t" array join (select groupArray(id) from "u") as "ids"
```

- A string key of the array is the alias of its array. The package does not add the aliases to the select list.
- `leftArrayJoin()` and `leftArrayJoinSub()` keep a row with an empty array one time.
- Each call adds an `ARRAY JOIN` clause. `count()` counts such a query in a sub-query.

### Joins

Each join method takes the arguments of Laravel's `join()`, and has a `Sub` form that takes the arguments of `joinSub()`:

| Method | SQL |
| --- | --- |
| `innerJoin()`, `innerJoinSub()` | `inner join`, as `join()` |
| `anyInnerJoin()`, `anyInnerJoinSub()`, `allInnerJoin()`, `allInnerJoinSub()` | `any inner join`, `all inner join` |
| `anyLeftJoin()`, `anyLeftJoinSub()`, `allLeftJoin()`, `allLeftJoinSub()` | `any left join`, `all left join` |
| `anyRightJoin()`, `anyRightJoinSub()`, `allRightJoin()`, `allRightJoinSub()` | `any right join`, `all right join` |
| `fullJoin()`, `fullJoinSub()` | `full join` |
| `semiLeftJoin()`, `semiLeftJoinSub()`, `semiRightJoin()`, `semiRightJoinSub()` | `semi left join`, `semi right join` |
| `antiLeftJoin()`, `antiLeftJoinSub()`, `antiRightJoin()`, `antiRightJoinSub()` | `anti left join`, `anti right join` |
| `asofJoin()`, `asofJoinSub()`, `asofLeftJoin()`, `asofLeftJoinSub()` | `asof join`, `asof left join` |

```php
$query->anyLeftJoin('u', 't.uid', '=', 'u.id');
// select * from "t" any left join "u" on "t"."uid" = "u"."id"

$query->asofLeftJoin('q', fn ($join) => $join->on('t.sym', '=', 'q.sym')->on('t.ts', '>=', 'q.ts'));
// select * from "t" asof left join "q" on "t"."sym" = "q"."sym" and "t"."ts" >= "q"."ts"
```

An ASOF join needs one inequality in its conditions. It selects the closest match.

### PREWHERE

The `preWhere` methods take the arguments of the `where` methods, closures and sub-queries included:

```php
$query->preWhere('a', 1)->orPreWhere('b', '>', 2)->preWhereIn('c', [3, 4])->where('h', 8);
// select * from "t" prewhere "a" = ? or "b" > ? and "c" in (?, ?) where "h" = ?
```

The methods are `preWhere()`, `orPreWhere()`, `preWhereRaw()`, `orPreWhereRaw()`, `preWhereIn()`, `orPreWhereIn()`, `preWhereNotIn()`, `orPreWhereNotIn()`, `preWhereNull()`, `orPreWhereNull()`, `preWhereNotNull()`, `orPreWhereNotNull()`, `preWhereBetween()`, `orPreWhereBetween()`, `preWhereNotBetween()` and `orPreWhereNotBetween()`.

### GLOBAL IN and empty()

```php
$query->whereGlobalIn('user_id', fn ($q) => $q->from('u')->select('id'));
// select * from "t" where "user_id" global in (select "id" from "u")

$query->whereEmpty('name')->orWhereNotEmpty(['tags', 'notes']);
// select * from "t" where empty("name") or notEmpty("tags") or notEmpty("notes")

$query->select('g')->groupBy('g')->havingNotEmpty('g');
// select "g" from "t" group by "g" having notEmpty("g")
```

- `whereGlobalIn()`, `orWhereGlobalIn()`, `whereGlobalNotIn()` and `orWhereGlobalNotIn()` take the values of `whereIn()`.
- `whereEmpty()`, `orWhereEmpty()`, `whereNotEmpty()`, `orWhereNotEmpty()` and the `HAVING` forms `havingEmpty()`, `orHavingEmpty()`, `havingNotEmpty()` and `orHavingNotEmpty()` take a column or a list.

### LIMIT BY

```php
$query->orderByDesc('ts')->limitBy(3, 'user_id')->limit(100);
// select * from "t" order by "ts" desc limit 3 by "user_id" limit 100

$query->limitByWithOffset(1, 2, ['user_id', 'day']);
// select * from "t" limit 1 offset 2 by "user_id", "day"
```

`count()` counts such a query in a sub-query.

### SETTINGS

```php
$query->settings(['max_threads' => 2, 'optimize_read_in_order' => true])->settings('max_threads', 4);
// select * from "t" settings max_threads=4, optimize_read_in_order=1
```

- A later value of a name replaces the earlier value. `null` removes a setting.
- Booleans become `1` and `0`. Strings become string literals. `DB::raw()` is SQL. A name that is not a plain identifier throws an `InvalidArgumentException`.
- The settings of `timeout()`, `forceIndex()` and `ignoreIndex()` come first. With a set operation, the query is selected from as a sub-query.

### WITH

```php
$query->from('recent')
    ->withExpression('recent', fn ($q) => $q->from('events')->where('a', 1))
    ->withAlias('total', fn ($q) => $q->from('events')->selectRaw('count()'))
    ->withAlias('ids', [1, 2]);
// with "recent" as (select * from "events" where "a" = ?), (select count() from "events") as "total",
//   [1, 2] as "ids" select * from "recent"
```

- `withExpression($name, $query)` adds a named sub-query. `$query` is a builder, a closure, `DB::raw()` or SQL.
- `withRecursiveExpression()` does the same and writes `with recursive`.
- `withAlias($alias, $value)` adds a value. A builder or a closure is a scalar sub-query, and another value is a literal.

### INTERSECT and EXCEPT

```php
$query->intersect($other)->exceptDistinct(fn ($q) => $q->from('v'));
// (select * from "t") intersect (select * from "u") except distinct (select * from "v")
```

`intersect()`, `intersectDistinct()`, `except()` and `exceptDistinct()` take a builder or a closure. `unionDistinct()` is the same as `union()`.
ClickHouse calculates `INTERSECT` before `UNION` and `EXCEPT`. See [Order of the operations](/query-builder/clickhouse-sql#order-of-the-operations).

## Mutations

`delete()` and `update()` refuse the same clauses and conditions as the package's query builder. See [Updates and deletions](/query-builder/writing-data).
On this query builder, they also refuse `limit()`, `groupLimit()`, an `offset()` above `0`, `groupBy()`, `having()`, unions, joins and a select alias or expression.
They also refuse `final()`, `sample()`, `arrayJoin()`, `limitBy()`, `withExpression()`, `settings()`, `intersect()` and `except()`.
The `preWhere` conditions join the `where` conditions: `where ("a" = ?) and ("b" = ?)`.

```php
$query->where('a', 1)->delete(lightweight: true);
// delete from "t" where "a" = ?

$query->where('a', 1)->delete(null, false, 202401);
// alter table "t" delete in partition 202401 where "a" = ?

$query->onCluster('c1')->where('a', 1)->update(['b' => 2], '202401');
// alter table "t" on cluster 'c1' update "b" = ? in partition '202401' where "a" = ?
```

- `delete($id = null, $lightweight = null, $partition = null)`: `true` sends a lightweight `delete from`, `false` an `alter table ... delete`, and `null` follows `use_lightweight_delete`.
- `update($values, $partition = null)` and `delete()` take a partition: an int is a number, a string is a string literal, and `DB::raw()` is SQL. A lightweight delete with a partition needs ClickHouse 24.9 or later.
- `onCluster('c1')` adds `on cluster 'c1'` to `delete()`, `update()` and `truncate()`. `withoutOnCluster()` removes the cluster of `use_on_cluster`.

- Laravel's `first()`, `value()`, `find()`, `sole()`, `chunk()` and `paginate()` keep a `LIMIT` on the builder. Start a new query for a mutation.
- `updateOrInsert()` adds `limit(1)` to its update, so it throws when the row exists.
- The `EXPLAIN` check runs while Laravel compiles the statement. The query log shows it.
- A value cannot change the SQL. A column name such as `{0}` stays a name:

  ```php
  DB::connection('clickhouse')->table('ph')->where('id', 1)->update(['{0}' => 'ABC']);
  // alter table "ph" update "{0}" = 'ABC' where "id" = 1
  ```

Do not take column names from user input. Laravel reads a dot in a name as a qualifier and the word `as` as an alias.

## Not supported

These methods throw Laravel's `RuntimeException` before the package sends a request:

| Method | Use instead |
| --- | --- |
| `upsert()` with columns to update | `insert()` into a `ReplacingMergeTree` table, or `update()` |
| `insertOrIgnore()` | `insert()`. ClickHouse has no unique constraints. |
| `whereJsonContains()`, JSON paths such as `where('payload->name', 'x')` | `whereRaw("JSONExtractString(payload, 'name') = ?", ['x'])` |
| `whereFullText()` | `whereRaw('hasToken(body, ?)', ['word'])` or `whereLike()` |

`lockForUpdate()` and `sharedLock()` add nothing. `lock()` writes a string into the SQL as it is.
