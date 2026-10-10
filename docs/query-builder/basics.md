# Query builder basics

The package has its own query builder, `Oralunal\LaravelClickHouse\Builder`. It writes ClickHouse SQL.
To use Laravel's query builder instead, see [Laravel's query builder](/query-builder/laravel-query-builder).

## Start a query

```php
use Illuminate\Support\Facades\DB;

MyTable::query();                                   // The table of the model
MyTable::select(['id', 'field_one']);
MyTable::where('id', 1);
DB::connection('clickhouse')->table('my_table');    // A table of the connection
```

`DB::connection('clickhouse')->table()` returns the package's query builder when `fix_default_query_builder` is `true`, which is the default.

A table name with a dot is a database and a table: `table('analytics.events')` gives ``FROM `analytics`.`events` ``.

## Select columns

```php
use Oralunal\LaravelClickHouse\RawColumn;
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;

$query->select(['id', 'field_one']);
// SELECT `id`, `field_one` FROM `my_table`

$query->select('field_one as name');
// SELECT `field_one` AS `name` FROM `my_table`

$query->select(new RawColumn('count()', 'total'));
// SELECT count() AS `total` FROM `my_table`

$query->select(raw('uniqExact(user_id) AS users'));
// SELECT uniqExact(user_id) AS users FROM `my_table`
```

The package quotes a string column as a name. Use `RawColumn`, `raw()` or `DB::raw()` for SQL.

## Group, order and limit

```php
$query->select(['field_one', new RawColumn('count()', 'c')])->groupBy('field_one')->having('c', '>', 1);
// SELECT `field_one`, count() AS `c` FROM `my_table` GROUP BY `field_one` HAVING `c` > 1

$query->orderBy('created_at', 'desc');
// SELECT * FROM `my_table` ORDER BY `created_at` DESC

$query->orderByRaw('field_two DESC, id');
// SELECT * FROM `my_table` ORDER BY field_two DESC, id

$query->latest('created_at');
// SELECT * FROM `my_table` ORDER BY `created_at` DESC

$query->inRandomOrder();
// SELECT * FROM `my_table` ORDER BY rand()

$query->limit(10);
// SELECT * FROM `my_table` LIMIT 10

$query->limit(10, 20); // limit, offset
// SELECT * FROM `my_table` LIMIT 20, 10

$query->orderBy('id', 'desc')->limitBy(1, 'grp');
// SELECT * FROM `my_table` ORDER BY `id` DESC LIMIT 1 BY `grp`
```

## Conditional clauses

`when()` and `unless()` add clauses only when a value is true or false, as in Laravel:

```php
$query->when($onlyActive, fn ($query) => $query->where('a', 1));
// SELECT * FROM `my_table` WHERE `a` = 1
```

## Settings

`settings()` adds a [`SETTINGS` clause](https://clickhouse.com/docs/en/sql-reference/statements/select#settings-in-select-query). Give an array, or a name and a value:

```php
MyTable::select()
    ->settings(['max_threads' => 3, 'optimize_move_to_prewhere' => false])
    ->settings('log_comment', "it's the monthly report")
    ->getRows();
// SELECT * FROM `my_table` FORMAT JSON
//   SETTINGS max_threads=3, optimize_move_to_prewhere=0, log_comment='it\'s the monthly report'
```

- Each call adds to the settings of the calls before it. A later value replaces an earlier value. `null` removes a setting. An empty array changes nothing.
- Booleans become `1` and `0`. Strings become escaped string literals. A backed enum becomes its value. A `Stringable` object, such as a Carbon date, becomes a string literal.
- For a list, a map or other SQL, give an expression:

  ```php
  MyTable::select()
      ->settings('additional_table_filters', new RawColumn("{'my_table': 'field_two > 0'}"))
      ->getRows();
  ```

- A setting name has letters, digits and underscores, and does not start with a digit. Another name throws an `InvalidArgumentException`.
- Settings go with `SELECT` queries only, `exists()` and `count()` included. `delete()` and `update()` throw for a query with settings. Put the settings of a mutation in the connection `settings`.
- A query with settings and without `format()` is sent with `FORMAT JSON` before `SETTINGS`. `toSql()` does not show `FORMAT JSON`.

## Output format

`format()` sets the `FORMAT` clause. `get()` returns the smi2 statement. Its `rawData()` holds the output:

```php
MyTable::select('id')->orderBy('id')->format('jsoneachrow')->get()->rawData();
// SELECT `id` FROM `my_table` ORDER BY `id` ASC FORMAT JSONEachRow
// Returns "{\"id\":1}\n{\"id\":2}\n"
```

- `format()` takes a format name in any letter case. A name that is not in `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format`, such as `'jsonl'`, throws an `UnexpectedValueException`.
- `rows()` and `getRows()` need `JSON`, the format of a query without `format()`. `rows()` throws ``Can`t find meta`` for `CSV`, `XML`, `JSONEachRow` and `JSONCompactEachRow`.
- `count()`, `exists()` and the aggregates ignore `format()`. `paginate()`, `chunk()`, `first()`, `value()` and `pluck()` read rows, so they need `JSON`.

## Show the SQL

`toSql()` returns the SQL of the query without sending it:

```php
MyTable::where('field_two', '>', 0)->toSql();
// SELECT * FROM `my_table` WHERE `field_two` > 0
```

## Next steps

- [Conditions](/query-builder/conditions)
- [ClickHouse SQL](/query-builder/clickhouse-sql): `PREWHERE`, `SAMPLE`, `FINAL`, set operations, `WITH`, joins and `ARRAY JOIN`
- [Read results](/query-builder/reading-results)
- [Updates and deletions](/query-builder/writing-data)
