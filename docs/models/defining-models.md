# Define a model

A model extends `Oralunal\LaravelClickHouse\BaseModel`. It is not an Eloquent model.
It has no primary key, timestamps or relationships.

```php
namespace App\Models\Clickhouse;

use Oralunal\LaravelClickHouse\BaseModel;

class MyTable extends BaseModel
{
    // Optional. Without it, the class name gives the table: MyTable => my_tables.
    protected $table = 'my_table';
}
```

## Tables of a model

A model can use other tables for inserts and for mutations:

| Property | Getter | Used by |
| --- | --- | --- |
| `$table` | `getTable()` | Queries |
| `$tableForInserts` | `getTableForInserts()` | Inserts. See [Buffer engine tables](/models/inserting-rows#buffer-engine-tables). |
| `$tableSources` | `getTableSources()` | `delete()`, `update()`, `truncate()` and `optimize()` |

Each getter returns `$table` when its property is not set.

## Start a query

`select()`, `where()` and `query()` start a query on the table of the model:

```php
use Oralunal\LaravelClickHouse\RawColumn;

$rows = MyTable::select(['field_one', new RawColumn('sum(field_two)', 'field_two_sum')])
    ->where('created_at', '>', '2020-09-14 12:47:29')
    ->groupBy('field_one')
    ->settings(['max_threads' => 3])
    ->getRows();

MyTable::query()->whereDate('created_at', '2024-01-05')->first();
// SELECT * FROM `my_table` WHERE toDate32(`created_at`) = '2024-01-05' LIMIT 1
```

`query()` starts a query without a condition, so any method of the query builder can come first.
See [Query builder basics](/query-builder/basics).

## Create one row

```php
$model = MyTable::create(['model_name' => 'model 1', 'some_param' => 1]);

$model = MyTable::make(['model_name' => 'model 1']);
$model->some_param = 1;
$model->save();

$model = new MyTable();
$model->fill(['model_name' => 'model 1', 'some_param' => 1])->save();
```

`save()` inserts a new row. It throws on a model that it saved before. To insert many rows, see [Insert rows](/models/inserting-rows).

## Casts

A model applies all Laravel cast types in `$casts` when you read an attribute:
`$model->column`, `getAttribute()` and `toArray()`.

When you set an attribute, only these casts change the stored value, which `create()` and `save()` insert:

| Cast | Stored value |
| --- | --- |
| `array`, `json`, `json:unicode`, `object`, `collection` | A JSON string |
| Enum cast | The value of a backed enum, or the name of a unit enum |
| `date`, `datetime`, `immutable_date`, `immutable_datetime` | A date string in `$dateFormat` |
| `encrypted`, `encrypted:*`, `hashed` | The encrypted or hashed string |
| Cast class | The return value of its `set()` method |

The other casts, such as `int`, `float`, `decimal:2`, `string`, `bool`, `boolean`, `timestamp` and
`datetime:Y-m-d`, store the value as you set it.

Row inserts change only `boolean` columns, to `0` or `1`. A `bool` cast is not changed.
`insertAssoc()` and `buffer()` find these columns by name. `insertBulk()` finds them in its `$columns` list:

```php
class MyTable extends BaseModel
{
    /** @var array<string, string> */
    protected $casts = ['some_bool_column' => 'boolean'];
}

MyTable::insertAssoc([
    ['some_param' => 1, 'some_bool_column' => false],
]);
```

A cast column that a row does not have stays out of the insert, so ClickHouse writes the column default.
The rows of `select()` are arrays, so no cast applies to them.

## Accessors, mutators and serialization

Models handle their attributes as Eloquent models do:

- Accessors and mutators: `getFooAttribute()` and `setFooAttribute()`, or a `foo(): Attribute` method.
- `$appends` adds accessors to the array of the model. `$hidden` and `$visible` remove attributes from it.
  `makeHidden()` and `makeVisible()` change them for one instance.
- `toArray()` and `attributesToArray()` apply the casts, accessors, `$appends`, `$hidden` and `$visible`.
  `getAttributes()` returns the stored values.
- `getOriginal()`, `syncOriginal()`, `isDirty()` and `getDirty()` compare the attributes with the original values.
  `wasChanged()` and `getChanges()` show the changes that `syncChanges()` records.

```php
use Illuminate\Database\Eloquent\Casts\Attribute;
use Oralunal\LaravelClickHouse\BaseModel;

class Visit extends BaseModel
{
    protected $casts = ['payload' => 'array'];

    protected $hidden = ['ip'];

    protected $appends = ['host'];

    public function setUrlAttribute($value)
    {
        $this->attributes['url'] = strtolower($value);
    }

    protected function host(): Attribute
    {
        return Attribute::get(fn ($value, array $attributes) => parse_url($attributes['url'], PHP_URL_HOST));
    }
}

$visit = Visit::make(['url' => 'https://Example.com/a', 'ip' => '10.0.0.1', 'payload' => ['a' => 1]]);

$visit->host;            // 'example.com'
$visit->payload;         // ['a' => 1]
$visit->getAttributes(); // ['url' => 'https://example.com/a', 'ip' => '10.0.0.1', 'payload' => '{"a":1}']
$visit->toArray();       // ['url' => 'https://example.com/a', 'payload' => ['a' => 1], 'host' => 'example.com']
```

### Attribute methods

A model has the attribute methods of Eloquent. They work as the [Eloquent documentation](https://laravel.com/docs/eloquent-mutators) tells:

| Area | Methods |
| --- | --- |
| Read | `getAttribute()`, `getAttributeValue()`, `getAttributes()`, `hasAttribute()`, `only()` |
| Write | `setAttribute()`, `fill()`, `setRawAttributes()`, `fillJsonAttribute()` |
| Original values | `getOriginal()`, `getRawOriginal()`, `syncOriginal()`, `syncOriginalAttribute()`, `syncOriginalAttributes()`, `originalIsEquivalent()`, `discardChanges()` |
| Changes | `isDirty()`, `isClean()`, `getDirty()`, `wasChanged()`, `getChanges()`, `syncChanges()` |
| Casts | `getCasts()`, `hasCast()`, `mergeCasts()`, `getDates()`, `fromDateTime()`, `fromJson()`, `fromFloat()`, `fromEncryptedString()` |
| Date format | `getDateFormat()`, `setDateFormat()` |
| Appended accessors | `append()`, `setAppends()`, `mergeAppends()`, `withoutAppends()`, `getAppends()`, `hasAppended()` |
| Accessors and mutators | `hasGetMutator()`, `hasSetMutator()`, `hasAttributeMutator()`, `hasAttributeGetMutator()`, `hasAttributeSetMutator()`, `hasAnyGetMutator()`, `getMutatedAttributes()`, `cacheMutatedAttributes()` |
| Visibility | `makeHidden()`, `makeVisible()` |

```php
$visit->fillJsonAttribute('payload->b', 2); // payload: '{"a":1,"b":2}'
$visit->only('url', 'host');                // ['url' => 'https://example.com/a', 'host' => 'example.com']
$visit->discardChanges();                   // Sets the attributes back to getOriginal().
```

The `encrypted` casts use the encrypter of the application. `BaseModel::encryptUsing($encrypter)` sets another encrypter, and `currentEncrypter()` returns the encrypter in use.

## Date format

The date casts store a date in `$dateFormat`. Without `$dateFormat`, they store `Y-m-d H:i:s`.
When the `datetime_precision` option is `microsecond`, a date with a sub-second part is stored as `Y-m-d H:i:s.u`.

- To keep the microseconds of a `DateTime64` column at each precision, set `$dateFormat` to `Y-m-d H:i:s.u`.
- For a `DateTime` column on a connection at `microsecond`, set `protected $dateFormat = 'Y-m-d H:i:s';`.
  ClickHouse 24.8 and 26.3 refuse microseconds in a `DateTime` column. 26.8 removes them.
- To change how `toArray()` writes dates, override `serializeDate()`.

See [Dates and times](/query-builder/dates).

## Differences from Eloquent

- `isset($model->column)` is `true` for a column that holds `null`.
- `save()` and `create()` do not call `syncOriginal()` or `syncChanges()`, so `isDirty()` stays `true` after them.
- A missing attribute reads as `null`, also when it has the name of a method of the model.
- The `casts()` method, the `#[Hidden]`, `#[Visible]`, `#[Appends]` and `#[DateFormat]` attributes,
  and `Model::preventAccessingMissingAttributes()` are not supported. Use the properties.
- To throw a `MissingAttributeException` for a missing attribute after `save()`, override
  `public static function preventsAccessingMissingAttributes()` and return `true`.
- A cast class gets the `BaseModel`. Declare the `$model` parameter of its `get()` and `set()` without a type.
  The class of `php artisan make:cast` declares `Model`, which causes a `TypeError`.
- `json_encode($model)` does not encode the attributes. Use `json_encode($model->toArray())`.

## PHPStan and Larastan

Larastan analyses a model that extends `BaseModel` without the `class.missingExtends` error. Two items are different from Eloquent:

- Larastan reads the columns of Eloquent models only. Declare the columns of a ClickHouse model with `@property` tags.
- From level 6, PHPStan reports `missingType.iterableValue` on `$casts` and `$appends` without a type. Declare their types.

```php
/**
 * @property string $url
 * @property array<string, mixed> $payload
 * @property-read string $host
 */
class Visit extends BaseModel
{
    /** @var array<string, string> */
    protected $casts = ['payload' => 'array'];

    /** @var list<string> */
    protected $appends = ['host'];
}
```

## Events

A model fires some [Eloquent model events](https://laravel.com/docs/eloquent#events):

| Call | Events |
| --- | --- |
| `MyTable::create([...])` | `creating`, `saved`, `created` |
| `MyTable::make([...])->save()` | `saved` |

A `creating` listener that returns `false` stops `create()`. `save()` does not fire `creating`.
Observers and the `$dispatchesEvents` map are not supported.

Listen to an event with its Eloquent name:

```php
use Illuminate\Support\Facades\Event;

Event::listen('eloquent.creating: ' . MyTable::class, fn (MyTable $model) => $model->some_param > 0);
```

- `MyTable::withoutEvents(fn () => MyTable::create([...]))` runs the callback without events. It returns the value of the callback.
- The models use the event dispatcher of the application. `setEventDispatcher()`, `getEventDispatcher()` and `unsetEventDispatcher()` change and read it.
  All ClickHouse models share one dispatcher, so these methods and `withoutEvents()` apply to all models.
