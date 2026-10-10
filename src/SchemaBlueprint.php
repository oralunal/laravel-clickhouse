<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use BackedEnum;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;
use Stringable;

/**
 * Laravel's Blueprint with the ClickHouse table clauses and column types.
 *
 * Schema::create() and Schema::table() on a ClickHouse connection pass a
 * SchemaBlueprint to the callback, unless a Schema::blueprintResolver() is
 * set. A callback that type-hints Laravel's Blueprint keeps working; type-hint
 * this class to call the methods below. SchemaGrammar::compileCreate() turns
 * the table clauses into the clauses of the CREATE TABLE statement; in
 * Schema::table() they compile to ALTER TABLE statements (MODIFY ORDER BY,
 * MODIFY SAMPLE BY, MODIFY TTL, MODIFY SETTING), except partitionBy(), which
 * throws because ClickHouse cannot change a partition key.
 *
 * Laravel's index commands give ClickHouse data-skipping indexes:
 * index('url', null, 'bloom_filter(0.01)') names the index type, and on the
 * returned definition algorithm('<type>') sets it too (rawIndex() has no type
 * argument), granularity(<n>) sets GRANULARITY <n>, and, in Schema::table(),
 * materialize() builds the new index for the rows the table already holds, in
 * a mutation that runs in the background: until it is done, ClickHouse
 * refuses to drop that index. An index without a type gets the connection's
 * default_index_type option on a table of the MergeTree family, or sends
 * nothing. dropIndex() sends nothing for a table whose engine cannot have
 * data-skipping indexes, such as Memory or Log. drop()->sync() and
 * dropIfExists()->sync() add SYNC to the DROP TABLE statement.
 *
 * Schema::table() sends its statements in the order of the calls, with the
 * columns it adds in one ALTER TABLE ... ADD COLUMN statement at the
 * position of the first of them. A dropColumn(), renameColumn() or change()
 * between two added columns starts a new statement, so that the column added
 * after it is added after it runs.
 *
 * On a connection with a cluster_name, every CREATE, ALTER, DROP and RENAME
 * statement of the schema builder goes ON CLUSTER '<cluster_name>', unless
 * the blueprint calls withoutOnCluster(), and a table of the MergeTree family
 * gets a replicated engine when the connection also lists its nodes (see
 * replicated()). Without a cluster_name, the statements run on the active
 * node, as in 3.0.0.
 *
 * temporary() creates a temporary table of the connection's session:
 * CREATE TEMPORARY TABLE, without ON CLUSTER, with the engine of engine() or,
 * without it, the server's default (Memory). Schema::create() throws for a
 * temporary table outside the connection's session(); Schema::dropTemporary()
 * and dropTemporaryIfExists() drop one with DROP TEMPORARY TABLE. In
 * Schema::table(), temporary() changes the session's temporary table of that
 * name, and throws before anything is sent when the session has none, and
 * for rename(): ClickHouse cannot rename a temporary table.
 *
 * @method SchemaColumnDefinition id(string $column = 'id')
 * @method SchemaColumnDefinition increments(string $column)
 * @method SchemaColumnDefinition bigIncrements(string $column)
 * @method SchemaColumnDefinition char(string $column, int|null $length = null)
 * @method SchemaColumnDefinition string(string $column, int|null $length = null)
 * @method SchemaColumnDefinition tinyText(string $column)
 * @method SchemaColumnDefinition text(string $column)
 * @method SchemaColumnDefinition mediumText(string $column)
 * @method SchemaColumnDefinition longText(string $column)
 * @method SchemaColumnDefinition integer(string $column, bool $autoIncrement = false, bool $unsigned = false)
 * @method SchemaColumnDefinition tinyInteger(string $column, bool $autoIncrement = false, bool $unsigned = false)
 * @method SchemaColumnDefinition smallInteger(string $column, bool $autoIncrement = false, bool $unsigned = false)
 * @method SchemaColumnDefinition mediumInteger(string $column, bool $autoIncrement = false, bool $unsigned = false)
 * @method SchemaColumnDefinition bigInteger(string $column, bool $autoIncrement = false, bool $unsigned = false)
 * @method SchemaColumnDefinition unsignedInteger(string $column, bool $autoIncrement = false)
 * @method SchemaColumnDefinition unsignedTinyInteger(string $column, bool $autoIncrement = false)
 * @method SchemaColumnDefinition unsignedSmallInteger(string $column, bool $autoIncrement = false)
 * @method SchemaColumnDefinition unsignedMediumInteger(string $column, bool $autoIncrement = false)
 * @method SchemaColumnDefinition unsignedBigInteger(string $column, bool $autoIncrement = false)
 * @method SchemaColumnDefinition float(string $column, int $precision = 53)
 * @method SchemaColumnDefinition double(string $column)
 * @method SchemaColumnDefinition decimal(string $column, int $total = 8, int $places = 2)
 * @method SchemaColumnDefinition boolean(string $column)
 * @method SchemaColumnDefinition enum(string $column, array $allowed)
 * @method SchemaColumnDefinition json(string $column)
 * @method SchemaColumnDefinition jsonb(string $column)
 * @method SchemaColumnDefinition date(string $column)
 * @method SchemaColumnDefinition dateTime(string $column, int|null $precision = null)
 * @method SchemaColumnDefinition dateTimeTz(string $column, int|null $precision = null)
 * @method SchemaColumnDefinition timestamp(string $column, int|null $precision = null)
 * @method SchemaColumnDefinition timestampTz(string $column, int|null $precision = null)
 * @method SchemaColumnDefinition softDeletes(string $column = 'deleted_at', int|null $precision = null)
 * @method SchemaColumnDefinition year(string $column)
 * @method SchemaColumnDefinition binary(string $column, int|null $length = null, bool $fixed = false)
 * @method SchemaColumnDefinition uuid(string $column = 'uuid')
 * @method SchemaColumnDefinition ulid(string $column = 'ulid', int|null $length = 26)
 * @method SchemaColumnDefinition ipAddress(string $column = 'ip_address')
 * @method SchemaColumnDefinition macAddress(string $column = 'mac_address')
 * @method SchemaColumnDefinition geometry(string $column, string|null $subtype = null, int $srid = 0)
 * @method SchemaColumnDefinition geography(string $column, string|null $subtype = null, int $srid = 4326)
 * @method SchemaColumnDefinition vector(string $column, int|null $dimensions = null)
 * @method SchemaColumnDefinition rawColumn(string $column, string $definition)
 */
class SchemaBlueprint extends Blueprint
{
    /**
     * Whether Schema::create() writes CREATE TABLE IF NOT EXISTS.
     *
     * @var bool
     */
    public bool $ifNotExists = false;

    /**
     * Whether Schema::create() makes an engine of the MergeTree family
     * replicated: null decides by the connection (see replicated()), true
     * and false are set by replicated().
     *
     * @var bool|null
     */
    public ?bool $replicated = null;

    /**
     * Whether the statements of the blueprint go ON CLUSTER on a connection
     * with a cluster_name; withoutOnCluster() turns this off.
     *
     * @var bool
     */
    public bool $onCluster = true;

    /**
     * Create the table only if it does not exist yet: CREATE TABLE IF NOT EXISTS.
     *
     * @param bool $ifNotExists
     * @return void
     */
    public function ifNotExists(bool $ifNotExists = true): void
    {
        $this->ifNotExists = $ifNotExists;
    }

    /**
     * Decide whether Schema::create() gives the table a replicated engine.
     *
     * Without this call, a table of the MergeTree family is replicated on a
     * connection that has a cluster_name and lists its nodes in the cluster
     * option: MergeTree() becomes ReplicatedMergeTree(), and
     * ReplacingMergeTree(version) becomes ReplicatedReplacingMergeTree(version),
     * with the server's default replica path, /clickhouse/tables/{uuid}/{shard}
     * by default. replicated(false) keeps the engine as given, for example for
     * a table that each host keeps for itself. replicated() makes the engine
     * replicated on any connection, such as one with a cluster_name that
     * reaches the cluster through one host; ClickHouse accepts a replicated
     * engine without a replica path only in a CREATE TABLE ... ON CLUSTER of
     * an Atomic database. An engine that already names Replicated or Shared,
     * an engine outside the MergeTree family and a temporary() table are
     * never changed.
     *
     * A table that the schema builder makes replicated keeps the insert
     * behaviour of its MergeTree engine: the CREATE gets
     * SETTINGS replicated_deduplication_window=0,
     * replicated_deduplication_window_for_async_inserts=0, so ClickHouse
     * keeps every insert that is identical to an earlier one, synchronous or
     * asynchronous, instead of dropping it as a duplicate (24.8, 26.3 and
     * 26.8 checked). settings() with another value for either setting wins,
     * and a null value leaves that setting to the server's default (see
     * SchemaGrammar::getReplicatedTableSettings()).
     *
     * @param bool $replicated
     * @return void
     */
    public function replicated(bool $replicated = true): void
    {
        $this->replicated = $replicated;
    }

    /**
     * Send the statements of the blueprint without ON CLUSTER, to the active
     * node only, also on a connection with a cluster_name, as 3.0.0 sent
     * every statement of the schema builder.
     *
     * This is for a table that does not exist on every host of the cluster,
     * such as one that 3.0.0's Schema::create() put on the active node only:
     * a statement ON CLUSTER would change it on the hosts that have it, then
     * fail with UNKNOWN_TABLE for the others. For example, this drops such a
     * table on the active node:
     *
     *     Schema::table('events', function (SchemaBlueprint $table) {
     *         $table->withoutOnCluster();
     *         $table->drop();
     *     });
     *
     * In Schema::create(), the table gets no automatic replicated engine (see
     * replicated()); in Schema::table(), change() reads the active node only.
     *
     * @return void
     */
    public function withoutOnCluster(): void
    {
        $this->onCluster = false;
    }

    /**
     * Set the sorting key of the table: ORDER BY (<columns>).
     *
     * A string is a column name and an expression is SQL:
     * orderBy('id', DB::raw('intHash32(id)')) gives ORDER BY (`id`, intHash32(id)).
     * The columns may also come as an array. Without columns the table gets
     * ORDER BY tuple(), which means no sorting key; an engine that merges the
     * rows that have the same sorting key, such as ReplacingMergeTree, then
     * merges the rows of each partition into one. The sorting key replaces
     * the one that primary() or the first column would give; primary() then
     * becomes the PRIMARY KEY, which must be a prefix of the sorting key.
     *
     * In Schema::table() it gives MODIFY ORDER BY (<columns>), in the same
     * ALTER as the columns that the call adds: ClickHouse only lets a new
     * sorting key append expressions of columns added by that ALTER. So when
     * a dropColumn(), renameColumn() or change() comes between the added
     * columns, which then go into several statements, Schema::table() throws
     * before anything is sent.
     *
     * @param string|Expression|array<int, string|Expression> ...$columns
     * @return Fluent
     */
    public function orderBy(string|Expression|array ...$columns): Fluent
    {
        $sortingKey = [];
        foreach ($columns as $column) {
            foreach (is_array($column) ? $column : [$column] as $element) {
                $sortingKey[] = $element;
            }
        }

        return $this->addCommand('orderBy', ['columns' => $sortingKey]);
    }

    /**
     * Set the partition key of the table: PARTITION BY <expression>.
     *
     * The expression is SQL, such as 'toYYYYMM(created_at)'. ClickHouse
     * cannot change the partition key of a table, so Schema::table() throws.
     *
     * @param string|Expression $expression
     * @return Fluent
     */
    public function partitionBy(string|Expression $expression): Fluent
    {
        return $this->addCommand('partitionBy', ['expression' => $expression]);
    }

    /**
     * Set the sampling expression of the table: SAMPLE BY <expression>.
     *
     * The expression is SQL, such as 'intHash32(id)'. ClickHouse requires it
     * to be part of the primary key. In Schema::table() it gives
     * ALTER TABLE ... MODIFY SAMPLE BY <expression>.
     *
     * @param string|Expression $expression
     * @return Fluent
     */
    public function sampleBy(string|Expression $expression): Fluent
    {
        return $this->addCommand('sampleBy', ['expression' => $expression]);
    }

    /**
     * Set the TTL of the table's rows: TTL <expression>.
     *
     * The expression is SQL, such as 'created_at + INTERVAL 1 YEAR'.
     * ClickHouse requires it to give a Date or a DateTime; a DateTime64
     * column needs toDateTime(). A column has its own TTL modifier (see
     * SchemaColumnDefinition). In Schema::table() it gives
     * ALTER TABLE ... MODIFY TTL <expression>, and ClickHouse applies the new
     * TTL to the rows the table already holds in a mutation that runs in the
     * background.
     *
     * @param string|Expression $expression
     * @return Fluent
     */
    public function ttl(string|Expression $expression): Fluent
    {
        return $this->addCommand('ttl', ['expression' => $expression]);
    }

    /**
     * Set table settings: SETTINGS <name>=<value>, ...
     *
     * Names and values are checked and written as Builder::settings() writes
     * them: a name must be a plain identifier, a bool becomes 1 or 0 and a
     * string a quoted literal. A later call for the same name wins. In
     * Schema::table() each call gives ALTER TABLE ... MODIFY SETTING <name>=<value>, ...
     *
     * A null value leaves the setting out of a new table's SETTINGS, so the
     * server's default applies, also for a setting that the schema builder
     * would add itself (see SchemaGrammar::getReplicatedTableSettings()).
     *
     * @param array<string, bool|int|float|string|BackedEnum|Stringable|Expression|null> $settings
     * @return Fluent
     */
    public function settings(array $settings): Fluent
    {
        return $this->addCommand('settings', ['settings' => $settings]);
    }

    /**
     * Create an Array(<type>) column, for example array('tags', 'String').
     *
     * @param string $column
     * @param string $type The ClickHouse type of the elements.
     * @return SchemaColumnDefinition
     */
    public function array(string $column, string $type): SchemaColumnDefinition
    {
        return $this->addColumn('array', $column, ['innerType' => $type]);
    }

    /**
     * Create a Map(<key type>, <value type>) column, for example map('attributes', 'String', 'UInt64').
     *
     * @param string $column
     * @param string $keyType
     * @param string $valueType
     * @return SchemaColumnDefinition
     */
    public function map(string $column, string $keyType, string $valueType): SchemaColumnDefinition
    {
        return $this->addColumn('map', $column, ['keyType' => $keyType, 'valueType' => $valueType]);
    }

    /**
     * Create an IPv4 column.
     *
     * @param string $column
     * @return SchemaColumnDefinition
     */
    public function ipv4(string $column = 'ip_address'): SchemaColumnDefinition
    {
        return $this->addColumn('ipv4', $column);
    }

    /**
     * Create an IPv6 column, which also holds IPv4 addresses as ::ffff:<address>.
     *
     * @param string $column
     * @return SchemaColumnDefinition
     */
    public function ipv6(string $column = 'ip_address'): SchemaColumnDefinition
    {
        return $this->addColumn('ipv6', $column);
    }

    /**
     * Create a Date32 column, which covers the years 1900 to 2299.
     *
     * @param string $column
     * @return SchemaColumnDefinition
     */
    public function date32(string $column): SchemaColumnDefinition
    {
        return $this->addColumn('date32', $column);
    }

    /**
     * Add a new column to the blueprint.
     *
     * @param string $type
     * @param string $name
     * @param array<string, mixed> $parameters
     * @return SchemaColumnDefinition
     */
    public function addColumn($type, $name, array $parameters = []): SchemaColumnDefinition
    {
        return $this->addColumnDefinition(new SchemaColumnDefinition(
            array_merge(['type' => $type, 'name' => $name], $parameters)
        ));
    }
}
