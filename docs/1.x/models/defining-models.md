# Define a model

A model extends `PhpClickHouseLaravel\BaseModel`. It is not an Eloquent model.

```php
namespace App\Models\Clickhouse;

use PhpClickHouseLaravel\BaseModel;

class MyTable extends BaseModel
{
    // Optional. Without it, the class name gives the table: MyTable => my_tables.
    protected $table = 'my_table';
}
```

For a model of another connection, set `protected $connection`. See [Multiple connections](/1.x/advanced/multiple-connections).

## Casts

Before an insert, the package converts the columns of `$casts`. Casts apply to inserts only, not to `select()`.
The only cast type is `boolean`.

- `insertAssoc()` and `buffer()` find the cast columns by name.
- `insertBulk()` finds them in its `$columns` list.

```php
class MyTable extends BaseModel
{
    /**
     * The columns that should be cast.
     *
     * @var array
     */
    protected $casts = ['some_bool_column' => 'boolean'];
}

MyTable::insertAssoc([
    ['some_param' => 1, 'some_bool_column' => false],
]);
```

## Events

A model fires some [Eloquent model events](https://laravel.com/docs/eloquent#events), with the same names:

| Call | Events |
| --- | --- |
| `MyTable::create([...])` | `creating`, `saved`, `created` |
| `MyTable::make([...])->save()` | `saved` |

A `creating` listener that returns `false` stops `create()`. `save()` does not fire `creating`, so a listener cannot stop it.
Observers and the `$dispatchesEvents` map are not supported.

## Next steps

- [Insert rows](/1.x/models/inserting-rows)
- [Query builder](/1.x/query-builder/basics)
