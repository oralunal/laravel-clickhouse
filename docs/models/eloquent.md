# Queries and mutations

A model extends `Oralunal\LaravelClickHouse\Eloquent\Model`. This page shows its queries, its updates and deletions,
its relations and its parallel queries. To define a model, see [Define an Eloquent model](/models/defining-models).

## Queries

The queries of a model use the package's `QueryBuilder`, which is [Laravel's query builder](/query-builder/basics)
with the ClickHouse clauses. So the ClickHouse clauses work on the model:

```php
Event::find('e1');
Event::where('user_id', 1)->latest()->get();

Event::query()->final()->preWhere('user_id', 1)->where('type', 'click')->settings('max_threads', 1)->get();
// select * from "events" final prewhere "user_id" = 1 where "type" = 'click' settings max_threads=1

Event::query()->insert($rows, 'JSONEachRow');
```

`newEloquentBuilder()` returns `Oralunal\LaravelClickHouse\Eloquent\Builder`, Eloquent's builder with the ClickHouse
options below.

### Read the raw result

`getStatement()` runs the query and returns the smi2 `Statement` of its result, in a format that you choose.
The global scopes of the model apply. No model is made:

```php
use Oralunal\LaravelClickHouse\Enums\Format;

$csv = Event::query()->select('id', 'type')->where('type', 'click')->getStatement(Format::Csv)->rawData();
// select "id", "type" from "events" where "type" = 'click' format CSV
```

See [Read results](/query-builder/reading-results).

## Updates and deletions

ClickHouse changes rows with mutations. ClickHouse runs a mutation in the background, unless the `mutations_sync`
setting waits for it.

| Call | SQL |
| --- | --- |
| `$event->save()` on an existing model, `$event->update([...])` | `alter table "events" update ... where "id" = ...` |
| `Event::where(...)->update($values, $partition = null)` | The same, with `in partition` for a partition |
| `$event->delete()`, `Event::where(...)->delete()` | `alter table "events" delete where ...`, or `delete from` with `use_lightweight_delete` |
| `Event::where(...)->delete(true)` | `delete from "events" where ...`, a lightweight delete |
| `Event::where(...)->delete(lightweight: true, partition: '202401')` | `delete from "events" in partition '202401' where ...` |
| `Event::where(...)->forceDelete($lightweight, $partition)` | The same, without `onDelete()` and the global scopes, as Eloquent's `forceDelete()` |

```php
Event::where('id', 'e1')->update(['type' => 'view']);
// alter table "events" update "type" = 'view', "updated_at" = '2024-01-02 03:04:05' where "id" = 'e1'

Event::where('id', 'e1')->delete(true);
// delete from "events" where "id" = 'e1'
```

- The `delete()` of the Eloquent builder takes `$lightweight` as its first argument. The `delete()` of the query
  builder takes Laravel's `$id` first, so pass the option by name there: `delete(lightweight: true)`.
- With timestamps, Eloquent adds `updated_at` to an update, as in Laravel.
- ClickHouse cannot update a column of the sorting key. A `ReplacingMergeTree` table also refuses an update of its
  version column.
- `update()` and `delete()` refuse clauses that a mutation ignores, such as a join or a limit.
  See [Updates and deletions](/query-builder/writing-data).

### Optimize

`optimize()` sends `OPTIMIZE TABLE`. It starts a merge of the parts of the table:

```php
Event::optimize();                // optimize table "events"
Event::optimize(true);            // optimize table "events" final
Event::optimize(false, '202401'); // optimize table "events" partition '202401'

Event::where('type', 'click')->optimize(true); // The builder's optimize() works on the table of the model.
```

It returns `true` when ClickHouse accepts the statement. With `use_on_cluster`, it goes `ON CLUSTER`.
See [Clusters](/advanced/clusters).

## Insert rows

`insert()`, `create()`, `buffer()` and `insertFiles()` of a model are on [Insert rows](/models/inserting-rows):

```php
Event::insertFiles('/data/events.csv', 'CSVWithNames', ['id', 'type']);
Event::query()->insertFiles(['/data/a.csv', '/data/b.csv']);
```

## Relations

Relations load with separate queries, as Eloquent loads them. They work between ClickHouse models, and between a
ClickHouse model and a model of another connection:

```php
$user = User::with('events')->find(42);  // A MySQL model with hasMany(Event::class)
$events = Event::with('user')->get();    // A ClickHouse model with belongsTo(User::class)
$user->events()->where('type', 'click')->count();
```

- `belongsToMany()`, `hasManyThrough()` and `hasOneThrough()` use a join. The tests of the package do not cover them.
- `whereHas()` and `has()` write a correlated sub-query. ClickHouse 24.8 cannot run it. 26.3 and 26.8 can.
- `upsert()`, `insertOrIgnore()` and the locks are not supported.
  See [Not supported](/query-builder/laravel-query-builder#not-supported).

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
