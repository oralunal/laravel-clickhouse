# Parallel queries

## Run queries at the same time

`Parallel::getRows()` runs queries at the same time. It returns the rows of each query, with the keys and the order that you gave:

```php
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\Parallel;

$table = fn () => DB::connection('clickhouse')->table('my_table');

$results = Parallel::getRows([
    'clicks' => $table()->where('field_one', 'click')->select(['id', 'field_two'])->orderBy('id'),
    'top' => $table()->select(['field_one', DB::raw('sum(field_two) as total')])
        ->groupBy('field_one')
        ->orderBy('field_one')
        ->settings('max_threads', 2),
    'sql' => ['sql' => 'SELECT count() AS c FROM my_table WHERE field_one = ?', 'bindings' => ['click']],
]);
// ['clicks' => [['id' => 1, 'field_two' => 10], ['id' => 3, 'field_two' => 30]],
//  'top' => [['field_one' => 'click', 'total' => '40'], ['field_one' => 'view', 'total' => '20']],
//  'sql' => [['c' => '2']]]
```

- The requests go out together over one curl multi handle. A batch takes approximately the time of its slowest query.
- At most 10 requests run at the same time. Give a different number, 2 or more, as the second argument.
- The queries can use different connections and nodes.
- ClickHouse 24.8 returns a `UInt64`, such as a count, as a string. 25.8 and later return a number.

## Entries

| Entry | Sent as |
| --- | --- |
| A query builder of a ClickHouse connection | `select()` sends it. Its rows go through its `afterQuery()` callbacks. |
| An Eloquent builder of a model of a ClickHouse connection | Its `toBase()`. `getRows()` returns an Eloquent collection of its models. |
| SQL: `['sql' => 'SELECT ...', 'bindings' => [...], 'connection' => 'clickhouse']` | `select()` sends it. `bindings` and `connection` are optional. The default connection is `clickhouse`. `connection` is a name or a connection. |

- For an Eloquent builder, the package loads the eager loads, `with()`, after the batch, one query after the other. See [Eloquent models](/models/eloquent#parallel-queries).
- The package compiles and checks each entry before it sends the batch. An incorrect entry stops the batch, and nothing is sent.
- Another entry, such as a query builder of a MySQL connection, throws an `InvalidArgumentException`.
- Before the batch, the connection of each entry calls its `beforeExecuting()` callbacks.
  `Connection::runBeforeExecutingCallbacks($sql, $bindings)` calls them.
- After the batch, the connection logs each successful query, and `DB::listen()` gets a `QueryExecuted` event. A failed query is not logged.

## Statements

`Parallel::get()` takes the same entries and returns the smi2 `ClickHouseDB\Statement` of each query.
Use it for `totals()`, `extremes()` or `statistics()`. It does not throw for a failed query. The statement throws when you read its rows.

```php
$statements = Parallel::get(['ids' => $table()->select('id')->orderBy('id')]);
$statements['ids']->rows();  // [['id' => 1], ['id' => 2], ['id' => 3]]
$statements['ids']->count(); // 3
```

The results are JSON. For another format, run the query on its own with [`getStatement()`](/query-builder/reading-results#other-formats).

## Errors

When a query fails, `getRows()` waits for the other queries. Then it throws a `ParallelQueryException` with the results of the successful queries:

```php
use Oralunal\LaravelClickHouse\Exceptions\ParallelQueryException;

try {
    $results = Parallel::getRows([
        'clicks' => $table()->where('field_one', 'click')->select(['id']),
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
IN:select * from "no_such_table" FORMAT JSON
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

A `connection` key in an entry is ignored: each query runs on this connection.

## Count, check and read a page in one batch

```php
$query = fn () => $table()->where('field_two', '>', 15);

Parallel::getRows([
    'total' => $query()->selectRaw('count() as total'),
    'exists' => $query()->selectRaw('1 as found')->limit(1),
    'page' => $query()->orderBy('id')->forPage(1, 15),
]);
// ['total' => [['total' => '2']], 'exists' => [['found' => 1]], 'page' => [['id' => 2, ...], ['id' => 3, ...]]]
```

`count()`, `exists()` and `paginate()` send their queries one after the other. In a batch, select the count and the page yourself.
For a query that `count()` counts in a sub-query, such as a grouped query, count a sub-query: `DB::connection('clickhouse')->query()->fromSub($query(), 'q')->selectRaw('count() as total')`.
Pass the query, not the closure: `fromSub()` calls a closure with a new query and ignores what it returns.
See [Which rows count() counts](/query-builder/reading-results#which-rows-count-counts).

## Limits

- `SELECT` only. The requests have `readonly=2`, so an `INSERT` or an `ALTER` fails with `READONLY`.
- A failed request is sent again in a later round, as the `retries` and `retry_on` of its connection allow. There is no failover to another node.
- `timeout_query` applies to each request from when it starts. A batch with more queries than the concurrency can take longer than `timeout_query`.
- The `max_concurrent_queries` of ClickHouse limits the queries on a server. A query above it fails with `TOO_MANY_SIMULTANEOUS_QUERIES`.
- In a [session](/advanced/sessions), `Parallel` and `selectParallelly()` throw a `LogicException`.
- While a connection pretends, its queries are logged and not sent, and their rows are empty.
