# ClickHouse SQL

The package's query builder writes ClickHouse clauses that Laravel's query builder does not have.
The examples use these imports:

```php
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\JoinClause;
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;
```

## PREWHERE and FINAL

```php
$query->final()->preWhere('event_date', '2024-01-05')->where('user_id', 7);
// SELECT * FROM `my_table` FINAL PREWHERE `event_date` = '2024-01-05' WHERE `user_id` = 7
```

All `where` methods have a `preWhere` form, such as `preWhereIn()`, `orPreWhere()` and `preWhereNull()`.

## SAMPLE

```php
$query->sample(0.1);      // SAMPLE 0.1
$query->sample(0.1, 0.5); // SAMPLE 0.1 OFFSET 0.5
```

The table needs a `SAMPLE BY` key. Without it, ClickHouse fails with `SAMPLING_NOT_SUPPORTED`.

## Set operations

```php
MyTable::select('user_id')
    ->where('event', 'signup')
    ->except(MyTable::select('user_id')->where('event', 'purchase'));
// SELECT `user_id` FROM `my_table` WHERE `event` = 'signup'
// EXCEPT SELECT `user_id` FROM `my_table` WHERE `event` = 'purchase'
```

| Method | Keyword |
| --- | --- |
| `unionAll()` | `UNION ALL` |
| `unionDistinct()` | `UNION DISTINCT` |
| `intersect()` | `INTERSECT` |
| `intersectDistinct()` | `INTERSECT DISTINCT` |
| `except()` | `EXCEPT` |
| `exceptDistinct()` | `EXCEPT DISTINCT` |

- Each method takes a builder, or a closure that gets a new builder.
- There is no `union()`. ClickHouse refuses a bare `UNION` without the `union_default_mode` setting.
- `intersect()` and `except()` keep the duplicate rows of the first query. The `Distinct` forms return each row one time.

### Order of the operations

The operations follow the call order without parentheses. ClickHouse calculates `INTERSECT` first, then `UNION` and `EXCEPT` from left to right.
`$a->unionAll($b)->intersect($c)` returns the rows of `$a` and the rows of `$b` that are also in `$c`.

To intersect all of the union, select from it as a sub-query:

```php
DB::connection('clickhouse')->table($a->unionAll($b))->intersect($c);
// SELECT * FROM (<a> UNION ALL <b>) INTERSECT <c>
```

A builder with its own set operations is one operand in parentheses:

```php
$a->except($b->unionAll($c));
// <a> EXCEPT (<b> UNION ALL <c>)

$a->unionAll($b->unionAll($c));
// <a> UNION ALL <b> UNION ALL <c>
```

### Settings, limits and pages

- Call `settings()` on the outer query. A query that ends with an operand in parentheses cannot have `settings()` as a sub-query. ClickHouse refuses it with a syntax error.
- `limit()` applies to the first query only. `count()` counts the full query in a sub-query and keeps that limit.
- `paginate()`, `chunk()`, `first()` and `value()` read the full operation as a sub-query.
  ClickHouse runs the queries at the same time, so the row order can change. For pages in a fixed order, order the sub-query:

  ```php
  DB::connection('clickhouse')->table($query)->orderBy('user_id')->paginate();
  // SELECT * FROM (<query>) ORDER BY `user_id` ASC LIMIT 0, 15
  ```

### INTERSECT and EXCEPT on ClickHouse 24.8

On 24.8, a query that reads only some columns of an `INTERSECT` or `EXCEPT` sub-query gets incorrect rows. 25.8 and later are correct.
`exists()`, `count()` and `paginate()` read all columns with `WHERE NOT ignore(*)` for such a query.
For your own query that selects some columns, add the same condition:

```php
DB::connection('clickhouse')->table($query)->select('user_id')->whereRaw('NOT ignore(*)');
// SELECT `user_id` FROM (<query>) WHERE NOT ignore(*)
```

The package does not examine raw SQL. Add `->whereRaw('NOT ignore(*)')` to a query that reads an `INTERSECT` or `EXCEPT` in raw SQL.

## WITH

```php
DB::connection('clickhouse')->table('recent')
    ->withExpression('recent', fn ($query) => $query
        ->from('events')
        ->where('created_at', '>=', raw('now() - INTERVAL 1 DAY')))
    ->withAlias('total', fn ($query) => $query->select(raw('count()'))->from('events'))
    ->select('user_id', 'total');
// WITH `recent` AS (SELECT * FROM `events` WHERE `created_at` >= now() - INTERVAL 1 DAY),
//      (SELECT count() FROM `events`) AS `total`
// SELECT `user_id`, `total` FROM `recent`
```

| Method | Adds |
| --- | --- |
| `withExpression('name', $query)` | `` `name` AS (<query>) ``. `$query` is a builder, a closure, `raw()` or SQL. |
| `withRecursiveExpression('name', $query)` | The same, with `WITH RECURSIVE` |
| `withAlias('alias', $value)` | `` <value> AS `alias` ``. A builder or closure is a scalar sub-query. An array is an array literal. |

```php
DB::connection('clickhouse')->table('r')
    ->withRecursiveExpression('r', fn ($query) => $query
        ->select(raw('1 AS n'))
        ->unionAll(fn ($query) => $query->select(raw('n + 1'))->from('r')->where('n', '<', 10)));
// WITH RECURSIVE `r` AS (SELECT 1 AS n UNION ALL SELECT n + 1 FROM `r` WHERE `n` < 10) SELECT * FROM `r`
```

- `WITH RECURSIVE` needs the analyzer, which is on by default from 24.8. With `enable_analyzer = 0`, the query fails.
- A name stays one identifier, also with a dot: `withAlias('a.b', 1)` adds `` 1 AS `a.b` ``. Refer to it with ``raw('`a.b`')``.
- In a set operation, a query with its own `WITH` clause does not see the `withExpression()` names of the outer query. It fails with `UNKNOWN_TABLE`.
  Select such a query from a sub-query: `->unionAll(fn ($query) => $query->from($queryWithItsOwnWith))`.

## Joins

```php
$query->select('id')->anyLeftJoin('users', ['user_id']);
// SELECT `id` FROM `my_table` ANY LEFT JOIN `users` USING `user_id`

$query->select('id')->leftJoin('users', 'any', ['user_id']);
// SELECT `id` FROM `my_table` ANY LEFT JOIN `users` USING `user_id`

$query->select('id')->join(fn (JoinClause $join) => $join
    ->table('users')->as('u')->type('inner')->strict('all')
    ->on('my_table.user_id', '=', 'u.id'));
// SELECT `id` FROM `my_table` ALL INNER JOIN `users` AS `u` ON `my_table`.`user_id` = `u`.`id`
```

`join($table, $strict = null, $type = null, $using = null, $global = false, $alias = null)` takes all parts of a join.
`leftJoin()`, `innerJoin()`, `rightJoin()` and `fullJoin()` take `($table, $strict = null, $using = null, $global = false, $alias = null)`. The default strictness is `ALL`:

```php
$query->innerJoin('users', 'any', ['user_id']);
// SELECT * FROM `my_table` ANY INNER JOIN `users` USING `user_id`

$query->leftJoin('users', 'all', ['user_id'], true, 'u');
// SELECT * FROM `my_table` GLOBAL ALL LEFT JOIN `users` AS `u` USING `user_id`
```

- `$global = true` adds `GLOBAL`. On a `Distributed` table, ClickHouse then reads the right table one time and sends it to all shards.
- `$table` can be a builder. The package writes it as a sub-query: `anyLeftJoin($usersQuery, ['user_id'], false, 'u')` gives ``ANY LEFT JOIN (SELECT ...) AS `u` USING `user_id` ``.

These methods take `($table, $using = null, $global = false, $alias = null)`:

| Method | SQL | Returns |
| --- | --- | --- |
| `anyLeftJoin()`, `allLeftJoin()` | `ANY LEFT JOIN`, `ALL LEFT JOIN` | Left rows, with one match or all matches |
| `anyInnerJoin()`, `allInnerJoin()` | `ANY INNER JOIN`, `ALL INNER JOIN` | Rows that have a match, one or all |
| `semiLeftJoin()` | `SEMI LEFT JOIN` | Left rows that have a match |
| `semiRightJoin()` | `SEMI RIGHT JOIN` | Right rows that have a match |
| `antiLeftJoin()` | `ANTI LEFT JOIN` | Left rows without a match |
| `antiRightJoin()` | `ANTI RIGHT JOIN` | Right rows without a match |
| `asofJoin()` | `ASOF JOIN` | Each left row with its closest match |
| `asofLeftJoin()` | `ASOF LEFT JOIN` | The same, and left rows without a match |
| `anyRightJoin()`, `allRightJoin()` | `ANY RIGHT JOIN`, `ALL RIGHT JOIN` | |

### Join closures

A closure in `join()` or a join method gets an `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\JoinClause`:

```php
$query->join(fn (JoinClause $join) => $join
    ->table('users')->as('u')->all()->inner()
    ->on('my_table.user_id', '=', 'u.id')
    ->on('my_table.tenant', '=', 'u.tenant'));
// SELECT * FROM `my_table` ALL INNER JOIN `users` AS `u`
//   ON `my_table`.`user_id` = `u`.`id` AND `my_table`.`tenant` = `u`.`tenant`

$query->join(fn (JoinClause $join) => $join
    ->any()->left()
    ->query(fn ($query) => $query->select('user_id', 'name')->from('users'))->as('u')
    ->using('user_id'));
// SELECT * FROM `my_table` ANY LEFT JOIN (SELECT `user_id`, `name` FROM `users`) AS `u` USING `user_id`
```

| `JoinClause` method | Sets |
| --- | --- |
| `table($table)` | The right table. A string is a name. `raw()` is SQL. |
| `as($alias)` | The alias of the right table |
| `query($query)` | A sub-query from a builder or a closure as the right table |
| `subQuery($alias = null)` | A sub-query as the right table. It returns a new builder for the sub-query. |
| `using(...$columns)`, `addUsing(...$columns)` | The `USING` columns. `using()` replaces them, and `addUsing()` adds to them. |
| `on($first, $operator, $second, $concatOperator = 'AND')` | An `ON` condition. Each call adds one. |
| `strict($strict)` | The strictness: `all`, `any`, `semi`, `anti` or `asof` |
| `all()`, `any()`, `semi()`, `anti()`, `asof()` | The same strictness |
| `type($type)` | The type: `inner`, `left`, `right`, `full` or `cross` |
| `inner()`, `left()`, `right()`, `full()`, `cross()` | The same type |
| `distributed(true)` | `GLOBAL` |

A join with both `USING` and `ON` throws a `GrammarException`.
The getters `getTable()`, `getAlias()`, `getStrict()`, `getType()`, `getUsing()`, `getOnClauses()`, `isDistributed()`, `getSubQuery()` and `getQueryBuilder()` return the parts of a join.

An ASOF join matches one condition by the closest value: the last `USING` column, or the inequality in `ON`:

```php
DB::connection('clickhouse')->table('events', 'e')
    ->select('e.id', 'p.price')
    ->asofLeftJoin(function (JoinClause $join) {
        $join->table('prices')->as('p')
            ->on('e.user_id', '=', 'p.user_id')
            ->on('e.created_at', '>=', 'p.ts');
    });
// SELECT `e`.`id`, `p`.`price` FROM `events` AS `e` ASOF LEFT JOIN `prices` AS `p`
//   ON `e`.`user_id` = `p`.`user_id` AND `e`.`created_at` >= `p`.`ts`
```

`crossJoin($table, $global = false, $alias = null)` adds a `CROSS JOIN` without keys.
A cross join with keys or a strictness throws a `GrammarException`.
In a join closure, `semi()`, `anti()`, `asof()`, `right()`, `full()` and `cross()` set the same keywords.

## ARRAY JOIN

```php
$query->select(['id', 'tags'])->arrayJoin('tags');
// SELECT `id`, `tags` FROM `my_table` ARRAY JOIN `tags`

MyTable::select(['id', 'tag', 'score'])
    ->arrayJoin(['tag' => 'tags', 'score' => 'scores']);
// SELECT `id`, `tag`, `score` FROM `my_table` ARRAY JOIN `tags` AS `tag`, `scores` AS `score`
```

- A string key is the alias of its array. An array without a key keeps its name. The package does not add the aliases to the select list.
- The arrays must have the same length in each row. If not, ClickHouse fails with `SIZES_OF_ARRAYS_DONT_MATCH`.
  The `enable_unaligned_array_join` setting fills the shorter arrays with default values.
- `leftArrayJoin()` takes the same argument and writes `LEFT ARRAY JOIN`.
