# Known limitations

These limits come from ClickHouse or from the design of the package.

| Limit | What to do |
| --- | --- |
| No transactions. `beginTransaction()`, `transaction()` and `commit()` throw a `LogicException`. | Use the `DatabaseTruncation` test trait. See [Tests](/advanced/testing). |
| No auto-increment. `insertGetId()` needs the key in the row. | Set the key, for example with `Str::uuid()`. The Eloquent model of the package has `$incrementing = false`. See [Eloquent models](/models/eloquent). |
| No foreign keys, unique constraints or spatial indexes. | The schema builder sends nothing for them. |
| An update or a deletion is a mutation, which ClickHouse runs in the background. | Set the `mutations_sync` setting to wait for it, or use a lightweight delete. See [Updates and deletions](/query-builder/writing-data). |
| A mutation uses only the `where` and `prewhere` conditions. | The package refuses other clauses. Select the keys first. See [Refused clauses](/query-builder/writing-data#refused-clauses). |
| A computed float keeps all its digits: `4.35 * 100` is `434.99999999999994`. A `Decimal(10, 2)` column stores `434.99`, and an `Int` column cuts the fraction. | Round the float to the scale of the column, or give a numeric string or an int. |
| ClickHouse 24.8 has no correlated sub-queries, so `whereHas()` and `has()` fail on 24.8. | Select the keys first. |
| `upsert()`, `insertOrIgnore()`, JSON paths and `whereFullText()` of Laravel's query builder throw. | See [Not supported](/query-builder/laravel-query-builder#not-supported). |
| `time()`, `set()`, `computed()`, full-text and vector indexes of the schema builder throw. | See [Not supported](/schema/schema-builder#not-supported). |
| `select()`, `get()` and `Parallel` read only JSON results. | Read another format with `getStatement('CSV')->rawData()` of the query builder, or with `DB::connection('clickhouse')->selectStatement($sql)->rawData()`. |
| Mass assignment leaves out a key with a dot, such as the Nested column `n.a`, as in Laravel. | Use `forceCreate()`, `forceFill()` or `insert()`. |
| A session runs one query at a time. `Parallel`, `insertFiles()` and asynchronous requests throw in a session. | See [Sessions](/advanced/sessions#limits). |
| `retry_on` set to `any` can run a write two times. | Use `unsent` on connections that write. See [Retries](/advanced/timeouts-and-retries#retries). |
