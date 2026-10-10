# Sessions and temporary tables

## Run queries in a session

`session()` runs a callback in one ClickHouse HTTP session and returns the result of the callback.
A temporary table and a `SET` value stay for the duration of the session. Only the queries of the session see them:

```php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\SchemaBlueprint;

$names = DB::connection('clickhouse')->session(function (Connection $connection) {
    Schema::connection('clickhouse')->create('tmp_ids', function (SchemaBlueprint $table) {
        $table->temporary();
        $table->integer('id');
    });
    // CREATE TEMPORARY TABLE `tmp_ids` (`id` Int32)

    $connection->table('tmp_ids')->insert([['id' => 1], ['id' => 2]]);
    $connection->statement('SET max_threads = 4');

    return MyTable::query()
        ->whereIn('id', fn ($query) => $query->select('id')->from('tmp_ids'))
        ->orderBy('id')
        ->pluck('field_one')
        ->all();
    // SELECT `field_one` FROM `my_table` WHERE `id` IN (SELECT `id` FROM `tmp_ids`) ORDER BY `id` ASC
}, timeout: 120);
// ['a', 'b']
```

- All queries that the connection sends while the callback runs join the session: models, the two query builders, `Schema`, `statement()` and `select()`.
- Other connections, queued jobs and other processes do not join it. A statement that goes to all nodes of a cluster joins it on the active node only.
- After the callback, a query of the temporary table fails with `UNKNOWN_TABLE`, and the `SET` values are gone.
- A session is not a transaction. Each statement takes effect when it runs. An exception in the callback does not undo statements.

## How a session works

- `session()` opens the session on the active node with `SELECT 1`, which is not logged.
- Each query of the callback has `session_id` (32 random characters), `session_timeout` and `session_check=1`.
- ClickHouse closes the session `timeout` seconds after its last query, 60 by default. You cannot close it earlier.
- A timeout less than 1 throws an `InvalidArgumentException`. A timeout above the `max_session_timeout` of the server (3600 by default) fails with `INVALID_SESSION_TIMEOUT` before the callback runs.

## Errors in a session

| Code | Cause | Exception |
| --- | --- | --- |
| 372 `SESSION_NOT_FOUND` | The session closed, or the server restarted | `Oralunal\LaravelClickHouse\Exceptions\QueryException`. Its previous exception is the ClickHouse error. |
| 373 `SESSION_IS_LOCKED` | An earlier query of the session still runs, for example after a client timeout | `QueryException` |

ClickHouse runs one query of a session at a time.

## Limits

- A request is sent again only when it did not reach the server. See [Retries](/advanced/timeouts-and-retries#retries).
- The smi2 client's `selectAsync()` with `executeAsync()`, and `insertFiles()`, throw a `QueryException` before the request.
- `Parallel` and `selectParallelly()` throw a `LogicException`.
- On a cluster connection, `slideNode()` throws a `LogicException`. The session exists on the active node only.
- A nested `session()` is a different session. It does not see the temporary tables of the outer session.
- A session id that `useSession()` of the smi2 client set comes back after the callback. `inSession()` tells if the queries carry a session id.
- Read the rows in the callback. A lazy reader, such as the generator of `cursor()` or a `LazyCollection`, runs after the session.
  A callback that returns one throws a `LogicException`. Return `iterator_to_array($connection->cursor(...))` or `->all()`.
- `buffer()` sends its rows at `flushBuffer()` or at the end of the request. Flush the buffer of a model of a temporary table in the callback.
- A load balancer must send all requests of a session to the same server.

## Temporary tables

`Schema::create()` with `$table->temporary()` sends `CREATE TEMPORARY TABLE`.
Outside a session, it throws a `QueryException` and sends nothing: `Cannot create the temporary table tmp_ids outside a session: ...`.

| Method | Sends |
| --- | --- |
| `Schema::create()` with `temporary()` | `CREATE TEMPORARY TABLE`. Without `engine()`, no `ENGINE` clause: the server uses `Memory`. |
| `Schema::table()` with `temporary()` | ``ALTER TABLE `tmp_ids` ADD COLUMN `extra` Int32 DEFAULT 3``, without `ON CLUSTER` |
| `Schema::dropTemporary('tmp_ids')` | ``DROP TEMPORARY TABLE `tmp_ids` `` |
| `Schema::dropTemporaryIfExists('tmp_ids')` | ``DROP TEMPORARY TABLE IF EXISTS `tmp_ids` `` |
| `Schema::hasTemporaryTable('tmp_ids')` | ``EXISTS TEMPORARY TABLE `tmp_ids` ``. `false` outside a session and while the connection pretends. |

- `$table->engine('MergeTree()')` gives ``CREATE TEMPORARY TABLE `tmp_ids` (`id` Int32) ENGINE = MergeTree() ORDER BY (`id`)``.
- The `engine` option, `ON CLUSTER` and replicated engines do not apply. ClickHouse refuses a `Replicated`, `Shared` or `KeeperMap` engine and a database name. A `Memory` table refuses `orderBy()` and `partitionBy()`.
- `Schema::table()` with `temporary()` throws when the session has no temporary table of that name. ClickHouse cannot rename a temporary table.
- `hasTable()`, `getTables()` and `getColumns()` read the database. They do not see temporary tables.

### A temporary table with the name of a database table

ClickHouse (24.8, 26.3 and 26.8 checked) runs `SELECT`, `INSERT`, `ALTER TABLE ... DELETE` and `UPDATE`, `TRUNCATE`, `OPTIMIZE` and `DROP TABLE` on the temporary table.
But it runs a lightweight `DELETE FROM`, `RENAME TABLE`, `CREATE TABLE` and each statement with `ON CLUSTER` on the database table.

In a session, the package refuses these statements when a temporary table has the name of the table:

- `delete(true)`, and `delete()` with `use_lightweight_delete`;
- `delete()`, `update()`, `truncate()` and `MyTable::optimize()` with `onCluster()` or `use_on_cluster`;
- on a connection with a `cluster_name`, `Schema::table()`, `drop()`, `dropIfExists()`, `dropSync()`, `dropIfExistsSync()` and `rename()`.

```text
Cannot send DELETE FROM for my_table: the session has a temporary table named my_table, and ClickHouse
(24.8 checked) runs a lightweight DELETE on the table my_table of the database instead. Call
delete(false), which sends ALTER TABLE ... DELETE to the temporary table.
```

- Each of these statements sends `EXISTS TEMPORARY TABLE` first.
- A name with a database, such as `analytics.my_table`, and raw SQL are not checked.
- `Schema::create()` is not refused. It creates the database table, as ClickHouse does.
- Drop a temporary table with `dropTemporary()` or `dropTemporaryIfExists()`.

### Mutations and temporary tables

ClickHouse runs a mutation of a database table outside the session, where the temporary tables do not exist.
In a session, `delete()` and `update()` check a sub-query outside the session, and refuse a condition that reads a temporary table:

```text
Cannot delete with a where condition that ClickHouse cannot run: it refused the condition in a SELECT
outside the session with UNKNOWN_TABLE. ...
```

Select the keys first:

```php
MyTable::query()->whereIn('id', $connection->table('tmp_ids')->pluck('id'))->delete(false);
// ALTER TABLE my_table DELETE WHERE `id` IN (1, 2)
```

A mutation of a `Memory` temporary table runs in the session, so its condition can read other temporary tables.
A `MergeTree` temporary table runs its mutations outside the session. Select the keys first for these tables too.
