<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Client;
use ClickHouseDB\Statement;
use Exception;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Concerns\HasAttributes;
use Oralunal\LaravelClickHouse\Concerns\HasBufferedInserts;
use Oralunal\LaravelClickHouse\Concerns\HasEvents;
use Illuminate\Database\Eloquent\Concerns\HidesAttributes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Operator;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\TwoElementsLogicExpression;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;

class BaseModel
{
    use HasAttributes;
    use HidesAttributes;
    use HasEvents;
    use HasBufferedInserts;
    use WithClient;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table;

    /**
     * Use this only when you have Buffer table engine for inserts
     * @link https://clickhouse.tech/docs/ru/engines/table-engines/special/buffer/
     *
     * @var string
     */
    protected $tableForInserts;

    /**
     * Use this field for OPTIMIZE TABLE OR ALTER TABLE (also DELETE) queries
     *
     * @var string
     */
    protected $tableSources;

    /**
     * The input format of this model's inserts: 'Values' or 'JSONEachRow', in any letter case, or a Format instance
     * of one of them. Null, the default, uses the insert_format option of the model's connection (see
     * getInsertFormat()).
     *
     * @var string|Format|null
     */
    protected $insertFormat;

    /**
     * Indicates if the model exists.
     *
     * @var bool
     */
    public $exists = false;

    /**
     * Indicates if the model was inserted during the current request lifecycle.
     *
     * @var bool
     */
    public $wasRecentlyCreated = false;

    /**
     * The name of the database connection to use.
     *
     * @var string
     */
    protected $connection = Connection::DEFAULT_NAME;

    /**
     * Determine if an attribute exists on the model.
     * The __isset magic method is triggered by calling isset() or empty() on inaccessible properties.
     *
     * A stored attribute exists even when it holds null, and so does a key with an
     * accessor: a getFooAttribute() method, or a foo(): Attribute method with a getter.
     *
     * @param string $key The name of the attribute.
     * @return bool  True if the attribute exists, false otherwise.
     */
    public function __isset($key)
    {
        return array_key_exists($key, $this->attributes) || $this->hasAnyGetMutator($key);
    }

    /**
     * Get the table associated with the model.
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table ?? Str::snake(Str::pluralStudly(class_basename($this)));
    }

    /**
     * Get the table name for insert queries
     *
     * @return string
     */
    public function getTableForInserts(): string
    {
        return $this->tableForInserts ?? $this->getTable();
    }

    /**
     * Use this field for OPTIMIZE TABLE OR ALTER TABLE (also DELETE) queries
     * @return string
     */
    public function getTableSources(): string
    {
        return $this->tableSources ?? $this->getTable();
    }

    /**
     * Get the input format of this model's inserts (insertAssoc(), insertBulk(), the prepareAndInsert methods,
     * create(), save() and flushBuffer()): Format::VALUES or Format::JSON_EACH_ROW.
     *
     * $insertFormat decides when it is set; it takes 'Values' or 'JSONEachRow' in any letter case and with
     * surrounding spaces, or a Format instance of one of them. When it is null or blank, the insert_format option
     * of the model's connection decides (see Connection::getInsertFormat()).
     *
     * With JSONEachRow, keyed rows are sent as one JSON object per row (FORMAT JSONEachRow), and positional rows,
     * those of insertBulk() and the prepareAndInsert methods, as one JSON array per row (FORMAT JSONCompactEachRow).
     * ClickHouse reads a few values differently in JSONEachRow (24.8 checked): an integer column refuses a float
     * with a fraction or an exponent, such as 2.5 or 1e20, which a Values insert truncates or saturates, so cast
     * it with (int) or round() first; a Date column refuses a date with a time, and raw SQL is refused.
     *
     * @return string
     * @throws InvalidArgumentException When $insertFormat, or the connection's insert_format, has another value
     */
    protected function getInsertFormat(): string
    {
        $format = $this->insertFormat;
        if ($format === null || (is_string($format) && trim($format) === '')) {
            return $this->resolveConnection()->getInsertFormat();
        }

        $name = $format instanceof Format ? $format->getValue() : $format;
        if (is_string($name)) {
            foreach ([Format::VALUES, Format::JSON_EACH_ROW] as $allowed) {
                if (strcasecmp(trim($name), $allowed) === 0) {
                    return $allowed;
                }
            }
        }

        throw new InvalidArgumentException(sprintf(
            "The \$insertFormat of the model [%s] must be '%s' or '%s', [%s] given.",
            static::class,
            Format::VALUES,
            Format::JSON_EACH_ROW,
            is_string($name) ? $name : get_debug_type($format)
        ));
    }

    /**
     * Create and return an un-saved model instance.
     * @param array $attributes
     * @return static
     */
    public static function make(array $attributes = [])
    {
        $model = new static;
        $model->fill($attributes);

        return $model;
    }

    /**
     * Save a new model and return the instance.
     * @param array $attributes
     * @return static
     * @throws Exception
     */
    public static function create(array $attributes = [])
    {
        $model = static::make($attributes);

        if ($model->fireModelEvent('creating') === false) {
            return false;
        }

        if ($model->save()) {
            $model->wasRecentlyCreated = true;

            $model->fireModelEvent('created', false);
        }

        return $model;
    }

    /**
     * Fill the model with an array of attributes.
     * @param array $attributes
     * @return $this
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * Save the model to the database.
     *
     * The attributes are sent as one row by insertAssoc(), so a model without attributes is refused before anything
     * is sent, with smi2's QueryException 'Inserting empty values array is not supported in ClickHouse'.
     *
     * @param array $options
     * @return bool
     * @throws Exception
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new Exception("Clickhouse does not allow update rows");
        }
        $this->exists = !static::insertAssoc([$this->getAttributes()])->isError();
        $this->fireModelEvent('saved', false);
        return $this->exists;
    }

    /**
     * Bulk insert into Clickhouse database
     *
     * Each column name is sent with its backticks and backslashes escaped (see insertRows()).
     *
     * $casts apply to the columns that $columns names. A row without a value at the position of a cast column is
     * left as it is, so a row shorter than $columns fails, in ClickHouse for the Values format and before anything
     * is sent for JSONEachRow, and nothing is inserted.
     *
     * No rows, or a row without values, are refused before anything is sent, in both insert formats, with smi2's
     * QueryException 'Inserting empty values array is not supported in ClickHouse'.
     *
     * @param array[] $rows
     * @param array $columns
     * @return Statement
     * @example MyModel::insertBulk([['model 1', 1], ['model 2', 2]], ['model_name', 'some_param']);
     */
    public static function insertBulk(array $rows, array $columns = []): Statement
    {
        $instance = new static();
        if ($castsAssoc = $instance->casts) {
            $casts = [];
            foreach ($castsAssoc as $castColumn => $castType) {
                $index = array_search($castColumn, $columns);
                if ($index !== false) {
                    $casts[$index] = $castType;
                }
            }
            foreach ($rows as &$row) {
                $row = static::castRow($row, $casts);
            }
        }
        return self::insertRows($rows, $columns);
    }

    /**
     * Prepare each row by calling static::prepareFromRequest() with the row and the
     * column list, then bulk insert the rows with insertBulk()
     *
     * @param array[] $rows
     * @param array $columns
     * @return Statement
     */
    public static function prepareAndInsertBulk(array $rows, array $columns = []): Statement
    {
        return static::insertBulk(
            array_map(fn (array $row): array => static::prepareFromRequest($row, $columns), $rows),
            $columns
        );
    }

    /**
     * Prepare each row by calling static::prepareFromRequest() with the row and the
     * column list, then bulk insert the rows without applying $casts
     *
     * @param array[] $rows
     * @param array $columns
     * @return Statement
     * @deprecated use prepareAndInsertBulk
     */
    public static function prepareAndInsert(array $rows, array $columns = []): Statement
    {
        $rows = array_map(fn (array $row): array => static::prepareFromRequest($row, $columns), $rows);

        return self::insertRows($rows, $columns);
    }

    /**
     * Bulk insert rows as associative array into Clickhouse database
     *
     * Each key is sent as a column name with its backticks and backslashes escaped (see insertAssocRows()).
     *
     * Every row must have the keys of the first row, in any order. Otherwise nothing is inserted: smi2 throws
     * 'Fields not match' for the Values format, and the JSONEachRow format throws QueryException::insertKeysDiffer().
     * No rows, or a row without keys, are refused before anything is sent, in both insert formats, with smi2's
     * QueryException 'Inserting empty values array is not supported in ClickHouse'.
     *
     * In the Values format, raw SQL, such as DB::raw('now()'), an Expression or smi2's Raw, is sent as the SQL it
     * holds; the JSONEachRow format refuses it.
     *
     * @param array[] $rows
     * @return Statement
     * @example MyModel::insertAssoc([['model_name' => 'model 1', 'some_param' => 1], ['model_name' => 'model 2', 'some_param' => 2]]);
     */
    public static function insertAssoc(array $rows): Statement
    {
        return self::insertAssocRows(static::prepareAssocRowsForInsert($rows));
    }

    /**
     * Insert positional rows into the table for inserts, in the model's insert format (see getInsertFormat()),
     * with each column name escaped (see Grammar::escapeInsertColumns()), so that a name with a backtick or a
     * trailing backslash stays one identifier. HasBufferedInserts::insertPositionalRows() sends them, also while the
     * connection pretends.
     *
     * The method is private and called with self::, as is insertAssocRows(), so a
     * model may declare a method of the same name with any signature: PHP applies
     * no inheritance rules to a private method. new static() still names the model
     * that the insert was called on.
     *
     * @param array[] $rows
     * @param array<int, int|string> $columns
     * @return Statement
     */
    private static function insertRows(array $rows, array $columns): Statement
    {
        return self::insertPositionalRows($rows, $columns);
    }

    /**
     * Insert associative rows into the table for inserts, in the model's insert format (see getInsertFormat()), with
     * each column name escaped (see insertRows(), which also says why both are private).
     * HasBufferedInserts::insertKeyedRows() sends them, as flushBuffer() does.
     *
     * In the Values format, smi2's Client::prepareInsertAssocBulk() splits the rows into the column names and the
     * values, and throws when a row's keys, or their order, differ from the first row's.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return Statement
     */
    private static function insertAssocRows(array $rows): Statement
    {
        return self::insertKeyedRows($rows);
    }

    /**
     * Reorder associative rows to the key order of the first row and apply $casts.
     * Shared by insertAssoc() and the buffered insert pipeline so cast behavior
     * stays identical.
     *
     * A row whose key set differs from the first row's is left as it is (see
     * reorderAssocRowKeys()), so the insert refuses the rows instead of sending
     * values that the caller did not give.
     *
     * @param array[] $rows
     * @return array[]
     */
    protected static function prepareAssocRowsForInsert(array $rows): array
    {
        $rows = static::reorderAssocRowKeys($rows);
        if ($casts = (new static())->casts) {
            foreach ($rows as &$row) {
                $row = static::castRow($row, $casts);
            }
            unset($row);
        }
        return $rows;
    }

    /**
     * Align every row to the key order of the first row, filling any key the row
     * lacks with its positional index in the first row.
     *
     * @deprecated No longer used: it gave a missing key a value that the caller did not give, such as '1' for a
     *             String column. prepareAssocRowsForInsert() uses reorderAssocRowKeys(), and the insert refuses a
     *             row whose keys differ from the first row's.
     *
     * @param array[] $rows
     * @return array[]
     */
    protected static function normalizeAssocRowKeyOrder(array $rows): array
    {
        $rows = array_values($rows);
        if (isset($rows[0]) && isset($rows[1])) {
            $keys = array_keys($rows[0]);
            foreach ($rows as &$row) {
                $row = array_replace(array_flip($keys), $row);
            }
            unset($row);
        }
        return $rows;
    }

    /**
     * Reorder rows to the first row's key order WITHOUT inventing values. Rows
     * buffered one at a time are each prepared in isolation, so their key order
     * is only comparable once they sit in one array. A row whose key set differs
     * is left untouched, so a genuinely mismatched buffer still fails loudly at
     * insert time instead of shipping fabricated column values.
     *
     * @param array[] $rows
     * @return array[]
     */
    protected static function reorderAssocRowKeys(array $rows): array
    {
        $rows = array_values($rows);
        if (!isset($rows[0]) || !isset($rows[1])) {
            return $rows;
        }
        $reference = array_flip(array_keys($rows[0]));
        foreach ($rows as &$row) {
            if (count($row) === count($reference) && !array_diff_key($reference, $row)) {
                $row = array_replace($reference, $row);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Prepare each row by calling static::prepareAssocFromRequest to bulk insert into database
     * @param array[] $rows
     * @return Statement
     */
    public static function prepareAndInsertAssoc(array $rows): Statement
    {
        $rows = array_map(fn (array $row): array => static::prepareAssocFromRequest($row), $rows);
        return static::insertAssoc($rows);
    }

    /**
     * Prepare row to insert into DB, non-associative array
     * Need to overwrite in nested models
     * @param array $row
     * @param array $columns
     * @return array
     */
    public static function prepareFromRequest(array $row, array $columns = []): array
    {
        return $row;
    }

    /**
     * Prepare row to insert into DB, associative array
     * Need to overwrite in nested models
     * @param array $row
     * @return array
     */
    public static function prepareAssocFromRequest(array $row): array
    {
        return $row;
    }

    /**
     * Apply $casts to a row before it is inserted: a 'boolean' cast writes 1 or 0. A cast column that the row does
     * not have is skipped, so it stays out of the insert and ClickHouse gives it the column's default.
     *
     * @param array<int|string, mixed> $row
     * @param array<int|string, string> $casts Cast type by column name, or by position for insertBulk()
     * @return array<int|string, mixed>
     */
    protected static function castRow(array $row, array $casts): array
    {
        foreach ($casts as $index => $castType) {
            if (!array_key_exists($index, $row)) {
                continue;
            }
            $value = $row[$index];
            if ('boolean' == $castType) {
                $value = (int)(bool)$value;
            }
            $row[$index] = $value;
        }
        return $row;
    }

    /**
     * @param string|array|RawColumn $select optional = ['*']
     * @return Builder
     */
    public static function select($select = ['*']): Builder
    {
        $instance = new static();
        return $instance->newQuery()->select($select)->from($instance->getTable());
    }

    /**
     * Get a new query builder on the model's connection, which follows the connection's options (see
     * Builder::followConnectionOptions()), so that it writes dates at the connection's datetime_precision, as the
     * builders of Connection::query() do.
     *
     * @return Builder
     */
    protected function newQuery(): Builder
    {
        return (new Builder($this->getThisClient(), $this->connection))
            ->followConnectionOptions($this->resolveConnection());
    }

    /**
     * Begin a query on this model's table with the sources table attached for
     * mutation queries. Shared scaffolding for query(), where() and whereRaw();
     * select() deliberately does not attach the sources table.
     *
     * @return Builder
     */
    protected static function newSourcedQuery(): Builder
    {
        $instance = new static();
        return $instance->newQuery()->select(['*'])
            ->from($instance->getTable())
            ->setSourcesTable($instance->getTableSources());
    }

    /**
     * Begin a query on this model's table without a condition, so that any method of the builder can follow:
     * MyTable::query()->whereDate('created_at', '2024-01-05') selects * from the model's table, as where() does.
     * The sources table is attached, so delete(), update() and truncate() write to getTableSources().
     *
     * The model has no __callStatic() that would forward every builder method: MyTable::insert([...]) would then
     * reach Builder::insert(), which writes to the sources table without $casts and without $tableForInserts.
     *
     * @return Builder
     */
    public static function query(): Builder
    {
        return static::newSourcedQuery();
    }

    /**
     * Dynamically retrieve attributes on the model.
     *
     * @param string $key
     * @return mixed
     */
    public function __get(string $key)
    {
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set attributes on the model.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function __set(string $key, $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Optimize table. Using for ReplacingMergeTree, etc.
     *
     * The partition is written as Builder::delete() writes its IN PARTITION: an int as a number, a string as an
     * escaped string literal, and an Expression, or a Laravel database expression such as DB::raw(), as the SQL it
     * holds, for example new Expression("ID '202401'") or a tuple. ClickHouse (24.8 checked) reads 202401 and
     * '202401' as the same partition of a toYYYYMM() key. Only null leaves the PARTITION clause out.
     *
     * With the use_on_cluster option of the connection, the statement gets ON CLUSTER '<cluster_name>', so that
     * every node optimizes its table (see compileDefaultOnClusterClause()); run it in the connection's
     * withoutOnCluster() callback to optimize the table of the active node only. In a session, such a statement on a
     * table name that a temporary table of the session has is refused before it is sent (see
     * refuseATemporaryTableThatOnClusterMisses()). While the connection pretends, the statement is logged and not
     * sent (see ClientRequests::write()).
     *
     * @source https://clickhouse.tech/docs/ru/sql-reference/statements/optimize/
     * @param bool $final
     * @param int|string|Expression|ExpressionContract|null $partition
     * @return Statement
     * @throws QueryException When the statement would miss a temporary table of the session
     */
    public static function optimize(
        bool $final = false,
        int|string|Expression|ExpressionContract|null $partition = null
    ): Statement {
        $instance = new static();
        $connection = $instance->resolveConnection();
        $onCluster = $instance->compileDefaultOnClusterClause();
        if ($onCluster !== '') {
            $instance->refuseATemporaryTableThatOnClusterMisses('OPTIMIZE TABLE ... ON CLUSTER');
        }

        $sql = "OPTIMIZE TABLE " . $instance->getTableSources() . $onCluster;
        if ($partition !== null) {
            $sql .= ' PARTITION ' . $connection->newBuilderGrammar()->wrap($partition);
        }
        if ($final) {
            $sql .= " FINAL";
        }

        return ClientRequests::write($instance->getThisClient(), $connection, $sql);
    }

    /**
     * Remove every row of the model's sources table (see getTableSources()) with TRUNCATE TABLE.
     *
     * With the use_on_cluster option of the connection, the statement gets ON CLUSTER '<cluster_name>', so that
     * every node is emptied (see compileDefaultOnClusterClause()); run it in the connection's withoutOnCluster()
     * callback to empty the table of the active node only. In a session, such a statement on a table name that a
     * temporary table of the session has is refused before it is sent (see refuseATemporaryTableThatOnClusterMisses()).
     * While the connection pretends, the statement is logged and not sent (see ClientRequests::write()).
     *
     * @return Statement
     * @throws QueryException When the statement would miss a temporary table of the session
     */
    public static function truncate(): Statement
    {
        $instance = new static();
        $onCluster = $instance->compileDefaultOnClusterClause();
        if ($onCluster !== '') {
            $instance->refuseATemporaryTableThatOnClusterMisses('TRUNCATE TABLE ... ON CLUSTER');
        }

        return ClientRequests::write(
            $instance->getThisClient(),
            $instance->resolveConnection(),
            'TRUNCATE TABLE ' . $instance->getTableSources() . $onCluster
        );
    }

    /**
     * Compile the ON CLUSTER clause of optimize() and truncate(), which follows the table name: ON CLUSTER '<name>'
     * for the default cluster of the model's connection (see Connection::getDefaultCluster()), which is null unless
     * the use_on_cluster option is on and no Connection::withoutOnCluster() callback runs, and nothing without one.
     *
     * Model queries, such as where(), get the same default through the builder (see
     * Builder::getEffectiveCluster()).
     *
     * @return string
     * @throws InvalidArgumentException When the connection's use_on_cluster option is not a boolean
     */
    protected function compileDefaultOnClusterClause(): string
    {
        $cluster = $this->resolveConnection()->getDefaultCluster();

        return $cluster === null ? '' : ' ON CLUSTER ' . (new Grammar())->quoteString($cluster);
    }

    /**
     * Refuse an ON CLUSTER statement on the sources table when the model's connection is in a session that has a
     * temporary table of that name: ClickHouse (24.8 checked) runs an ON CLUSTER statement outside the session, on
     * the table of that name in the database of every node.
     *
     * The check runs when the sources table is a name without a database and the connection is in a session and does
     * not pretend: Connection::hasTemporaryTable() then sends EXISTS TEMPORARY TABLE `<name>`, which is logged. The
     * method is private, so a model may declare a method of the same name with any signature.
     *
     * @param string $statement The statement, such as 'TRUNCATE TABLE ... ON CLUSTER'
     * @return void
     * @throws QueryException When the session has a temporary table of the name (see
     *                        QueryException::cannotReachTemporaryTable())
     */
    private function refuseATemporaryTableThatOnClusterMisses(string $statement): void
    {
        $connection = $this->resolveConnection();
        $table = trim($this->getTableSources());
        if (preg_match('/^`((?:[^`\\\\]|``)*)`\z/', $table, $quoted) === 1) {
            $table = str_replace('``', '`', $quoted[1]);
        } elseif (preg_match('/[`"\s]/', $table) === 1) {
            return;
        }

        if ($table === '' || str_contains($table, '.') || $connection->pretending()
            || !$connection->hasTemporaryTable($table)
        ) {
            return;
        }

        throw QueryException::cannotReachTemporaryTable(
            $statement,
            $table,
            "Run it in the connection's withoutOnCluster() callback, so that it is sent without ON CLUSTER and"
            . ' reaches the temporary table.'
        );
    }

    /**
     * Begin a query on this model's table with a where condition.
     *
     * The arguments are passed to Builder::where() exactly as given, so
     * where('col', $value) and where('col', '=', $value) build the same
     * condition as they do on the builder.
     *
     * @param TwoElementsLogicExpression|string|Closure $column
     * @param int|float|string|null $operator or $value
     * @param int|float|string|null $value
     * @param string $concatOperator Operator::AND for example
     * @return Builder
     */
    public static function where(
        $column,
        $operator = null,
        $value = null,
        string $concatOperator = Operator::AND
    ): Builder {
        return static::newSourcedQuery()->where(...func_get_args());
    }

    /**
     * @param string $expression
     * @return Builder
     */
    public static function whereRaw(string $expression): Builder
    {
        return static::newSourcedQuery()->whereRaw($expression);
    }
}
