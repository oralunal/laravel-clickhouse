# Dates and times

## How the package writes a date

The package writes a `DateTimeInterface` value, Carbon included, in the time zone of the date.
By default, it removes the sub-second part: `'2024-01-02 03:04:05'`.

- ClickHouse reads the literal in the time zone of the column, or of the server for a column without one. If the two time zones are different, convert the date first.
- ClickHouse does not compare a `Date` or `Date32` column with a `'Y-m-d H:i:s'` literal. The query fails with `TYPE_MISMATCH`. Give `$date->format('Y-m-d')` for these columns.
- The package writes a string as it is.

## Microseconds

To keep the sub-second part, set `datetime_precision` to `microsecond`:

```dotenv
CLICKHOUSE_DATETIME_PRECISION=microsecond
```

A date with a sub-second part is then written as `'Y-m-d H:i:s.u'`. A whole second stays `'Y-m-d H:i:s'`:

```php
$at = Carbon::parse('2024-01-02 03:04:05.123456');

MyTable::query()->where('created_at', '>=', $at);
// SELECT * FROM `my_table` WHERE `created_at` >= '2024-01-02 03:04:05.123456'

MyTable::query()->whereIn('created_at', [$at, $at->copy()->startOfSecond()]);
// SELECT * FROM `my_table` WHERE `created_at` IN ('2024-01-02 03:04:05.123456', '2024-01-02 03:04:05')
```

The option applies to:

- the conditions, `update()` and `withAlias()` of the query builder;
- the `?` and named bindings of raw SQL and of Laravel's query builder, and `Connection::escape()`;
- all inserts, in the two insert formats;
- the date casts of a model without `$dateFormat`.

The option takes `second` or `microsecond`, in any letter case. `null` and `''` are `second`. There is no `millisecond`: ClickHouse cuts a date to the precision of its column.
A query builder that you make with a client only, `new Builder($client)`, writes whole seconds.

### Result by column type

ClickHouse 24.8, 26.3 and 26.8 checked:

| Column | Date with a sub-second part, at `microsecond` |
| --- | --- |
| `DateTime64(6)` | Kept, in inserts, conditions, `update()` and `delete()` |
| `DateTime64(3)` | Cut to milliseconds in inserts and conditions. `where('d3', $at)` finds the row that the same `$at` inserted. |
| `DateTime` | 24.8 and 26.3 refuse it: a condition fails with `TYPE_MISMATCH`, an insert with `CANNOT_PARSE_TEXT`. 26.8 cuts it to the second. |
| `Date`, `Date32` | As at `second`. Give `$date->format('Y-m-d')`. |

On a connection that also writes `DateTime` columns:

- Give these columns a string, such as `$date->format('Y-m-d H:i:s')`.
- Give models with such a column `protected $dateFormat = 'Y-m-d H:i:s';`.

The settings `date_time_input_format` and, on 26.x only, `cast_string_to_date_time_mode` control if ClickHouse refuses or cuts the sub-second part.
24.8 and 26.3 use `basic`, which refuses. 26.8 uses `best_effort`, which cuts. See [ClickHouse versions](/reference/clickhouse-versions#dates-with-a-sub-second-part).

### Second precision

At `second`, the default, the package removes the sub-second part. To compare a `DateTime64` column to the millisecond, give a string,
`$date->format('Y-m-d H:i:s.v')`, or set `datetime_precision`.

## Date conditions

| Method | SQL |
| --- | --- |
| `whereDate('created_at', '2024-01-05')` | ``toDate32(`created_at`) = '2024-01-05'`` |
| `whereTime('created_at', '>=', '9:05')` | ``formatDateTime(`created_at`, '%H:%i:%S') >= '09:05:00'`` |
| `whereDay('created_at', '05')` | ``toDayOfMonth(`created_at`) = 5`` |
| `whereMonth('created_at', '>', 1)` | ``toMonth(`created_at`) > 1`` |
| `whereYear('created_at', Carbon::parse('2024-06-01'))` | ``toYear(`created_at`) = 2024`` |

Each method has `or`, `preWhere` and `orPreWhere` forms, such as `orWhereDate()` and `preWhereYear()`.

- `whereDate()` uses `toDate32()`. It works on `Date`, `Date32`, `DateTime` and `DateTime64` columns and keeps dates before 1970 and after 2149. It can use the primary key.
- `whereTime()` compares the time of day to the second, as text, in the time zone of the column. `'9:05'` becomes `'09:05:00'`. The hour has one or two digits, the minutes and seconds two. `'7:8'` throws an `InvalidArgumentException`. `formatDateTime()` cannot use the primary key.
- `whereDay()`, `whereMonth()` and `whereYear()` compare with an integer. A value that is not an integer, a string of digits or a date throws an `InvalidArgumentException`.
- A `DateTimeInterface` value gives its parts in its own time zone.
- `null` gives `IS NULL`, or `IS NOT NULL` with `!=`: `whereDate('deleted_at', null)` gives ``toDate32(`deleted_at`) IS NULL``. A list works with `IN`, `NOT IN`, `BETWEEN` and `NOT BETWEEN`.

For the date methods of Laravel's query builder, see [Laravel's query builder](/query-builder/laravel-query-builder#dates-and-times).

## Model dates

See [Date format](/models/defining-models#date-format).
