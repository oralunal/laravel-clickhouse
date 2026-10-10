# Known limitations

## The default query builder

Laravel's `Illuminate\Database\Query\Builder` numbered the parameters of `whereIn()` and `whereBetween()` incorrectly on ClickHouse.
See [glushkovds/phpclickhouse-laravel#34](https://github.com/glushkovds/phpclickhouse-laravel/pull/34).

`fix_default_query_builder` is `true` in the packaged config, so `DB::connection('clickhouse')->table()` returns `Oralunal\LaravelClickHouse\Builder`.
If you define the connection in `config/database.php`, add the option:

```php
'clickhouse' => [
    'driver' => 'clickhouse',
    // Your other options
    'fix_default_query_builder' => true,
],
```

With this option, `get()` returns an smi2 `Statement`, not a collection. Read the rows with `rows()`:

```php
$rows = DB::table('my-table')
    // ...
    ->get()   // Statement
    ->rows(); // array
```

The package's query builder has methods for ClickHouse SQL that Laravel's builder does not have. It comes from `the-tinderbox/ClickhouseBuilder`, through the `glushkovds` and `oralunal` forks.

## Problems that 4.0 fixes

4.0 fixes these problems of 3.0.0. See the [4.0 changelog](https://github.com/oralunal/laravel-clickhouse/blob/master/CHANGELOG.md) for the full list, and [Upgrade to 4.x](/getting-started/upgrading).

| Problem in 3.x | Example |
| --- | --- |
| `delete()` and `update()` use only the `where` conditions. They can change more rows than the query selects. | `MyTable::where('a', '<', 0)->orderBy('id')->limit(2)->delete()` deletes all rows with a negative `a`. |
| `delete()` and `update()` without a `where` condition send SQL that ClickHouse refuses. | `ALTER TABLE my_table DELETE WHERE ` |
| A condition with a date, a boolean, `null`, an enum or a `Stringable` value sends incomplete SQL. | `where('active', true)` gives ``WHERE `active` =``. Give `1`, or a date string. |
| Operators and booleans in lower case throw an `UnexpectedValueException`. | `where('a', 'like', 'x%')`. Give `'LIKE'`. |
| `final(false)` writes `FINAL`. | `MyTable::select()->final(false)` |
| `format()` refuses format names with lower-case letters. | `format('JSONEachRow')` |
| `pretend()` and `php artisan migrate --pretend` run the statements of ClickHouse connections. | `migrate --pretend` creates the tables. |
| `?` placeholders do not work in `select()`, `statement()` and `insert()` of the connection. | `INSERT INTO t VALUES (?, ?)` fails with a syntax error. |
| A `SELECT` with the word `format` and a format name in a value returns no rows or fails. | `where('note', 'export as format csv')` |
| The package does not escape column names of inserts and `update()`, the strings of `InsertArray`, the partition of `optimize()` and the names of `addSelectDict()`. | Do not give user input to them. |
| `migrate:rollback` fails when the `migrations` table is on ClickHouse. `migrate:refresh` can skip a migration. | See [Migration commands](/3.x/schema/migration-commands#clickhouse-is-the-primary-connection). |
| `slideNode()` does not reach the nodes after a node that is down. | See [Clusters](/3.x/advanced/clusters#change-the-active-node). |
| A retry can run a write more than one time. | See [Timeouts and retries](/3.x/advanced/timeouts-and-retries#retries). |
