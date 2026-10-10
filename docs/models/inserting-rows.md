# Insert rows

## Insert many rows

`insertAssoc()` takes rows with keys. `insertBulk()` takes rows with positions and a list of columns:

```php
MyTable::insertAssoc([
    ['model_name' => 'model 1', 'some_param' => 1],
    ['some_param' => 2, 'model_name' => 'model 2'],
]);

MyTable::insertBulk([['model 1', 1], ['model 2', 2]], ['model_name', 'some_param']);
```

- Each row of `insertAssoc()` must have the keys of the first row, in any order.
  A row with other keys throws `Fields not match: model_name and model_name,some_param on element 1`, and the package inserts no row.
- An empty list, or an empty row, throws `Inserting empty values array is not supported in ClickHouse` before the package sends the request.
- The package quotes each column name as one identifier. A column name cannot add SQL to the statement.

## Prepare rows before the insert

`prepareAndInsertAssoc()` and `prepareAndInsertBulk()` call a method of the model for each row, then insert the rows.
Override the method to change the rows:

| Method | Calls | Then |
| --- | --- | --- |
| `prepareAndInsertAssoc($rows)` | `prepareAssocFromRequest($row)` | `insertAssoc()` |
| `prepareAndInsertBulk($rows, $columns)` | `prepareFromRequest($row, $columns)` | `insertBulk()` |

`prepareAndInsert()` is deprecated. Use `prepareAndInsertBulk()`.

## Arrays

Write an `Array` column value with `InsertArray`:

```php
use Oralunal\LaravelClickHouse\Expressions\InsertArray;

MyTable::insertAssoc([
    ['id' => 1, 'field_one' => 'str', 'field_array' => new InsertArray(['a', 'b'])],
]);

MyTable::insertBulk([[1, 'str', new InsertArray(['a', 'b'])]], ['id', 'field_one', 'field_array']);
```

| Type | Items |
| --- | --- |
| `InsertArray::TYPE_STRING` (default), `TYPE_STRING_ESCAPE` | Strings. The package escapes backslashes and single quotes: `new InsertArray(["O'Brien", 'C:\temp'])` stores `O'Brien` and `C:\temp`. |
| `InsertArray::TYPE_INT` | Integers |
| `InsertArray::TYPE_DECIMAL` | Floats with all digits: `new InsertArray([1 / 3, 2], InsertArray::TYPE_DECIMAL)` writes `[0.3333333333333333,2.0]`. `NAN`, `INF` and `-INF` are `nan`, `inf` and `-inf`. |

::: warning
An `Array(Decimal(10, 2))` column cuts a calculated float at its scale. `4.35 * 100` is `434.99999999999994` in PHP and stores `434.99`.
Round the value first: `round(4.35 * 100, 2)` stores `435`.
:::

## Buffered inserts

`buffer()` keeps rows in PHP memory. `flushBuffer()` sends them in one `INSERT` request:

```php
MyTable::buffer(['model_name' => 'model 1', 'some_param' => 1]);
MyTable::buffer([
    ['model_name' => 'model 2', 'some_param' => 2],
    ['model_name' => 'model 3', 'some_param' => 3],
]);

MyTable::flushBuffer(); // One INSERT request
```

| Method | Description |
| --- | --- |
| `MyTable::buffer($rowOrRows)` | Adds a row or a list of rows to the buffer |
| `MyTable::flushBuffer()` | Sends the buffer. Returns a `Statement`, or `null` for an empty buffer. |
| `MyTable::bufferCount()` | Returns the number of buffered rows |
| `MyTable::getBufferedRows()` | Returns a copy of the buffered rows |
| `MyTable::clearBuffer()` | Removes the rows without a request |
| `BaseModel::flushAllBuffers(silent: false)` | Sends the buffers of all models |

- `buffer()` applies the casts of `insertAssoc()`.
- All rows of a buffer must have the same keys, in any order. `buffer()` refuses a row with other keys and buffers no row of that call:

  ```php
  MyTable::buffer(['model_name' => 'model 1', 'some_param' => 1]);
  MyTable::buffer(['model_name' => 'model 2']);
  // Throws Oralunal\LaravelClickHouse\Exceptions\QueryException: Cannot buffer the rows of the model
  // [App\Models\Clickhouse\MyTable]: the row at index 0 has the keys [model_name], and the rows already
  // buffered [model_name, some_param]. ... Nothing was buffered.
  ```

- At the end of the script, the package sends all buffers. It uses Laravel's `terminating()` hook and `register_shutdown_function()`.
  It reports an error with `report()` and does not throw it.
- If `flushBuffer()` fails, it throws and keeps the rows. You can send them again:

  ```php
  try {
      MyTable::flushBuffer();
  } catch (\Throwable $e) {
      // The rows are in MyTable::getBufferedRows(). Correct the problem and try again.
  }
  ```

- Each model class has its own buffer. With `silent: true`, `flushAllBuffers()` reports a failed model and continues with the next one. A failed model keeps its rows.
- While the connection [pretends](/advanced/raw-sql#pretend-mode), `buffer()` logs each row at once and keeps no row. `flushBuffer()` logs the `INSERT`, sends nothing and keeps the rows that you buffered before.

## Buffer engine tables

For a table with the [Buffer engine](https://clickhouse.com/docs/en/engines/table-engines/special/buffer/), set `$tableForInserts`:

```php
class MyTable extends BaseModel
{
    protected $table = 'my_table';               // SELECT reads this table.
    protected $tableForInserts = 'my_table_buffer'; // Inserts write to this table.
}
```

To also read from the buffer table, set `$table` to the buffer table.
`delete()`, `update()`, `truncate()` and `optimize()` of the model use `$tableSources`. Without it, they use `$table`.

## JSONEachRow inserts

The inserts send their rows in the `Values` format by default. To send one JSON object for each row, set the `insert_format` option or the `$insertFormat` property:

```dotenv
CLICKHOUSE_INSERT_FORMAT=JSONEachRow
```

```php
class MyTable extends BaseModel
{
    protected $insertFormat = 'JSONEachRow';
}

MyTable::insertAssoc([['model_name' => 'model 1', 'some_param' => 1], ['model_name' => 'model 2', 'some_param' => 2]]);
// INSERT INTO `my_table` (`model_name`, `some_param`) FORMAT JSONEachRow
// {"model_name":"model 1","some_param":1}
// {"model_name":"model 2","some_param":2}

DB::connection('clickhouse')->table('my_table')->insert($rows, 'JSONEachRow');
```

- `$insertFormat` applies to `insertAssoc()`, `insertBulk()`, the `prepareAndInsert` methods, `create()`, `save()` and `flushBuffer()`.
  Without it, the `insert_format` option applies.
- Rows with positions, from `insertBulk()` and `prepareAndInsertBulk()`, are sent as `FORMAT JSONCompactEachRow`.
- The query builder's `insert()` takes the format as the second argument: `'Values'`, `'JSONEachRow'` or `Format::JSON_EACH_ROW`.
  Another format throws `insert() takes the format 'Values' or 'JSONEachRow', [CSV] given.`
- The `insert()` of the package's query builder returns a statement. `summary('written_rows')` gives the number of rows, such as `'2'`.
- `insert_format` and `$insertFormat` take `Values` or `JSONEachRow`, in any letter case. `null` and `''` are `Values`. Another value throws an `InvalidArgumentException`.
- Each row must have the keys of the first row. A row with other keys throws before the package sends the request:
  `Cannot insert the rows as JSONEachRow: the row at index 1 lacks the keys [some_param]. ...`
- The `INSERT` goes in the URL and the rows in the body. An error message shows the `INSERT` and some bytes near the incorrect value, not all of the data.

ClickHouse reads some values differently in the two formats (24.8 checked):

| Value | `Values` | `JSONEachRow` |
| --- | --- | --- |
| A float, `NAN`, `INF` | All digits; `nan`, `inf` | The same |
| `true` into a `UInt8` column | Fails: `Cannot parse string 'true' as UInt8` | `1` |
| An array with keys, such as `['x' => 1]`, into a `Map` column | Fails with `TYPE_MISMATCH` | `{'x':1}` |
| An empty `Map` | `[]` | `new \stdClass()`. `[]` fails. |
| A list, such as `[1, 'a']`, into a `Tuple(UInt8, String)` column | Fails with `NO_COMMON_TYPE` | `(1, 'a')` |
| A date with a time, such as a Carbon, into a `Date` column | The date | Fails. Pass `$date->format('Y-m-d')`. |
| A float with a fraction, such as `2.5`, into an integer column | Cut: `2` | Fails. Use `(int)` or `round()`. |
| A string that is not valid UTF-8 | Stored | An `InvalidArgumentException` before the request |
| Raw SQL, such as `DB::raw("upper('x')")` | The SQL runs | An `InvalidArgumentException` before the request |
| A pure enum | An `UnsupportedValueType` exception | Its name |
| An `InsertArray` | Its SQL | Its items |
| A `Stringable` object with a public `value` property | Its `value` | Its string |

A whole float, such as `2.0`, is written as `2`. Keep binary strings, such as `md5($value, true)`, in `Values`.

## File inserts

`insertFiles()` inserts the rows of files in an input format. It sends one `INSERT` for each file:

```php
$statements = DB::connection('clickhouse')->table('my_table')
    ->insertFiles(['/data/part-1.csv', '/data/part-2.csv'], 'CSVWithNames', ['id', 'field_one']);
// INSERT INTO `my_table` ( `id`,`field_one` ) FORMAT CSVWithNames, once for each file

$statements['/data/part-1.csv']->summary('written_rows'); // '2'
```

- Formats: `TabSeparated`, `TabSeparatedWithNames`, `CSV` (default), `CSVWithNames`, `JSONEachRow`, `CSVWithNamesAndTypes` and `TSVWithNamesAndTypes`.
  Another format throws an `InvalidArgumentException`.
- Each path must be a readable file. If not, the package sends no file:
  `Cannot insert the file [/data/part-3.csv]: it is not a readable file. No file was sent.`
- The files go as separate inserts. A failed file does not undo the others. The client throws for the first failed file after it sends all files.
- Each file must be inserted within `timeout_query`. Increase it for large files. `retries` does not apply.
- In a [session](/advanced/sessions), `insertFiles()` throws a `QueryException` before it sends a file.
- While the connection pretends, `insertFiles()` logs each `INSERT` and sends no file.
- `MyTable::query()->insertFiles(...)` inserts into `$tableSources`, not `$tableForInserts`.
