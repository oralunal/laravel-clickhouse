# Laravel's query builder

The query builder of a ClickHouse connection, `Oralunal\LaravelClickHouse\QueryBuilder`, is a subclass of Laravel's `Illuminate\Database\Query\Builder`.
`DB::connection('clickhouse')->table()`, `query()` and the Eloquent models of the connection use it. Laravel's methods and macros work on it.
This page lists what works differently on ClickHouse, and what does not work.

## Rows and bindings

- `get()` returns associative arrays, not `stdClass` objects. See [Read results](/query-builder/reading-results#rows).
- The grammar writes `?` for each value. The connection writes the values into the SQL as ClickHouse literals. See [Bindings and the SQL of a query](/query-builder/basics#bindings-and-the-sql-of-a-query).
- Names are in double quotes, with backslashes and double quotes doubled: `select('na\me')` gives `select "na\\me"`.

## Methods that work differently

| Method | SQL or behavior |
| --- | --- |
| `count()`, `paginate()` | Count the rows that `get()` returns, in a sub-query when needed. See [Count and aggregates](/query-builder/reading-results#count-and-aggregates). |
| `exists()` | `select exists(...) as "exists"`. A query with `INTERSECT` or `EXCEPT` reads all their columns. |
| `pluck()` | Reads a joined column by the name that ClickHouse gives it, such as `users.name` |
| `sum()`, `avg()`, `min()`, `max()`, `aggregate()` | Laravel's. A union keeps the order of its first query. |
| `inRandomOrder()` | `order by rand()`. A seed throws. Use `orderByRaw('cityHash64(?, id)', [$seed])`. |
| `union()` | `union distinct`. With `union_default_mode` in the connection `settings`, a bare `union`. `unionAll()` sends `union all`. |
| `whereLike()`, `whereNotLike()` | `ilike`, `not ilike`. With `caseSensitive: true`, `like`, `not like`. |
| `whereNullSafeEquals('comment', null)` | `["comment"] = [null]`, which matches the `NULL` rows. The `<=>` operator also works. |
| `where('field_two', '&', 4)` | `bitAnd("field_two", 4) != 0`. `\|`, `^`, `<<`, `>>` and `&~` also work. |
| `whereDate()`, `whereTime()`, `whereDay()`, `whereMonth()`, `whereYear()` | ClickHouse functions of the column. See [Date conditions](/query-builder/dates#date-conditions). |
| `insert()` | Takes a second argument, the format: `Values` or `JSONEachRow`. A collection is an array literal. To store JSON, call `toJson()`. |
| `insertGetId()` | Inserts the row and returns its key as you gave it. A row without its key throws a `RuntimeException`. |
| `update()`, `delete()` | Mutations: `alter table ... update`, `alter table ... delete` or `delete from`. See [Updates and deletions](/query-builder/writing-data). |
| `delete($id)` | `alter table "my_table" delete where "id" = 5`. A bool as `$id` throws: give `delete(lightweight: true)`. |
| `truncate()` | `truncate table "analytics"."my_table"`, without the alias |
| `cursor()` | Reads through the connection's [`cursor()`](/advanced/raw-sql#read-rows-one-by-one), one row at a time |
| `timeout(5)` | `settings max_execution_time = 5` on the outer `select` |
| `forceIndex('idx_url')`, `ignoreIndex('idx_url')` | `settings force_data_skipping_indices = 'idx_url'`, `ignore_data_skipping_indices`. `useIndex()` throws. |
| `lockForUpdate()`, `sharedLock()` | Add nothing. `lock()` writes a string into the SQL as it is. |

The ClickHouse clauses, such as `final()`, `preWhere()`, `sample()`, `arrayJoin()`, `limitBy()` and `settings()`, are in [ClickHouse SQL](/query-builder/clickhouse-sql).

## Dates and times

The date methods call ClickHouse functions. Laravel prepares their values: it pads a time, and writes a day or a month as an integer.
See [Date conditions](/query-builder/dates#date-conditions).

## Eloquent

ClickHouse has no auto-increment. The package's model sets `$incrementing` to `false`. Give the key before `create()`, for example with `Str::uuid()`.
See [Eloquent models](/models/eloquent).

## Not supported

These methods throw Laravel's `RuntimeException` before the package sends a request:

| Method | Use instead |
| --- | --- |
| `upsert()` with columns to update | `insert()` into a `ReplacingMergeTree` table, or `update()`. `upsert()` without columns to update is an `insert()`. |
| `insertOrIgnore()` | `insert()`. ClickHouse has no unique constraints. |
| `whereJsonContains()`, JSON paths such as `where('payload->name', 'x')` | `whereRaw("JSONExtractString(payload, 'name') = ?", ['x'])` |
| `whereFullText()` | `whereRaw('hasToken(body, ?)', ['word'])` or `whereLike()` |

Laravel's transactions are not supported either. See [Transactions](/advanced/raw-sql#transactions).
