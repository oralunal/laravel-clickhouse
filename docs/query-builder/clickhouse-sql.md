# ClickHouse SQL

The query builder adds the ClickHouse clauses that Laravel's query builder does not have.
Their arguments follow Laravel's methods. The examples use `$query = DB::connection('clickhouse')->table('my_table')`.

## PREWHERE and FINAL

```php
$query->final()->preWhere('event_date', '2024-01-05')->where('user_id', 7);
// select * from "my_table" final prewhere "event_date" = '2024-01-05' where "user_id" = 7
```

- `final()` adds `FINAL`. `final(false)` removes it. `table($table, $as, true)` and `from($table, $as, true)` also add it.
- A query on a sub-query cannot have `FINAL`: `toSql()` throws a `LogicException`. Put `final()` on the query of the sub-query.
- Each `where` method has a `preWhere` form, such as `preWhereIn()` or `orPreWhereDate()`. See [WHERE, PREWHERE and HAVING](/query-builder/conditions#where-prewhere-and-having).

## SAMPLE

```php
$query->sample(0.1);       // select * from "my_table" sample 0.1
$query->sample(0.1, 0.5);  // select * from "my_table" sample 0.1 offset 0.5
$query->sample(1_000_000); // select * from "my_table" sample 1000000
```

- A coefficient from 0 to 1 is a part of the data. A coefficient above 1 is a number of rows. The offset is from 0 to 1.
- A coefficient of 0 or less throws an `InvalidArgumentException`.
- The table needs a `SAMPLE BY` key. Without it, ClickHouse fails with `SAMPLING_NOT_SUPPORTED`.

## ARRAY JOIN

```php
$query->select(['id', 'tags'])->arrayJoin('tags');
// select "id", "tags" from "my_table" array join "tags"

$query->select('id', 'tag')->arrayJoin('tags', 'tag');
// select "id", "tag" from "my_table" array join "tags" as "tag"

$query->select(['id', 'tag', 'score'])->arrayJoin(['tag' => 'tags', 'score' => 'scores']);
// select "id", "tag", "score" from "my_table" array join "tags" as "tag", "scores" as "score"

$query->leftArrayJoin('tags');
// select * from "my_table" left array join "tags"

$query->arrayJoinSub(fn ($query) => $query->from('users')->selectRaw('groupArray(id)'), 'ids');
// select * from "my_table" array join (select groupArray(id) from "users") as "ids"
```

- A string key of the array is the alias of its array. An array without a key keeps its name. The grammar does not add the aliases to the select list.
- `leftArrayJoin()` and `leftArrayJoinSub()` keep a row with an empty array one time.
- Each call adds an `ARRAY JOIN` clause. `count()` counts such a query in a sub-query.
- The arrays must have the same length in each row. If not, ClickHouse fails with `SIZES_OF_ARRAYS_DONT_MATCH`.
  The `enable_unaligned_array_join` setting fills the shorter arrays with default values.

## Joins

Laravel's `join()`, `leftJoin()`, `rightJoin()`, `crossJoin()` and their `Sub` forms work. The query builder adds a method for each ClickHouse join.
Each method takes the arguments of Laravel's `join()`, and its `Sub` form the arguments of `joinSub()`:

| Method | SQL | Returns |
| --- | --- | --- |
| `innerJoin()`, `innerJoinSub()` | `inner join`, as `join()` | Rows that have a match |
| `anyInnerJoin()`, `allInnerJoin()`, `anyInnerJoinSub()`, `allInnerJoinSub()` | `any inner join`, `all inner join` | Rows that have a match, one or all |
| `anyLeftJoin()`, `allLeftJoin()`, `anyLeftJoinSub()`, `allLeftJoinSub()` | `any left join`, `all left join` | Left rows, with one match or all matches |
| `anyRightJoin()`, `allRightJoin()`, `anyRightJoinSub()`, `allRightJoinSub()` | `any right join`, `all right join` | Right rows, with one match or all matches |
| `fullJoin()`, `fullJoinSub()` | `full join` | All rows of both sides |
| `semiLeftJoin()`, `semiRightJoin()`, `semiLeftJoinSub()`, `semiRightJoinSub()` | `semi left join`, `semi right join` | Left or right rows that have a match |
| `antiLeftJoin()`, `antiRightJoin()`, `antiLeftJoinSub()`, `antiRightJoinSub()` | `anti left join`, `anti right join` | Left or right rows without a match |
| `asofJoin()`, `asofLeftJoin()`, `asofJoinSub()`, `asofLeftJoinSub()` | `asof join`, `asof left join` | Each left row with its closest match |

```php
$query->select('my_table.id', 'users.name')->anyLeftJoin('users', 'my_table.user_id', '=', 'users.id');
// select "my_table"."id", "users"."name" from "my_table" any left join "users" on "my_table"."user_id" = "users"."id"

$query->anyLeftJoinSub(DB::connection('clickhouse')->table('users')->select('id', 'name'), 'u', 'my_table.user_id', '=', 'u.id');
// select * from "my_table" any left join (select "id", "name" from "users") as "u" on "my_table"."user_id" = "u"."id"
```

Laravel's join methods have no `USING` clause. Write the condition with `on`.

### Join closures

A closure gets Laravel's `JoinClause`. Each `on()` adds a condition:

```php
$query->allInnerJoin('users', fn ($join) => $join
    ->on('my_table.user_id', '=', 'users.id')
    ->on('my_table.tenant', '=', 'users.tenant'));
// select * from "my_table" all inner join "users"
//   on "my_table"."user_id" = "users"."id" and "my_table"."tenant" = "users"."tenant"
```

An ASOF join matches one condition by the closest value. It needs one inequality in its conditions:

```php
DB::connection('clickhouse')->table('events', 'e')
    ->select('e.id', 'p.price')
    ->asofLeftJoin('prices as p', fn ($join) => $join
        ->on('e.user_id', '=', 'p.user_id')
        ->on('e.created_at', '>=', 'p.ts'));
// select "e"."id", "p"."price" from "events" as "e" asof left join "prices" as "p"
//   on "e"."user_id" = "p"."user_id" and "e"."created_at" >= "p"."ts"
```

### GLOBAL joins

On a `Distributed` table, a `GLOBAL` join reads the right table one time and sends it to all shards.
Give the keywords as the `$type` argument of Laravel's `join()`:

```php
$query->join('users', 'my_table.user_id', '=', 'users.id', 'global any left');
// select * from "my_table" global any left join "users" on "my_table"."user_id" = "users"."id"
```

The type is written as given. Do not take it from user input.

## LIMIT BY

`LIMIT n BY columns` keeps the first `n` rows of each group of the columns:

```php
$query->orderByDesc('ts')->limitBy(3, 'user_id')->limit(100);
// select * from "my_table" order by "ts" desc limit 3 by "user_id" limit 100

$query->limitBy(2, 'user_id', 'event');
// select * from "my_table" limit 2 by "user_id", "event"

$query->limitByWithOffset(1, 2, ['user_id', 'day']);
// select * from "my_table" limit 1 offset 2 by "user_id", "day"
```

`count()` counts such a query in a sub-query.

## WITH

```php
DB::connection('clickhouse')->table('recent')
    ->withExpression('recent', fn ($query) => $query
        ->from('events')
        ->where('created_at', '>=', DB::raw('now() - INTERVAL 1 DAY')))
    ->withAlias('total', fn ($query) => $query->selectRaw('count()')->from('events'))
    ->select('user_id', 'total');
// with "recent" as (select * from "events" where "created_at" >= now() - INTERVAL 1 DAY),
//   (select count() from "events") as "total" select "user_id", "total" from "recent"

$query->withAlias('ids', [1, 2])->whereRaw('has(ids, id)');
// with [1, 2] as "ids" select * from "my_table" where has(ids, id)
```

| Method | Adds |
| --- | --- |
| `withExpression('name', $query)` | `"name" as (<query>)`. `$query` is a builder, a closure, `DB::raw()` or SQL. |
| `withRecursiveExpression('name', $query)` | The same, with `with recursive` |
| `withAlias('alias', $value)` | `<value> as "alias"`. A builder or a closure is a scalar sub-query, `DB::raw()` is SQL, and another value is a literal. |

```php
DB::connection('clickhouse')->table('r')
    ->withRecursiveExpression('r', fn ($query) => $query
        ->selectRaw('1 as n')
        ->unionAll(fn ($query) => $query->selectRaw('n + 1')->from('r')->where('n', '<', 10)));
// with recursive "r" as ((select 1 as n) union all (select n + 1 from "r" where "n" < 10)) select * from "r"
```

- `WITH RECURSIVE` needs the analyzer, which is on by default from 24.8. With `enable_analyzer = 0`, the query fails.
- ClickHouse runs the expression of a `withAlias()` only when the select list, a condition or the order reads its name. So `count()` counts a query with `withAlias()` in a sub-query.

## Set operations

```php
DB::connection('clickhouse')->table('my_table')->select('user_id')->where('event', 'signup')
    ->except(DB::connection('clickhouse')->table('my_table')->select('user_id')->where('event', 'purchase'));
// (select "user_id" from "my_table" where "event" = 'signup')
//   except (select "user_id" from "my_table" where "event" = 'purchase')
```

| Method | Keyword |
| --- | --- |
| `unionAll()` | `union all` |
| `union()`, `unionDistinct()` | `union distinct`. With `union_default_mode` in the connection `settings`, `union()` writes a bare `union`. |
| `intersect()`, `intersectDistinct()` | `intersect`, `intersect distinct` |
| `except()`, `exceptDistinct()` | `except`, `except distinct` |

- Each method takes a builder, or a closure that gets a new builder. Each operand is in parentheses.
- `intersect()` and `except()` keep the duplicate rows of the first query. The `Distinct` forms return each row one time.
- `orderBy()`, `limit()` and `offset()` after a set operation apply to the whole operation. The grammar selects from the operation:
  `$a->unionAll($b)->orderBy('x')->limit(10)` gives `select * from ((...) union all (...)) order by "x" asc limit 10`.
  Call them before the set operation for the first query only.
- `settings()` goes on the outer query in the same way: `select * from ((...) union all (...)) settings max_threads=1`.

### Order of the operations

ClickHouse calculates `INTERSECT` first, then `UNION` and `EXCEPT` from left to right.
`$a->unionAll($b)->intersect($c)` gives `(<a>) union all (<b>) intersect (<c>)`. It returns the rows of `$a` and the rows of `$b` that are also in `$c`.

To intersect all of the union, select from it as a sub-query:

```php
DB::connection('clickhouse')->query()->fromSub($a->unionAll($b), 'u')->intersect($c);
// (select * from ((<a>) union all (<b>)) as "u") intersect (<c>)
```

A builder with its own set operations is one operand in parentheses:

```php
$a->except($b->unionAll($c));
// (<a>) except ((<b>) union all (<c>))
```

### INTERSECT and EXCEPT in sub-queries

ClickHouse leaves out the columns of the queries that `INTERSECT` and `EXCEPT` compare when the outer query does not read them.
Then a count or an existence check compares fewer columns than `get()`, and gets another answer. On ClickHouse 24.8, the count of an `EXCEPT` that returns two rows was `0`.

`count()`, the other aggregates, `exists()` and `whereExists()` add `where not ignore(*)` to a query that holds `INTERSECT` or `EXCEPT`. Then ClickHouse reads all columns:

```php
DB::connection('clickhouse')->query()->fromSub($a->except($b), 's')->count();
// select count(*) as "aggregate" from ((<a>) except (<b>)) as "s" where not ignore(*)
```

The grammar does not examine the SQL of your own select list. For a query that selects some columns of such a sub-query, add the condition:

```php
DB::connection('clickhouse')->query()->fromSub($a->except($b), 's')->select('user_id')->whereRaw('not ignore(*)');
```
