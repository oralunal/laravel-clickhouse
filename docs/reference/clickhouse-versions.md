# ClickHouse versions

The package is tested on ClickHouse 24.8, 26.3 and 26.8. It sends the same SQL to all versions.
But the defaults of ClickHouse changed after 24.8, and some results changed with them.
The examples in this documentation show the results of 24.8.

This page lists the differences of 26.3 and 26.8, and the connection `settings` or server config that give the behavior of 24.8.

## 64-bit integers

24.8 returns `UInt64` and `Int64` values, such as `count()`, as strings: `'3'`.
From 25.8, `output_format_json_quote_64bit_integers` is `0`, so they are JSON numbers. PHP reads them as ints, or as floats above `PHP_INT_MAX`, which loses digits:
`toUInt64('18446744073709551615')` gives `1.8446744073709552E+19`.

To get strings on all versions:

```php
'settings' => ['output_format_json_quote_64bit_integers' => 1],
```

Or for one query: `->settings('output_format_json_quote_64bit_integers', 1)`. The query builder's `count()` returns an int on all versions.

## Asynchronous inserts

26.3 and 26.8 insert asynchronously by default (`async_insert = 1`, `wait_for_async_insert = 1`). Each `INSERT` waits until the server flushes its buffer.

- The row count of `affectingStatement()` and `insertUsing()` changes. See [Raw SQL](/advanced/raw-sql#statements-and-counts).
- On 26.3, a replicated table checks an asynchronous insert against `replicated_deduplication_window_for_async_inserts`. The schema builder sets it to `0`. See [Identical inserts](/advanced/clusters#identical-inserts).
- `'settings' => ['async_insert' => 0]` inserts synchronously, as 24.8 does.
- The migration repository and the load of a schema dump always insert synchronously.

## Mutations on a server with little RAM

When the server config does not set `background_pool_size`, 26.8 makes it equal to the RAM in GiB, for example 3 for 3.8 GiB. 24.8 and 26.3 keep 16.
Then 26.8 delays a mutation while the pool has too few free threads.

A statement that waits for its mutation on a small `MergeTree` table then takes a few hundred milliseconds, and sometimes more than 2 seconds.
24.8 and 26.3 usually take less than 20 ms. This applies to a lightweight `DELETE`, a mutation with `mutations_sync`, and the delete of a rollback.

With the packaged `timeout_query` of 2 seconds, `migrate:rollback`, `migrate:refresh` and `migrate:reset` can fail with
`Operation timed out after 2001 milliseconds with 0 bytes received`. The server still deletes the row.

- Increase `timeout_query` for migration commands, or
- set `<background_pool_size>16</background_pool_size>` in the server config.

On a replicated table, these statements usually take some tens of milliseconds on all three versions.

## Dates with a sub-second part

At the microsecond `datetime_precision`, an insert writes a date with a sub-second part as a string, such as `'2024-01-02 03:04:05.500000'`.
26.8 parses it with `date_time_input_format` and `cast_string_to_date_time_mode` set to `best_effort`. So a `DateTime` column removes the sub-second part. 24.8 and 26.3 refuse it.

- On 26.8, `date_time_input_format` set to `basic` refuses it in JSONEachRow inserts. A `Values` insert is refused when `cast_string_to_date_time_mode` is `basic` too.
- A condition or an update writes the date as `toDateTime64('2024-01-02 03:04:05.500000', 6)`, so all versions accept it. It does not equal a value of a `DateTime` column, which has no sub-second part.
- 24.8 does not have `cast_string_to_date_time_mode`. A connection whose `settings` have it fails on 24.8 with `UNKNOWN_SETTING`. Set it only on 26.x connections.

See [Dates and times](/query-builder/dates).

## change() to a type without NULL

26.3 and 26.8 refuse to make a `nullable()` column a type without `NULL`, unless the new definition has a default:

```php
$table->string('note')->default('')->change();
```

Without a default, they fail with ``Please specify `DEFAULT` expression in ALTER MODIFY COLUMN statement``.

## Sorting keys

26.8 prints a sorting key of one column, ``ORDER BY (`id`)``, with its parentheses: `ORDER BY (id)` in `SHOW CREATE TABLE` and `schema:dump`, and `(id)` in `system.tables`.
24.8 and 26.3 print `ORDER BY id`. The table is the same. `Schema::getIndexes()` gives `['id']` on all versions.

## Correlated sub-queries

26.3 and 26.8 run a `SELECT` whose sub-query reads a column of the outer query. 24.8 refuses it.
They refuse a mutation with such a condition at once. See [Deletions](/query-builder/writing-data#conditions-that-clickhouse-cannot-run).

## INTERSECT and EXCEPT

24.8 compares only the columns that the outer query reads in an `INTERSECT` or `EXCEPT` sub-query, so `count()` and `exists()` of such a query got other rows than `get()`.
From 25.8, a query that reads only some columns gets the correct rows.
The package adds `where not ignore(*)` to `count()`, the other aggregates, `exists()` and `whereExists()` of such a query, so that ClickHouse reads all columns on all versions. This has no effect on the result.

## EXISTS with arrayJoin()

With the analyzer, 26.8 answers `EXISTS` of a query that selects `arrayJoin()` of an empty array with `1`, although the query returns no row.
The package selects from such a query in `exists()` and `whereExists()`, with `where not ignore(*)`, which gives the correct answer on all versions.

## Lightweight delete with a set operation

26.8 runs a lightweight delete with `UNION`, `INTERSECT` or `EXCEPT` in its condition. 24.8 and 26.3 fail it and keep a mutation that stops the later mutations.
The package refuses it on all versions.

## Floats

24.8 and 26.3 read some floats of an `INSERT` with an error of 1 or 2 units in the last place.
In the test samples, this was between one fifth and one third of random doubles.
26.8 reads them exactly. `precise_float_parsing = 1` does not change this on 24.8 or 26.3.
`where('ratio', 1 / 3)` finds the row that an insert of `1 / 3` stored, on all three versions.
