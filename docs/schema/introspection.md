# Schema introspection

Laravel's schema methods read ClickHouse connections:

```php
use Illuminate\Support\Facades\Schema;

Schema::getTables();                            // Tables of the database of the connection
Schema::getTableListing();                      // ['default.my_table', ...]
Schema::getViews();                             // Views and materialized views
Schema::getColumns('my_table');
Schema::getColumnListing('my_table');           // ['id', 'created_at', ...]
Schema::hasColumn('my_table', 'field_one');     // true
Schema::hasColumns('my_table', ['id', 'field_one']); // true
Schema::getColumnType('my_table', 'field_one'); // 'String'
Schema::getIndexes('my_table');
Schema::getIndexListing('my_table');            // ['primary']
Schema::hasIndex('my_table', 'primary');        // true
Schema::getCurrentSchemaName();                 // 'default', the database of the connection
Schema::getCurrentSchemaListing();              // ['default']
Schema::hasTable('my_table');                   // true for a table, false for a view or a dictionary
Schema::hasView('my_view');                     // Views and materialized views
Schema::hasDictionary('my_dictionary');         // Dictionaries
```

To read another database, give its name, or a list of names, to `getTables()`, `getTableListing()` and `getViews()`.
Give a `<database>.<table>` name to the other methods: `Schema::getTables('analytics')`, `Schema::getColumns('analytics.events')`.
When ClickHouse is not the default connection, use `Schema::connection('clickhouse')`.

## Results

| Method | Result |
| --- | --- |
| `getTables()` | The tables, with the database as `schema`, `total_bytes` as `size`, the comment and the engine. No views, materialized views, `.inner` tables or dictionaries. The `size` of a `Merge` table is `NULL`. |
| `getViews()` | The views and materialized views, with their `SELECT` as `definition` |
| `hasTable()` | `true` for a table only. Use `hasView()` for a view and `hasDictionary()` for a dictionary. |
| `getColumns()` | The full type, such as `LowCardinality(Nullable(String))`, as `type` and `type_name`. `default` is the `DEFAULT` expression. `MATERIALIZED` columns have a `stored` generation. `ALIAS` columns have a `virtual` generation. |
| `getIndexes()` | The primary key, with the name `primary`, then the data-skipping indexes |
| `getForeignKeys()`, `hasForeignKey()` | `[]` and `false`. ClickHouse has no foreign keys. |

- A column is `nullable` when its type accepts `NULL`: `Nullable(...)`, `LowCardinality(Nullable(...))`, `Variant(...)`, `Dynamic`, or a `SimpleAggregateFunction` over one of them. `Array(Nullable(String))` is not nullable.
- The `columns` of an index are the parts of its expression: `ORDER BY (id, intHash32(id))` gives `['id', 'intHash32(id)']`. `ORDER BY (id)` gives `['id']` on all versions.
- The `type` of the primary key is `null`. The `type` of a data-skipping index is its type, such as `minmax` or `bloom_filter`. Index names are lowercase. No index is unique.
- `Schema::disableForeignKeyConstraints()`, `enableForeignKeyConstraints()` and `withoutForeignKeyConstraints()` send no query.

## Artisan commands

```sh
php artisan db:show --database=clickhouse --counts --views
php artisan db:table my_table --database=clickhouse
```

- `db:show` shows the server version and the tables. `--counts` adds the row counts. `--views` adds the views.
- Open Connections are the client connections to the active node, which `system.metrics` counts. A user without access to the `system` tables gets an error.
- `db:table` shows the columns and indexes of one table. The primary key has the attribute `primary`, or `compound, primary` for many columns.
- `--counts` fails on a table that ClickHouse cannot read, such as `Kafka` or `Set`. `--views` fails on a parameterized view.
