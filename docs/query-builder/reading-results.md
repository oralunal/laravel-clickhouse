# Read results

The examples use `$query = DB::connection('clickhouse')->table('my_table')`.

## Rows

`get()` returns a Laravel collection of the rows. Each row is an associative array:

```php
$query->select('id', 'field_one')->orderBy('id')->get();
// select "id", "field_one" from "my_table" order by "id" asc
// collect([['id' => 1, 'field_one' => 'a'], ['id' => 2, 'field_one' => 'b'], ['id' => 3, 'field_one' => 'c']])
```

- Laravel's query builder returns `stdClass` objects on the other drivers. Read a column as `$row['id']`, or cast the row: `(object) $row`.
- The Eloquent builder of a model returns models. See [Eloquent models](/models/eloquent).
- ClickHouse 24.8 returns 64-bit integers, such as `UInt64` and the result of `count()` in SQL, as strings. 25.8 and later return numbers.
  The `output_format_json_quote_64bit_integers` setting chooses. See [ClickHouse versions](/reference/clickhouse-versions).

### Static analysis

`QueryBuilder` gives static analysis tools, such as PHPStan and Larastan, the array type of its rows.
`get()` returns `Collection<int, array<string, mixed>>`, and `first()` returns `array<string, mixed>|null`.
The same applies to `find()`, `sole()`, `cursor()`, `lazy()`, `chunk()`, `each()` and their variants.
On a model query, `toBase()` and `getQuery()` return `QueryBuilder`.

`DB::connection()` declares Laravel's connection type. Thus PHPStan reads `DB::connection('clickhouse')->table()` as
Laravel's builder, with `stdClass` rows. Declare the type of the connection:

```php
use Oralunal\LaravelClickHouse\Connection;

/** @var Connection $clickhouse */
$clickhouse = DB::connection('clickhouse');

$rows = $clickhouse->table('my_table')->get(); // Collection<int, array<string, mixed>>
```

## First row and single values

```php
$query->orderBy('created_at', 'desc')->first();
// select * from "my_table" order by "created_at" desc limit 1
// ['id' => 3, 'field_one' => 'c', ...], or null without a row

$query->where('id', 2)->value('field_one');
// select "field_one" from "my_table" where "id" = 2 limit 1
// 'b'

$query->orderBy('id')->pluck('field_one', 'id');
// select "field_one", "id" from "my_table" order by "id" asc
// collect([1 => 'a', 2 => 'b', 3 => 'c'])
```

- `find($id)` reads the row whose `id` is `$id`. `sole()` throws when the query returns no row or more than one.
- `first()`, `value()`, `find()` and `sole()` add a `LIMIT` to the builder itself, as in Laravel. Start a new query for a mutation after them.
- `implode('field_one', ',')` joins the values of a column.

### pluck() and the names of the columns

ClickHouse names a column of a joined table with its table, such as `users.id`, when the query's own table has a column of the same name.
Other columns keep their name, such as `name`. `pluck()` reads a column by the name that ClickHouse gives it:

```php
$query->select('my_table.id', 'users.name')
    ->anyLeftJoin('users', 'my_table.user_id', '=', 'users.id')
    ->pluck('users.name', 'my_table.id');
// collect([1 => 'Ada', 2 => 'Bob', 3 => 'Ada'])
```

`pluck('field_one as name')` and `pluck(DB::raw('field_two + 1 as next'))` read the alias.

## Check if rows exist

```php
$query->where('id', 3)->exists();       // true
$query->where('id', 99)->doesntExist(); // true
// select exists(select * from "my_table" where "id" = 3) as "exists"
```

- A query that holds `INTERSECT` or `EXCEPT` gets `where not ignore(*)`, so that ClickHouse compares all columns. See [INTERSECT and EXCEPT in sub-queries](/query-builder/clickhouse-sql#intersect-and-except-in-sub-queries).
- A query that calls `arrayJoin()`, in `selectRaw()`, `withAlias()` or an order, is selected from, also in `whereExists()`:
  `select exists(select * from (...) as "exists_table" where not ignore(*)) as "exists"`. With the analyzer, ClickHouse 26.3 and 26.8 answer `true` for `exists(select arrayJoin(arr) ...)` over an empty array.
- `exists()` reads the only column of the result, whatever its name. Without the analyzer (`enable_analyzer = 0`), ClickHouse names it `in(1, _subquery1)` for a query with a `withExpression()` or a join.

## Count and aggregates

```php
$query->where('field_two', '>', 0)->count();
// select count(*) as "aggregate" from "my_table" where "field_two" > 0
// 2

$query->where('field_two', '>', 0)->max('field_two');
// select max("field_two") as "aggregate" from "my_table" where "field_two" > 0

$query->sum(DB::raw('field_two * id'));
// select sum(field_two * id) as "aggregate" from "my_table"

$query->aggregate('quantile(0.5)', ['field_two']);
// select quantile(0.5)("field_two") as "aggregate" from "my_table"
```

- `count()` returns an int on all ClickHouse versions.
- Without rows, `sum()` returns `0` and `avg()` returns `null`. `min()` and `max()` return the default of the column type, such as `0` or `''`.
- `aggregate($function, $columns)` takes an aggregate function, such as `uniqExact`, or a parametric function, such as `quantile(0.5)`, `quantiles(0.5, 0.9)` or `topK(2)`.
  The function is written as given. Do not take it from user input.
- `average()` is the same as `avg()`.

::: warning
The aggregates quote a string column as a column name. `sum('field_two * id')` fails with `UNKNOWN_IDENTIFIER`.
Give SQL with `DB::raw()`.
:::

### Which rows count() counts

`count()` and the total of `paginate()` count the rows that `get()` returns.
A query whose select list, order or grouping changes these rows is counted in a sub-query, without its `LIMIT` and `OFFSET`:

```php
$query->select('user_id')->groupBy('user_id')->count();
// select count(*) as "aggregate" from (select "user_id" from "my_table" group by "user_id") as "aggregate_table"

$query->select('id as x')->where('x', 3)->count();
// select count(*) as "aggregate" from (select "id" as "x" from "my_table" where "x" = 3) as "aggregate_table"
```

These queries are counted in a sub-query:

- `groupBy()`, `distinct()` without columns, `groupLimit()`, `limitBy()`, `arrayJoin()` and `withAlias()`;
- a select alias or expression, such as `selectRaw('arrayJoin(tags) as tag')`;
- an order that defines an alias, calls `arrayJoin()`, adds rows with `WITH FILL`, or limits the rows with a raw `LIMIT`, `LIMIT ... BY`, `OFFSET` or `FETCH`.

Other notes:

- A grouped query without a select list is counted by its groups:
  `groupBy('user_id')->havingRaw('count() > 1')->count()` sends
  `select count(*) as "aggregate" from (select 1 from "my_table" group by "user_id" having count() > 1) as "aggregate_table"`.
- `distinct('user_id')->count()` sends Laravel's `count(distinct "user_id")`.
- A union and a query with `having()` are counted as Laravel counts them: `select count(*) as "aggregate" from (...) as "temp_table"`.
- The settings of `timeout()`, `forceIndex()` and `ignoreIndex()` go on the outer query.

### Other aggregates

`count($column)`, `sum()`, `avg()`, `min()`, `max()` and `aggregate()` are Laravel's: they remove the select list, and the order of a query without `groupBy()`.
A union keeps the order of its first query, because the order decides, with its limit, which rows that query returns.
To aggregate the rows of a grouped query or of a select list, aggregate a sub-query:

```php
DB::connection('clickhouse')->query()
    ->fromSub($query->select('user_id', DB::raw('max(field_two) as m'))->groupBy('user_id'), 'q')
    ->sum('m');
// select sum("m") as "aggregate" from (select "user_id", max(field_two) as m from "my_table" group by "user_id") as "q"
```

## Pagination

```php
$query->orderBy('id')->paginate(15);
// select count(*) as "aggregate" from "my_table"
// select * from "my_table" order by "id" asc limit 15 offset 0
```

- `paginate()` gets the total from a count. See [Which rows count() counts](#which-rows-count-counts).
- `simplePaginate()` reads one row more than a page and does not count.
- Order the query. Without `ORDER BY`, ClickHouse returns the rows in no fixed order, and pages can overlap.
- `paginate()`, `simplePaginate()`, `chunk()` and `first()` add a `LIMIT` after the `ORDER BY`.
  ClickHouse refuses it after a raw `LIMIT`, `OFFSET` or `FETCH` in `orderByRaw()`. Read such a query as a sub-query:
  `DB::connection('clickhouse')->query()->fromSub($query, 'q')->orderBy('id')->paginate()`.

## Chunks

```php
$query->select('id')->orderBy('id')->chunk(2, function (Collection $rows, int $page) {
    foreach ($rows as $row) {
        echo $row['id'] . "\n";
    }
});
// select "id" from "my_table" order by "id" asc limit 2 offset 0
// select "id" from "my_table" order by "id" asc limit 2 offset 2
```

- `chunk()` needs an order, as in Laravel: without one, it throws `You must specify an orderBy clause when using this function.`
- The callback gets a collection of the rows of a page and the page number, which starts at 1. It stops when the callback returns `false`.
- The `limit()` and `offset()` of the query limit the rows.
- `lazy()` and `lazyById()` read in pages too.

## Read rows one by one

`cursor()` reads all rows of the query with one request, and yields them one at a time:

```php
foreach ($query->select('id')->orderBy('id')->cursor() as $row) {
    // ['id' => 1], then ['id' => 2], ...
}
```

It uses the [`cursor()`](/advanced/raw-sql#read-rows-one-by-one) of the connection. Its memory stays flat.

## Other formats

`get()` and the other methods of this page read the rows of a JSON result.
`getStatement($format = null)` runs the query with a `FORMAT` clause, and returns the smi2 `Statement`. Its `rawData()` is the output:

```php
$query->select('id', 'field_one')->orderBy('id')->getStatement('CSVWithNames')->rawData();
// select "id", "field_one" from "my_table" order by "id" asc format CSVWithNames
// "\"id\",\"field_one\"\n1,\"a\"\n2,\"b\"\n3,\"c\"\n"

$statement = $query->select('user_id', DB::raw('count() as c'))->groupByRaw('user_id with totals')->getStatement();
$statement->rows();   // [['user_id' => 7, 'c' => '2'], ['user_id' => 8, 'c' => '1']]
$statement->totals(); // ['user_id' => 0, 'c' => '3']
```

- `getStatement()` takes a case of `Oralunal\LaravelClickHouse\Enums\Format`, such as `Format::Csv`, or the name of a ClickHouse output format, such as `'TSVRaw'`.
  The name of a case is matched in any letter case: `'jsoneachrow'` gives `format JSONEachRow`. A name with other characters than letters and digits throws an `InvalidArgumentException`.
- Without a format, the result is JSON. `rows()`, `totals()`, `extremes()` and `statistics()` read it.
- A ClickHouse error throws when the query runs. The query is logged as `get()` logs it.
- The Eloquent builder of a model has `getStatement()` too. It applies the global scopes of the model.
- For raw SQL with a `FORMAT` clause, use the connection's [`selectStatement()`](/advanced/raw-sql#format-clauses).
