<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Eloquent;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Oralunal\LaravelClickHouse\QueryBuilder;

/**
 * The Eloquent query builder of a ClickHouse model (see Model): Eloquent's builder, whose delete(), forceDelete()
 * and update() pass the ClickHouse options to QueryBuilder. The other methods of QueryBuilder, such as final(),
 * preWhere(), settings() and the ClickHouse joins, reach it through Eloquent's forwarding.
 *
 * @template TModel of EloquentModel
 *
 * @extends EloquentBuilder<TModel>
 *
 * @mixin QueryBuilder
 */
class Builder extends EloquentBuilder
{
    /**
     * Delete the rows of the query, as Eloquent does: with the replacement of onDelete(), such as a soft delete, when
     * one is registered, else with QueryBuilder::delete().
     *
     * $lightweight true sends a lightweight DELETE FROM, false an ALTER TABLE ... DELETE mutation, and null follows
     * the connection's use_lightweight_delete option. $partition limits the delete to one partition (IN PARTITION).
     *
     * @param bool|null $lightweight
     * @param int|string|ExpressionContract|null $partition
     * @return mixed
     */
    public function delete(?bool $lightweight = null, int|string|ExpressionContract|null $partition = null)
    {
        if (isset($this->onDelete)) {
            return call_user_func($this->onDelete, $this);
        }

        return $this->toBase()->delete(null, $lightweight, $partition);
    }

    /**
     * Delete the rows of the query without the replacement of onDelete() and without the global scopes, as
     * Eloquent's forceDelete() does (see delete()).
     *
     * @param bool|null $lightweight
     * @param int|string|ExpressionContract|null $partition
     * @return int
     */
    public function forceDelete(?bool $lightweight = null, int|string|ExpressionContract|null $partition = null)
    {
        return $this->query->delete(null, $lightweight, $partition);
    }

    /**
     * Update the rows of the query with an ALTER TABLE ... UPDATE mutation, with the updated-at timestamp as
     * Eloquent adds it. $partition limits the update to one partition (IN PARTITION).
     *
     * @param array<string, mixed> $values
     * @param int|string|ExpressionContract|null $partition
     * @return int
     */
    public function update(array $values, int|string|ExpressionContract|null $partition = null)
    {
        return $this->toBase()->update($this->addUpdatedAtColumn($values), $partition);
    }
}
