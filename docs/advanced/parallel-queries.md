# Parallel queries

## Run queries at the same time

`Parallel::getRows()` runs queries at the same time. It returns the rows of each query, with the keys and the order that you gave:

```php
use Oralunal\LaravelClickHouse\Parallel;
use Oralunal\LaravelClickHouse\RawColumn;

$results = Parallel::getRows([
    'clicks' => MyTable::where('field_one', 'click')->select(['id', 'field_two'])->orderBy('id'),
    'top' => MyTable::select(['field_one', new RawColumn('sum(field_two)', 'total')])
        ->groupBy('field_one')
        ->orderBy('field_one')
        ->settings('max_threads', 2),
    'other' => MyTable2::where('f_int', 1),
]);
// ['clicks' => [['id' => 1, 'field_two' => 10], ['id' => 3, 'field_two' => 30]],
//  'top' => [['field_one' => 'click', 'total' => '40'], ['field_one' => 'view', 'total' => '20']],
//  'other' => [['id' => 7, 'f_int' => 1]]]
```

- The requests go out together over one curl multi handle. A batch takes approximately the time of its slowest query.
- At most 10 requests run at the same time. Give a different number, 2 or more, as the second argument.
- The queries can use different connections and nodes. `MyTable2` is a model of the `clickhouse2` connection.

## Entries

| Entry | Sent as |
| --- | --- |
| A query of the package's query builder | `get()` sends it, on the node of its connection |
| A Laravel query builder on a ClickHouse connection | `select()` sends it. Its rows go through its `afterQuery()` callbacks. |
| SQL: `['sql' => 'SELECT count() AS c FROM my_table WHERE field_one = ?', 'bindings' => ['click'], 'connection' => 'clickhouse']` | `select()` sends it. `bindings` and `connection` are optional. The default connection is `clickhouse`. |

- An Eloquent builder throws an `InvalidArgumentException`. Give `$query->toBase()`. Its rows come back as arrays.
- The package compiles and checks each entry before it sends the batch. An incorrect entry stops the batch.

## Statements

`Parallel::get()` takes the same entries and returns the `ClickHouseDB\Statement` of each query.
Use it for `totals()`, `extremes()`, `countAll()` or `rawData()`. It does not throw for a failed query. The statement throws when you read its rows.

```php
$statements = Parallel::get(['ids' => MyTable::select('id')->orderBy('id')->format('CSV')]);
$statements['ids']->rawData(); // "1\n2\n3\n"
```

To send a query of the package's query builder yourself, use `getQueryToSend()` and `getQueryClient()`:

```php
$query = MyTable::select('id')->orderBy('id')->settings('max_threads', 2);
$sql = $query->getQueryToSend()->toSql();
// SELECT `id` FROM `my_table` ORDER BY `id` ASC FORMAT JSON SETTINGS max_threads=2
$query->getQueryClient()->select($sql)->rows(); // [['id' => 1], ['id' => 2]]
```

## Errors

When a query fails, `getRows()` waits for the other queries. Then it throws a `ParallelQueryException` with the results of the successful queries:

```php
use Oralunal\LaravelClickHouse\Exceptions\ParallelQueryException;

try {
    $results = Parallel::getRows([
        'clicks' => MyTable::where('field_one', 'click')->select(['id']),
        'missing' => DB::connection('clickhouse')->table('no_such_table'),
    ]);
} catch (ParallelQueryException $e) {
    $e->getResults();    // ['clicks' => [['id' => 1], ['id' => 3]]]
    $e->getErrors();     // ['missing' => a ClickHouseDB\Exception\DatabaseException with the code 60]
    $e->getStatements(); // The statement of each query
}
```

The message shows each error. `getPrevious()` is the first error:

```text
1 of 2 parallel queries failed:
[missing] ClickHouseDB\Exception\DatabaseException: Unknown table expression identifier 'no_such_table' in scope SELECT * FROM no_such_table. (UNKNOWN_TABLE)
IN:SELECT * FROM `no_such_table` FORMAT JSON
```

`ParallelQueryException` extends the package's `QueryException`. `catch (\ClickHouseDB\Exception\QueryException $e)` also catches it.

## One connection

`selectParallelly()` runs SQL on the active node of one connection. The bindings fill `?`, `:name` and `{name}` as in `select()`:

```php
DB::connection('clickhouse')->selectParallelly([
    'total' => 'SELECT count() AS c FROM my_table',
    'recent' => ['sql' => 'SELECT id FROM my_table WHERE field_two >= ? ORDER BY id', 'bindings' => [20]],
]);
// ['total' => [['c' => '3']], 'recent' => [['id' => 2], ['id' => 3]]]
```

## Count, check and read a page in one batch

```php
$query = MyTable::where('field_two', '>', 15)->orderBy('id');

Parallel::getRows([
    'total' => $query->getQueryForCount(),
    'exists' => $query->getQueryForExists(),
    'page' => $query->getQueryForPage(15, 1),
]);
// ['total' => [['count' => '2']], 'exists' => [['1' => 1]], 'page' => [['id' => 2, ...], ['id' => 3, ...]]]
```

ClickHouse 24.8 returns the count as a string. 25.8 and later return an int.

## Limits

- `SELECT` only. The requests have `readonly=2`, so an `INSERT` or an `ALTER` fails with `READONLY`.
- A failed request is sent again in a later round, as the `retries` and `retry_on` of its connection allow. There is no failover to another node.
- `timeout_query` applies to each request from when it starts. A batch with more queries than the concurrency can take longer than `timeout_query`.
- The `max_concurrent_queries` of ClickHouse limits the queries on a server. A query above it fails with `TOO_MANY_SIMULTANEOUS_QUERIES`.
- In a [session](/advanced/sessions), `Parallel` and `selectParallelly()` throw a `LogicException`.
- While a connection pretends, its Laravel query builders and SQL return no rows. A query of the package's query builder is sent.

## Deprecated methods

`asyncWithQuery()`, `getAsyncQueries()`, `toAsyncSqls()` and `toAsyncQueries()` are deprecated. `get()` did not run the queries of `asyncWithQuery()`.
Use `Parallel::get([$query, $otherQuery])`, or `Parallel::getRows($query->getAsyncQueries())`.
