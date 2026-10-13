# Known limitations

These limits come from ClickHouse or from the design of the package.

| Limit | What to do |
| --- | --- |
| No transactions. `beginTransaction()`, `transaction()` and `commit()` throw a `LogicException`. | Use the `DatabaseTruncation` test trait. See [Tests](/advanced/testing). |
| No auto-increment. `insertGetId()` needs the key in the row. | Use `HasUuids` or `HasUlids` on a model, or set the key, for example with `Str::uuid()`. The Eloquent model of the package has `$incrementing = false`. See [Keys](/models/defining-models#keys). |
| No foreign keys, unique constraints or spatial indexes. | The schema builder sends nothing for them. |
| An update or a deletion is a mutation, which ClickHouse runs in the background. | Wait for it with `->settings('mutations_sync', 2)`, or use a lightweight delete. See [Mutation settings](/query-builder/writing-data#mutation-settings). |
| A mutation uses only the `where` and `prewhere` conditions. | The package refuses other clauses. Select the keys first. See [Refused clauses](/query-builder/writing-data#refused-clauses). |
| A computed float keeps all its digits: `4.35 * 100` is `434.99999999999994`. A `Decimal(10, 2)` column stores `434.99`, and an `Int` column cuts the fraction. | Round the float to the scale of the column, or give a numeric string or an int. |
| ClickHouse 24.8 has no correlated sub-queries. `withCount()`, `withExists()` and the other `with` aggregates of relations fail on 24.8, and so does a `whereHas()` whose query reads the outer table or has a `limit()`. On 26.3 and 26.8, `withCount()` gives `null`, not `0`, for a model without related rows. | Use 26.3 or later, or select the keys first. Write `$user->posts_count ?? 0`. `has()` and `whereHas()` use an `IN` sub-query otherwise. See [Relations](/models/eloquent#relations). |
| `upsert()` and `insertOrIgnore()` of Laravel's query builder throw. | See [Not supported](/query-builder/laravel-query-builder#not-supported). |
| JSON paths read a `String` column, not the native `JSON` type, and `update()` cannot set an array element of a path. | Read a `JSON` column with `whereRaw()`. Update the whole column. See [JSON paths](/query-builder/conditions#json-paths). |
| `time()`, `fullText()`, `vectorIndex()` and `whereFullText()` need ClickHouse 26.3 or later. `set()` and `computed()` throw. | See [Text and vector indexes](/schema/schema-builder#text-and-vector-indexes) and [Not supported](/schema/schema-builder#not-supported). |
| At `datetime_precision` `microsecond`, `created_at` and `updated_at` have a sub-second part, which a `DateTime` column refuses on ClickHouse 24.8 and 26.3. 26.8 cuts it to the second. | Create the columns with `$table->timestamps(6)`, or set `$dateFormat = 'Y-m-d H:i:s'` on the model. See [Date format](/models/defining-models#date-format). |
| `select()`, `get()` and `Parallel` read only JSON results. | Read another format with `getStatement('CSV')->rawData()` of the query builder, or with `DB::connection('clickhouse')->selectStatement($sql)->rawData()`. |
| A session runs one query at a time. `Parallel`, `insertFiles()` and asynchronous requests throw in a session. | See [Sessions](/advanced/sessions#limits). |
| A read-only user, the `readonly` option of a connection, cannot change a setting. A `Decimal` then comes as a JSON number, which keeps about 15 significant digits, and NaN and the infinities of a `Float` come as `null`. | Use a user that can change settings, such as a profile with `readonly = 2`. See [Static analysis](/reference/static-analysis#the-types-of-the-rows). |
| The PHPStan extension finds the connection of a migration only when it is a constant: `Schema::connection('clickhouse')`, or a string in the `$connection` property of the migration. | Declare `SchemaBlueprint` as the type of the parameter of the schema callback. See [Static analysis](/reference/static-analysis#limits). |
