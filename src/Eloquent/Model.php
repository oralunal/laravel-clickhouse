<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Eloquent;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use LogicException;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\QueryBuilder;
use Throwable;

/**
 * An Eloquent model of a ClickHouse table, with Eloquent's relations, timestamps, casts, events and observers.
 *
 * Its queries use Laravel's query builder on the ClickHouse connection, the package's QueryBuilder, so the ClickHouse
 * clauses of QueryBuilder, such as final(), preWhere() or settings(), work on the model's queries. ClickHouse has no
 * auto-increment, so the key is not incrementing and is a string by default: give the key before the model is
 * created, for example with Str::uuid(), or set $keyType to 'int'. A date cast follows the connection's
 * datetime_precision (see fromDateTime()).
 *
 * save() of an existing model and update() send an ALTER TABLE ... UPDATE mutation, and delete() an ALTER TABLE
 * ... DELETE mutation or, with the use_lightweight_delete option, a lightweight DELETE FROM. ClickHouse runs a
 * mutation in the background unless the mutations_sync setting waits for it.
 *
 * buffer() keeps rows in the memory of the process and flushBuffer() inserts them in one INSERT; the package flushes
 * every buffer at the end of the request or script. optimize() sends OPTIMIZE TABLE.
 */
abstract class Model extends EloquentModel
{
    /**
     * The connection name for the model.
     *
     * @var string|null
     */
    protected $connection = Connection::DEFAULT_NAME;

    /**
     * Indicates if the IDs are auto-incrementing: ClickHouse has no auto-increment.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The data type of the primary key.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The rows that buffer() keeps for each model class, until flushBuffer() inserts them.
     *
     * @var array<class-string, list<array<string, mixed>>>
     */
    protected static array $bufferedRows = [];

    /**
     * Keep one row, or a list of rows, in the memory of the process, for flushBuffer() to insert in one INSERT.
     *
     * Each row has column keys. An array with an integer key is a list of rows, also when its keys are not 0, 1, 2, ...,
     * such as the result of array_filter() or rows keyed by an id; an array with only string keys is one row. Every
     * buffered row of a model must have the same keys, in any order, because the buffer is sent as one insert: a row
     * with other keys, or without keys, is refused before anything is buffered, and the rows buffered before stay
     * buffered. The rows are inserted as given, as insert() inserts them: casts and mutators do not apply. While the
     * model's connection pretends, the rows are inserted at once, which logs the INSERT and sends nothing.
     *
     * @param array<string, mixed>|array<int, array<string, mixed>> $rowOrRows
     * @return void
     * @throws QueryException When a row has no keys, or other keys than the other buffered rows
     */
    public static function buffer(array $rowOrRows): void
    {
        if ($rowOrRows === []) {
            return;
        }

        $rows = array_filter(array_keys($rowOrRows), 'is_int') !== [] ? array_values($rowOrRows) : [$rowOrRows];
        $model = new static();

        if ($model->getConnection()->pretending()) {
            $model->newQuery()->toBase()->insert($rows);

            return;
        }

        $reference = static::$bufferedRows[static::class][0] ?? $rows[0];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || $row === [] || array_is_list($row) || !is_array($reference)
                || count($row) !== count($reference) || array_diff_key($row, $reference) !== []
            ) {
                throw new QueryException(sprintf(
                    'Cannot buffer the rows of the model [%s]: the row at index %s has the keys [%s], and the buffer'
                    . ' needs the keys [%s]. Every buffered row of a model must have the same column keys, in any'
                    . ' order, because the buffer is sent as one insert. Nothing was buffered.',
                    static::class,
                    $index,
                    is_array($row) ? implode(', ', array_keys($row)) : get_debug_type($row),
                    is_array($reference) ? implode(', ', array_keys($reference)) : ''
                ));
            }
        }

        static::$bufferedRows[static::class] ??= [];
        array_push(static::$bufferedRows[static::class], ...$rows);
    }

    /**
     * Insert the buffered rows of the model in one INSERT, with the connection's insert format. It returns false
     * when nothing is buffered. When the insert fails, it throws and the rows stay buffered. While the model's
     * connection pretends, the INSERT is logged and not sent, and the rows stay buffered, so that the first flush
     * after pretend() sends them.
     *
     * @return bool
     */
    public static function flushBuffer(): bool
    {
        $rows = static::$bufferedRows[static::class] ?? [];
        if ($rows === []) {
            unset(static::$bufferedRows[static::class]);

            return false;
        }

        $query = (new static())->newQuery()->toBase();
        $query->insert($rows);

        if (!$query->getConnection()->pretending()) {
            unset(static::$bufferedRows[static::class]);
        }

        return true;
    }

    /**
     * Flush the buffers of every model, whichever model it is called on. The package calls it at the end of the
     * request and of the script.
     *
     * @param bool $silent True reports an error with report() and goes on with the next model, false throws it
     * @return void
     * @throws Throwable When a flush fails and $silent is false
     */
    public static function flushAllBuffers(bool $silent = false): void
    {
        foreach (array_keys(self::$bufferedRows) as $class) {
            try {
                $class::flushBuffer();
            } catch (Throwable $exception) {
                if (!$silent) {
                    throw $exception;
                }

                report($exception);
            }
        }
    }

    /**
     * Get the number of rows in the buffer of the model.
     *
     * @return int
     */
    public static function bufferCount(): int
    {
        return count(static::$bufferedRows[static::class] ?? []);
    }

    /**
     * Get the rows in the buffer of the model.
     *
     * @return list<array<string, mixed>>
     */
    public static function getBufferedRows(): array
    {
        return static::$bufferedRows[static::class] ?? [];
    }

    /**
     * Remove the rows in the buffer of the model without inserting them.
     *
     * @return void
     */
    public static function clearBuffer(): void
    {
        unset(static::$bufferedRows[static::class]);
    }

    /**
     * Start a merge of the parts of the model's table with OPTIMIZE TABLE (see QueryBuilder::optimize()).
     *
     * @param bool $final
     * @param int|string|ExpressionContract|null $partition
     * @return bool
     */
    public static function optimize(bool $final = false, int|string|ExpressionContract|null $partition = null): bool
    {
        return (new static())->newQuery()->toBase()->optimize($final, $partition);
    }

    /**
     * Convert a date to the storable string of a date cast. Without $dateFormat, the date is written as the
     * connection writes dates (see Connection::formatDateTime()): at the microsecond datetime_precision with its
     * sub-second part, and otherwise with seconds. A date without a sub-second part keeps the plain form, which a
     * DateTime column takes. A model with $dateFormat uses it, as Eloquent does.
     *
     * @param mixed $value
     * @return string|null
     */
    public function fromDateTime($value)
    {
        $connection = $this->getConnection();

        if (empty($value) || $this->dateFormat !== null || !$connection instanceof Connection) {
            return parent::fromDateTime($value);
        }

        return $connection->formatDateTime($this->asDateTime($value));
    }

    /**
     * Get a new query builder for the model's connection: the package's QueryBuilder, Laravel's query builder with
     * the ClickHouse clauses.
     *
     * @return QueryBuilder
     * @throws LogicException When the model's connection is not a ClickHouse connection
     */
    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        if (!$connection instanceof Connection) {
            throw new LogicException(sprintf(
                'The model [%s] extends %s, which needs a ClickHouse connection, but its connection [%s] is a %s.',
                static::class,
                self::class,
                $connection->getName(),
                get_debug_type($connection)
            ));
        }

        return new QueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    }

    /**
     * Create a new Eloquent query builder for the model: the package's Eloquent Builder, whose delete(),
     * forceDelete() and update() take the ClickHouse options.
     *
     * @param QueryBuilder $query
     * @return Builder<static>
     */
    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }
}
