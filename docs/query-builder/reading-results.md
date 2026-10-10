# Read results

## Rows, collections and statements

| Method | Returns |
| --- | --- |
| `getRows()` | An array of rows. Each row is an associative array. |
| `getCollection()` | A Laravel collection of the rows |
| `get()` | The smi2 `Statement` |

```php
MyTable::select('id')->orderBy('id')->getCollection();
// collect([['id' => 1], ['id' => 2], ['id' => 3]])
```

The rows are arrays. Laravel's query builder returns `stdClass` objects. For `$row->id`, cast the row: `(object) $row`.

## First row and single values

```php
MyTable::query()->orderBy('created_at', 'desc')->first();
// SELECT * FROM `my_table` ORDER BY `created_at` DESC LIMIT 1
// ['id' => 3, 'created_at' => '2024-01-07 10:00:00', ...], or null without a row

MyTable::where('id', 2)->value('field_one');
// SELECT `field_one` FROM `my_table` WHERE `id` = 2 LIMIT 1

MyTable::query()->orderBy('id')->pluck('field_one', 'id');
// SELECT `field_one`, `id` FROM `my_table` ORDER BY `id` ASC
// collect([1 => 'a', 2 => 'b', 3 => 'c'])
```

- `first()` reads one row within the `LIMIT` and `OFFSET` of the query: `orderBy('id')->limit(10, 1)->first()` sends ``... ORDER BY `id` ASC LIMIT 1, 1``.
- On a query that selects all columns, `value()` and `pluck()` select only their columns. An alias, an expression and an `ALIAS` or `MATERIALIZED` column work.
- On a query with its own select list, they read each column by the name that ClickHouse gives it. A joined column keeps its table name, such as `users.name`.
- `value()` throws for a column that the query does not select: `The query does not select the column [field_one] that value() reads.`

## Check if rows exist

```php
MyTable::where('id', 123)->exists();      // true or false
MyTable::where('id', 123)->doesntExist();
// SELECT 1 FROM `my_table` WHERE `id` = 123 LIMIT 1
```

A query with expressions, aliases, `GROUP BY`, `HAVING`, `LIMIT BY`, a set operation or `orderByRaw()` is wrapped in a sub-query: `SELECT 1 FROM (...) LIMIT 1`.
Then the result agrees with the rows of the query.

## Count and aggregates

```php
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;

MyTable::where('field_two', '>', 0)->count();
// SELECT count() as `count` FROM `my_table` WHERE `field_two` > 0

MyTable::where('field_two', '>', 0)->max('field_two');
// SELECT max(`field_two`) AS `aggregate` FROM `my_table` WHERE `field_two` > 0

MyTable::query()->sum(raw('field_two * id'));
// SELECT sum(field_two * id) AS `aggregate` FROM `my_table`

MyTable::query()->aggregate('quantile(0.5)', ['field_two']);
// SELECT quantile(0.5)(`field_two`) AS `aggregate` FROM `my_table`
```

- `count()`, `min()`, `max()`, `sum()`, `avg()`, `average()` and `aggregate()` read the rows that the query returns. They ignore `format()`, `LIMIT` and `OFFSET`.
- Without rows, `sum()` returns `0` and `avg()` returns `null`. `min()` and `max()` return the default of the column type, such as `0` or `''`.
- `count()` returns an int on all ClickHouse versions.

::: warning
The aggregates quote a string column as a column name. `sum('field_two * id')` fails with `UNKNOWN_IDENTIFIER`.
Give SQL with `raw()`, `RawColumn` or `DB::raw()`. Give a name without backticks.
:::

`aggregate()` takes an aggregate function, such as `uniqExact`, or a parametric function with numbers, such as `quantile(0.5)`, `quantiles(0.5, 0.9)` or `topK(2)`.
Another name throws an `InvalidArgumentException`. Select such an aggregate with `RawColumn` and read it with `value()`:

```php
MyTable::select(new RawColumn("sequenceMatch('(?1)(?2)')(created_at, field_two > 0, field_two < 0)", 'matched'))
    ->value('matched');
// SELECT sequenceMatch('(?1)(?2)')(created_at, field_two > 0, field_two < 0) AS `matched` FROM `my_table` LIMIT 1
```

### Count in a sub-query

The package counts and aggregates some queries in a sub-query:

- A query with a set operation, `GROUP BY`, `HAVING` or `LIMIT BY`.
- A query that selects an alias, an expression, a `DISTINCT` column or a `withAlias()` name.
- A query with an `ORDER BY` entry that defines an alias or uses `WITH FILL` or `arrayJoin()`.
- A query with `LIMIT`, `OFFSET`, `FETCH`, `UNION`, `INTERSECT` or `EXCEPT` in the raw SQL of an `ORDER BY` entry.

```php
MyTable::select('field_one')->groupBy('field_one')->count();
// SELECT count() AS `count` FROM (SELECT `field_one` FROM `my_table` GROUP BY `field_one`)
```

In a sub-query, the aggregated column must be a column that the query returns.
`select(['field_one', new RawColumn('count()', 'c')])->groupBy('field_one')->max('id')` fails with `UNKNOWN_IDENTIFIER`.
Select the column, as in `select(['field_one', new RawColumn('max(id)', 'id')])`, or remove the select list.

A raw limit in an `ORDER BY` entry is a clause of the query, so the count includes it. For a table `lb` with the rows `(1, 'g1')`, `(2, 'g1')` and `(3, 'g2')`:

```php
$query = DB::connection('clickhouse')->table('lb')->select('id', 'grp')->orderByRaw('id DESC LIMIT 1 BY grp');

$query->count(); // 2
// SELECT count() AS `count` FROM (SELECT `id`, `grp` FROM `lb` ORDER BY id DESC LIMIT 1 BY grp)

$query->min('id'); // 2
// SELECT min(`id`) AS `aggregate` FROM (SELECT `id`, `grp` FROM `lb` ORDER BY id DESC LIMIT 1 BY grp)
```

### Settings that change the result

`exists()`, `count()` and `paginate()` do not support settings that change the result, such as `offset`, `limit` and `additional_result_filter`.
These settings act on the `SELECT 1` or `SELECT count()` that the methods send.

## Pagination

```php
MyTable::query()->orderBy('id')->paginate(15);
// SELECT count() as `count` FROM `my_table`
// SELECT * FROM `my_table` ORDER BY `id` ASC LIMIT 0, 15
```

- `paginate()` gets the total from `count()`. `simplePaginate()` reads one row more than a page and does not count.
- `getQueryForCount()`, `getQueryForExists()` and `getQueryForPage($perPage, $page)` return the queries of `count()`, `exists()` and `paginate()` without sending them.
- `paginate()`, `simplePaginate()`, `chunk()`, `first()` and `value()` add a `LIMIT` after the `ORDER BY`.
  ClickHouse refuses it after a raw `LIMIT`, `OFFSET` or `FETCH`. Read such a query as a sub-query:
  `DB::connection('clickhouse')->table($query)->orderBy('id', 'desc')->paginate()`.

## Chunks

```php
MyTable::select(['field_one', 'field_two'])
    ->orderBy('field_one')
    ->chunk(30, function (array $rows, int $page) {
        foreach ($rows as $row) {
            echo $row['field_two'] . "\n";
        }
    });
// SELECT `field_one`, `field_two` FROM `my_table` ORDER BY `field_one` ASC LIMIT 0, 30
// SELECT `field_one`, `field_two` FROM `my_table` ORDER BY `field_one` ASC LIMIT 30, 30
// ...
```

- The callback gets the rows of a page and the page number, which starts at 1.
- `chunk()` stops after a page with fewer rows, at an empty page, and when the callback returns `false`.
- The `LIMIT` and `OFFSET` of the query limit the rows: `limit(100)->chunk(30, ...)` reads pages of 30, 30, 30 and 10 rows.
- A size less than 1 throws `The chunk size should be at least 1, 0 given.`
- Without `ORDER BY`, ClickHouse returns rows in no fixed order, and pages can overlap. Order the query.

For a very large result, use the [`cursor()`](/advanced/raw-sql#read-rows-one-by-one) of the connection. It reads one row at a time.

## The builder does not change

`first()`, `value()`, `pluck()`, `chunk()`, `paginate()` and `simplePaginate()` read with a copy of the query.
The builder keeps its clauses. A `delete()` or `update()` on it after these calls changes the rows that the query selects.
