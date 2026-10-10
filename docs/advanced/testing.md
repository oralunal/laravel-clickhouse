# Tests

## Use DatabaseTruncation

ClickHouse has no transactions. `RefreshDatabase`, `LazilyRefreshDatabase` and `DatabaseTransactions` start a transaction on each connection in `$connectionsToTransact`.
With a ClickHouse connection there, each test fails with a `LogicException`.

Use Laravel's `DatabaseTruncation` trait for ClickHouse connections:

```php
use Illuminate\Foundation\Testing\DatabaseTruncation;

class EventReportTest extends TestCase
{
    use DatabaseTruncation;

    // Without this property, the trait truncates only the default connection.
    protected $connectionsToTruncate = ['mysql', 'clickhouse'];
}
```

- The first test that uses the trait runs `migrate:fresh`. Before each next test, the trait truncates each table that holds rows, but not the `migrations` table.
- The trait gets the tables from `Schema::getTables()`, so it does not touch views, materialized views and dictionaries.
- The other traits continue to work for your other connections, when `$connectionsToTransact` does not have a ClickHouse connection.

## Tables to exclude

Before the trait truncates a table, it reads one row from it. These tables make a test fail:

| Table | Problem |
| --- | --- |
| `Kafka`, `Set`, a `File` table without a data file, or a table with an endpoint that does not answer (`URL`, `S3`, `MySQL`, `PostgreSQL`) | ClickHouse cannot read it, also when it is empty |
| `Buffer`, `Merge`, `Null`, `URL`, `Kafka`, `GenerateRandom`, `MySQL`, `PostgreSQL`, an `S3` table with globs | ClickHouse refuses `TRUNCATE`: `Truncate is not supported by storage ...` |

::: danger
`TRUNCATE` also deletes data outside ClickHouse. On an `S3` table, ClickHouse deletes the object from the bucket.
:::

List these tables in `$exceptTables`:

```php
protected $exceptTables = ['my_table_buffer'];
```

Use table names, not `<database>.<table>`. With `--parallel`, the database becomes `<database>_test_<token>`, and a qualified name does not match.
A list for each connection, such as `['mysql' => [], 'clickhouse' => ['my_table_buffer']]`, needs a key for each truncated connection. Without the key, the trait fails with `Array to string conversion`.

## Rows that the truncation does not reach

- A materialized view with its own `ENGINE` keeps its rows in an inner table.
- A `Buffer` table can hold rows that it writes later, during another test.
- A dictionary keeps the rows it loaded until it reloads them. A dictionary with `LIFETIME(0)` does not reload by itself.

Empty the first two in `beforeTruncatingDatabase()`. This method also runs before the first `migrate:fresh`, so check that the tables exist.
Flush the `Buffer` table before you truncate the materialized view:

```php
protected function beforeTruncatingDatabase(): void
{
    $clickhouse = DB::connection('clickhouse');

    if ($clickhouse->getSchemaBuilder()->hasTable('my_table_buffer')) {
        // Writes the buffered rows to my_table, which the trait truncates next.
        $clickhouse->statement('OPTIMIZE TABLE my_table_buffer');
    }

    if ($clickhouse->getSchemaBuilder()->hasView('my_materialized_view')) {
        $clickhouse->table('my_materialized_view')->truncate();
    }
}
```

Reload a dictionary in `afterTruncatingDatabase()`, which runs after the truncation and the seeding:

```php
protected function afterTruncatingDatabase(): void
{
    DB::connection('clickhouse')->statement('SYSTEM RELOAD DICTIONARY my_dictionary');
}
```

## Clusters

On a cluster connection, the trait truncates the tables on the active node only.

- With [`use_on_cluster`](/query-builder/writing-data#on-cluster), each `TRUNCATE` goes `ON CLUSTER` and empties all nodes.
- A `Replicated*MergeTree` table sends the `TRUNCATE` to its other replicas.
- A table that is not replicated keeps its rows on the other nodes. Truncate it with `onCluster()` in `beforeTruncatingDatabase()`:

  ```php
  if ($clickhouse->getSchemaBuilder()->hasTable('my_table')) {
      $clickhouse->table('my_table')->onCluster('company_cluster')->truncate();
  }
  ```

## Parallel tests

With `php artisan test --parallel` or `pest --parallel`, each test process gets its own database on each secondary ClickHouse connection: `<database>_test_<token>`, for example `analytics_test_1`.
The `migrate:fresh` of one process does not drop the tables of another process.

- A process creates the database for its first test case that uses `RefreshDatabase`, `LazilyRefreshDatabase`, `DatabaseMigrations`, `DatabaseTransactions` or `DatabaseTruncation`.
- `--recreate-databases` drops these databases before the run. `--drop-databases` drops them after the run. `--without-databases` keeps the configured database.
- When ClickHouse is the primary connection, Laravel changes its database to `<database>_test_<token>`.
- The ClickHouse user must have permission to create and drop databases. With a `cluster_name`, the package creates and drops the databases `ON CLUSTER`.
