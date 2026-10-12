# Insert rows

## Insert rows

`insert()` sends many rows in one `INSERT`. It is Laravel's `insert()`:

```php
Event::insert([
    ['id' => 'e1', 'type' => 'click', 'amount' => 10],
    ['type' => 'view', 'id' => 'e2', 'amount' => 20],
]);
// insert into "events" ("amount", "id", "type") values (10, 'e1', 'click'), (20, 'e2', 'view')

DB::connection('clickhouse')->table('events')->insert(['id' => 'e3', 'type' => 'view', 'amount' => 5]);
```

- The rows go as given. Casts, mutators, timestamps and model events do not apply. To apply them, use `create()`.
- Laravel takes the columns from the keys of the first row, in alphabetical order, and writes the values of each row
  in the order of its own keys. Each row must have the keys of the first row, in any order.
  A row with other keys throws a `QueryException` before the package sends the request, in both formats:
  `Cannot insert the rows as Values: the row at index 1 lacks the keys [amount]. ...`
  Without this check, `[['id' => 'e1'], ['type' => 'view']]` would store `view` in the column `id`.
- `insert([])` sends nothing and returns `true`, as in Laravel. A row without keys, such as `[[]]`, throws
  `Inserting empty values array is not supported in ClickHouse` before the package sends the request.
- The package quotes each column name and doubles the quotes in it, so a name cannot close the column list. As in
  Laravel, a dot in a name is a qualifier and the word `as` an alias. Do not take column names from user input.
- `insertGetId()` needs the key in the row, because ClickHouse has no auto-increment.

### One row

`create()` and `save()` insert one row, with the casts, mutators, timestamps and events of the model:

```php
Event::create(['id' => (string) Str::uuid(), 'type' => 'click', 'payload' => ['a' => 1]]);
```

See [Define an Eloquent model](/models/defining-models#create-one-row).

### Arrays

A PHP array is written as an array literal. A collection is written as its items:

```php
Event::insert([['id' => 'e1', 'tags' => ['a', "it's"]]]);
// insert into "events" ("id", "tags") values ('e1', ['a', 'it\'s'])
```

The package writes floats with all digits, and `NAN`, `INF` and `-INF` as `nan`, `inf` and `-inf`.

::: warning
A `Decimal` column cuts a calculated float at its scale. `4.35 * 100` is `434.99999999999994` in PHP and stores `434.99`.
Round the value first: `round(4.35 * 100, 2)` stores `435`.
:::

## Buffered inserts

`buffer()` keeps rows in PHP memory. `flushBuffer()` sends them in one `INSERT`:

```php
Event::buffer(['id' => 'e1', 'type' => 'click']);
Event::buffer([
    ['id' => 'e2', 'type' => 'view'],
    ['type' => 'view', 'id' => 'e3'],
]);

Event::flushBuffer(); // One INSERT
```

| Method | Description |
| --- | --- |
| `Event::buffer($rowOrRows)` | Adds a row or a list of rows to the buffer of the model |
| `Event::flushBuffer()` | Sends the buffer in the connection's `insert_format`. Returns `true`, or `false` for an empty buffer. |
| `Event::bufferCount()` | Returns the number of buffered rows |
| `Event::getBufferedRows()` | Returns the buffered rows |
| `Event::clearBuffer()` | Removes the rows without a request |
| `Model::flushAllBuffers(silent: false)` | Sends the buffers of all models |

- The rows are inserted as given, as `insert()` inserts them. Casts and mutators do not apply.
- An array with an integer key is a list of rows, also with keys such as those of `array_filter()` or rows keyed by an
  id. An array with only string keys is one row.
- All rows of a buffer must have the same keys, in any order. `buffer()` refuses a row with other keys, or without keys,
  and buffers no row of that call:

  ```php
  Event::buffer(['id' => 'e1', 'type' => 'click']);
  Event::buffer(['id' => 'e2']);
  // Throws Oralunal\LaravelClickHouse\Exceptions\QueryException: Cannot buffer the rows of the model
  // [App\Models\Event]: the row at index 0 has the keys [id], and the buffer needs the keys [id, type].
  // ... Nothing was buffered.
  ```

- At the end of the request or the command, the package sends all buffers. It uses Laravel's `terminating()` hook
  and `register_shutdown_function()`. It reports an error with `report()` and does not throw it.
- If `flushBuffer()` fails, it throws and keeps the rows. You can send them again:

  ```php
  try {
      Event::flushBuffer();
  } catch (\Throwable $e) {
      // The rows are in Event::getBufferedRows(). Correct the problem and try again.
  }
  ```

- Each model class has its own buffer. With `silent: true`, `flushAllBuffers()` reports a failed model and continues
  with the next one. A failed model keeps its rows.
- While the connection [pretends](/advanced/raw-sql#pretend-mode), `buffer()` logs the `INSERT` at once and keeps no row.
  `flushBuffer()` logs the `INSERT`, sends nothing and keeps the rows that you buffered before, so the first flush
  after `pretend()` sends them.

## Buffer engine tables

For a table with the [Buffer engine](https://clickhouse.com/docs/en/engines/table-engines/special/buffer/),
insert into the Buffer table and read from the table behind it. Use the query builder, or a second model:

```php
DB::connection('clickhouse')->table('events_buffer')->insert($rows);

class EventBuffer extends Event
{
    protected $table = 'events_buffer'; // Inserts and buffer() write to the Buffer table.
}

EventBuffer::buffer($row);
```

## JSONEachRow inserts

The inserts send their rows in the `Values` format by default. To send one JSON object for each row, set the
`insert_format` option, or give the format to `insert()`:

```dotenv
CLICKHOUSE_INSERT_FORMAT=JSONEachRow
```

```php
use Oralunal\LaravelClickHouse\Enums\Format;

Event::query()->insert([['id' => 'e1', 'amount' => 1], ['id' => 'e2', 'amount' => 2]], 'JSONEachRow');
// insert into "events" ("id", "amount") format JSONEachRow
// {"id":"e1","amount":1}
// {"id":"e2","amount":2}

DB::connection('clickhouse')->table('events')->insert($rows, Format::JsonEachRow);
```

- The second argument of `insert()` takes `Format::Values` or `Format::JsonEachRow`, or `'Values'` or `'JSONEachRow'`
  in any letter case. Another format throws `insert() takes the format 'Values' or 'JSONEachRow', [CSV] given.`
  Without it, the `insert_format` option of the connection applies.
- `create()`, `save()` and `flushBuffer()` use the `insert_format` option. `Model::insert()` uses it when you give no format.
- `insert_format` takes `Values` or `JSONEachRow`, in any letter case, or a `Format` case. `null` and `''` are `Values`.
  Another value throws an `InvalidArgumentException`.
- Each row must have the keys of the first row, as in `Values`. A row with other keys throws before the package sends
  the request: `Cannot insert the rows as JSONEachRow: the row at index 1 lacks the keys [amount]. ...`
- The `INSERT` goes in the URL and the rows in the body. An error message shows the `INSERT` and some bytes near the
  incorrect value, not all of the data.

ClickHouse reads some values differently in the two formats (24.8 checked):

| Value | `Values` | `JSONEachRow` |
| --- | --- | --- |
| A float, `NAN`, `INF` | All digits; `nan`, `inf` | The same |
| An array with keys, such as `['x' => 1]`, into a `Map` column | Fails with `TYPE_MISMATCH` | `{'x':1}` |
| An empty `Map` | `[]` | `new \stdClass()`. `[]` fails. |
| A list, such as `[1, 'a']`, into a `Tuple(UInt8, String)` column | Fails with `NO_COMMON_TYPE` | `(1, 'a')` |
| A date with a time, such as a Carbon, into a `Date` column | The date | Fails. Pass `$date->format('Y-m-d')`. |
| A float with a fraction, such as `2.5`, into an integer column | Cut: `2` | Fails. Use `(int)` or `round()`. |
| A string that is not valid UTF-8 | Stored | An `InvalidArgumentException` before the request |
| Raw SQL, such as `DB::raw("upper('x')")` | The SQL runs | An `InvalidArgumentException` before the request |

In `JSONEachRow`, a whole float, such as `2.0`, is written as `2`. In `Values` it is `2.0`. Keep binary strings, such as `md5($value, true)`, in `Values`.

## File inserts

`insertFiles()` inserts the rows of files in an input format. It sends one `INSERT` for each file:

```php
$statements = DB::connection('clickhouse')->table('events')
    ->insertFiles(['/data/part-1.csv', '/data/part-2.csv'], 'CSVWithNames', ['id', 'type']);
// INSERT INTO "events" ( "id","type" ) FORMAT CSVWithNames, once for each file

$statements['/data/part-1.csv']->summary('written_rows'); // '2'

Event::insertFiles('/data/part-3.json', Format::JsonEachRow, ['id', 'type']);
```

- Formats: `TabSeparated`, `TabSeparatedWithNames`, `CSV` (default), `CSVWithNames`, `JSONEachRow`,
  `CSVWithNamesAndTypes` and `TSVWithNamesAndTypes`, as a `Format` case or a name in any letter case.
  Another format throws an `InvalidArgumentException`.
- Each path must be a readable file. If not, the package sends no file:
  `Cannot insert the file [/data/part-3.csv]: it is not a readable file. No file was sent.`
- The files go as separate inserts. A failed file does not undo the others. The client throws for the first failed
  file after it sends all files.
- Each file must be inserted within `timeout_query`. Increase it for large files. `retries` does not apply.
- In a [session](/advanced/sessions), `insertFiles()` throws a `QueryException` before it sends a file.
- While the connection pretends, `insertFiles()` logs each `INSERT` and sends no file.

## Pretend

While the connection [pretends](/advanced/raw-sql#pretend-mode), as `DB::pretend()` and `php artisan migrate --pretend`
do, each insert is logged and not sent. The checks that run before the request still throw, in both formats: a row
with other keys or without keys, a JSON path as a column, and, in `JSONEachRow`, raw SQL or a string that is not valid
UTF-8.
