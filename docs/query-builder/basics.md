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

`new Builder()` makes a builder on the `clickhouse` connection. `new Builder($client)` uses an smi2 client and follows no connection.
`followConnectionOptions($connection)` makes such a builder use the `datetime_precision` and the grammar of a connection.
`newQuery()` returns an empty builder on the same client and connection.

### Table alias and FINAL

`from()` and `table()` take the table, an alias and `FINAL`:

```php
$query->from('events', 'e', true);
// SELECT * FROM `events` AS `e` FINAL

DB::connection('clickhouse')->table('events')->as('e');
// SELECT * FROM `events` AS `e`
```

`alias()` is the same as `as()`. For `final()`, see [ClickHouse SQL](/query-builder/clickhouse-sql#prewhere-and-final).

### Sub-queries and table functions

`from()` takes a query builder or a closure. The closure gets an `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\From`:

```php
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\From;
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;

DB::connection('clickhouse')->table(MyTable::select('user_id')->groupBy('user_id'), 'u')->select(raw('count()'));
// SELECT count() FROM (SELECT `user_id` FROM `my_table` GROUP BY `user_id`) AS `u`

$query->from(fn (From $from) => $from->query(fn ($query) => $query->select('user_id')->from('events'))->as('u'));
// SELECT * FROM (SELECT `user_id` FROM `events`) AS `u`

$query->from(fn (From $from) => $from->merge('analytics', '^events_\d+$'));
// SELECT * FROM merge(analytics, '^events_\\d+$')

$query->from(fn (From $from) => $from->remote('ch-2:9000', 'analytics', 'events', 'reader', 'secret'));
// SELECT * FROM remote('ch-2:9000', analytics, events, 'reader', 'secret')

$query->from(raw('numbers(10)'));
// SELECT * FROM numbers(10)
```

| `From` method | Sets |
| --- | --- |
| `table($table)` | The table. A string is a name. `raw()` is SQL. |
| `as($alias)` | The alias |
| `final($isFinal = true)` | `FINAL` |
| `query($query)` | A sub-query from a builder or a closure |
| `subQuery()` | A sub-query. It returns a new builder for the sub-query. |
| `merge($database, $regexp)` | The `merge()` table function: all tables of the database that match the regular expression |
| `remote($addresses, $database, $table, $user = null, $password = null)` | The `remote()` table function: a table of other servers, without a `Distributed` table |

- The package writes the regular expression, the addresses, the user and the password as escaped string literals.
  It writes the database and the table of `merge()` and `remote()` as given.
- `remote()` takes one address or a list, for example `'ch-1:9000,ch-2:9000'`.
- For other table functions, give the SQL with `raw()`. Do not put user input into it.

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

`addSelect()` adds columns to the select list. In an array, a string key is a column, and its value is the alias. For a builder value, the key is the alias of the sub-query:

```php
$query->select('id')->addSelect('field_one', 'field_two');
// SELECT `id`, `field_one`, `field_two` FROM `my_table`

$query->select(['id', 'field_one' => 'name']);
// SELECT `id`, `field_one` AS `name` FROM `my_table`

$query->select(['id', 'events' => DB::connection('clickhouse')->table('events')->select(raw('count()'))]);
// SELECT `id`, (SELECT count() FROM `events`) AS `events` FROM `my_table`
```

### Column expressions

A closure in `select()` gets an `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Column`. A string key of the array is the column:

```php
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Column;

$query->select(fn (Column $column) => $column->sum('amount')->as('total'));
// SELECT sum(`amount`) AS `total` FROM `my_table`

$query->select(['price' => fn (Column $column) => $column->multiple(1.2)->plus(1)->round(2)->as('gross')]);
// SELECT round(`price` * 1.2 + 1, 2) AS `gross` FROM `my_table`

$query->select(fn (Column $column) => $column->name('amount')->sumIf("event = 'click'")->as('clicks'));
// SELECT sumIf(`amount`, event = 'click') AS `clicks` FROM `my_table`
```

| `Column` method | SQL |
| --- | --- |
| `name($column)` | The column. A string is a name. `raw()` is SQL. |
| `as($alias)`, `alias($alias)` | `` AS `alias` `` |
| `sum($column = null)`, `max($column = null)` | `sum(<column>)`, `max(<column>)`. The argument sets the column. |
| `plus($value)`, `multiple($value)` | `<column> + value`, `<column> * value` |
| `round($decimals = 0)` | `round(<column>, decimals)` |
| `runningDifference()` | `runningDifference(<column>)` |
| `sumIf($condition)` | `sumIf(<column>, condition)`. The condition is SQL. The package joins an array with spaces. |
| `distinct()` | `DISTINCT <column>` |
| `count()` | `count()`. It replaces the functions before it. |
| `query($query)`, `subQuery()` | `` (<sub-query>) AS `alias` ``. `subQuery()` returns a new builder for the sub-query. |

The functions apply in the order of the calls. The values of `plus()`, `multiple()` and `sumIf()` are SQL. Do not put user input into them.

### Dictionaries

`addSelectDict($dict, $attribute, $key, $as = null)` selects a `String` attribute of a [dictionary](https://clickhouse.com/docs/en/sql-reference/dictionaries) with `dictGetString()`:

```php
$query->select('id')->addSelectDict('users_dict', 'name', raw('user_id'), 'user_name');
// SELECT `id`, dictGetString('users_dict', 'name', user_id) as `user_name` FROM `my_table`

$query->whereDict('users_dict', 'country', raw('user_id'), 'DE');
// SELECT dictGetString('users_dict', 'country', user_id) as `country` FROM `my_table` WHERE `country` = 'DE'
```

- The key is a value. A string is a string literal: `'user_id'` finds the key `user_id`, not the value of the column. For a column, give `raw('user_id')`.
- An array is a key of many parts: `[raw('country'), raw('zip')]` gives `tuple(country, zip)`.
- The alias is the attribute name when you do not give one.
- `whereDict()` and `orWhereDict()` select the attribute and add a condition on its alias. They take the operator and the value as `where()` does.
- `dictGetString()` reads only `String` attributes. For another type, write the SQL: `select(raw("dictGet('users_dict', 'age', user_id) AS age"))`.

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

- `addGroupBy()` adds columns to the `GROUP BY` clause.
- `orderBy()`, `orderByAsc()` and `orderByDesc()` take a collation: `orderBy('name', 'asc', 'tr')` gives ``ORDER BY `name` ASC COLLATE 'tr'``.
- `latest()` orders by `created_at` descending, and `oldest()` ascending. Both take another column.
- `take()` is the same as `limit()`, and `takeBy()` as `limitBy()`. `limitBy(2, 'user_id', 'event')` gives ``LIMIT 2 BY `user_id`, `event` ``.
- For `HAVING` conditions, see [Conditions](/query-builder/conditions#where-prewhere-and-having).

## Copy a query

`clone $query` copies a query. `cloneWithout()` copies it and replaces some of its parts:

```php
$page = MyTable::where('a', 1)->orderBy('id')->limit(5);

$page->cloneWithout(['orders' => [], 'limit' => null]);
// SELECT * FROM `my_table` WHERE `a` = 1
```

The keys are the names of the parts: `columns`, `from`, `joins`, `arrayJoin`, `prewheres`, `wheres`, `groups`, `havings`, `orders`, `limit`, `limitBy`, `unions`, `withs`, `sample` and `format`.

## Examine a query

These methods return the parts of a query: `getColumns()`, `getFrom()`, `getJoins()`, `getArrayJoin()`, `getPreWheres()`, `getWheres()`, `getGroups()`, `getHavings()`, `getOrders()`, `getLimit()`, `getLimitBy()`, `getUnions()`, `getUnionTypes()`, `getWiths()`, `getSample()`, `getSampleOffset()`, `getFormat()`, `getSettings()` and `getOnCluster()`.
`getCountQuery()` returns the query with ``count() as `count` `` as its only column and without `LIMIT`. For the query that `count()` sends, use `getQueryForCount()`. `getGrammar()` returns the grammar that writes the SQL.

The parts have their own getters:

| Part | Getters |
| --- | --- |
| `getFrom()` | `getTable()`, `getAlias()`, `getFinal()`, `getSubQuery()`, `getQueryBuilder()` |
| `getColumns()`, each a `Column` | `getColumnName()`, `getAlias()`, `getFunctions()`, `getSubQuery()` |
| `getJoins()`, each a `JoinClause` | See [Join closures](/query-builder/clickhouse-sql#join-closures). |
| `getArrayJoin()` | `getArrays()`, `getArrayIdentifier()`, `getType()` |
| `getLimit()`, `getLimitBy()` | `getLimit()`, `getOffset()`, `getBy()` |

`useWritePdo()` does nothing and returns the builder. It is there for code that calls it on Laravel's query builder.

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
