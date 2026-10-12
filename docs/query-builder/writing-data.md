# Inserts, updates and deletions

The examples use `$query = DB::connection('clickhouse')->table('my_table')`. An Eloquent model has the same methods, see [Eloquent models](/models/eloquent).

## Inserts

```php
$query->insert([['id' => 20, 'field_one' => 'p'], ['id' => 21, 'field_one' => 'q']]);
// insert into "my_table" ("field_one", "id") values ('p', 20), ('q', 21)

$query->insert([['id' => 20, 'field_one' => 'p']], 'JSONEachRow');
// insert into "my_table" ("id", "field_one") format JSONEachRow, with the rows in the request body
```

- `insert($values, $format = null)` takes one row or a list of rows. The format is `Values`, `JSONEachRow`, a case of `Oralunal\LaravelClickHouse\Enums\Format`, or `null` for the `insert_format` option of the connection.
- `insert([])` returns `true` and sends nothing. A row without keys throws `Inserting empty values array is not supported in ClickHouse` before the request.
- `insertGetId()` inserts the row and returns its key as you gave it. ClickHouse has no auto-increment.
- `insertFiles()` inserts CSV, TSV or JSONEachRow files.

For the formats, buffered inserts and files, see [Insert rows](/models/inserting-rows).

## Deletions

`delete()` sends a lightweight delete or a mutation:

```php
$query->where('id', 123)->delete(lightweight: true);
// delete from "my_table" where "id" = 123

$query->where('id', 123)->delete(lightweight: false);
// alter table "my_table" delete where "id" = 123

$query->delete(5);
// alter table "my_table" delete where "id" = 5
```

| | Lightweight `DELETE FROM` | `ALTER TABLE ... DELETE` |
| --- | --- | --- |
| Speed | Marks the rows as deleted | Writes all data parts that hold a row again. It uses many resources. |
| When the call returns | The rows are marked. `SELECT` does not see them (`lightweight_deletes_sync = 2`). | The mutation is in the queue. The rows can still be there (`mutations_sync = 0`). |

- `delete($id = null, $lightweight = null, $partition = null)`: `true` sends a lightweight `delete from`, `false` an `alter table ... delete`, and `null` follows the `use_lightweight_delete` option of the connection. The default is `false`:

  ```dotenv
  CLICKHOUSE_USE_LIGHTWEIGHT_DELETE=true
  ```

- The first argument is Laravel's `$id`. Give the options by name. A bool as the first argument throws an `InvalidArgumentException`, because `delete(true)` would delete the row whose `id` is `1`:
  `delete() takes the id of a row as its first argument, a bool given. Pass the option by name: delete(lightweight: true).`
- The `delete()` of an Eloquent builder has no `$id`: `MyModel::where('id', 1)->delete(true)` sends a lightweight delete.
- A lightweight delete works only on tables of the MergeTree family. ClickHouse refuses it on other engines, such as `Memory`.

### Rules

- `delete()` needs a `where()` or `preWhere()` condition. Without one, it throws a `QueryException` and sends nothing:
  `Cannot delete without a where condition. Add a where condition, or call truncate() to remove every row.`
- A mutation has no `PREWHERE`. The `preWhere()` conditions join the `where()` conditions:

  ```php
  $query->where('field_two', '<', 0)->preWhere('created_at', '<', '2024-01-01 00:00:00')->delete(lightweight: false);
  // alter table "my_table" delete where ("created_at" < '2024-01-01 00:00:00') and ("field_two" < 0)
  ```

- A mutation removes the table name and the alias from the column names of its own table.
- Laravel's `first()`, `value()`, `find()`, `sole()`, `chunk()` and `paginate()` leave a `LIMIT` on the builder, which a mutation refuses. Start a new query for a mutation.
- `updateOrInsert()` adds `limit(1)` to its update, so it throws when the row exists.

::: danger
`whereNotIn('id', $ids)` with an empty `$ids` adds `1 = 1`. As the only condition, or with `or`, it deletes all rows.
:::

### Refused clauses

A mutation uses only the `where` and `prewhere` conditions. The package refuses a query with a clause that the mutation would ignore.
It throws a `QueryException` and sends nothing, because the mutation could change rows that the query does not select.

| Refused | Example |
| --- | --- |
| `limit()`, `groupLimit()`, an `offset()` above `0` | `->limit(2)->delete()` |
| `groupBy()`, `having()`, unions, `intersect()`, `except()` | `->groupBy('id')->update([...])` |
| Joins, `arrayJoin()`, `limitBy()`, `withExpression()`, `withAlias()` | `->join(...)->delete()` |
| `final()`, `sample()` | `->final()->delete()` |
| A select alias or expression | `->select('id as x')` |
| An order that defines an alias, calls `arrayJoin()`, or limits the rows with a raw `LIMIT`, `LIMIT ... BY`, `OFFSET` or `FETCH` | `->orderBy('a as b')` |
| A setting that changes which rows a select reads, such as `limit`, `offset` or `additional_table_filters` | `->settings(['limit' => 1])->delete()` |

```php
$query->where('field_two', '<', 0)->orderBy('created_at')->limit(2)->delete(lightweight: false);
// Throws QueryException: Cannot delete with a query that uses LIMIT: a ClickHouse DELETE only takes the where
// and prewhere conditions, so this clause would be ignored, ... Select the keys first, and delete by them instead:
// whereIn('id', $query->pluck('id')). ...
```

To delete the rows of such a query, select their keys first:

```php
$ids = DB::connection('clickhouse')->table('my_table')
    ->where('field_two', '<', 0)->orderBy('created_at')->limit(2)->pluck('id');

DB::connection('clickhouse')->table('my_table')->whereIn('id', $ids)->delete(lightweight: false);
// alter table "my_table" delete where "id" in (123, 124)
```

- The mutation ignores a select list of plain columns and another order.
- A mutation sends the settings that change how it runs. See [Mutation settings](#mutation-settings).
- Raw SQL in `fromRaw()`, such as a table function, is no table. ClickHouse refuses the mutation with `SYNTAX_ERROR`.
- For a long list of keys, increase `max_query_size` (256 KiB by default) in the connection `settings`.
  Do not give the query as a sub-query, as in `whereIn('id', $query)`, when its rows depend on other rows of the table.
  ClickHouse can run the sub-query again for each data part, so the mutation can delete more or fewer rows.

### Conditions that ClickHouse cannot run

ClickHouse accepts some conditions in a mutation, but then fails the mutation again and again in the background.
Such a mutation stops all later mutations of the table. The package refuses these conditions before it sends the mutation:

- **A sub-query, or `in` with a table.** Before the mutation, the package sends `explain plan select 1 from <table> <conditions>`.
  If ClickHouse refuses it, the call throws a `QueryException`. The ClickHouse error is the previous exception:

  ```php
  DB::connection('clickhouse')->table('users')
      ->whereExists(fn ($query) => $query->from('orders')->whereColumn('orders.user_id', 'users.id'))
      ->delete(lightweight: false);
  // Throws QueryException: Cannot delete with a where condition that ClickHouse cannot run: it refused
  // the condition in a SELECT with UNSUPPORTED_METHOD. ... Nothing was sent. Select the keys first, and
  // delete by them instead: whereIn('id', $query->pluck('id')).
  ```

  The `EXPLAIN` is one more read-only request. Each error refuses the mutation, `ACCESS_DENIED` included. While the connection pretends, it is not sent.
  ClickHouse 26.3 and 26.8 refuse a correlated sub-query in a mutation themselves, at once.
- **A lightweight delete with `UNION`, `INTERSECT` or `EXCEPT` in its condition.** ClickHouse 24.8 and 26.3 fail it and keep its mutation.
  Use `delete(lightweight: false)`. Raw SQL with these words, such as `SELECT * EXCEPT (col)`, is also refused.

To remove a failed mutation that you sent with `statement()`:

```sql
KILL MUTATION WHERE database = 'default' AND table = 'my_table';
```

### Projections

ClickHouse refuses a lightweight delete on a table with projections, unless `lightweight_mutation_projection_mode` is `drop`. `delete(lightweight: false)` works on such tables.

- On 24.8, it is a query setting: `->settings('lightweight_mutation_projection_mode', 'drop')->delete(lightweight: true)`, or the connection `settings`.
- From 24.9, it is a table setting. Set it on the table:

  ```sql
  ALTER TABLE my_table MODIFY SETTING lightweight_mutation_projection_mode = 'drop';
  ```

## Updates

```php
$query->where('id', 123)->update(['field_one' => 'new_val']);
// alter table "my_table" update "field_one" = 'new_val' where "id" = 123

$query->where('id', 123)->update(['field_one' => DB::raw("concat(field_one, 'new_val')")]);
// alter table "my_table" update "field_one" = concat(field_one, 'new_val') where "id" = 123

$query->where('id', 123)->update(['tags' => ['a', 'b']]);
// alter table "my_table" update "tags" = ['a', 'b'] where "id" = 123
```

- `update($values, $partition = null)` sends an `ALTER TABLE ... UPDATE` mutation. It returns `1`, or `0` while the connection pretends. The call returns when the mutation is in the queue.
- Values are written as in [conditions](/query-builder/conditions#values). An array or a collection is an array literal. For a `Map`, use `DB::raw("map('k', 1)")`.
- The grammar quotes each column name as one identifier. A column name such as `{0}` stays a name. Do not take column names from user input: Laravel reads a dot in a name as a qualifier and the word `as` as an alias.
- `update()` has the rules and refusals of `delete()`. To update all rows, add `whereRaw('1')`.
- ClickHouse cannot update a column of the sorting key. A `ReplacingMergeTree` table also refuses an update of its version column.

::: warning
ClickHouse reads a float in a mutation as `Float64` and cuts it at the scale of the column.
`update(['price' => 19.99])` stores `19.98` in a `Decimal(10, 2)` column. `round()` does not help.
Give a numeric string, such as `'19.99'`, or an int, such as `(int) round(0.29 * 100)`.
:::

## Mutation settings

`settings()` of a `delete()` or an `update()` goes after the statement, for the settings that change how the mutation runs or how long the call waits for it:

```php
$query->where('id', 123)->settings('mutations_sync', 2)->update(['field_one' => 'new_val']);
// alter table "my_table" update "field_one" = 'new_val' where "id" = 123 settings mutations_sync=2

$query->where('id', 123)->settings('lightweight_deletes_sync', 2)->delete(lightweight: true);
// delete from "my_table" where "id" = 123 settings lightweight_deletes_sync=2
```

- `mutations_sync = 2` makes the call return when the mutation is done on all replicas, and `1` when it is done on this server.
- A mutation takes these settings: `mutations_sync`, `alter_sync`, `replication_alter_partitions_sync`, `lightweight_deletes_sync`, `lightweight_delete_mode`, `lightweight_mutation_projection_mode`, `enable_lightweight_delete`, `allow_experimental_lightweight_delete`, `allow_nondeterministic_mutations`, `mutations_execute_subqueries_on_initiator`, `mutations_execute_nondeterministic_on_initiator`, `mutations_max_literal_size_to_replace`, `distributed_ddl_task_timeout`, `distributed_ddl_output_mode`, `log_comment`, `max_query_size`, `max_ast_depth`, `max_ast_elements`, `max_expanded_ast_elements` and `max_parser_depth`. `QueryGrammar::MUTATION_SETTINGS` lists them.
- ClickHouse ignores the other settings in a mutation, such as `limit`, `offset` or `additional_table_filters`, which change the rows that a select reads. The mutation would then change every row that the conditions match. The package refuses them and sends nothing:

  ```text
  Cannot delete with a query that uses SETTINGS: ... A mutation ignores the settings limit, which change the rows
  that a SELECT reads. It only takes the settings that change how it runs, such as mutations_sync and
  lightweight_deletes_sync.
  ```

- The connection `settings` go with every query, mutations included.

## Partitions

`delete()` and `update()` take a partition. An int is a number, a string is a quoted string, and `DB::raw()` is SQL.
The examples use a table with `PARTITION BY toYYYYMM(created_at)`:

```php
$query->where('field_two', '<', 0)->delete(lightweight: false, partition: 202401);
// alter table "my_table" delete in partition 202401 where "field_two" < 0

$query->where('field_two', '<', 0)->delete(lightweight: false, partition: DB::raw("ID '202401'"));
// alter table "my_table" delete in partition ID '202401' where "field_two" < 0

$query->where('id', 123)->update(['field_one' => 'new_val'], '202401');
// alter table "my_table" update "field_one" = 'new_val' in partition '202401' where "id" = 123
```

A lightweight delete in a partition, `delete(lightweight: true, partition: $partition)`, needs ClickHouse 24.9 or later.

## ON CLUSTER

On a cluster with tables that are not replicated, each node holds its own rows. `onCluster()` sends the statement to all nodes:

```php
$query->where('id', 123)->onCluster('company_cluster')->delete(lightweight: true);
// delete from "my_table" on cluster 'company_cluster' where "id" = 123

$query->where('id', 123)->onCluster('company_cluster')->update(['field_one' => 'new_val']);
// alter table "my_table" on cluster 'company_cluster' update "field_one" = 'new_val' where "id" = 123
```

A `Replicated*MergeTree` table does not need it. ClickHouse sends deletes, updates and `TRUNCATE` to the other replicas.

The `use_on_cluster` option adds `on cluster '<cluster_name>'` to all `delete()`, `update()`, `truncate()` and `optimize()` statements of the connection:

```php
$query->optimize(true, 202401);
// optimize table "my_table" on cluster 'company_cluster' partition 202401 final

$query->where('id', 123)->withoutOnCluster()->delete(lightweight: false);
// alter table "my_table" delete where "id" = 123

DB::connection('clickhouse')->withoutOnCluster(fn () => DB::connection('clickhouse')->table('my_table')->truncate());
// truncate table "my_table"
```

- `onCluster('other_cluster')` names another cluster for one query.
- `withoutOnCluster()` removes `ON CLUSTER` from one query.
- `Connection::withoutOnCluster($callback)` removes it from all mutations while the callback runs. It does not change the schema builder.
- The option needs `cluster_name`. Each mutation waits for all hosts and fails while a host is down. Use it for tables that are not replicated.

See [Clusters](/advanced/clusters).

## Truncate

```php
$query->truncate();
// truncate table "my_table"

DB::connection('clickhouse')->table('default.my_table')->truncate();
// truncate table "default"."my_table"

DB::connection('clickhouse')->table('my_table')->onCluster('company_cluster')->truncate();
// truncate table "my_table" on cluster 'company_cluster'
```

`truncate()` ignores the conditions and the alias of the query.

## Optimize

[`OPTIMIZE`](https://clickhouse.com/docs/en/sql-reference/statements/optimize/) merges the data parts of a table:

```php
$query->optimize(true, 202401);
// optimize table "my_table" partition 202401 final

$query->optimize(false, "it's");
// optimize table "my_table" partition 'it\'s'

MyModel::optimize(true);
// optimize table "my_table" final
```

- `optimize($final = false, $partition = null)` returns `true`. The query builder, the Eloquent builder and the model have it.
- The partition is written as in `delete()`. Only `null` removes the `partition` clause, so `''` and `'0'` are partitions.

## Table names

`delete()`, `update()`, `truncate()`, `optimize()` and `insert()` quote the table of `table()` or `from()` as a `select` does: `default.my-table` is `"default"."my-table"`.
Give the name without quotes.

## Pretend

While the connection [pretends](/advanced/raw-sql#pretend-mode), as in `migrate --pretend`, the package logs `insert()`, `delete()`, `update()`, `truncate()` and `optimize()` and does not send them.
