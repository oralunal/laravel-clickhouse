<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Eloquent;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use LogicException;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\QueryBuilder;

/**
 * An Eloquent model of a ClickHouse table, with Eloquent's relations, timestamps, casts, events and observers.
 *
 * Its queries use Laravel's query builder on the ClickHouse connection, the package's QueryBuilder, whatever the
 * connection's fix_default_query_builder option says, so the ClickHouse clauses of QueryBuilder, such as final(),
 * preWhere() or settings(), work on the model's queries. ClickHouse has no auto-increment, so the key is not
 * incrementing and is a string by default: give the key before the model is created, for example with
 * Str::uuid(), or set $keyType to 'int'.
 *
 * save() of an existing model and update() send an ALTER TABLE ... UPDATE mutation, and delete() an ALTER TABLE
 * ... DELETE mutation or, with the use_lightweight_delete option, a lightweight DELETE FROM. ClickHouse runs a
 * mutation in the background unless the mutations_sync setting waits for it.
 *
 * For the package's own model, which is not an Eloquent model, see BaseModel.
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
     * Get a new query builder for the model's connection: the package's QueryBuilder, Laravel's query builder with
     * the ClickHouse clauses, also when the connection's fix_default_query_builder option is on.
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
