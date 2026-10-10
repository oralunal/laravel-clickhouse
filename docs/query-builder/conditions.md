# Conditions

The examples use these imports:

```php
use Illuminate\Support\Facades\DB;
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;
```

## Basic conditions

`where()`, `preWhere()` and `having()` and their `or` forms read operators and booleans in any letter case.
An array of conditions is one group in parentheses:

```php
MyTable::select()
    ->where('field_one', 'like', 'a%')                     // `field_one` LIKE 'a%'
    ->where('id', 'not in', [1, 2])                        // AND `id` NOT IN (1, 2)
    ->where(['field_two' => 2, 'user_id' => 7])            // AND (`field_two` = 2 AND `user_id` = 7)
    ->orWhere([['field_two', '>', 10], ['user_id', 8]])    // OR (`field_two` > 10 OR `user_id` = 8)
    ->whereIn('id', [3], 'or');                            // OR `id` IN (3)
```

- An entry of an array is `[column, value]`, `[column, operator, value]` or `column => value`. Another entry throws an `InvalidArgumentException`.
- A closure or an array that adds no condition is removed: `where('id', 1)->where(fn ($query) => $query)` gives ``WHERE `id` = 1``.
- With a `null` third argument, a second argument that is not an operator is the value, as in Laravel: `where('processed', 0, null)` gives `` `processed` = 0 ``.
- `<>` is written as `!=`: `where('status', '<>', 'paused')` gives `` `status` != 'paused' ``.

### Groups

A closure is one group in parentheses. It gets a new builder:

```php
MyTable::where('a', 1)->where(fn ($query) => $query->where('b', 2)->orWhere('c', 3));
// SELECT * FROM `my_table` WHERE `a` = 1 AND (`b` = 2 OR `c` = 3)

MyTable::where('a', 1)->orWhere(fn ($query) => $query->where('b', 2)->where('c', 3));
// SELECT * FROM `my_table` WHERE `a` = 1 OR (`b` = 2 AND `c` = 3)
```

## WHERE, PREWHERE and HAVING

Each condition method has a form for each clause. The `or` form adds the condition with `OR`:

| `WHERE` | `PREWHERE` | `HAVING` |
| --- | --- | --- |
| `where()`, `orWhere()` | `preWhere()`, `orPreWhere()` | `having()`, `orHaving()` |
| `whereRaw()`, `orWhereRaw()` | `preWhereRaw()`, `orPreWhereRaw()` | `havingRaw()`, `orHavingRaw()` |
| `whereIn()`, `orWhereIn()`, `whereNotIn()`, `orWhereNotIn()` | `preWhereIn()`, `orPreWhereIn()`, `preWhereNotIn()`, `orPreWhereNotIn()` | `havingIn()`, `orHavingIn()`, `havingNotIn()`, `orHavingNotIn()` |
| `whereGlobalIn()`, `orWhereGlobalIn()`, `whereGlobalNotIn()`, `orWhereGlobalNotIn()` | | |
| `whereBetween()`, `orWhereBetween()`, `whereNotBetween()`, `orWhereNotBetween()` | `preWhereBetween()`, `orPreWhereBetween()`, `preWhereNotBetween()`, `orPreWhereNotBetween()` | `havingBetween()`, `orHavingBetween()`, `havingNotBetween()`, `orHavingNotBetween()` |
| `whereBetweenColumns()`, `orWhereBetweenColumns()`, `whereNotBetweenColumns()`, `orWhereNotBetweenColumns()` | `preWhereBetweenColumns()`, `orPreWhereBetweenColumns()`, `preWhereNotBetweenColumns()`, `orPreWhereNotBetweenColumns()` | `havingBetweenColumns()`, `orHavingBetweenColumns()`, `havingNotBetweenColumns()`, `orHavingNotBetweenColumns()` |
| `whereNull()`, `orWhereNull()`, `whereNotNull()`, `orWhereNotNull()` | `preWhereNull()`, `orPreWhereNull()`, `preWhereNotNull()`, `orPreWhereNotNull()` | `havingNull()`, `orHavingNull()`, `havingNotNull()`, `orHavingNotNull()` |
| `whereEmpty()`, `orWhereEmpty()`, `whereNotEmpty()`, `orWhereNotEmpty()` | `preWhereEmpty()`, `orPreWhereEmpty()`, `preWhereNotEmpty()`, `orPreWhereNotEmpty()` | `havingEmpty()`, `orHavingEmpty()`, `havingNotEmpty()`, `orHavingNotEmpty()` |
| `whereColumn()`, `orWhereColumn()` | `preWhereColumn()`, `orPreWhereColumn()` | |
| `whereAny()`, `orWhereAny()`, `whereAll()`, `orWhereAll()`, `whereNone()`, `orWhereNone()` | `preWhereAny()`, `orPreWhereAny()`, `preWhereAll()`, `orPreWhereAll()`, `preWhereNone()`, `orPreWhereNone()` | |
| `whereDate()`, `orWhereDate()`, `whereTime()`, `orWhereTime()`, `whereDay()`, `orWhereDay()`, `whereMonth()`, `orWhereMonth()`, `whereYear()`, `orWhereYear()` | `preWhereDate()`, `orPreWhereDate()`, `preWhereTime()`, `orPreWhereTime()`, `preWhereDay()`, `orPreWhereDay()`, `preWhereMonth()`, `orPreWhereMonth()`, `preWhereYear()`, `orPreWhereYear()` | |
| `whereLike()`, `orWhereLike()`, `whereNotLike()`, `orWhereNotLike()` | | |
| `whereExists()`, `orWhereExists()`, `whereNotExists()`, `orWhereNotExists()` | | |
| `whereDict()`, `orWhereDict()` | | |

The raw methods add SQL as it is. Do not put user input into them:

```php
MyTable::select('user_id', raw('count() AS c'))
    ->groupBy('user_id')
    ->having('c', '>', 1)
    ->orHavingRaw('c = 1000');
// SELECT `user_id`, count() AS c FROM `my_table` GROUP BY `user_id` HAVING `c` > 1 OR c = 1000
```

## Values

The package writes PHP values as ClickHouse literals:

| PHP value | SQL |
| --- | --- |
| `DateTimeInterface`, Carbon included | `'2026-10-08 14:30:00'` |
| `true`, `false` | `1`, `0` |
| Float | All digits: `0.30000000000000004` for `0.1 + 0.2`. `nan`, `inf` and `-inf` for `NAN`, `INF` and `-INF`. |
| Backed enum | Its value: `'active'`, `3` |
| Pure enum | Its name, quoted: `'Active'` |
| smi2 value object or expression, such as `UInt64`, `UUID`, `MapType` or `Raw` | A literal of its type, or its SQL: `18446744073709551615`, `map('k', 1)`, `now()` |
| Other `Stringable` object, such as `Str::of()` | Its string, quoted |

```php
MyTable::select()
    ->where('created_at', '>=', now()->subDay())           // `created_at` >= '2026-10-07 14:30:00'
    ->where('is_active', true)                             // `is_active` = 1
    ->whereIn('status', [Status::Active, Status::Paused]); // `status` IN ('active', 'paused')
```

A value that the builder cannot write, such as a `stdClass` object, throws an `InvalidArgumentException`.
For an Eloquent model, give `$model->getKey()`. For dates, see [Dates and times](/query-builder/dates).

### Floats

A condition compares the exact PHP float: `where('ratio', 1 / 3)` gives `` `ratio` = 0.3333333333333333 ``.
All inserts of the package also write all digits, so the condition finds the row that `insertAssoc([['ratio' => 1 / 3]])` stored.

- A float that PHP calculates can miss a `Decimal` value. `where('price', 4.35 * 100)` does not find `435.00`.
  Round it: `where('price', round(4.35 * 100, 2))`, or give a string: `where('price', '435.00')`.
- Named bindings in raw SQL, such as `:ratio`, write 14 significant digits. Such a stored value is not equal to the PHP float.
  Round the float before the insert and the comparison, or compare with a range:
  `whereBetween('ratio', [$ratio - 1e-9, $ratio + 1e-9])`.
- No value is equal to NaN. `where('f', NAN)` finds no row. Use `whereRaw('isNaN(f)')`.

For floats in `update()`, see [Updates](/query-builder/writing-data#updates).

### Raw SQL

Write SQL with `raw()`, `new RawColumn(...)` or `DB::raw()`:

```php
MyTable::where(DB::raw('field_two + 1'), 6);
// SELECT * FROM `my_table` WHERE field_two + 1 = 6
```

## Lists: IN and BETWEEN

The `In` methods take a PHP array, a collection or another `Arrayable`. `where()` without an operator compares with a list as `IN`.
A nested array is a tuple:

```php
MyTable::select()
    ->whereIn('id', collect([1, 2, 3]))                      // `id` IN (1, 2, 3)
    ->where('status', collect(['active', 'paused']))         // `status` IN ('active', 'paused')
    ->where('user_id', 'GLOBAL IN', [7, 8])                  // `user_id` GLOBAL IN (7, 8)
    ->whereIn(raw('(id, field_one)'), [[1, 'a'], [2, 'b']]); // (id, field_one) IN ((1, 'a'), (2, 'b'))
```

An empty list matches no row with `IN` and all rows with `NOT IN`, as in Laravel:

```php
->whereIn('id', [])                                // 0 = 1
->whereNotIn('id', [])                             // 1 = 1
->where('field_two', '<', 0)->orWhereIn('id', [])  // `field_two` < 0 OR 0 = 1
->where('field_two', '<', 0)->whereNotIn('id', []) // `field_two` < 0 AND 1 = 1
```

::: danger
`->whereNotIn('id', $ids)->delete()` with an empty `$ids` deletes all rows. Check the list before a mutation.
:::

A string after `IN` is a value: `whereIn('id', 'ids')` gives `` `id` IN 'ids' ``. To read a table, give `raw('ids')` or a sub-query.

A closure or a builder is a sub-query:

```php
MyTable::whereIn('user_id', fn ($query) => $query->select('id')->from('users')->where('banned', 1));
// SELECT * FROM `my_table` WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `banned` = 1)
```

### GLOBAL IN

On a `Distributed` table, `GLOBAL IN` runs the sub-query one time and sends its result to all shards:

```php
MyTable::whereGlobalIn('user_id', fn ($query) => $query->select('id')->from('users'));
// SELECT * FROM `my_table` WHERE `user_id` GLOBAL IN (SELECT `id` FROM `users`)

MyTable::where('a', 1)->orWhereGlobalNotIn('user_id', [7]);
// SELECT * FROM `my_table` WHERE `a` = 1 OR `user_id` GLOBAL NOT IN (7)
```

`whereGlobalIn()`, `whereGlobalNotIn()` and their `or` forms take the same values as `whereIn()`.

`BETWEEN` takes the first two values of the list: `where('id', 'BETWEEN', [1, 10])` gives `` `id` BETWEEN 1 AND 10 ``.
`whereBetween()`, `whereNotBetween()` and their forms read the list the same way. A list with fewer than two values throws an `InvalidArgumentException`.

## NULL checks

```php
MyTable::select()
    ->whereNull('deleted_at')             // `deleted_at` IS NULL
    ->orWhereNotNull(['email', 'phone']); // OR `email` IS NOT NULL OR `phone` IS NOT NULL

->where('parent_id', null)       // `parent_id` IS NULL
->where('parent_id', '!=', null) // `parent_id` IS NOT NULL
```

The methods have `PREWHERE` and `HAVING` forms, such as `preWhereNull()` and `orHavingNotNull()`. An array adds one condition for each column.
Only a column whose type accepts `NULL` holds it, such as `Nullable(...)`. On another column, `IS NULL` matches no row.

## Empty strings and arrays

```php
MyTable::select()
    ->whereEmpty('tags')          // empty(`tags`)
    ->orWhereNotEmpty('comment'); // OR notEmpty(`comment`)
```

These methods work on `String`, `Array` and `Map` columns. They have `PREWHERE` and `HAVING` forms.

## Compare columns

```php
MyTable::select()
    ->whereColumn('updated_at', 'created_at')               // `updated_at` = `created_at`
    ->whereColumn('updated_at', '>', 'created_at')          // AND `updated_at` > `created_at`
    ->orWhereColumn([['field_one', 'comment'], ['field_two', '<', 'user_id']]);
    // OR (`field_one` = `comment` OR `field_two` < `user_id`)
```

A string is a column name. `raw()` is SQL: `whereColumn('field_two', '<', raw('user_id + 1'))` gives `` `field_two` < user_id + 1 ``.
`whereBetweenColumns('field_two', ['user_id', raw('user_id + 10')])` gives `` `field_two` BETWEEN `user_id` AND user_id + 10 ``.
`whereNotBetweenColumns()` writes the same condition in `NOT ( ... )`.

## Conditions on many columns

```php
MyTable::select()
    ->whereAny(['field_one', 'comment'], 'ilike', '%error%')
    // (`field_one` ILIKE '%error%' OR `comment` ILIKE '%error%')
    ->whereAll(['field_two', 'user_id'], '>', 0)  // AND (`field_two` > 0 AND `user_id` > 0)
    ->whereNone(['field_one', 'comment'], 'x');  // AND NOT (`field_one` = 'x' OR `comment` = 'x')
```

An empty list of columns throws an `InvalidArgumentException`. In a mutation, a missing condition would change more rows.

## EXISTS sub-queries

```php
MyTable::select('id')->whereExists(fn ($query) => $query->from('users')->where('banned', 1));
// SELECT `id` FROM `my_table` WHERE EXISTS (SELECT * FROM `users` WHERE `banned` = 1)
```

`whereExists()`, `orWhereExists()`, `whereNotExists()` and `orWhereNotExists()` take a closure or a query builder of this package.

ClickHouse 24.8 has no correlated sub-queries. A sub-query cannot read a column of the outer query, as `whereColumn('orders.user_id', 'users.id')` does.
ClickHouse 26.3 and 26.8 run such a `SELECT`. For mutations, see [Deletions](/query-builder/writing-data#deletions).

## Dates and times

```php
->whereDate('created_at', '2024-01-05') // toDate32(`created_at`) = '2024-01-05'
```

`whereDate()`, `whereTime()`, `whereDay()`, `whereMonth()` and `whereYear()` are in [Dates and times](/query-builder/dates#date-conditions).

## LIKE and ILIKE

```php
->whereLike('field_one', 'error%')           // `field_one` ILIKE 'error%'
->whereLike('field_one', 'Error%', true)     // `field_one` LIKE 'Error%'
->whereNotLike('field_one', '%debug%')       // `field_one` NOT ILIKE '%debug%'
```

`whereLike()` and `whereNotLike()` ignore case, as in Laravel. With `true` as the third argument, they compare case.
ClickHouse can use the primary key for `LIKE 'prefix%'`, but not for `ILIKE`.

## Random order

`inRandomOrder()` gives `ORDER BY rand()`. ClickHouse has no random function with a seed, so a seed throws an `InvalidArgumentException`.
For an order that repeats, use a hash: `orderByRaw('cityHash64(42, id)')`.
