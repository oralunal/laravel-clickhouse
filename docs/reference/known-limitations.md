# Known limitations

These limits come from ClickHouse or from the design of the package.

| Limit | What to do |
| --- | --- |
| No transactions. `beginTransaction()`, `transaction()` and `commit()` throw a `LogicException`. | Use the `DatabaseTruncation` test trait. See [Tests](/advanced/testing). |
| No auto-increment. `insertGetId()` needs the key in the row. | Set the key, for example with `Str::uuid()`. Set `$incrementing` to `false` on Eloquent models. |
| No foreign keys, unique constraints or spatial indexes. | The schema builder sends nothing for them. |
| `BaseModel` is not an Eloquent model: no relationships, timestamps or primary key. | See [Differences from Eloquent](/models/defining-models#differences-from-eloquent). |
| `save()` inserts a new row. It does not update a row. | Use `update()` on a query. See [Updates](/query-builder/writing-data#updates). |
| A mutation uses only the `where` and `prewhere` conditions. | The package refuses other clauses. Select the keys first. See [Refused clauses](/query-builder/writing-data#refused-clauses). |
| A mutation with a float writes it as `Float64`. A `Decimal` column stores less than you meant. | Give a numeric string or an int. |
| ClickHouse 24.8 has no correlated sub-queries. | Select the keys first. |
| `upsert()`, `insertOrIgnore()`, JSON paths and `whereFullText()` of Laravel's query builder throw. | See [Not supported](/query-builder/laravel-query-builder#not-supported). |
| `time()`, `set()`, `computed()`, full-text and vector indexes of the schema builder throw. | See [Not supported](/schema/schema-builder#not-supported). |
| `select()` reads only JSON results. | Use the `format()` of the query builder, or `getClient()->select($sql)->rawData()`. |
| A session runs one query at a time. `Parallel`, `insertFiles()` and asynchronous requests throw in a session. | See [Sessions](/advanced/sessions#limits). |
| `retry_on` set to `any` can run a write two times. | Use `unsent` on connections that write. See [Retries](/advanced/timeouts-and-retries#retries). |
