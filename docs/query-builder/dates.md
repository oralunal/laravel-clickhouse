# Dates and times

## How the package writes a date

The package writes a `DateTimeInterface` value, Carbon included, in the time zone of the date.
By default, it removes the sub-second part: `'2024-01-02 03:04:05'`.

- ClickHouse reads the literal in the time zone of the column, or of the server for a column without one. If the two time zones are different, convert the date first.
- ClickHouse does not compare a `Date` or `Date32` column with a `'Y-m-d H:i:s'` literal. The query fails with `TYPE_MISMATCH`. Give `$date->format('Y-m-d')` for these columns, or use `whereDate()`.
- The package writes a string as it is.

## Microseconds

To keep the sub-second part, set `datetime_precision` to `microsecond`:

```dotenv
CLICKHOUSE_DATETIME_PRECISION=microsecond
```

A condition and an `update()` value then write a date with a sub-second part as a `DateTime64(6)`. A whole second stays a string literal:

```php
$at = Carbon::parse('2024-01-02 03:04:05.123456');

DB::connection('clickhouse')->table('my_table')->where('created_at', '>=', $at);
// select * from "my_table" where "created_at" >= toDateTime64('2024-01-02 03:04:05.123456', 6)

DB::connection('clickhouse')->table('my_table')->whereIn('created_at', [$at, $at->copy()->startOfSecond()]);
// select * from "my_table" where "created_at" in (toDateTime64('2024-01-02 03:04:05.123456', 6), '2024-01-02 03:04:05')
```

Inserts, raw SQL bindings and `escape()` write the date as `'2024-01-02 03:04:05.123456'`.

The option applies to:

- the conditions, `update()` and `withAlias()` of the query builder;
- the `?` and named bindings of raw SQL, and `Connection::escape()`;
- all inserts, in the two insert formats;
- the date casts of an Eloquent model without `$dateFormat`.

The option takes `second` or `microsecond`, in any letter case, or a case of `Oralunal\LaravelClickHouse\Enums\DateTimePrecision`. `null` and `''` are `second`.
There is no `millisecond`: ClickHouse cuts a date to the precision of its column.

### Result by column type

ClickHouse compares a `DateTime64(6)` value exactly with the column. Checked on ClickHouse 24.8:

| Column | Date with a sub-second part, at `microsecond` |
| --- | --- |
| `DateTime64(6)` | Kept, in inserts, conditions, `update()` and `delete()`. `where('d6', $at)` finds the row that the same `$at` inserted. |
| `DateTime64(3)` | Cut to milliseconds in an insert. A condition compares the microseconds, so `where('d3', $at)` does not find that row. Cut the date: `where('d3', $at->copy()->startOfMillisecond())`. |
| `DateTime` | A condition does not match the whole second: `where('dt', $at)` finds no row, and `where('dt', '<', $at)` finds the row of `03:04:05`. An insert depends on the server, see below. |
| `Date`, `Date32` | As at `second`. Give `$date->format('Y-m-d')`. |

An insert into a `DateTime` column writes the sub-second part as a string literal.
The settings `date_time_input_format` and, on 26.x only, `cast_string_to_date_time_mode` control if ClickHouse refuses or cuts it.
24.8 and 26.3 use `basic`, which refuses it with `CANNOT_PARSE_TEXT`. 26.8 uses `best_effort`, which cuts it to the second.
See [ClickHouse versions](/reference/clickhouse-versions#dates-with-a-sub-second-part).

On a connection that also writes `DateTime` columns:

- Give these columns a string, such as `$date->format('Y-m-d H:i:s')`, or `$date->copy()->startOfSecond()`.
- Give Eloquent models with such a column `protected $dateFormat = 'Y-m-d H:i:s';`.

### Second precision

At `second`, the default, the package removes the sub-second part. To compare a `DateTime64` column to the millisecond, give a string,
`$date->format('Y-m-d H:i:s.v')`, or set `datetime_precision`.

## Date conditions

| Method | SQL |
| --- | --- |
| `whereDate('created_at', '2024-01-05')` | `toDate32("created_at") = '2024-01-05'` |
| `whereDate('created_at', '>=', Carbon::parse('2024-02-15 23:00:00'))` | `toDate32("created_at") >= '2024-02-15'` |
| `whereTime('created_at', '>=', '9:05')` | `formatDateTime("created_at", '%H:%i:%S') >= '09:05:00'` |
| `whereDay('created_at', 5)` | `toDayOfMonth("created_at") = 5` |
| `whereMonth('created_at', '>', 1)` | `toMonth("created_at") > 1` |
| `whereYear('created_at', Carbon::parse('2024-06-01'))` | `toYear("created_at") = 2024` |

Each method has `or`, `preWhere` and `orPreWhere` forms, such as `orWhereDate()` and `preWhereYear()`.

- `whereDate()` uses `toDate32()`. It works on `Date`, `Date32`, `DateTime` and `DateTime64` columns, and keeps dates before 1970 and after 2149. It can use the primary key.
- `whereTime()` compares the time of day to the second, as text, in the time zone of the column. Laravel pads each part: `'7:8'` becomes `'07:08:00'`. Another string, such as `'10:20 am'`, is compared as text. `formatDateTime()` cannot use the primary key.
- `whereDay()`, `whereMonth()` and `whereYear()` compare with an integer: `whereDay('created_at', '05')` gives `= 5`, and `whereDay('created_at', 5.5)` gives `= 5`.
- A `DateTimeInterface` value gives its parts in its own time zone.
- With `null`, the condition is `= null` and matches no row, as on the other drivers of Laravel. Use `whereNull()`.
- In a join closure, Laravel's `JoinClause` writes `whereDay()` and `whereMonth()` with padded text, such as `'05'`, which fails with `TYPE_MISMATCH`.

## Model dates

The date casts of an Eloquent model follow `datetime_precision`, unless the model has `$dateFormat`. See [Eloquent models](/models/eloquent).
