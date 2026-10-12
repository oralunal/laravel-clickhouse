# Query builder basics

The query builder of a ClickHouse connection is `Oralunal\LaravelClickHouse\QueryBuilder`.
It is Laravel's query builder, a subclass of `Illuminate\Database\Query\Builder`, with the ClickHouse clauses of [ClickHouse SQL](/query-builder/clickhouse-sql).
Laravel's methods and macros work on it. Its grammar writes ClickHouse SQL.

## Start a query

```php
use Illuminate\Support\Facades\DB;

DB::connection('clickhouse')->table('my_table');   // A table of the connection
DB::connection('clickhouse')->query();             // A query without a table
DB::table('my_table');                             // When clickhouse is the default connection
MyTable::query();                                  // The Eloquent builder of a model
```

The Eloquent builder of a model uses this query builder. See [Eloquent models](/models/eloquent).

A table name with a dot is a database and a table: `table('analytics.events')` gives `select * from "analytics"."events"`.

### Table alias and FINAL

`table()` and `from()` take the table, an alias and `FINAL`:

```php
DB::connection('clickhouse')->table('events', 'e', true);
// select * from "events" as "e" final

DB::connection('clickhouse')->query()->from('events', 'e', true);
// select * from "events" as "e" final

DB::connection('clickhouse')->table('events as e');
// select * from "events" as "e"
```

For `final()`, see [ClickHouse SQL](/query-builder/clickhouse-sql#prewhere-and-final).

### Sub-queries and table functions

`fromSub()` reads a sub-query. `fromRaw()` writes SQL, such as a table function. Its `?` bindings become literals:

```php
$clickhouse = DB::connection('clickhouse');

$clickhouse->query()
    ->fromSub($clickhouse->table('my_table')->select('user_id')->groupBy('user_id'), 'u')
    ->selectRaw('count() as users');
// select count() as users from (select "user_id" from "my_table" group by "user_id") as "u"

$clickhouse->query()->fromRaw('numbers(10)');
// select * from numbers(10)

$clickhouse->query()->fromRaw('remote(?, ?, ?, ?, ?)', ['ch-2:9000', 'analytics', 'events', 'reader', 'secret']);
// select * from remote('ch-2:9000', 'analytics', 'events', 'reader', 'secret')

$clickhouse->query()->fromRaw('merge(?, ?)', ['analytics', '^events_\d+$']);
// select * from merge('analytics', '^events_\\d+$')
```

- `remote()` takes one address or a list, for example `'ch-1:9000,ch-2:9000'`.
- Give the values of a table function as bindings, not in the SQL string. Do not put user input into the SQL of `fromRaw()`.

## Select columns

```php
$query = DB::connection('clickhouse')->table('my_table');

$query->select(['id', 'field_one']);
// select "id", "field_one" from "my_table"

$query->select('field_one as name');
// select "field_one" as "name" from "my_table"

$query->selectRaw('count() as total');
// select count() as total from "my_table"

$query->select(DB::raw('uniqExact(user_id) as users'));
// select uniqExact(user_id) as users from "my_table"
```

The grammar quotes a string column as a name. Use `selectRaw()` or `DB::raw()` for SQL.

`addSelect()` adds columns to the select list. `selectSub()`, or a builder with a string key, selects a scalar sub-query:

```php
$query->select('id')->addSelect('field_one', 'field_two');
// select "id", "field_one", "field_two" from "my_table"

$query->select('id')->selectSub(DB::connection('clickhouse')->table('events')->selectRaw('count()'), 'events');
// select "id", (select count() from "events") as "events" from "my_table"
```

### Dictionaries

Read an attribute of a [dictionary](https://clickhouse.com/docs/en/sql-reference/dictionaries) with `dictGet()` in `selectRaw()`:

```php
$query->select('id')->selectRaw('dictGet(?, ?, user_id) as user_name', ['users_dict', 'name']);
// select "id", dictGet('users_dict', 'name', user_id) as user_name from "my_table"
```

The bindings are string literals. Write the key, here the column `user_id`, as SQL.

## Group, order and limit

```php
$query->select('field_one', DB::raw('count() as c'))->groupBy('field_one')->having('c', '>', 1);
// select "field_one", count() as c from "my_table" group by "field_one" having "c" > 1

$query->orderBy('created_at', 'desc');
// select * from "my_table" order by "created_at" desc

$query->orderByRaw('field_two DESC, id');
// select * from "my_table" order by field_two DESC, id

$query->latest();
// select * from "my_table" order by "created_at" desc

$query->inRandomOrder();
// select * from "my_table" order by rand()

$query->limit(10)->offset(20);
// select * from "my_table" limit 10 offset 20

$query->orderBy('id')->forPage(3, 15);
// select * from "my_table" order by "id" asc limit 15 offset 30

$query->orderBy('id', 'desc')->limitBy(1, 'grp');
// select * from "my_table" order by "id" desc limit 1 by "grp"
```

- `latest()` and `oldest()` order by `created_at`. Both take another column.
- `inRandomOrder()` takes no seed. See [Random order](/query-builder/conditions#random-order).
- For `limitBy()`, see [LIMIT BY](/query-builder/clickhouse-sql#limit-by). For `HAVING` conditions, see [Conditions](/query-builder/conditions#where-prewhere-and-having).

## Bindings and the SQL of a query

The grammar writes `?` for each value. The connection writes the values into the SQL as ClickHouse literals before it sends the query.
`toSql()` shows the placeholders. `toRawSql()` shows the SQL that the package sends:

```php
$query = DB::connection('clickhouse')->table('my_table')->where('field_one', "it's")->where('ratio', 0.1 + 0.2);

$query->toSql();    // select * from "my_table" where "field_one" = ? and "ratio" = ?
$query->toRawSql(); // select * from "my_table" where "field_one" = 'it\'s' and "ratio" = 0.30000000000000004
```

- The query log, `DB::listen()` and the `QueryExecuted` event get the SQL with `?` and the bindings. `QueryExecuted::toRawSql()` writes the literals.
- `whereRaw()`, `selectRaw()`, `orderByRaw()`, `havingRaw()`, `groupByRaw()` and `fromRaw()` take `?` too.
- For the literal of each PHP value, see [Values](/query-builder/conditions#values).

## Conditional clauses

`when()` and `unless()` add clauses only when a value is true or false:

```php
$query->when($onlyActive, fn ($query) => $query->where('a', 1));
// select * from "my_table" where "a" = 1
```

## Settings

`settings()` adds a [`SETTINGS` clause](https://clickhouse.com/docs/en/sql-reference/statements/select#settings-in-select-query). Give an array, or a name and a value:

```php
DB::connection('clickhouse')->table('my_table')
    ->settings(['max_threads' => 3, 'optimize_move_to_prewhere' => false])
    ->settings('log_comment', "it's the monthly report")
    ->get();
// select * from "my_table" settings max_threads=3, optimize_move_to_prewhere=0, log_comment='it\'s the monthly report'
```

- Each call adds to the settings of the calls before it. A later value of a name replaces the earlier value. `null` removes a setting.
- Booleans become `1` and `0`. Strings become escaped string literals. A backed enum becomes its value. Another `Stringable` object, such as a Carbon date, becomes a string literal.
- For a list, a map or other SQL, give `DB::raw()`:

  ```php
  $query->settings('additional_table_filters', DB::raw("{'my_table': 'field_two > 0'}"));
  // select * from "my_table" settings additional_table_filters={'my_table': 'field_two > 0'}
  ```

- A setting name has letters, digits and underscores, and does not start with a digit. Another name throws an `InvalidArgumentException`.
- The settings go on the outer query. A union is selected from as a sub-query first, because ClickHouse refuses `SETTINGS` after the last query of a union in parentheses.
- `timeout($seconds)`, `forceIndex($index)` and `ignoreIndex($index)` add their settings before the others:
  `timeout(5)` gives `settings max_execution_time = 5`, and `forceIndex('idx_url')` gives `settings force_data_skipping_indices = 'idx_url'`.
- `delete()` and `update()` throw for a query with settings. Put the settings of a mutation in the connection `settings`.

## Other output formats

`get()` reads the rows of a JSON result. For the output of another format, such as `CSV`, `XML` or `Pretty`, use `getStatement()`. It returns the smi2 `Statement`:

```php
DB::connection('clickhouse')->table('my_table')->select('id')->orderBy('id')->getStatement('JSONEachRow')->rawData();
// select "id" from "my_table" order by "id" asc format JSONEachRow
// "{\"id\":1}\n{\"id\":2}\n"
```

See [Other formats](/query-builder/reading-results#other-formats).

## Next steps

- [Conditions](/query-builder/conditions)
- [ClickHouse SQL](/query-builder/clickhouse-sql): `PREWHERE`, `SAMPLE`, `FINAL`, joins, `ARRAY JOIN`, `LIMIT BY`, `WITH` and set operations
- [Read results](/query-builder/reading-results)
- [Updates and deletions](/query-builder/writing-data)
- [Laravel's query builder](/query-builder/laravel-query-builder): what works on ClickHouse, and what does not
