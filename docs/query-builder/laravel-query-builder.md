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

## Mutations

`delete()` and `update()` refuse the same clauses and conditions as the package's query builder. See [Updates and deletions](/query-builder/writing-data).
On this query builder, they also refuse `limit()`, `groupLimit()`, an `offset()` above `0`, `groupBy()`, `having()`, unions, joins and a select alias or expression.

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
