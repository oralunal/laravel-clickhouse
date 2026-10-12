<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Eloquent;

use ClickHouseDB\Statement;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Oralunal\LaravelClickHouse\Enums\Format;
use Oralunal\LaravelClickHouse\QueryBuilder;

/**
 * The Eloquent query builder of a ClickHouse model (see Model): Eloquent's builder, whose delete(), forceDelete()
 * and update() pass the ClickHouse options to QueryBuilder, and whose optimize(), getStatement() and insertFiles()
 * return what QueryBuilder returns. The other methods of QueryBuilder, such as final(), preWhere(), settings() and
 * the ClickHouse joins, reach it through Eloquent's forwarding. has(), whereHas(), doesntHave() and their variants
 * check the relation with an IN sub-query (see addHasWhere()).
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
     * Start a merge of the parts of the model's table with OPTIMIZE TABLE (see QueryBuilder::optimize()).
     *
     * @param bool $final
     * @param int|string|ExpressionContract|null $partition
     * @return bool
     */
    public function optimize(bool $final = false, int|string|ExpressionContract|null $partition = null): bool
    {
        return $this->toBase()->optimize($final, $partition);
    }

    /**
     * Run the query, with the global scopes of the model, and return the smi2 statement of its result in the given
     * format (see QueryBuilder::getStatement()).
     *
     * @param Format|string|null $format
     * @return Statement
     */
    public function getStatement(Format|string|null $format = null): Statement
    {
        return $this->toBase()->getStatement($format);
    }

    /**
     * Insert the rows of files into the model's table (see QueryBuilder::insertFiles()).
     *
     * @param string|array<int, string> $paths
     * @param Format|string $format
     * @param array<int, string> $columns
     * @return array<string, Statement>
     */
    public function insertFiles(string|array $paths, Format|string $format = Format::Csv, array $columns = []): array
    {
        return $this->toBase()->insertFiles($paths, $format, $columns);
    }

    /**
     * Add the condition of has(), whereHas(), doesntHave() and their variants, such as whereRelation() and
     * whereHasMorph(): with an IN sub-query when the relation's query reads the outer query only in the condition
     * that joins the two tables, as Laravel's relations write it (see getRelationExistenceAsIn()), and otherwise as
     * Laravel does, with a correlated EXISTS or count sub-query.
     *
     * ClickHouse 24.8 has no correlated sub-queries: "users"."id" in (select "posts"."user_id" from "posts" ...)
     * runs there, where exists (select * from "posts" where "users"."id" = "posts"."user_id") fails with
     * UNSUPPORTED_METHOD. 26.3 and 26.8 run both, and the IN sub-query in a delete() or an update() too.
     *
     * @param EloquentBuilder<*> $hasQuery
     * @param Relation<*, *, *> $relation
     * @param string $operator
     * @param int|string $count
     * @param string $boolean
     * @return $this
     */
    protected function addHasWhere(EloquentBuilder $hasQuery, Relation $relation, $operator, $count, $boolean)
    {
        $hasQuery->mergeConstraintsFrom($relation->getQuery());
        $query = $hasQuery->toBase();

        $in = $this->getRelationExistenceAsIn($query, $operator, $count);
        if ($in === null) {
            return $this->canUseExistsForExistenceCheck($operator, $count)
                ? $this->addWhereExistsQuery($query, $boolean, $operator === '<' && $count === 1)
                : $this->addWhereCountQuery($query, $operator, $count, $boolean);
        }

        [$column, $subQuery, $not] = $in;

        if (! $not) {
            $this->query->whereIn($column, $subQuery, $boolean);

            return $this;
        }

        $this->query->whereNested(
            fn (BaseBuilder $nested) => $nested->whereNotIn($column, $subQuery)->orWhereNull($column),
            $boolean
        );

        return $this;
    }

    /**
     * Turn the existence query of a relation into the column of the outer query and the sub-query of an IN
     * condition, or null when it cannot be.
     *
     * The existence query joins the two tables with one whereColumn() of the outer table's column and the related
     * column, such as "users"."id" = "posts"."user_id". That condition is left out, and the sub-query selects the
     * related column. Without it the sub-query must not read the outer table, nor have a limit, an offset, a group,
     * a having or a union, which would select other rows than the correlated sub-query. Its order is left out.
     *
     * - Existence, as has() and whereHas(): <column> in (<sub-query>).
     * - Absence, as doesntHave(): (<column> not in (<sub-query>) or <column> is null), since a row whose column is
     *   null has no related row, and NOT IN is null for it.
     * - A count, as has('posts', '>=', 3): the sub-query groups by the related column, with having count(*) >= 3. When
     *   no related row at all satisfies the count, as with '<' 3 or '=' 0, the outer row must not be in the groups
     *   whose count fails it: not in (... having count(*) >= 3), or null.
     *
     * @param BaseBuilder $query The existence query, as the relation writes it
     * @param mixed $operator
     * @param mixed $count
     * @return array{0: string, 1: BaseBuilder, 2: bool}|null The outer column, the sub-query, and whether the
     *                                                         condition is NOT IN
     */
    protected function getRelationExistenceAsIn(BaseBuilder $query, mixed $operator, mixed $count): ?array
    {
        $inverse = ['=' => '!=', '!=' => '=', '<>' => '=', '<' => '>=', '<=' => '>', '>' => '<=', '>=' => '<'];

        if ($query->limit !== null || $query->offset !== null || $query->groups !== null || $query->havings !== null
            || $query->unions !== null || ! (is_int($count) || (is_string($count) && ctype_digit($count)))
            || ! is_string($operator) || ! isset($inverse[$operator])
        ) {
            return null;
        }

        $count = (int) $count;

        $outerQualifiers = $this->getOuterQualifiers();
        $isOuter = function (mixed $column) use ($outerQualifiers): bool {
            foreach ($outerQualifiers as $qualifier) {
                if (is_string($column) && str_starts_with($column, $qualifier . '.')) {
                    return true;
                }
            }

            return false;
        };

        foreach ((array) $query->wheres as $index => $where) {
            if ($where['type'] !== 'Column' || $where['operator'] !== '=' || $where['boolean'] !== 'and'
                || $isOuter($where['first']) === $isOuter($where['second'])
            ) {
                continue;
            }

            [$outer, $related] = $isOuter($where['first'])
                ? [$where['first'], $where['second']]
                : [$where['second'], $where['first']];

            $subQuery = clone $query;
            $wheres = $subQuery->wheres;
            unset($wheres[$index]);
            $subQuery->wheres = array_values($wheres);
            $subQuery->columns = [$related];
            $subQuery->orders = null;
            $subQuery->bindings['select'] = [];
            $subQuery->bindings['order'] = [];

            if ($this->readsTheOuterTable($subQuery, $outerQualifiers)) {
                return null;
            }

            $exists = $this->canUseExistsForExistenceCheck($operator, $count);
            $holdsForNoRow = match ($operator) {
                '=' => $count === 0,
                '!=', '<>' => $count !== 0,
                '<' => 0 < $count,
                '<=' => 0 <= $count,
                '>' => 0 > $count,
                '>=' => 0 >= $count,
            };

            if (! $exists) {
                $subQuery->groupBy($related)->havingRaw(
                    'count(*) ' . ($holdsForNoRow ? $inverse[$operator] : $operator) . ' ' . $count
                );
            }

            return [$outer, $subQuery, $holdsForNoRow];
        }

        return null;
    }

    /**
     * Get the names that qualify a column of the outer query: its model's table, and the table and alias of its
     * from clause.
     *
     * @return list<string>
     */
    protected function getOuterQualifiers(): array
    {
        $qualifiers = [$this->getModel()->getTable()];

        if (is_string($this->query->from)) {
            array_push($qualifiers, ...preg_split('/\s+as\s+/i', trim($this->query->from)));
        }

        return array_values(array_unique(array_filter($qualifiers, fn (string $qualifier): bool => $qualifier !== '')));
    }

    /**
     * Determine if a sub-query reads a column of the outer table: its SQL has the table, or its alias, as a
     * qualifier, quoted, as in "users"."id", or as a word, as in raw SQL such as users.id.
     *
     * @param BaseBuilder $subQuery
     * @param list<string> $outerQualifiers
     * @return bool
     */
    protected function readsTheOuterTable(BaseBuilder $subQuery, array $outerQualifiers): bool
    {
        $sql = $subQuery->toSql();

        foreach ($outerQualifiers as $qualifier) {
            if (str_contains($sql, $subQuery->getGrammar()->wrapTable($qualifier) . '.')
                || preg_match('/(?<![\w"`.])' . preg_quote($qualifier, '/') . '\./', $sql) === 1
            ) {
                return true;
            }
        }

        return false;
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
