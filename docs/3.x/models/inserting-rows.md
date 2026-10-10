# Insert rows

## One row

```php
$model = MyTable::create(['model_name' => 'model 1', 'some_param' => 1]);

$model = MyTable::make(['model_name' => 'model 1']);
$model->some_param = 1;
$model->save();

$model = new MyTable();
$model->fill(['model_name' => 'model 1', 'some_param' => 1])->save();
```

## Many rows

```php
// Rows without keys, and a list of columns
MyTable::insertBulk([['model 1', 1], ['model 2', 2]], ['model_name', 'some_param']);

// Rows with column => value pairs
MyTable::insertAssoc([
    ['model_name' => 'model 1', 'some_param' => 1],
    ['some_param' => 2, 'model_name' => 'model 2'],
]);
```

## Arrays

For an `Array` column, give an `Oralunal\LaravelClickHouse\Expressions\InsertArray`:

```php
use Oralunal\LaravelClickHouse\Expressions\InsertArray;

MyTable::insertAssoc([
    ['id' => 1, 'field_one' => 'str', 'field_array' => new InsertArray(['a', 'b'])],
]);

MyTable::insertBulk([[1, 'str', new InsertArray(['a', 'b'])]], ['id', 'field_one', 'field_array']);
```

## Buffer engine tables

For a table with the [Buffer engine](https://clickhouse.com/docs/en/engines/table-engines/special/buffer/), set `$tableForInserts`:

```php
class MyTable extends BaseModel
{
    protected $table = 'my_table';                  // SELECT reads this table.
    protected $tableForInserts = 'my_table_buffer'; // Inserts write to this table.
}
```

To also read from the buffer table, set `$table` to the buffer table.
`OPTIMIZE`, `TRUNCATE`, `update()` and `delete()` use `$tableSources`. See [Updates and deletions](/3.x/query-builder/writing-data#buffer-engine-tables).

## Buffer in PHP memory

`buffer()` keeps rows in the memory of the PHP process. `flushBuffer()` sends them in one HTTP request.
This buffer is not the `Buffer` engine of ClickHouse. You do not need a table for it.

```php
MyTable::buffer(['model_name' => 'model 1', 'some_param' => 1]);
MyTable::buffer(['model_name' => 'model 2', 'some_param' => 2]);

MyTable::flushBuffer(); // One INSERT
```

`buffer()` takes one row with column keys, or a list of such rows:

```php
MyTable::buffer([
    ['model_name' => 'model 1', 'some_param' => 1],
    ['model_name' => 'model 2', 'some_param' => 2],
]);
```

- `buffer()` applies the casts of `insertAssoc()` when it adds a row.
- At the end of the script, the package sends the rows of each buffer. It uses the `terminating()` hook of Laravel, and a `register_shutdown_function()` for scripts outside HTTP. It reports an error of this step with `report()` and does not throw it.
- When `flushBuffer()` fails, it throws the exception and keeps the rows. You can try again:

  ```php
  try {
      MyTable::flushBuffer();
  } catch (\Throwable $e) {
      // The rows are still in MyTable::getBufferedRows().
  }
  ```

- Each model class has its own buffer.

| Method | Does |
| --- | --- |
| `MyTable::buffer($rowOrRows)` | Adds a row or rows to the buffer |
| `MyTable::flushBuffer()` | Sends the buffer. It returns a `Statement`, or `null` for an empty buffer. |
| `MyTable::bufferCount()` | Returns the number of rows in the buffer of the model |
| `MyTable::getBufferedRows()` | Returns the rows in the buffer |
| `MyTable::clearBuffer()` | Removes the rows and sends nothing |
| `BaseModel::flushAllBuffers(silent: false)` | Sends the buffers of all models. With `silent: true`, it reports an error with `report()` and does not throw it. |
