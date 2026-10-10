# Updates and deletions

For inserts, see [Insert rows](/models/inserting-rows).

## Deletions

`delete()` sends a lightweight delete or a mutation:

```php
MyTable::where('id', 123)->delete(true);
// DELETE FROM my_table WHERE `id` = 123

MyTable::where('id', 123)->delete(false);
// ALTER TABLE my_table DELETE WHERE `id` = 123
```

| | Lightweight `DELETE FROM` | `ALTER TABLE ... DELETE` |
| --- | --- | --- |
| Speed | Marks the rows as deleted | Writes all data parts that hold a row again. It uses many resources. |
| When the call returns | The rows are marked. `SELECT` does not see them (`lightweight_deletes_sync = 2`). | The mutation is in the queue. The rows can still be there (`mutations_sync = 0`). |

A lightweight delete works only on tables of the MergeTree family. ClickHouse refuses it on other engines, such as `Memory`.

`delete()` without an argument sends a lightweight delete when `use_lightweight_delete` is `true`. The default is `false`:

```dotenv
CLICKHOUSE_USE_LIGHTWEIGHT_DELETE=true
```

### Rules

- `delete()` needs a `where()` or `preWhere()` condition. Without one, it throws a `QueryException` and sends nothing. To remove all rows, use `truncate()`.
- A delete has no `PREWHERE`. The `preWhere()` conditions join the `where()` conditions:

  ```php
  MyTable::where('field_two', '<', 0)->preWhere('created_at', '<', '2024-01-01 00:00:00')->delete(false);
  // ALTER TABLE my_table DELETE WHERE (`created_at` < '2024-01-01 00:00:00') AND (`field_two` < 0)
  ```

::: danger
`whereNotIn('id', $ids)` with an empty `$ids` adds `1 = 1`. As the only condition, or with `OR`, it deletes all rows.
:::

### Refused clauses

A mutation uses only the `where` and `prewhere` conditions. The package refuses a query with a clause that the mutation ignores.
It throws a `QueryException` and sends nothing, because the mutation can change rows that the query does not select.

| Refused | Example |
| --- | --- |
| `WITH`, `SAMPLE`, `ARRAY JOIN`, `JOIN`, `GROUP BY`, `HAVING`, `LIMIT BY`, `LIMIT`, set operations | `->limit(2)->delete()` |
| `FINAL` | `->final()->delete()` |
| A select list with anything but plain columns | `select('id as x')` |
| A sub-query or raw SQL in `from()` that is not a table name | `from(raw('numbers(3)'))` |
| An `ORDER BY` with `arrayJoin()`, an alias, an aggregate, a position, or a raw limit | `orderBy('a as b')`, `orderBy(1)` |
| `SETTINGS` | `->settings([...])->delete()` |

The mutation ignores a select list of plain columns, `format()` and other `ORDER BY` entries.

To delete the rows of such a query, select their keys first:

```php
MyTable::where('field_two', '<', 0)->orderBy('created_at')->limit(2)->delete(false);
// Throws QueryException: Cannot delete with a query that uses LIMIT: ...

$ids = MyTable::select('id')->where('field_two', '<', 0)->orderBy('created_at')->limit(2)->pluck('id');
MyTable::where('id', $ids)->delete(false);
// ALTER TABLE my_table DELETE WHERE `id` IN (123, 124)
```

For a long list of keys, increase `max_query_size` (256 KiB by default) in the connection `settings`.
Do not give the query as a sub-query, as in `whereIn('id', $query)`, when its rows depend on other rows of the table.
ClickHouse can run the sub-query again for each data part, so the mutation can delete more or fewer rows.

### Conditions that ClickHouse cannot run

ClickHouse accepts some conditions in a mutation, but then fails the mutation again and again in the background.
Such a mutation stops all later mutations of the table. The package refuses these conditions before it sends the mutation:

- **A sub-query, or `IN` with a table.** Before the mutation, the package sends `EXPLAIN PLAN SELECT 1 FROM <table> <conditions>`.
  If ClickHouse refuses it, the call throws a `QueryException`. The ClickHouse error is the previous exception:

  ```php
  DB::connection('clickhouse')->table('users')
      ->whereExists(fn ($query) => $query->from('orders')->whereColumn('orders.user_id', 'users.id'))
      ->delete(false);
  // Throws QueryException: Cannot delete with a where condition that ClickHouse cannot run: it refused
  // the condition in a SELECT with UNSUPPORTED_METHOD. ... Nothing was sent. Select the keys first, and
  // delete by them instead: whereIn('id', $query->pluck('id')).
  ```

  The `EXPLAIN` is one more read-only request. Each error refuses the mutation, `ACCESS_DENIED` included. ClickHouse 26.3 and 26.8 refuse a correlated sub-query in a mutation themselves, at once.
- **A lightweight delete with `UNION`, `INTERSECT` or `EXCEPT` in its condition.** ClickHouse 24.8 and 26.3 fail it and keep its mutation. Use `delete(false)`. Raw SQL with these words, such as `SELECT * EXCEPT (col)`, is also refused.

To remove a failed mutation that you sent with `statement()`:

```sql
KILL MUTATION WHERE database = 'default' AND table = 'my_table';
```

### Projections

ClickHouse refuses a lightweight delete on a table with projections, unless `lightweight_mutation_projection_mode` is `drop`. `delete(false)` works on such tables.

- On 24.8, it is a query setting. Put it in the connection `settings`.
- From 24.9, it is a table setting. Set it on the table:

  ```sql
  ALTER TABLE my_table MODIFY SETTING lightweight_mutation_projection_mode = 'drop';
  ```

## Updates

```php
MyTable::where('id', 123)->update(['field_one' => 'new_val']);
// ALTER TABLE my_table UPDATE `field_one` = 'new_val' WHERE `id` = 123

MyTable::where('id', 123)
    ->update(['field_one' => new RawColumn("concat(field_one,'new_val')")]);
// ALTER TABLE my_table UPDATE `field_one` = concat(field_one,'new_val') WHERE `id` = 123
```

- `update()` sends an `ALTER TABLE ... UPDATE` mutation. The call returns when the mutation is in the queue.
- Values are written as in [conditions](/query-builder/conditions#values). An array is an array literal: `update(['tags' => ['a', 'b']])` sets `` `tags` = ['a', 'b'] ``.
- The package quotes each column name as one identifier. A Nested column such as `n.a` is `` `n.a` ``.
- `update()` has the rules and refusals of `delete()`. To update all rows, add `whereRaw('1')`.

::: warning
ClickHouse reads a float in a mutation as `Float64` and cuts it at the scale of the column.
`update(['price' => 19.99])` stores `19.98` in a `Decimal(10, 2)` column. `round()` does not help.
Give a numeric string, such as `'19.99'`, or an int, such as `(int) round(0.29 * 100)`.
:::

## Partitions

`delete()` and `update()` take a partition as the second argument. An int is a number, a string is a quoted string, and an expression is written as it is.
The examples use a table with `PARTITION BY toYYYYMM(created_at)`:

```php
MyTable::where('field_two', '<', 0)->delete(false, 202401);
// ALTER TABLE my_table DELETE IN PARTITION 202401 WHERE `field_two` < 0

MyTable::where('field_two', '<', 0)->delete(false, new RawColumn("ID '202401'"));
// ALTER TABLE my_table DELETE IN PARTITION ID '202401' WHERE `field_two` < 0

MyTable::where('id', 123)->update(['field_one' => 'new_val'], '202401');
// ALTER TABLE my_table UPDATE `field_one` = 'new_val' IN PARTITION '202401' WHERE `id` = 123
```

A lightweight delete in a partition, `delete(true, $partition)`, needs ClickHouse 24.9 or later.

## ON CLUSTER

On a cluster with tables that are not replicated, each node holds its own rows. `onCluster()` sends the statement to all nodes:

```php
MyTable::where('id', 123)->onCluster('company_cluster')->delete(true);
// DELETE FROM my_table ON CLUSTER 'company_cluster' WHERE `id` = 123

MyTable::where('id', 123)->onCluster('company_cluster')->update(['field_one' => 'new_val']);
// ALTER TABLE my_table ON CLUSTER 'company_cluster' UPDATE `field_one` = 'new_val' WHERE `id` = 123
```

A `Replicated*MergeTree` table does not need it. ClickHouse sends deletes, updates and `TRUNCATE` to the other replicas.

The `use_on_cluster` option adds `ON CLUSTER '<cluster_name>'` to all `delete()`, `update()`, `truncate()` and `optimize()` statements of the connection:

```php
MyTable::optimize(true, 202401);
// OPTIMIZE TABLE my_table ON CLUSTER 'company_cluster' PARTITION 202401 FINAL

MyTable::where('id', 123)->withoutOnCluster()->delete(false);
// ALTER TABLE my_table DELETE WHERE `id` = 123

DB::connection('clickhouse')->withoutOnCluster(fn () => MyTable::truncate());
// TRUNCATE TABLE my_table
```

- `onCluster('other_cluster')` names another cluster for one query.
- `withoutOnCluster()` removes `ON CLUSTER` from one query.
- `Connection::withoutOnCluster($callback)` removes it from all mutations while the callback runs. It does not change the schema builder.
- The option needs `cluster_name`. Each mutation waits for all hosts and fails while a host is down. Use it for tables that are not replicated.

See [Clusters](/advanced/clusters).

## Truncate

```php
MyTable::truncate();
// TRUNCATE TABLE my_table

DB::connection('clickhouse')->table('default.my_table')->truncate();
// TRUNCATE TABLE `default`.`my_table`

DB::connection('clickhouse')->table('my_table')->onCluster('company_cluster')->truncate();
// TRUNCATE TABLE `my_table` ON CLUSTER 'company_cluster'
```

`truncate()` ignores the conditions of the query.

## Optimize

[`OPTIMIZE`](https://clickhouse.com/docs/en/sql-reference/statements/optimize/) merges the data parts of a table:

```php
MyTable::optimize(true, 202401);
// OPTIMIZE TABLE my_table PARTITION 202401 FINAL

MyTable::optimize(false, "it's");
// OPTIMIZE TABLE my_table PARTITION 'it\'s'
```

The partition is written as in `delete()`. Only `null` removes the `PARTITION` clause, so `''` and `'0'` are partitions.

## Table names

`delete()`, `update()`, `truncate()` and `insert()` quote the table of `table()` or `from()` as a `SELECT` does: `default.my-table` is `` `default`.`my-table` ``.
Give the name without backticks. A `raw()` table is written as it is.

The queries of a model use `$tableSources` for `delete()`, `update()`, `truncate()` and `optimize()`. Without it, they use `$table`:

```php
class MyTable extends BaseModel
{
    protected $table = 'my_table_buffer'; // SELECT and INSERT
    protected $tableSources = 'my_table';  // OPTIMIZE, TRUNCATE, update() and delete()
}
```

## Pretend

While the connection [pretends](/advanced/raw-sql#pretend-mode), as in `migrate --pretend`, the package logs `delete()`, `update()` and `truncate()` and does not send them.
