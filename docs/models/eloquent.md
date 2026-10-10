# Eloquent models

`Oralunal\LaravelClickHouse\Eloquent\Model` is an Eloquent model for a ClickHouse table. It has Eloquent's relations, timestamps, casts, events, observers and `find()`.
For the package's own model, which is not an Eloquent model, see [Define a model](/models/defining-models).

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Oralunal\LaravelClickHouse\Eloquent\Model;

class Event extends Model
{
    protected $table = 'events';

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class); // A model of another connection, such as MySQL
    }
}
```

| Property | Default | Description |
| --- | --- | --- |
| `$connection` | `clickhouse` | A ClickHouse connection. Another connection throws a `LogicException`. |
| `$incrementing` | `false` | ClickHouse has no auto-increment. Give the key before `create()`, for example with `Str::uuid()`. |
| `$keyType` | `string` | Set `int` for an integer key. |
| `$timestamps` | `true` | `created_at` and `updated_at`, as in Eloquent |

## Queries

The queries of the model use [Laravel's query builder](/query-builder/laravel-query-builder), also when `fix_default_query_builder` is `true`.
So the ClickHouse clauses of that page work on the model:

```php
Event::find('e1');
Event::where('user_id', 1)->latest()->get();

Event::query()->final()->preWhere('user_id', 1)->where('type', 'click')->settings('max_threads', 1)->get();
// select * from "events" final prewhere "user_id" = ? where "type" = ? settings max_threads=1

Event::query()->insert($rows, 'JSONEachRow');
```

`newEloquentBuilder()` returns `Oralunal\LaravelClickHouse\Eloquent\Builder`, Eloquent's builder with the ClickHouse options below.

## Updates and deletions

ClickHouse changes rows with mutations. ClickHouse runs a mutation in the background, unless the `mutations_sync` setting waits for it.

| Call | SQL |
| --- | --- |
| `$event->save()` on an existing model, `$event->update([...])` | `alter table "events" update ... where "id" = ?` |
| `Event::where(...)->update($values, $partition = null)` | The same, with `in partition` for a partition |
| `$event->delete()`, `Event::where(...)->delete()` | `alter table "events" delete where ...`, or `delete from` with `use_lightweight_delete` |
| `Event::where(...)->delete(lightweight: true, partition: '202401')` | `delete from "events" in partition '202401' where ...` |
| `Event::where(...)->forceDelete($lightweight, $partition)` | The same, without `onDelete()` and the global scopes, as Eloquent's `forceDelete()` |

ClickHouse cannot update a column of the sorting key. A `ReplacingMergeTree` table also refuses an update of its version column.

## Relations

Relations load with separate queries, as Eloquent loads them. They work between ClickHouse models, and between a ClickHouse model and a model of another connection:

```php
$user = User::with('events')->find(42);     // A MySQL model with hasMany(Event::class)
$events = Event::with('user')->get();        // A ClickHouse model with belongsTo(User::class)
$user->events()->where('type', 'click')->count();
```

- `belongsToMany()`, `hasManyThrough()` and `hasOneThrough()` use a join. The tests of the package do not cover them.
- `whereHas()` and `has()` write a correlated sub-query. ClickHouse 24.8 cannot run it. 26.3 and 26.8 can.
- `upsert()`, `insertOrIgnore()` and the locks are not supported. See [Not supported](/query-builder/laravel-query-builder#not-supported).

## Parallel queries

`Parallel::getRows()` takes an Eloquent builder. It returns an Eloquent collection of models, with their eager loads:

```php
use Oralunal\LaravelClickHouse\Parallel;

$results = Parallel::getRows([
    'events' => Event::with('user')->where('user_id', 1),
    'top' => Event::query()->orderByDesc('amount')->limit(10),
]);
// $results['events'] and $results['top'] are Eloquent collections of Event models.
```

See [Parallel queries](/advanced/parallel-queries).
