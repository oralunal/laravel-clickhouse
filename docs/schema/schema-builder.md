# Laravel's schema builder

`Schema::create()` creates a ClickHouse table from Laravel's column methods and modifiers:

```php
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\SchemaBlueprint;

Schema::create('events', function (SchemaBlueprint $table) {
    $table->id();
    $table->string('name')->nullable()->default('x')->comment("it's");
    $table->timestamps();
});
// CREATE TABLE `events` (`id` Int64, `name` Nullable(String) DEFAULT 'x' COMMENT 'it\'s',
//   `created_at` Nullable(DateTime), `updated_at` Nullable(DateTime)) ENGINE = MergeTree() ORDER BY (`id`)
```

- In a migration, the `Schema` facade uses the connection of the migration. Extend `Oralunal\LaravelClickHouse\Migration`, whose connection is `clickhouse`, or set `protected $connection`.
- In other code, use `Schema::connection('clickhouse')` when ClickHouse is not the default connection.
- The callback gets `Oralunal\LaravelClickHouse\SchemaBlueprint`: Laravel's `Blueprint` with ClickHouse methods. Declare this type so that your editor knows the methods.
- With a `cluster_name`, the statements go `ON CLUSTER`. See [Clusters](/advanced/clusters#the-schema-builder-on-a-cluster).
- In a [session](/advanced/sessions#temporary-tables), `$table->temporary()` creates a temporary table.

The statement has this layout. The parts in brackets appear when the blueprint sets them:

```sql
CREATE TABLE [IF NOT EXISTS] <table> (<columns>[, INDEX <name> <expression> TYPE <type> [GRANULARITY <n>]])
ENGINE = <engine> [PARTITION BY <expression>] [PRIMARY KEY (<columns>)] ORDER BY (<columns>)
[SAMPLE BY <expression>] [TTL <expression>] [SETTINGS <name>=<value>, ...] [COMMENT '<text>']
```

All names are in backticks. A column name is one identifier: `n.a` is `` `n.a` ``. A table name with a dot names its database: `analytics.events` is `` `analytics`.`events` ``.

## Drop and rename tables

| Method | SQL |
| --- | --- |
| `Schema::drop('events')` | ``DROP TABLE `events` `` |
| `Schema::dropIfExists('events')` | ``DROP TABLE IF EXISTS `events` `` |
| `Schema::dropSync('events')`, `Schema::dropIfExistsSync('events')` | ``DROP TABLE `events` SYNC`` |
| `Schema::rename('events', 'events_old')` | ``RENAME TABLE `events` TO `events_old` `` |

`SYNC` waits until ClickHouse removes the data, and the replica of a replicated table. In a blueprint, use `drop()->sync()` or `dropIfExists()->sync()`.

## Column types

| Laravel column method | ClickHouse type |
| --- | --- |
| `char()`, `ulid()`, `foreignUlid()` | `FixedString(<length>)`: 255 for `char()` without a length, 26 for a ULID |
| `string()`, `tinyText()`, `text()`, `mediumText()`, `longText()` | `String`. The length is ignored. |
| `float()` | `Float64`, or `Float32` for a precision up to 24 |
| `double()` | `Float64` |
| `decimal($column, $total, $places)` | `Decimal(<total>, <places>)` |
| `boolean()` | `Bool` |
| `enum($column, ['new', 'done'])` | `Enum('new', 'done')`. `['low' => 1, 'high' => 10]` gives `Enum('low' = 1, 'high' = 10)`. |
| `json()`, `jsonb()` | `String` |
| `date()` | `Date` |
| `dateTime()`, `dateTimeTz()`, `timestamp()`, `timestampTz()` | `DateTime`, or `DateTime64(<precision>)` for a precision above 0 |
| `year()` | `UInt16` |
| `binary()` | `String`, or `FixedString(<length>)` with `fixed: true` |
| `uuid()`, `foreignUuid()` | `UUID` |
| `ipAddress()`, `macAddress()` | `String` |
| `geometry()`, `geography()` | `Point`, `Ring`, `LineString`, `MultiLineString`, `Polygon` or `MultiPolygon`, by the subtype |
| `vector()` | `Array(Float32)` |
| `rawColumn($column, 'Tuple(a UInt8, b String)')` | The type as given |
| `array('tags', 'String')` | `Array(String)` |
| `map('attributes', 'String', 'UInt64')` | `Map(String, UInt64)` |
| `ipv4()`, `ipv6()` | `IPv4`, `IPv6` |
| `date32()` | `Date32` |

- `array()`, `map()`, `ipv4()`, `ipv6()` and `date32()` are `SchemaBlueprint` methods.
- `json()` stores the document as a string, because the `JSON` type of 24.8 is experimental. On 25.8 and later, use `rawColumn('payload', 'JSON')`.
- An `IPv6` column stores `127.0.0.1` as `::ffff:127.0.0.1`.
- ClickHouse pads a short `FixedString` value with NUL bytes.

### Integer types

By default, the integer methods give the types of 3.x, and `unsigned()` does nothing. With `exact_integer_types`, each method gets its own width, and `unsigned()` gives a `UInt` type:

| Laravel column method | Default | With `exact_integer_types` |
| --- | --- | --- |
| `tinyInteger()` | `Int16` | `Int8` |
| `unsignedTinyInteger()`, `tinyIncrements()` | `Int16` | `UInt8` |
| `smallInteger()` | `Int16` | `Int16` |
| `unsignedSmallInteger()`, `smallIncrements()` | `Int32` | `UInt16` |
| `mediumInteger()`, `integer()` | `Int32` | `Int32` |
| `unsignedMediumInteger()`, `mediumIncrements()`, `unsignedInteger()`, `increments()` | `Int32` | `UInt32` |
| `bigInteger()` | `Int64` | `Int64` |
| `unsignedBigInteger()`, `id()`, `bigIncrements()`, `foreignId()` | `Int64` | `UInt64` |

Set `'exact_integer_types' => true` (`CLICKHOUSE_EXACT_INTEGER_TYPES=true`) in a new project.

::: warning
In an existing project, the option changes the tables that you create after you set it. ClickHouse wraps a value that does not fit the narrower type, without an error:
`200` in an `Int8` column reads as `-56`, and `-1` in a `UInt32` column reads as `4294967295`.
:::

ClickHouse has no auto-increment. `id()` and the `increments()` methods create an integer column. A row without a value gets `0`.

### Enums

- An array with a string key maps each name to its number. Integer keys are names too: `['active' => 1, '200' => 2]` gives `Enum('active' = 1, '200' = 2)`.
- An array with integer keys only is a list of names: `array_unique([200, 404, 200, 500])` gives `Enum('200', '404', '500')`.
- To number names that are all numbers, write the type: `rawColumn('status', "Enum8('200' = 1, '404' = 2)")`.
- In an array with a string key, each number must be an integer or a string of digits. `['low' => 1.0]` throws. `['low' => (int) 1.0]` gives `Enum('low' = 1)`.

## Column modifiers

| Modifier | SQL |
| --- | --- |
| `nullable()` | `Nullable(<type>)` |
| `default($value)` | `DEFAULT <value>` |
| `useCurrent()` | `DEFAULT now()`, `now64(<precision>)` with a precision, `today()` on `date()`, `toYear(now())` on `year()` |
| `storedAs('domain(url)')` | `MATERIALIZED domain(url)` |
| `virtualAs('upper(url)')` | `ALIAS upper(url)` |
| `ephemeral()`, `ephemeral('')` | `EPHEMERAL`, `EPHEMERAL ''` |
| `comment("it's")` | `COMMENT 'it\'s'` |
| `lowCardinality()` | `LowCardinality(<type>)`. With `nullable()`, `LowCardinality(Nullable(<type>))`. |
| `codec('ZSTD(3)')` | `CODEC(ZSTD(3))` |
| `ttl('created_at + INTERVAL 1 DAY')` | The column TTL `TTL created_at + INTERVAL 1 DAY` |
| `timezone('UTC')` | The time zone of a date-time type: `DateTime('UTC')`, `DateTime64(3, 'UTC')` |
| `unsigned()` | A `UInt` type with `exact_integer_types` |

- `default()` writes each value as a literal:

  | Value | Literal |
  | --- | --- |
  | String | An escaped string literal |
  | Bool | `1` or `0` |
  | Float | All digits |
  | Backed enum, pure enum | Its value, its name |
  | Date | `'Y-m-d H:i:s'` |
  | Array | An array literal |
  | `DB::raw()` | The SQL |

- A float default of a `Decimal` column is written as a string: `decimal('price', 10, 2)->default(19.99)` gives `DEFAULT '19.99'`, which stores `19.99`.
- `timestamps()` and `softDeletes()` create `Nullable(DateTime)` columns. After `Schema::defaultTimePrecision(3)`, they are `Nullable(DateTime64(3))`.
- A `nullable()` column stores `NULL`. A column without it stores the default of its type, such as `''` or `1970-01-01 00:00:00`.
- `autoIncrement()`, `useCurrentOnUpdate()`, `charset()`, `collation()`, `invisible()` and `from()` add nothing.
- `first()` and `after('id')` add nothing in `Schema::create()`. In `Schema::table()`, they give `FIRST` and ``AFTER `id` ``.
- ClickHouse refuses `Nullable` around `Array`, `Map` or `LowCardinality`. Write `array('tags', 'Nullable(String)')`.
- `LowCardinality` around a number needs the `allow_suspicious_low_cardinality_types` setting.

## Table options

```php
use Illuminate\Support\Facades\DB;

Schema::create('visits', function (SchemaBlueprint $table) {
    $table->unsignedBigInteger('id');
    $table->unsignedBigInteger('version');
    $table->dateTime('visited_at');
    $table->string('url');
    $table->string('country')->lowCardinality();
    $table->engine('ReplacingMergeTree(version)');
    $table->partitionBy('toYYYYMM(visited_at)');
    $table->primary(['id', DB::raw('intHash32(id)')], 'visits_primary');
    $table->orderBy('id', DB::raw('intHash32(id)'), 'visited_at');
    $table->sampleBy('intHash32(id)');
    $table->ttl('visited_at + INTERVAL 1 YEAR');
    $table->settings(['index_granularity' => 8192, 'ttl_only_drop_parts' => true]);
    $table->comment('Page visits, deduplicated by version');
    $table->index('url', null, 'bloom_filter(0.01)')->granularity(4);
});
// CREATE TABLE `visits` (`id` Int64, `version` Int64, `visited_at` DateTime, `url` String,
//   `country` LowCardinality(String), INDEX `visits_url_index` `url` TYPE bloom_filter(0.01) GRANULARITY 4)
//   ENGINE = ReplacingMergeTree(version) PARTITION BY toYYYYMM(visited_at)
//   PRIMARY KEY (`id`, intHash32(id)) ORDER BY (`id`, intHash32(id), `visited_at`)
//   SAMPLE BY intHash32(id) TTL visited_at + INTERVAL 1 YEAR
//   SETTINGS index_granularity=8192, ttl_only_drop_parts=1 COMMENT 'Page visits, deduplicated by version'
```

| Method | Clause |
| --- | --- |
| `engine('ReplacingMergeTree(version)')` | `ENGINE = ReplacingMergeTree(version)`. Without it, the `engine` option. Without the option, `MergeTree()`. |
| `orderBy('id', DB::raw('intHash32(id)'))` | ``ORDER BY (`id`, intHash32(id))``. A string is a column. `DB::raw()` is SQL. Without columns, `ORDER BY tuple()`. |
| `primary($columns, $name)` | The sorting key, or with `orderBy()` the `PRIMARY KEY` |
| `partitionBy('toYYYYMM(visited_at)')` | `PARTITION BY toYYYYMM(visited_at)` |
| `sampleBy('intHash32(id)')` | `SAMPLE BY intHash32(id)`. It must be part of the primary key. |
| `ttl('visited_at + INTERVAL 1 YEAR')` | `TTL visited_at + INTERVAL 1 YEAR`. It must give a `Date` or a `DateTime`. For `DateTime64`, use `toDateTime(visited_at)`. |
| `settings(['index_granularity' => 8192])` | `SETTINGS index_granularity=8192`. Another call adds its settings. `null` removes a setting. |
| `comment('...')` | `COMMENT '...'` |
| `ifNotExists()` | `CREATE TABLE IF NOT EXISTS` |

- `primary()` with a `DB::raw()` column needs a name as the second argument.
- `settings()` writes values as the query builder's [`settings()`](/query-builder/basics#settings) does.
- Give the settings of a MergeTree table with `settings()`, not in the engine string. `engine('MergeTree() SETTINGS index_granularity = 1024')` gives an `ORDER BY` after `SETTINGS`, which ClickHouse refuses.

### Sorting key

For an engine of the MergeTree family:

1. `orderBy()` is the sorting key. With `primary()` too, `primary()` is the `PRIMARY KEY`, which must be a prefix of the sorting key:
   `primary('id')` with `orderBy('id', 'at')` gives ``PRIMARY KEY (`id`) ORDER BY (`id`, `at`)``.
2. `primary()` alone is the sorting key: ``ORDER BY (`id`)``.
3. Without them, the first column is the sorting key.
4. If the first column cannot be a sorting key, for example a `nullable()` column, `MergeTree`, `ReplicatedMergeTree` and `SharedMergeTree` get `ORDER BY tuple()`.
   Other engines, such as `ReplacingMergeTree`, throw a `RuntimeException` before the request: call `orderBy()` or `primary()`.

Outside the MergeTree family, `primary()` and the first column add no key.
`EmbeddedRocksDB`, `KeeperMap`, `Redis` and `MaterializedPostgreSQL` use the `PRIMARY KEY`: `$table->string('key')->primary()` with `engine('EmbeddedRocksDB')` gives ``ENGINE = EmbeddedRocksDB PRIMARY KEY (`key`)``.
The package writes `orderBy()`, `partitionBy()`, `sampleBy()`, `ttl()`, `settings()` and `comment()` for all engines. ClickHouse accepts or refuses them.

## Data-skipping indexes

Give the type as the third argument of `index()`, or with `algorithm()`, and the granularity with `granularity()`:

```php
$table->index('url', null, 'bloom_filter(0.01)')->granularity(4);
// INDEX `visits_url_index` `url` TYPE bloom_filter(0.01) GRANULARITY 4
$table->index(['country', 'id'], null, 'minmax');
// INDEX `visits_country_id_index` (`country`, `id`) TYPE minmax
$table->rawIndex('lower(url)', 'visits_lower_url')->algorithm('ngrambf_v1(3, 256, 2, 0)')->granularity(2);
// INDEX `visits_lower_url` lower(url) TYPE ngrambf_v1(3, 256, 2, 0) GRANULARITY 2
```

- Without `granularity()`, ClickHouse uses 1.
- A type that ClickHouse does not know, such as `btree`, fails with `INCORRECT_QUERY`. Tables outside the MergeTree family refuse these indexes.
- An index without a type, such as the index of `morphs()`, sends nothing. The `default_index_type` option gives it a type:

  ```php
  // With 'default_index_type' => 'minmax'
  Schema::create('comments', function (SchemaBlueprint $table) {
      $table->id();
      $table->morphs('commentable');
  });
  // CREATE TABLE `comments` (`id` Int64, `commentable_type` String, `commentable_id` Int64,
  //   INDEX `comments_commentable_type_commentable_id_index` (`commentable_type`, `commentable_id`) TYPE minmax)
  //   ENGINE = MergeTree() ORDER BY (`id`)
  ```

In `Schema::table()`, `materialize()` also builds the new index for the rows that the table holds:

```php
Schema::table('visits', function (SchemaBlueprint $table) {
    $table->index('url', null, 'bloom_filter(0.01)')->granularity(4)->materialize();
});
// ALTER TABLE `visits` ADD INDEX `visits_url_index` `url` TYPE bloom_filter(0.01) GRANULARITY 4
// ALTER TABLE `visits` MATERIALIZE INDEX `visits_url_index`
```

`MATERIALIZE INDEX` starts a mutation in the background. Until it ends, ClickHouse refuses to drop the index.

`dropIndex('visits_url_index')`, or `dropIndex(['url'])`, sends ``ALTER TABLE `visits` DROP INDEX IF EXISTS `visits_url_index` ``.
`IF EXISTS` lets `down()` drop an index without a type, which the package did not create.
`dropIndex()` reads the engine of the table from `system.tables` when it compiles, also under `toSql()` and `migrate --pretend`.

## Change tables

`Schema::table()` sends an `ALTER TABLE` statement for each change, in the order of the calls:

```php
Schema::table('visits', function (SchemaBlueprint $table) {
    $table->string('referrer')->nullable()->after('id');
    $table->string('country')->lowCardinality();
    $table->dropColumn('legacy');
    $table->renameColumn('payload', 'body');
    $table->comment('Page visits');
});
// ALTER TABLE `visits` ADD COLUMN `referrer` Nullable(String) AFTER `id`, ADD COLUMN `country` LowCardinality(String)
// ALTER TABLE `visits` DROP COLUMN `legacy`
// ALTER TABLE `visits` RENAME COLUMN `payload` TO `body`
// ALTER TABLE `visits` MODIFY COMMENT 'Page visits'
```

The added columns go into one statement. A `dropColumn()`, `renameColumn()` or `change()` between two added columns starts another `ADD COLUMN` statement:

```php
Schema::table('orders', function (SchemaBlueprint $table) {
    $table->string('note');
    $table->dropColumn('amount');
    $table->decimal('amount', 10, 2);
});
// ALTER TABLE `orders` ADD COLUMN `note` String
// ALTER TABLE `orders` DROP COLUMN `amount`
// ALTER TABLE `orders` ADD COLUMN `amount` Decimal(10, 2)
```

| Method in `Schema::table()` | Statement |
| --- | --- |
| `dropColumn('a', 'b')`, `dropTimestamps()`, `dropSoftDeletes()`, `dropMorphs()` | ``ALTER TABLE `t` DROP COLUMN `a`, DROP COLUMN `b` `` |
| `renameColumn('a', 'b')` | ``ALTER TABLE `t` RENAME COLUMN `a` TO `b` `` |
| `comment('Page visits')` | ``ALTER TABLE `t` MODIFY COMMENT 'Page visits'`` |
| `orderBy('id', 'shard')` | ``MODIFY ORDER BY (`id`, `shard`)``, in the statement that adds the columns |
| `sampleBy('intHash32(id)')` | ``ALTER TABLE `t` MODIFY SAMPLE BY intHash32(id)`` |
| `ttl('visited_at + INTERVAL 2 YEAR')` | ``ALTER TABLE `t` MODIFY TTL visited_at + INTERVAL 2 YEAR`` |
| `settings(['merge_with_ttl_timeout' => 3600])` | ``ALTER TABLE `t` MODIFY SETTING merge_with_ttl_timeout=3600`` |
| `rename('t_old')` | ``RENAME TABLE `t` TO `t_old` `` |

- ClickHouse accepts a new sorting key only when it adds columns of the same `ALTER`. `$table->unsignedInteger('shard'); $table->orderBy('id', 'shard');` sends ``ALTER TABLE `visits` ADD COLUMN `shard` Int32, MODIFY ORDER BY (`id`, `shard`)``.
- `partitionBy()` throws a `RuntimeException`. ClickHouse cannot change the partition key.
- `ttl()` applies the new TTL to the rows of the table in a background mutation.
- `Schema::rename('analytics.visits', 'visits_old')` sends ``RENAME TABLE `analytics`.`visits` TO `analytics`.`visits_old` ``.
- The server refuses these changes:
  - add a column that exists;
  - drop a column that does not exist, or a column of the sorting key or of an index;
  - rename a table to a name that is in use.
- Each statement runs alone. When the server refuses one, the statements before it stay.

## Change a column

`change()` sends `MODIFY COLUMN` with the new definition. An attribute that the new definition does not have is removed:

```php
// The column was `source` String DEFAULT 'web' COMMENT 'src'
Schema::table('visits', function (SchemaBlueprint $table) {
    $table->string('source')->nullable()->after('id')->renameTo('traffic_source')->change();
});
// ALTER TABLE `visits` MODIFY COLUMN `source` REMOVE DEFAULT
// ALTER TABLE `visits` MODIFY COLUMN `source` REMOVE COMMENT
// ALTER TABLE `visits` MODIFY COLUMN `source` Nullable(String) AFTER `id`
// ALTER TABLE `visits` RENAME COLUMN `source` TO `traffic_source`
```

- The `DEFAULT`, `MATERIALIZED` or `ALIAS` expression and the comment are removed, unless the new definition gives them again.
- The `CODEC` and the TTL stay, unless `codec()` or `ttl()` gives new ones. An `EPHEMERAL` column stays `EPHEMERAL`.
- `first()` and `after()` move the column. `renameTo()` renames it after the change.
- `change()` reads the column from `system.columns` when it compiles. So `toSql()` and `migrate --pretend` read the server.

::: danger
A new type rewrites the column in a mutation. If ClickHouse cannot convert a value, the mutation stays, and all later queries of the table fail. Kill the mutation and change the type back:

```sql
KILL MUTATION WHERE database = 'default' AND table = 'visits';
ALTER TABLE visits MODIFY COLUMN referrer Nullable(String);
```
:::

`change()` refuses the most frequent case before the request: a column with `NULL` values and a new type without `NULL`:

```text
The column [referrer] of the table [visits] holds NULL values, so its type cannot change from
Nullable(String) to String: ClickHouse would fail to convert them in a mutation, and every later
query of the table would fail until the mutation is killed. Replace the NULL values first, or keep
the column nullable(). ...
```

- The check sends ``SELECT 1 FROM `visits` WHERE `referrer` IS NULL LIMIT 1 SETTINGS apply_deleted_mask = 0`` within `timeout_query`. On a large table, increase the timeout.
- Rows of a lightweight delete count until a merge. Run `ALTER TABLE visits APPLY DELETED MASK SETTINGS mutations_sync = 2` and migrate again.
- The check does not examine maps, tuples or other values that ClickHouse cannot convert, such as `'abc'` to `Int64`.
- ClickHouse 26.3 and 26.8 need a default to make a `nullable()` column non-nullable: `$table->string('referrer')->default('')->change()`.

## Not supported

These calls throw before the blueprint sends a statement:

| Call | Exception |
| --- | --- |
| `time()`, `timeTz()`, `set()`, `computed()` | `RuntimeException`. The message names a replacement, such as `rawColumn('flags', "Array(Enum('a', 'b'))")`. |
| `geometry()`, `geography()` without a subtype of the type table | `RuntimeException` |
| `virtualAsJson()`, `storedAsJson()` | `RuntimeException`. Use `virtualAs()` or `storedAs()` with `JSONExtractString(payload, 'name')`. |
| `fullText()`, `dropFullText()`, `vectorIndex()`, `dropVectorIndex()` | `RuntimeException`. Use a data-skipping index, such as `index('body', null, 'tokenbf_v1(512, 3, 0)')`. |
| In `Schema::table()`: `primary()`, `dropPrimary()`, `renameIndex()`, `partitionBy()` | `RuntimeException` |
| An incorrect `enum()` array, a `default()` without a literal, an incorrect setting name | `InvalidArgumentException` |

ClickHouse has no foreign keys, unique constraints or spatial indexes.
`foreign()`, `constrained()`, `dropForeign()`, `unique()`, `dropUnique()`, `spatialIndex()` and `dropSpatialIndex()` send nothing.
`foreignId('user_id')->constrained()` adds only the column.
