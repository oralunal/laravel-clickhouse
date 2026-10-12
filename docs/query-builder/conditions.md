# Conditions

The conditions are Laravel's. The grammar writes them as ClickHouse SQL, and the connection writes the values as ClickHouse literals.
The examples use `$query = DB::connection('clickhouse')->table('my_table')` and show the SQL of `toRawSql()`.

## Basic conditions

```php
$query->where('field_one', 'like', 'a%')                 // "field_one" like 'a%'
    ->where(['field_two' => 2, 'user_id' => 7])          // and ("field_two" = 2 and "user_id" = 7)
    ->orWhere([['field_two', '>', 10], ['user_id', 8]])  // or ("field_two" > 10 or "user_id" = 8)
    ->whereIn('id', [3], 'or');                          // or "id" in (3)
```

- An entry of an array is `[column, value]`, `[column, operator, value]` or `column => value`. An array is one group in parentheses.
- The operators are Laravel's, such as `=`, `<>`, `!=`, `<`, `like`, `not like`, `ilike` and `not ilike`, in any letter case.
  `in`, `between` and `is null` are no operators: use `whereIn()`, `whereBetween()` and `whereNull()`.
- A closure that adds no condition is removed: `where('id', 1)->where(fn ($query) => $query)` gives `where "id" = 1`.

### Groups

A closure is one group in parentheses. It gets a new builder:

```php
$query->where('a', 1)->where(fn ($query) => $query->where('b', 2)->orWhere('c', 3));
// select * from "my_table" where "a" = 1 and ("b" = 2 or "c" = 3)

$query->where('a', 1)->orWhere(fn ($query) => $query->where('b', 2)->where('c', 3));
// select * from "my_table" where "a" = 1 or ("b" = 2 and "c" = 3)
```

## WHERE, PREWHERE and HAVING

ClickHouse reads the columns of a `PREWHERE` condition first, and then the other columns of the rows that it matches only.
Each `where` method of the builder has a `preWhere` form: put `preWhere` in place of `where`, as in `preWhereIn()`, `orPreWhereColumn()` or `preWhereDate()`.
The form takes the arguments of the `where` method and adds its conditions to the `PREWHERE` clause:

```php
$query->preWhere('event_date', '2024-01-05')
    ->orPreWhereIn('user_id', [7, 8])
    ->preWhereColumn('updated_at', '>', 'created_at')
    ->where('user_id', 7);
// select * from "my_table" prewhere "event_date" = '2024-01-05' or "user_id" in (7, 8)
//   and "updated_at" > "created_at" where "user_id" = 7

$query->preWhere(fn ($query) => $query->where('a', 1)->orWhere('b', 2))->where('c', 3);
// select * from "my_table" prewhere ("a" = 1 or "b" = 2) where "c" = 3
```

- The explicit methods are `preWhere()`, `orPreWhere()`, `preWhereRaw()`, `orPreWhereRaw()`, `preWhereIn()`, `orPreWhereIn()`, `preWhereNotIn()`, `orPreWhereNotIn()`, `preWhereNull()`, `orPreWhereNull()`, `preWhereNotNull()`, `orPreWhereNotNull()`, `preWhereBetween()`, `orPreWhereBetween()`, `preWhereNotBetween()` and `orPreWhereNotBetween()`.
  The other forms, such as `preWhereEmpty()`, `preWhereAny()` or `orPreWhereLike()`, call the `where` method of the same name. A form without a `where` method throws a `BadMethodCallException`.
- A closure in `preWhere()` is one group, as in `where()`.

The `HAVING` methods are Laravel's: `having()`, `orHaving()`, `havingRaw()`, `orHavingRaw()`, `havingNull()`, `havingNotNull()`, `havingBetween()`, `havingNotBetween()` and their `or` forms, and `havingEmpty()` and `havingNotEmpty()` of [Empty strings and arrays](#empty-strings-and-arrays):

```php
$query->select('user_id', DB::raw('count() as c'))
    ->groupBy('user_id')
    ->having('c', '>', 1)
    ->orHavingRaw('c = 1000');
// select "user_id", count() as c from "my_table" group by "user_id" having "c" > 1 or c = 1000
```

The raw methods add SQL as it is. Do not put user input into them. Give values as `?` bindings.

## Values

The connection writes PHP values as ClickHouse literals:

| PHP value | SQL |
| --- | --- |
| `DateTimeInterface`, Carbon included | `'2026-10-07 14:30:00'`. See [Dates and times](/query-builder/dates). |
| `true`, `false` | `1`, `0` |
| `null` | `null` |
| Float | All digits: `0.30000000000000004` for `0.1 + 0.2`. `nan`, `inf` and `-inf` for `NAN`, `INF` and `-INF`. |
| String | A string literal, with `'`, `"`, `\` and NUL escaped: `'it\'s'` |
| Array | An array literal: `['a', 'b']` |
| Backed enum | Its value: `'active'`, `3` |
| Pure enum | Its name, quoted: `'Active'` |
| `DB::raw()` | Its SQL |
| smi2 value object or expression, such as `UInt64`, `UUID`, `MapType` or `Raw` | A literal of its type, or its SQL: `18446744073709551615`, `map('k', 1)`, `now()` |
| Other `Stringable` object, such as `Str::of()` | Its string, quoted |

```php
$query->where('created_at', '>=', now()->subDay())         // "created_at" >= '2026-10-07 14:30:00'
    ->where('is_active', true)                             // and "is_active" = 1
    ->whereIn('status', [Status::Active, Status::Paused]); // and "status" in ('active', 'paused')
```

In a condition, Laravel flattens an array value: `where('tags', ['a', 'b'])` compares with `'a'`, and
`whereRaw('tags = ?', [['a', 'b']])` throws, since it gives 2 bindings for 1 `?`. Write an array with `DB::raw()`:
`where('tags', DB::raw("['a', 'b']"))`.

A value that the grammar cannot write, such as a `stdClass` object, throws an `InvalidArgumentException` before the request:
`Cannot render a value of type stdClass in a ClickHouse query. ...`. For an Eloquent model, give `$model->getKey()`.

### Floats

A condition compares the exact PHP float: `where('ratio', 1 / 3)` gives `"ratio" = 0.3333333333333333`.
The inserts write all digits too, so the condition finds the row that `insert(['ratio' => 1 / 3])` stored.

- A float that PHP calculates can miss a `Decimal` value. `where('price', 4.35 * 100)` gives `"price" = 434.99999999999994` and does not find `435.00`.
  Round it: `where('price', round(4.35 * 100, 2))`, or give a string: `where('price', '435.00')`.
- Named bindings in raw SQL, such as `:ratio`, write 14 significant digits. See [Named bindings](/advanced/raw-sql#named-bindings).
- No value is equal to NaN. `where('f', NAN)` gives `"f" = nan`, which finds no row. Use `whereRaw('isNaN(f)')`.

For floats in `update()`, see [Updates](/query-builder/writing-data#updates).

### Raw SQL

Write SQL with `DB::raw()` or a raw method:

```php
$query->where(DB::raw('field_two + 1'), 6);
// select * from "my_table" where field_two + 1 = 6

$query->whereRaw('isNaN(f) or f > ?', [10]);
// select * from "my_table" where isNaN(f) or f > 10
```

## Lists: IN and BETWEEN

`whereIn()` and `whereNotIn()` take a PHP array, a collection or another `Arrayable`, or a sub-query:

```php
$query->whereIn('id', collect([1, 2, 3]));
// select * from "my_table" where "id" in (1, 2, 3)

$query->whereIn('user_id', fn ($query) => $query->select('id')->from('users')->where('banned', 1));
// select * from "my_table" where "user_id" in (select "id" from "users" where "banned" = 1)
```

An empty list matches no row with `IN` and all rows with `NOT IN`, as in Laravel:

```php
->whereIn('id', [])                                // where 0 = 1
->whereNotIn('id', [])                             // where 1 = 1
->where('field_two', '<', 0)->orWhereIn('id', [])  // where "field_two" < 0 or 0 = 1
```

::: danger
`->whereNotIn('id', $ids)->delete()` with an empty `$ids` deletes all rows. Check the list before a mutation.
:::

`whereBetween()` and `whereNotBetween()` take the first two values of the list, with or without keys:

```php
$query->whereBetween('id', [1, 10]);                    // "id" between 1 and 10
$query->whereNotBetween('id', ['from' => 1, 'to' => 10]); // "id" not between 1 and 10
```

### GLOBAL IN

On a `Distributed` table, `GLOBAL IN` runs the sub-query one time and sends its result to all shards:

```php
$query->whereGlobalIn('user_id', fn ($query) => $query->select('id')->from('users'));
// select * from "my_table" where "user_id" global in (select "id" from "users")

$query->where('a', 1)->orWhereGlobalNotIn('user_id', [7]);
// select * from "my_table" where "a" = 1 or "user_id" global not in (7)
```

`whereGlobalIn()`, `orWhereGlobalIn()`, `whereGlobalNotIn()` and `orWhereGlobalNotIn()` take the values of `whereIn()`.

## NULL checks

```php
$query->whereNull('deleted_at')->orWhereNotNull(['email', 'phone']);
// select * from "my_table" where "deleted_at" is null or "email" is not null or "phone" is not null

$query->where('parent_id', null);       // "parent_id" is null
$query->where('parent_id', '!=', null); // "parent_id" is not null
```

Only a column whose type accepts `NULL` holds it, such as `Nullable(...)`. On another column, `is null` matches no row.

## Empty strings and arrays

```php
$query->whereEmpty('tags')->orWhereNotEmpty('comment');
// select * from "my_table" where empty("tags") or notEmpty("comment")

$query->select('g')->groupBy('g')->havingNotEmpty('g');
// select "g" from "my_table" group by "g" having notEmpty("g")
```

- `whereEmpty()`, `orWhereEmpty()`, `whereNotEmpty()` and `orWhereNotEmpty()` take a column or a list. A list adds one condition for each column.
- `havingEmpty()`, `orHavingEmpty()`, `havingNotEmpty()` and `orHavingNotEmpty()` are the `HAVING` forms. `preWhereEmpty()` is the `PREWHERE` form.
- The functions work on `String`, `Array` and `Map` columns.

## Compare columns

```php
$query->whereColumn('updated_at', 'created_at')
    ->whereColumn('updated_at', '>', 'created_at')
    ->orWhereColumn([['field_one', 'comment'], ['field_two', '<', 'user_id']]);
// select * from "my_table" where "updated_at" = "created_at" and "updated_at" > "created_at"
//   or ("field_one" = "comment" or "field_two" < "user_id")

$query->whereColumn('field_two', '<', DB::raw('user_id + 1'));
// select * from "my_table" where "field_two" < user_id + 1

$query->whereBetweenColumns('field_two', ['user_id', DB::raw('user_id + 10')]);
// select * from "my_table" where "field_two" between "user_id" and user_id + 10
```

`whereNotBetweenColumns()` writes `not between`. Each method has an `or` form.

## Conditions on many columns

```php
$query->whereAny(['field_one', 'comment'], 'ilike', '%error%')
    ->whereAll(['field_two', 'user_id'], '>', 0)
    ->whereNone(['field_one', 'comment'], 'x');
// select * from "my_table" where ("field_one" ilike '%error%' or "comment" ilike '%error%')
//   and ("field_two" > 0 and "user_id" > 0) and not ("field_one" = 'x' or "comment" = 'x')
```

An empty list of columns adds no condition, as in Laravel. Check the list before a mutation.

## EXISTS sub-queries

```php
$query->select('id')->whereExists(fn ($query) => $query->from('users')->where('banned', 1));
// select "id" from "my_table" where exists (select * from "users" where "banned" = 1)
```

- `whereExists()`, `orWhereExists()`, `whereNotExists()` and `orWhereNotExists()` take a closure or a query builder.
- ClickHouse 24.8 leaves columns out of the queries that `INTERSECT` and `EXCEPT` compare when the outer query does not read all of them.
  For a sub-query that holds `INTERSECT` or `EXCEPT`, or reads a `withExpression()` name of an outer query that holds them, the grammar adds `where not ignore(*)`, so that ClickHouse compares all columns.
  See [INTERSECT and EXCEPT](/query-builder/clickhouse-sql#intersect-and-except-in-sub-queries).
- ClickHouse 24.8 has no correlated sub-queries. A sub-query cannot read a column of the outer query, as `whereColumn('orders.user_id', 'users.id')` does.
  ClickHouse 26.3 and 26.8 run such a `SELECT`. For mutations, see [Deletions](/query-builder/writing-data#deletions).

## Dates and times

```php
$query->whereDate('created_at', '2024-01-05'); // toDate32("created_at") = '2024-01-05'
```

`whereDate()`, `whereTime()`, `whereDay()`, `whereMonth()` and `whereYear()` are in [Dates and times](/query-builder/dates#date-conditions).

## LIKE and ILIKE

```php
$query->whereLike('field_one', 'error%');       // "field_one" ilike 'error%'
$query->whereLike('field_one', 'Error%', true); // "field_one" like 'Error%'
$query->whereNotLike('field_one', '%debug%');   // "field_one" not ilike '%debug%'
```

`whereLike()` and `whereNotLike()` ignore case, as in Laravel. With `true` as the third argument, they compare case.
ClickHouse can use the primary key for `like 'prefix%'`, but not for `ilike`.

## Bitwise and null-safe conditions

```php
$query->where('field_two', '&', 4);           // bitAnd("field_two", 4) != 0
$query->whereNullSafeEquals('comment', null); // ["comment"] = [null]
```

- The bitwise operators `&`, `|`, `^`, `<<` and `>>` call `bitAnd()`, `bitOr()`, `bitXor()`, `bitShiftLeft()` and `bitShiftRight()`, and compare the result with `0`.
  `&~` gives `bitAnd("field_two", 4) != "field_two"`.
- `whereNullSafeEquals()` and the `<=>` operator hold when both sides are equal or both are `NULL`. A `NULL` value or an expression is compared in arrays, `["comment"] = [null]`, so that `NULL` equals `NULL`. Another value is compared with `=`.

## JSON paths

A JSON path, such as `payload->user->name`, reads a value of a JSON document in a `String` column, as `json()` creates it.
It works in conditions, `select()`, `orderBy()` and `groupBy()`:

```php
$query->where('payload->user->name', 'Ann')        // JSONExtractString("payload", 'user', 'name') = 'Ann'
    ->where('payload->size', '>', 2)               // JSONExtract("payload", 'size', 'Nullable(Float64)') > 2
    ->where('payload->active', true)               // JSONExtract("payload", 'active', 'Nullable(Bool)') = true
    ->where('payload->tags[0]', 'en')              // JSONExtractString("payload", 'tags', 1) = 'en'
    ->whereNull('payload->deleted');               // JSONType("payload", 'deleted') = 'Null'

$query->select('id', 'payload->user as user')->orderBy('payload->color');
// select "id", JSONExtractString("payload", 'user') as "user" from "my_table" order by JSONExtractString("payload", 'color') asc
```

- A value is read as a string: a string without its quotes, and a number, a boolean, an object or an array as its JSON text, such as `1.5`, `true` or `{"a":1}`.
  `null`, a missing key and a document that is no JSON give `''`.
- A comparison with an int or a float reads the value as a `Float64`, also in `whereIn()` and `whereBetween()`.
  A value that is no number, such as `"large"`, is then `NULL`, so no comparison holds for it, `!=` and `whereNotIn()` included.
  A string that holds a number, such as `"2"`, is read as that number. ClickHouse 26.3 and 26.8 read `true` and `false` as `1` and `0`; 24.8 reads them as `NULL`.
- `true` and `false` read the value as a `Bool`. `whereNull()` holds for `null`, for a missing key and for a document that is no JSON.
- An array index counts from `0`, as in Laravel: `[0]` is the first element and `[-1]` the last. A key can hold any character, such as a space or a dot.
- A native `JSON` column of ClickHouse 26.x is no `String`. Read it with its own syntax, such as `whereRaw('payload.user.name = ?', ['Ann'])`.

```php
$query->whereJsonContains('payload->tags', 'en');        // hasAll(JSONExtract("payload", 'tags', 'Array(String)'), JSONExtract('[\"en\"]', 'Array(String)'))
$query->whereJsonContains('payload->tags', ['en', 'de']); // the array holds every value
$query->whereJsonOverlaps('payload->tags', ['fr', 'de']); // hasAny(...): the array holds one of the values
$query->whereJsonContainsKey('payload->user->name');      // JSONHas("payload", 'user', 'name')
$query->whereJsonLength('payload->tags', '>', 1);         // JSONLength("payload", 'tags') > 1
```

- `whereJsonContains()` and `whereJsonOverlaps()` read both arrays as `Array(String)`, `Array(Float64)` or `Array(Bool)` when the values are all strings, all numbers or all booleans.
  Other values are compared as their JSON text.
  An array with keys, which is a JSON object, throws an `InvalidArgumentException`: ClickHouse cannot check that one object holds another.
- `whereJsonContainsKey()` holds for a key whose value is `null`. `whereJsonLength()` counts the elements of an array or the keys of an object.
- Each method has its `or` form, and each method but `whereJsonLength()` its `doesnt` form, as in Laravel.

To set a JSON path, see [Updates](/query-builder/writing-data#json-paths).

## Full-text search

`whereFullText()` checks the tokens of a column. A text index of the column speeds it up. See [Text indexes](/schema/schema-builder#text-and-vector-indexes).
The tokenizer of the index then splits the value and the column: with `ngrams(3)`, `whereFullText('title', 'qui')` matches `quick`.
The rules below hold for a column without a text index and for the default tokenizer, `splitByNonAlpha`.

```php
$query->whereFullText('body', 'quick fox');                     // hasAllTokens("body", 'quick fox')
$query->whereFullText(['title', 'body'], 'fox', ['mode' => 'any']); // (hasAnyTokens("title", 'fox') or hasAnyTokens("body", 'fox'))
```

- The value is split into tokens at each ASCII character that is no letter or digit, such as a space, `-` or `_`. Other characters, such as `ü`, are part of a token: `Grüße` is one token.
  Without a mode, every token must be in the column. With `['mode' => 'any']`, one of them.
  Tokens compare with their letter case: `Lazy` does not match `lazy`.
  Another mode, such as MySQL's `boolean`, throws an `InvalidArgumentException`.
- ClickHouse 26.3 and 26.8 have `hasAllTokens()` and `hasAnyTokens()`. 24.8 does not, and the query fails with `UNKNOWN_FUNCTION`.

## Vector search

Laravel's vector methods use the cosine distance, `1 -` the cosine similarity, as PostgreSQL's pgvector:

```php
$query->whereVectorSimilarTo('embedding', $vector, 0.8);
// where cosineDistance("embedding", CAST('[0.1,0.2,...]' AS Array(Float32))) <= 0.19999999999999996
//   order by cosineDistance("embedding", CAST('[0.1,0.2,...]' AS Array(Float32))) asc

$query->select('id')->selectVectorDistance('embedding', $vector, 'distance')->orderByVectorDistance('embedding', $vector)->limit(10);
```

- The maximum distance is `1 - 0.8`, a PHP float, which the grammar writes with all its digits.
- The column is an `Array(Float32)`, as `vector()` creates it. ClickHouse 24.8, 26.3 and 26.8 have `cosineDistance()`.
- A vector similarity index of the column speeds up an `orderByVectorDistance()` with a `limit()`. See [Vector indexes](/schema/schema-builder#text-and-vector-indexes).
- A string as the vector calls Laravel's `toEmbeddings()`, as in Laravel.

## Random order

`inRandomOrder()` gives `order by rand()`. ClickHouse has no random function with a seed, so a seed throws an `InvalidArgumentException`.
For an order that repeats, order by a hash: `orderByRaw('cityHash64(?, id)', [$seed])`.
