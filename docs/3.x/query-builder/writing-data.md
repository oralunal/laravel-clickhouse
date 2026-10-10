# Updates and deletions

## Deletions

`delete()` sends an [`ALTER TABLE ... DELETE`](https://clickhouse.com/docs/en/sql-reference/statements/alter/delete/) mutation:

```php
MyTable::where('field_one', 123)->delete();
```

## Updates

`update()` sends an [`ALTER TABLE ... UPDATE`](https://clickhouse.com/docs/en/sql-reference/statements/alter/update/) mutation. A `RawColumn` value is SQL:

```php
use Oralunal\LaravelClickHouse\RawColumn;

MyTable::where('field_one', 123)->update(['field_two' => 'new_val']);

MyTable::where('field_one', 123)
    ->update(['field_two' => new RawColumn("concat(field_two, 'new_val')")]);
```

::: danger
`delete()` and `update()` of 3.x use only the `where` conditions of the query. They ignore `preWhere()`, `orderBy()`, `limit()`, joins and the other clauses, so they can change more rows than the query selects.
Without a `where` condition, they send a statement that ClickHouse refuses. 4.0 refuses such queries before it sends them.
:::

## OPTIMIZE

[`OPTIMIZE TABLE`](https://clickhouse.com/docs/en/sql-reference/statements/optimize/) starts a merge of the parts of the table:

```php
MyTable::optimize($final = false, $partition = null);
```

3.x writes the partition into the SQL without escapes. Do not give user input as the partition.

## TRUNCATE

`truncate()` removes all rows of the table:

```php
MyTable::truncate();
```

## Buffer engine tables

`OPTIMIZE`, `TRUNCATE`, `update()` and `delete()` use `$tableSources`. Without it, they use `$table`:

```php
class MyTable extends BaseModel
{
    // SELECT and INSERT
    protected $table = 'my_table_buffer';
    // OPTIMIZE, TRUNCATE, update() and delete()
    protected $tableSources = 'my_table';
}
```
