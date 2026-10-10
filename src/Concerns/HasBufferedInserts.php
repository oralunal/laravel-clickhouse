<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Concerns;

use ClickHouseDB\Exception\QueryException as ClientQueryException;
use ClickHouseDB\Statement;
use Illuminate\Container\Container;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use Oralunal\LaravelClickHouse\ClientRequests;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\JsonEachRowEncoder;
use ReflectionMethod;
use Throwable;

/**
 * In-memory buffered inserts for ClickHouse models.
 *
 * Rows are accumulated per-class via buffer() and sent to ClickHouse as a
 * single INSERT HTTP request when flushBuffer() is called. The cast
 * pipeline used by insertAssoc() is applied at buffer time, not at flush time,
 * so the buffer always holds prepared rows ready for the wire.
 *
 * buffer() refuses a row whose keys differ from those of the model's other buffered rows, and a row without
 * keys, before anything is buffered, so that one bad call cannot keep the model's other rows from being flushed.
 * On flush failure the buffer is preserved so the caller can retry.
 *
 * BaseModel already uses this trait, so a model does not need to. A model that
 * uses it again keeps its buffers, and those of its children, in its own static
 * properties. Every copy of the trait registers its models in one
 * BufferedInsertRegistry, so flushAllBuffers(), called on any model, and the
 * automatic flush at the end of the request or script flush every model that
 * has buffered rows.
 *
 * The trait also holds the code that sends a model's rows, which BaseModel's
 * inserts use too: the copy of flushBuffer() in a model that uses the trait
 * again cannot call BaseModel's private methods. Its helpers are private, so a
 * model may declare methods of the same names with any signature.
 */
trait HasBufferedInserts
{
    /** @var array<class-string, array<int, array<string, mixed>>> */
    protected static array $buffers = [];

    /** @var array<class-string, true> */
    protected static array $modelsWithBuffer = [];

    /**
     * Accept a single associative row or an array of associative rows.
     *
     * The buffer is sent as one insert, so every buffered row of a model must have the same keys, in any order.
     * A row whose keys differ from those of the first row of the call, or from those of the rows already
     * buffered, and a row without keys, are refused before anything is buffered: the call throws, and the rows
     * buffered before it stay buffered and can still be flushed. An empty array buffers nothing.
     *
     * While the model's connection pretends (see DB::pretend()), the rows are not buffered: they go through the
     * insert at once, which logs the INSERT and sends nothing, so that a flush after pretend() ends, such as the
     * automatic one at the end of the request, cannot send them. The model's connection is the one that its inserts
     * go through, also when the model overrides resolveConnection() (see madeConnectionPretends()).
     *
     * @param array<string, mixed>|array<int|string, array<string, mixed>> $rowOrRows
     * @return void
     * @throws QueryException When a row's keys differ from those of the first row or of the buffered rows
     * @throws ClientQueryException When a row has no keys
     */
    public static function buffer(array $rowOrRows): void
    {
        if ($rowOrRows === []) {
            return;
        }

        $rows = self::isSingleAssocRow($rowOrRows) ? [$rowOrRows] : array_values($rowOrRows);

        $prepared = static::prepareAssocRowsForInsert($rows);
        $pretending = self::madeConnectionPretends();

        self::verifyRowsToBuffer($prepared, $pretending ? null : (static::$buffers[static::class][0] ?? null));

        if ($pretending) {
            self::insertKeyedRows($prepared);

            return;
        }

        if (!isset(static::$buffers[static::class])) {
            static::$buffers[static::class] = [];
        }
        array_push(static::$buffers[static::class], ...$prepared);
        static::$modelsWithBuffer[static::class] = true;
        BufferedInsertRegistry::add(static::class);
    }

    /**
     * Send all buffered rows to ClickHouse as a single HTTP request.
     * Returns null when the buffer is empty. On failure the buffer is preserved.
     *
     * The rows are sent as insertAssoc() sends them (see insertKeyedRows()): in the
     * model's insert format, with each key sent as a column name with its backticks
     * and backslashes escaped. While the model's connection pretends, the INSERT is
     * logged and not sent, and the rows stay buffered, so that the first flush after
     * pretend() ends, such as the automatic one at the end of the request, sends
     * them: pretending must not lose rows that were buffered before it began.
     *
     * The model's connection is the one that its resolveConnection() returns, which
     * the insert goes through, also when a model overrides resolveConnection(): the
     * rows are kept exactly when that connection logs the INSERT instead of sending it.
     */
    public static function flushBuffer(): ?Statement
    {
        $rows = static::$buffers[static::class] ?? [];
        if ($rows === []) {
            BufferedInsertRegistry::remove(static::class);

            return null;
        }

        // Rows buffered one at a time were each prepared in isolation, so their
        // key order is only comparable now that they sit in one array. buffer()
        // has refused every row with another key set, so reordering is enough.
        $rows = static::reorderAssocRowKeys($rows);

        $pretending = (new static())->resolveConnection()->pretending();
        $statement = self::insertKeyedRows($rows);
        if ($pretending) {
            return $statement;
        }

        unset(static::$buffers[static::class], static::$modelsWithBuffer[static::class]);
        BufferedInsertRegistry::remove(static::class);

        return $statement;
    }

    /**
     * Flush every model that currently has buffered rows: those of every copy of
     * this trait (see BufferedInsertRegistry), whichever model it is called on.
     * Each model's own flushBuffer() sends its rows, or, while that model's
     * connection pretends, logs the INSERT and keeps them.
     *
     * @param bool $silent When true, exceptions are reported via report() instead of bubbling.
     *                     Used by the script-shutdown auto-flush hook.
     */
    public static function flushAllBuffers(bool $silent = false): void
    {
        $classes = array_unique([...BufferedInsertRegistry::all(), ...array_keys(self::$modelsWithBuffer)]);

        foreach ($classes as $class) {
            try {
                /** @var class-string<self> $class */
                $class::flushBuffer();
            } catch (Throwable $e) {
                if (!$silent) {
                    throw $e;
                }
                if (function_exists('report')) {
                    report($e);
                }
            }
        }
    }

    public static function bufferCount(): int
    {
        return count(static::$buffers[static::class] ?? []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getBufferedRows(): array
    {
        return static::$buffers[static::class] ?? [];
    }

    /**
     * Discard the buffer without sending anything to ClickHouse.
     */
    public static function clearBuffer(): void
    {
        unset(static::$buffers[static::class], static::$modelsWithBuffer[static::class]);
        BufferedInsertRegistry::remove(static::class);
    }

    private static function isSingleAssocRow(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check the prepared rows of a buffer() call before they are buffered: no row may be without keys, and every
     * row must have the keys of the first buffered row, or, when nothing is buffered, of the first row of the
     * call, in any order.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>|null $bufferedRow The first row already buffered, or null when there is none
     * @return void
     * @throws ClientQueryException When a row has no keys
     * @throws QueryException When a row's keys differ
     */
    private static function verifyRowsToBuffer(array $rows, ?array $bufferedRow): void
    {
        self::verifyRowsHaveValues($rows);

        $reference = $bufferedRow ?? $rows[array_key_first($rows)];
        foreach ($rows as $index => $row) {
            if (count($row) !== count($reference) || array_diff_key($row, $reference) !== []) {
                throw new QueryException(sprintf(
                    'Cannot buffer the rows of the model [%s]: the row at index %s has the keys [%s], and %s [%s].'
                    . ' Every buffered row of a model must have the same keys, in any order, because the buffer is'
                    . ' sent as one insert. Nothing was buffered.',
                    static::class,
                    $index,
                    implode(', ', array_keys($row)),
                    $bufferedRow === null ? 'the first row' : 'the rows already buffered',
                    implode(', ', array_keys($reference))
                ));
            }
        }
    }

    /**
     * Refuse an insert without rows, or with a row without values, before anything is sent or logged, in either
     * insert format, with the exception that smi2's Client::insert() throws for an empty row list.
     *
     * A Values insert would otherwise send VALUES (), which ClickHouse (24.8 checked) refuses as a SYNTAX_ERROR,
     * or, for a single numeric column, stores as a row with 0 instead of the column's default; a JSONEachRow
     * insert would store a row of column defaults for {}.
     *
     * @param array<int|string, mixed> $rows
     * @return void
     * @throws ClientQueryException When there are no rows, or a row is an empty array
     */
    private static function verifyRowsHaveValues(array $rows): void
    {
        if ($rows === [] || in_array([], $rows, true)) {
            throw ClientQueryException::cannotInsertEmptyValues();
        }
    }

    /**
     * Insert keyed rows into the model's table for inserts, in the model's insert format (see
     * BaseModel::getInsertFormat()):
     * - JSONEachRow: ClientRequests::insertJsonEachRow() sends one JSON object per row, after checking that every
     *   row has the first row's keys, in any order;
     * - Values: smi2's Client::prepareInsertAssocBulk() splits the rows into the column names and the values, and
     *   throws 'Fields not match' when a row's keys, or their order, differ from the first row's; the rows are then
     *   inserted as insertPositionalRows() inserts them.
     *
     * No rows, or a row without keys, are refused in both formats (see verifyRowsHaveValues()). Nothing is sent
     * when a check fails. new static() names the model that the insert was called on.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return Statement
     * @throws ClientQueryException When there are no rows, a row has no keys, or, in the Values format, a row's
     *                              keys differ from the first row's
     * @throws QueryException When, in the JSONEachRow format, a row's keys differ from the first row's
     */
    private static function insertKeyedRows(array $rows): Statement
    {
        self::verifyRowsHaveValues($rows);

        $instance = new static();
        $connection = $instance->resolveConnection();

        if ($instance->getInsertFormat() === Format::JSON_EACH_ROW) {
            return ClientRequests::insertJsonEachRow(
                $instance->getThisClient(),
                $connection,
                $instance->getTableForInserts(),
                $rows,
                new JsonEachRowEncoder($connection->newBuilderGrammar()->formatDateTime(...))
            );
        }

        [$columns, $values] = $instance->getThisClient()->prepareInsertAssocBulk($rows);

        return self::insertValuesRows($instance, $connection, $values, $columns);
    }

    /**
     * Insert positional rows into the model's table for inserts, in the model's insert format (see
     * BaseModel::getInsertFormat()):
     * - JSONEachRow: ClientRequests::insertJsonCompactEachRow() sends one JSON array per row, after checking that
     *   every row has as many values as the columns, or as the first row when no columns are given;
     * - Values: see insertValuesRows().
     *
     * Without columns, the values of a row go to the table's columns in their order. No rows, or a row without
     * values, are refused in both formats (see verifyRowsHaveValues()).
     *
     * @param array<int|string, array<int|string, mixed>> $rows
     * @param array<int, int|string> $columns
     * @return Statement
     * @throws ClientQueryException When there are no rows, or a row has no values
     */
    private static function insertPositionalRows(array $rows, array $columns): Statement
    {
        self::verifyRowsHaveValues($rows);

        $instance = new static();
        $connection = $instance->resolveConnection();

        if ($instance->getInsertFormat() === Format::JSON_EACH_ROW) {
            return ClientRequests::insertJsonCompactEachRow(
                $instance->getThisClient(),
                $connection,
                $instance->getTableForInserts(),
                $rows,
                new JsonEachRowEncoder($connection->newBuilderGrammar()->formatDateTime(...)),
                $columns
            );
        }

        return self::insertValuesRows($instance, $connection, $rows, $columns);
    }

    /**
     * Insert positional rows in the Values format: compile the INSERT that smi2's Client::insert() would send for
     * them (see ClientRequests::compileValuesInsert()) and send it with Client::write(), which hands it to the
     * transport as Client::insert() does.
     *
     * A grammar of the connection (Connection::newBuilderGrammar()) escapes the backticks and backslashes of each
     * column name (see Grammar::escapeInsertColumns()), so that it stays one identifier, and writes the values
     * that smi2 would write differently from the rest of the package (see ClientRequests::compileValuesInsert()).
     *
     * While the connection pretends, the INSERT is logged and nothing is sent (see ClientRequests::write()).
     *
     * @param BaseModel $instance The model that the insert was called on
     * @param Connection $connection
     * @param array<int|string, array<int|string, mixed>> $rows
     * @param array<int, int|string> $columns
     * @return Statement
     * @throws ClientQueryException When there are no rows, or the insert fails
     */
    private static function insertValuesRows(
        BaseModel $instance,
        Connection $connection,
        array $rows,
        array $columns
    ): Statement {
        $grammar = $connection->newBuilderGrammar();
        $sql = ClientRequests::compileValuesInsert(
            $grammar,
            $instance->getTableForInserts(),
            $rows,
            $grammar->escapeInsertColumns($columns)
        );

        return ClientRequests::write($instance->getThisClient(), $connection, $sql);
    }

    /**
     * Determine if the model's connection, the one that its inserts go through, pretends, without making the
     * connection: a connection that the database manager has not made yet, which pings a node, cannot be pretending.
     *
     * A model that overrides resolveConnection() chooses its connection itself, so its resolveConnection() is asked,
     * which may make the connection. Otherwise the connection that the model's $connection names, or the default one,
     * is looked up among the connections that the database manager has made.
     *
     * @return bool
     */
    private static function madeConnectionPretends(): bool
    {
        $instance = new static();
        if ((new ReflectionMethod($instance, 'resolveConnection'))->class !== BaseModel::class) {
            return $instance->resolveConnection()->pretending();
        }

        $container = Container::getInstance();
        if (!$container->bound('db') || !$container->bound('config')) {
            return false;
        }

        $name = $instance->connection ?? $container->make('config')->get('database.default');
        $connection = $container->make('db')->getConnections()[$name] ?? null;

        return $connection instanceof Connection && $connection->pretending();
    }
}
