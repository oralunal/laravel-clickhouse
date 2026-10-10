# Raw SQL

## Bindings

`select()`, `statement()`, `insert()`, `update()`, `delete()`, `affectingStatement()` and `cursor()` take Laravel's `?` placeholders.
The package writes each binding into the SQL as a ClickHouse literal and sends the query without bindings:

```php
use Illuminate\Support\Facades\DB;

$clickhouse = DB::connection('clickhouse');

$clickhouse->select('SELECT id FROM my_table WHERE field_one = ? AND created_at >= ?', ["it's", now()->subDay()]);
// SELECT id FROM my_table WHERE field_one = 'it\'s' AND created_at >= '2026-10-07 14:30:00'

$clickhouse->insert('INSERT INTO my_table (id, field_one, ratio) VALUES (?, ?, ?)', [1, 'a', 1 / 3]);
// INSERT INTO my_table (id, field_one, ratio) VALUES (1, 'a', 0.3333333333333333)
```

- A string is escaped. A date is written in its own time zone. `true` and `false` are `1` and `0`. A float keeps all digits. An array is an array literal. `DB::raw()` is written as SQL.
- A `?` in a string literal, a quoted name, a heredoc such as `$$...$$` or a comment is not a placeholder.
- Write a `?` that is not a placeholder, such as the ternary operator, as `??`:
  `$clickhouse->select('SELECT ? > 1 ?? 10 : 20 AS t', [5])` returns `[['t' => 10]]`.
  An `INSERT` without bindings is sent as written, so the rows after its `FORMAT` keep their `??`.
- A different number of placeholders and bindings throws an `InvalidArgumentException` before the request:
  `The query has 1 "?" placeholder, but 2 bindings were given. Write a literal "?" as "??".`
- The query log, `DB::listen()` and the `QueryExecuted` event get the SQL with `?` and the bindings.

### Named bindings

Named bindings keep the placeholders of the smi2 client: `:name`, `{name}`, and `:0` and `{0}` for a list.

- The smi2 client replaces them everywhere in the SQL, also in string literals and names.
- It writes `{name}` without quotes, a float with 14 significant digits, and a collection as JSON. Give `$ids->all()` to `IN (:ids)`.
- `{name:Type}` is a ClickHouse query parameter. The server fills it in.

Use `?` when you can. A query that has `?` and a `:0`, `{0}` or `{name:Type}` throws:
`The query mixes "?" placeholders with smi2 placeholders (:0, {0}) or query parameters ({name:Type}). Use "?" for every binding.`

## FORMAT clauses

`select()`, `scalar()`, `selectOne()`, `selectParallelly()` and Laravel's query builder read the rows of a JSON result.
A `FORMAT` clause with `JSON`, `JSONStrings`, `JSONCompact` or `JSONCompactStrings` returns rows.
Another format throws an `Oralunal\LaravelClickHouse\Exceptions\QueryException` before the request:

```text
Cannot read rows from a query whose FORMAT clause names CSV: select() and parallel queries read the rows
of a JSON result (JSON, JSONStrings, JSONCompact, JSONCompactStrings). Leave the FORMAT clause out, or
read the result in CSV with the package query builder, format('CSV')->get()->rawData(), or with the
smi2 client, getClient()->select($sql)->rawData().
```

The `FORMAT` clause is the word `FORMAT` and a name at the end of the query, before `SETTINGS` and `;`.
A column, alias or table with the name `format`, as in `ORDER BY format DESC` or `SELECT * FROM format(CSV, '1')`, is not a `FORMAT` clause.

## Read rows one by one

`cursor()` yields the rows of a `SELECT` one at a time, as associative arrays. Its memory stays flat:

```php
foreach ($clickhouse->cursor('SELECT id, field_one FROM my_table WHERE field_two > ? ORDER BY id', [0]) as $row) {
    // ['id' => 1, 'field_one' => 'click'], then ['id' => 2, 'field_one' => 'view'], ...
}
// SELECT id, field_one FROM my_table WHERE field_two > 0 ORDER BY id
// FORMAT JSONEachRow
```

- `cursor()` asks for `JSONEachRow` and downloads the result into `php://temp`. This stream keeps 2 MB in memory and moves to a temporary file above that.
- The query runs when you ask for the first row. The full download must end within `timeout_query`.
- On ClickHouse 24.8, two million rows used 2 MB more memory. `select()` used 193 MB for 200,000 of these rows.
- A query with its own `FORMAT` clause, `JSON` included, throws before the request.
- A ClickHouse error throws a `ClickHouseDB\Exception\DatabaseException` before the first row, also when the query fails after its first rows.
  A timeout or a refused connection throws a `ClickHouseDB\Exception\QueryException`.
- A string that is not valid UTF-8 is yielded as `select()` returns it, with U+FFFD for the invalid bytes.
- The query is logged one time. While the connection pretends, `cursor()` logs the query and yields no row.
- Laravel's `cursor()` and Eloquent's `cursor()` use it. `lazy()`, `lazyById()` and `chunk()` send one query for each page.
- In a [session](/advanced/sessions), read the rows before the callback returns.

## Statements and counts

| Method | Returns |
| --- | --- |
| `statement()`, `insert()` | `true` |
| `affectingStatement()` | For an `INSERT`, the `written_rows` that ClickHouse reports. For other statements, `1`. |
| `unprepared()` | `true`. It sends the SQL as written, `?` and `??` included. |
| `selectResultSets()` | Throws a `LogicException`. ClickHouse returns one result set for each query. |

`written_rows` is not always the number of inserted rows:

- A synchronous insert counts the rows that the materialized views of the table write. 3 rows into a table with one materialized view return `6`.
- An asynchronous insert does not count them. 24.8 reports `0`. 26.3 and 26.8 report the rows of the table when the query waits, and `0` when it does not.
- 26.3 and 26.8 insert asynchronously by default. `'settings' => ['async_insert' => 0]` gives the counts of 24.8.

## Escape values

`escape()` writes a value as a ClickHouse literal:

```php
$clickhouse->escape("it's");            // 'it\'s'
$clickhouse->escape(0.1 + 0.2);         // 0.30000000000000004
$clickhouse->escape([1, 'a', null]);    // [1, 'a', null]
$clickhouse->escape("\xff\x00", true);  // x'ff00'
```

A string with a NUL byte or invalid UTF-8 throws a `RuntimeException`, unless you escape it as binary.
`toRawSql()`, `dumpRawSql()`, `DB::getRawQueryLog()` and `QueryExecuted::toRawSql()` use `escape()` for the bindings.

## Errors

A query that ClickHouse refuses throws a `ClickHouseDB\Exception\DatabaseException`. Its code is the ClickHouse error code, such as `60` for `UNKNOWN_TABLE`.
Laravel's `QueryException` does not wrap it, and the query log does not show the query.

## Transactions

ClickHouse has no transactions. `beginTransaction()`, `transaction()` and `commit()` throw a `LogicException`:

```text
ClickHouse does not support transactions. In tests, use the DatabaseTruncation trait for ClickHouse connections and leave them out of $connectionsToTransact.
```

`transaction()` does not call its callback. `rollBack()` does nothing. `transactionLevel()` is `0`. See [Tests](/advanced/testing).

## Pretend mode

`DB::connection('clickhouse')->pretend()` and `php artisan migrate --pretend` send nothing. They log each statement with its bindings:

```php
$log = DB::connection('clickhouse')->pretend(function ($connection) {
    $connection->statement('ALTER TABLE my_table DELETE WHERE field_one = ?', ["it's"]);
    Schema::connection('clickhouse')->create('events', fn ($table) => $table->id());
});
// array_column($log, 'query'):
// ["ALTER TABLE my_table DELETE WHERE field_one = 'it\'s'",
//  'CREATE TABLE `events` (`id` Int64) ENGINE = MergeTree() ORDER BY (`id`)']
```

| Call | While the connection pretends |
| --- | --- |
| `select()`, `cursor()` | No rows. `Schema::hasTable()` is `false`. |
| `statement()`, `insert()`, `unprepared()` | `true` |
| `affectingStatement()`, `update()`, `delete()` | `0` |
| `Schema::create()`, `Schema::table()`, `Migration::write()`, `Migration::createMergeTree()` | Logged, not sent |
| Inserts, `truncate()`, `optimize()` of models; `insert()`, `delete()`, `update()`, `truncate()`, `insertFiles()` of the package's query builder | Logged, not sent. They return a statement whose `isError()` is `false`. |
| `buffer()` | Logs the insert at once and does not buffer the rows |
| `flushBuffer()`, `flushAllBuffers()` | Log the insert, send nothing and keep the buffered rows |
| Reads of the package's query builder, such as `get()` and `count()` | Sent, as in 3.x |
| `session()` | Sends the `SELECT 1` that opens the session |

```php
$log = DB::connection('clickhouse')->pretend(function ($connection) {
    $connection->table('my_table')->insert([['id' => 20, 'field_one' => 'p']]);
    $connection->table('my_table')->where('id', 1)->delete(false);
});
// array_column($log, 'query'):
// ["INSERT INTO `my_table` (`id`,`field_one`)  VALUES  (20,'p')",
//  'ALTER TABLE `my_table` DELETE WHERE `id` = 1']
```

- A JSONEachRow insert is logged without its rows. The package still checks the rows, so an incorrect row throws.
- The checks before a mutation, `EXPLAIN` and the lookup of temporary tables, are not sent.
- `Schema::dropAllTables()` and `Schema::dropAllViews()` send no `DROP`, because the list of tables is empty.
- `change()` and `dropIndex()` in `Schema::table()` read the server while they compile, also while the connection pretends.
- The log writes each `??` as `?`.
