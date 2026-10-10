# Query builder basics

## The smi2 client

`getClient()` returns the smi2 client of the connection:

```php
use Illuminate\Support\Facades\DB;

/** @var \ClickHouseDB\Client $db */
$db = DB::connection('clickhouse')->getClient();
$statement = $db->select('SELECT * FROM summing_url_views LIMIT 2');
```

See the [smi2/phpClickHouse documentation](https://github.com/smi2/phpClickHouse/blob/master/README.md).

## Start a query

```php
use PhpClickHouseLaravel\RawColumn;

$rows = MyTable::select(['field_one', new RawColumn('sum(field_two)', 'field_two_sum')])
    ->where('created_at', '>', '2020-09-14 12:47:29')
    ->groupBy('field_one')
    ->settings(['max_threads' => 3])
    ->getRows();
// SELECT `field_one`, sum(field_two) AS `field_two_sum` FROM `my_table`
//   WHERE `created_at` > '2020-09-14 12:47:29' GROUP BY `field_one` SETTINGS max_threads=3
```

- `MyTable::select()`, `MyTable::where()` and `MyTable::whereRaw()` start a query on the table of the model.
- `DB::connection('clickhouse')->table('my_table')` starts a query on a table. It returns the package's query builder when `fix_default_query_builder` is `true`, the default.
- `toSql()` returns the SQL of the query and does not send it.
- `settings()` takes an array and adds a `SETTINGS` clause.

## Read the rows

| Method | Returns |
| --- | --- |
| `get()` | The smi2 `Statement`. `rows()` of the statement returns the rows. |
| `getRows()` | The rows as arrays |
| `count()` | The number of rows |
| `paginate()`, `simplePaginate()` | A page of Laravel's paginator |
| `chunk($count, $callback)` | Calls the callback with each group of `$count` rows |

```php
MyTable::select(['field_one', 'field_two'])
    ->chunk(30, function ($rows) {
        foreach ($rows as $row) {
            echo $row['field_two'] . "\n";
        }
    });
```

## Clauses

The query builder of 2.x has these methods:

| Clause | Methods |
| --- | --- |
| `SELECT` | `select()`, `addSelect()`, `addSelectDict()` |
| `FROM` | `from()`, `table()`, `as()`, `alias()`, `final()`, `sample()` |
| `JOIN` | `join()`, `leftJoin()`, `innerJoin()`, `anyLeftJoin()`, `allLeftJoin()`, `anyInnerJoin()`, `allInnerJoin()`, `arrayJoin()`, `leftArrayJoin()` |
| `PREWHERE` | `preWhere()`, `preWhereIn()`, `preWhereNotIn()`, `preWhereBetween()`, `preWhereNotBetween()`, `preWhereBetweenColumns()`, `preWhereNotBetweenColumns()`, `preWhereRaw()`, and their `or` forms |
| `WHERE` | `where()`, `whereIn()`, `whereNotIn()`, `whereGlobalIn()`, `whereGlobalNotIn()`, `whereBetween()`, `whereNotBetween()`, `whereBetweenColumns()`, `whereRaw()`, `whereDict()`, and their `or` forms |
| `GROUP BY`, `HAVING` | `groupBy()`, `addGroupBy()`, `having()`, `havingIn()`, `havingNotIn()`, `havingBetween()`, `havingNotBetween()`, `havingBetweenColumns()`, `havingRaw()`, and their `or` forms |
| `ORDER BY`, `LIMIT` | `orderBy()`, `orderByAsc()`, `orderByDesc()`, `orderByRaw()`, `limit()`, `take()`, `limitBy()`, `takeBy()` |
| Other | `unionAll()`, `format()`, `onCluster()`, `cloneWithout()`, `toSql()` |

```php
$query->final()->preWhere('event_date', '=', '2024-01-05')->where('user_id', '=', 7);
// SELECT * FROM `my_table` FINAL PREWHERE `event_date` = '2024-01-05' WHERE `user_id` = 7

$query->whereIn('id', [1, 2])->orWhere('field_two', '>', 10);
// SELECT * FROM `my_table` WHERE `id` IN (1, 2) OR `field_two` > 10

$query->orderBy('id', 'desc')->limit(10, 20);
// SELECT * FROM `my_table` ORDER BY `id` DESC LIMIT 20, 10

$query->select('id')->anyLeftJoin('users', ['user_id']);
// SELECT `id` FROM `my_table` ANY LEFT JOIN `users` USING `user_id`
```

- Write SQL with `new RawColumn('sum(x)', 'alias')` or `raw()`. Import `raw()` with `use function PhpClickHouseLaravel\ClickhouseBuilder\raw;`.
- Give the operator in capital letters, such as `'LIKE'`. The builder of 2.x refuses operators in lower case. 4.0 accepts both.
- `format()` accepts only format names in capital letters, such as `JSON`, `CSV` and `TSV`. `format('JSONEachRow')` throws an `UnexpectedValueException`.

See [Known limitations](/2.x/reference/known-limitations) for the problems of the 2.x query builder.
