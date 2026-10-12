# Define an Eloquent model

A model of a ClickHouse table extends `Oralunal\LaravelClickHouse\Eloquent\Model`. It is an Eloquent model.
It has Eloquent's casts, accessors, mutators, events, observers, relations, timestamps and `find()`.

```php
namespace App\Models;

use Oralunal\LaravelClickHouse\Eloquent\Model;

class Event extends Model
{
    // Optional. Without it, the class name gives the table: Event => events.
    protected $table = 'events';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'happened_at' => 'datetime',
    ];
}
```

## Define a model

The model sets these defaults:

| Property | Default | Description |
| --- | --- | --- |
| `$connection` | `clickhouse` | A ClickHouse connection. A model on another connection throws a `LogicException` when it starts a query. |
| `$incrementing` | `false` | ClickHouse has no auto-increment. Give the key before `create()`, or let Eloquent make it. See [Keys](#keys). |
| `$keyType` | `string` | Set `int` for an integer key. |
| `$primaryKey` | `id` | The column that `find()`, `save()` and `delete()` of one model use |
| `$timestamps` | `true` | `created_at` and `updated_at`, as in Eloquent. Set `false` when the table has no such columns. |

A model on another connection, such as `sqlite`, throws:

```text
The model [App\Models\Event] extends Oralunal\LaravelClickHouse\Eloquent\Model, which needs a ClickHouse connection,
but its connection [sqlite] is a Illuminate\Database\SQLiteConnection.
```

For a second ClickHouse connection, set `$connection` to its name. See [Multiple connections](/advanced/multiple-connections).

## Keys

ClickHouse has no auto-increment, and an `INSERT` returns no key. Use one of Laravel's key traits, which give the key before the insert:

```php
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Oralunal\LaravelClickHouse\Eloquent\Model;

class Event extends Model
{
    use HasUuids; // An ordered UUID (version 7) in a UUID column. HasUlids gives a ULID in a FixedString(26) column.
}

$event = Event::create(['type' => 'click']);
$event->getKey(); // '01a1302e-f0b9-73b2-ba69-1d24a005db21'
// insert into "events" ("created_at", "id", "type", "updated_at")
//   values ('2024-01-02 03:04:05', '01a1302e-f0b9-73b2-ba69-1d24a005db21', 'click', '2024-01-02 03:04:05')
```

- `Schema::create()` with `$table->uuid('id')` gives a `UUID` column, and `$table->ulid('id')` a `FixedString(26)` column.
- For rows that `insert()` writes without a key, give the column a default, such as `DEFAULT generateUUIDv7()` or
  `DEFAULT generateSnowflakeID()` (both on 24.8 and later). ClickHouse fills it in, but `insert()` does not return it.

## Start a query

`query()`, `where()`, `select()` and the other static calls start a query on the table of the model:

```php
Event::query()->where('type', 'click')->latest('happened_at')->first();
// select * from "events" where "type" = 'click' order by "happened_at" desc limit 1

Event::query()->final()->preWhere('user_id', 1)->settings('max_threads', 2)->get();
// select * from "events" final prewhere "user_id" = 1 settings max_threads=2
```

The queries use the [query builder](/query-builder/basics), so its ClickHouse clauses work on the model.
See [Queries and mutations](/models/eloquent#queries).

## Create one row

```php
use Illuminate\Support\Str;

$event = Event::create(['id' => (string) Str::uuid(), 'type' => 'click', 'payload' => ['a' => 1]]);

$event = new Event(['id' => (string) Str::uuid()]);
$event->type = 'view';
$event->save();
```

`create()` and the first `save()` of a model send one `INSERT`. The casts, mutators and timestamps apply.
`save()` of a model that exists sends an `ALTER TABLE ... UPDATE`. See [Queries and mutations](/models/eloquent#updates-and-deletions).

To insert many rows in one request, see [Insert rows](/models/inserting-rows).

Mass assignment leaves out a key with a dot, as in Laravel, unless `$fillable` names it. For a column of a `Nested`
structure, such as `n.a`, add it to `$fillable`, or use `forceCreate()`, `forceFill()` or `insert()`:

```php
protected $fillable = ['id', 'n.a'];

Event::create(['id' => 1, 'n.a' => [1, 2]]); // n.a is [1, 2]
```

## Casts

The casts of `$casts` work as in Eloquent: `array`, `json`, `collection`, `datetime`, enum casts, `encrypted`,
cast classes and the others. They apply when you set and read an attribute, and on `create()` and `save()`.

`insert()`, `buffer()` and the query builder send the rows as given. No cast applies to them.
See [Insert rows](/models/inserting-rows).

## Date format

The date casts and the timestamps write a date as the connection writes dates. The `datetime_precision` option decides:

| `datetime_precision` | `2024-01-02 03:04:05.123456` | `2024-01-02 03:04:05` |
| --- | --- | --- |
| `second` (default) | `2024-01-02 03:04:05` | `2024-01-02 03:04:05` |
| `microsecond` | `2024-01-02 03:04:05.123456` | `2024-01-02 03:04:05` |

`fromDateTime()` of the model converts the date. It uses `Connection::formatDateTime()`.
A model with `$dateFormat` uses that format, as in Eloquent.

- At `microsecond`, `created_at` and `updated_at` get the microseconds of `now()`. A `DateTime` column refuses them on
  ClickHouse 24.8 and 26.3. Create the columns as `DateTime64(6)` with `$table->timestamps(6)`, or set
  `protected $dateFormat = 'Y-m-d H:i:s';`.
- To keep the microseconds of a `DateTime64` column at `second` precision, set `$dateFormat` to `Y-m-d H:i:s.u`.
- An attribute with a date cast reads back as a Carbon instance.

See [Dates and times](/query-builder/dates).

## Relations

A model has Eloquent's relations. A relation can join a ClickHouse model and a model of another connection:

```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Oralunal\LaravelClickHouse\Eloquent\Model;

class Event extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class); // A model of another connection, such as MySQL
    }
}

// In App\Models\User, a MySQL model:
public function events(): HasMany
{
    return $this->hasMany(Event::class);
}
```

The limits of relations on ClickHouse are in [Queries and mutations](/models/eloquent#relations).

## Events

The model fires Eloquent's [model events](https://laravel.com/docs/eloquent#events), such as `creating`, `created`,
`updating`, `updated`, `deleting` and `deleted`. Observers and `$dispatchesEvents` work as in Eloquent.

A query that updates or deletes many rows, such as `Event::where('type', 'click')->delete()`, fires no model event, as in Eloquent.

## Next steps

- [Insert rows](/models/inserting-rows).
- [Queries, updates and deletions of a model](/models/eloquent).
