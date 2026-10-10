<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Stringable;
use RuntimeException;

/**
 * Laravel's query builder on a ClickHouse connection, as Connection::query(), DB::table() and Eloquent models
 * return it when the connection sets fix_default_query_builder to false.
 *
 * It is Laravel's builder, so its methods and macros apply, with these differences:
 * - count() without a column and the paginate() total count the rows that get() returns, in a sub-query, when the
 *   select list, the order, distinct(), GROUP BY or a group limit changes them (see countsInASubquery()); a grouped
 *   query without a select list counts its groups. sum(), avg(), min(), max() and count($column) are Laravel's;
 * - whereDay(), whereMonth() and whereYear() compare with numbers, integers for whole numbers, and whereTime() with
 *   a time padded to 'HH:MM:SS' (see addDateBasedWhere());
 * - insert() writes a collection value as an array, as update() does;
 * - insertGetId() returns the key that the row carries, and refuses a row without one before anything is sent.
 *
 * @property QueryGrammar $grammar
 */
class QueryBuilder extends Builder
{
    /**
     * Run an aggregate function. count() with no column of a query whose rows the select list, the order, distinct(),
     * GROUP BY or a group limit changes (see countsInASubquery()) counts the rows that get() returns, in a sub-query
     * (see runCountInASubquery()), where Laravel would drop the select list, and so the aliases its conditions read,
     * and the order. So does count() of a query with a having clause that groups its rows without a select list (see
     * groupsWithoutASelectList()): Laravel would count select * of its groups, which ClickHouse rejects. Every other
     * aggregate, count($column), and the aggregate of a union or of another query with a having clause are Laravel's.
     *
     * @param string $function
     * @param array<int, ExpressionContract|string> $columns
     * @return mixed
     */
    public function aggregate($function, $columns = ['*'])
    {
        if ($function === 'count' && $columns === ['*'] && empty($this->unions)
            && (empty($this->havings) ? $this->countsInASubquery() : $this->groupsWithoutASelectList())
        ) {
            $results = $this->runCountInASubquery($columns);

            return isset($results[0]) ? array_change_key_case((array) $results[0])['aggregate'] : null;
        }

        return parent::aggregate($function, $columns);
    }

    /**
     * Run the count query of the paginate() total. A query with a having clause, or whose rows the select list, the
     * order, distinct(), GROUP BY or a group limit changes (see countsInASubquery()), is counted in a sub-query (see
     * runCountInASubquery()); a union and any other query as Laravel counts them.
     *
     * @param array<int, ExpressionContract|string> $columns
     * @return array<int, mixed>
     */
    protected function runPaginationCountQuery($columns = ['*'])
    {
        if (empty($this->unions) && (!empty($this->havings) || $this->countsInASubquery())) {
            return $this->runCountInASubquery($columns);
        }

        return parent::runPaginationCountQuery($columns);
    }

    /**
     * Count the rows that the query returns without its LIMIT and OFFSET: select count(*) as "aggregate" from
     * (<query>) as "aggregate_table". The settings of timeout(), forceIndex() and ignoreIndex() go on the outer
     * query, since ClickHouse does not enforce max_execution_time from the SETTINGS of a sub-query.
     *
     * A query that groups its rows without a select list (see groupsWithoutASelectList()) selects 1 from each group,
     * without DISTINCT, so that its groups are counted: select count(*) as "aggregate" from (select 1 from "t" group
     * by "user") as "aggregate_table".
     *
     * @param array<int, ExpressionContract|string> $columns
     * @return array<int, mixed>
     */
    protected function runCountInASubquery(array $columns): array
    {
        $query = $this->cloneWithout(['limit', 'offset', 'timeout', 'indexHint']);

        if ($query->groupsWithoutASelectList()) {
            $query->columns = [new Expression('1')];
            $query->distinct = false;
        }

        $count = $this->newQuery()
            ->fromSub($query, 'aggregate_table')
            ->setAggregate('count', $this->withoutSelectAliases($columns));
        $count->timeout = $this->timeout;
        $count->indexHint = $this->indexHint;

        return $count->get()->all();
    }

    /**
     * Determine if count() must count the query in a sub-query, because the rows that get() returns differ from the
     * table's rows that match the conditions, or the conditions read a name that only the select list or the order
     * defines:
     * - GROUP BY, distinct() without columns, or a group limit. distinct('column') alone keeps Laravel's
     *   count(distinct "column");
     * - a select alias or expression, such as selectRaw('arrayJoin(tags) as tag') (see
     *   QueryGrammar::selectsAnAliasOrAnExpression());
     * - an order that defines an alias, calls arrayJoin(), adds rows with WITH FILL, or limits the rows with raw
     *   LIMIT, LIMIT ... BY, OFFSET or FETCH (see QueryGrammar::hasAnOrderThatDefinesAnAlias(),
     *   hasAnOrderThatCallsArrayJoin(), hasAnOrderThatAddsRows() and hasAnOrderThatLimitsRows()).
     *
     * @return bool
     */
    protected function countsInASubquery(): bool
    {
        if (!empty($this->groups) || $this->distinct === true || $this->groupLimit !== null) {
            return true;
        }

        return $this->grammar instanceof QueryGrammar
            && ($this->grammar->selectsAnAliasOrAnExpression($this)
                || $this->grammar->hasAnOrderThatDefinesAnAlias($this)
                || $this->grammar->hasAnOrderThatCallsArrayJoin($this)
                || $this->grammar->hasAnOrderThatAddsRows($this)
                || $this->grammar->hasAnOrderThatLimitsRows($this));
    }

    /**
     * Determine if the query groups its rows without a select list, or with select('*') alone, and without a group
     * limit. Its SQL would select * from the groups, which ClickHouse (24.8 checked) rejects with NOT_AN_AGGREGATE
     * unless every column is grouped, so runCountInASubquery() selects 1 from each group instead.
     *
     * @return bool
     */
    protected function groupsWithoutASelectList(): bool
    {
        return !empty($this->groups) && $this->groupLimit === null
            && ($this->columns === null || $this->columns === ['*']);
    }

    /**
     * Add a whereDate(), whereTime(), whereDay(), whereMonth() or whereYear() condition.
     *
     * A numeric day, month or year becomes a number (see castDatePartToNumber()), since ClickHouse (24.8 checked)
     * does not compare the result of toDayOfMonth() or toMonth() with the zero-padded text that Laravel binds, such
     * as '05'. A time of hours and minutes, with or without seconds, each part written with one or two digits, as a
     * string or a Stringable, such as '9:05', '7:8' or '09:05:3.5', is padded to 'HH:MM:SS', without a fraction of a
     * second, to compare with formatDateTime(column, '%H:%i:%S') (see QueryGrammar::dateBasedWhere()). Expressions
     * and other values, null included, are left as they are, as Laravel's other grammars bind them.
     *
     * @param string $type
     * @param ExpressionContract|string $column
     * @param string $operator
     * @param mixed $value
     * @param string $boolean
     * @return $this
     */
    protected function addDateBasedWhere($type, $column, $operator, $value, $boolean = 'and')
    {
        if (in_array($type, ['Day', 'Month', 'Year'], true) && (is_string($value) || is_float($value))
            && is_numeric($value)
        ) {
            $value = $this->castDatePartToNumber($value);
        } elseif ($type === 'Time' && (is_string($value) || $value instanceof Stringable)
            && preg_match('/\A(\d{1,2}):(\d{1,2})(?::(\d{1,2})(?:\.\d+)?)?\z/', (string) $value, $matches) === 1
        ) {
            $value = sprintf('%02d:%02d:%02d', $matches[1], $matches[2], $matches[3] ?? 0);
        }

        return parent::addDateBasedWhere($type, $column, $operator, $value, $boolean);
    }

    /**
     * Cast a numeric string or a float of whereDay(), whereMonth() or whereYear() to a number: an integer when it is
     * a whole number within the integer range, such as '05', '2024', '2024.0' or 2024.0, and a float otherwise, such
     * as 2024.5 or '2024.5', which no day, month or year equals, as in Laravel's other grammars. So whereYear(2024.5)
     * matches no row instead of the rows of 2024, also in a delete or an update.
     *
     * whereDay() and whereMonth() write their value with sprintf('%02d') before this builder gets it, so a day or a
     * month always comes as a whole number: Laravel turns 5.5 into '05' on every connection.
     *
     * @param string|float $value A numeric string or a float
     * @return int|float
     */
    protected function castDatePartToNumber(string|float $value): int|float
    {
        $number = is_string($value) ? $value + 0 : $value;

        if (is_float($number) && floor($number) === $number && $number >= PHP_INT_MIN && $number < PHP_INT_MAX) {
            return (int) $number;
        }

        return $number;
    }

    /**
     * Insert one row, or a list of rows. A collection value, such as a Collection or a LazyCollection, is inserted as
     * the array of its toArray(), which the connection writes as a ClickHouse array literal, as update() writes it
     * (see QueryGrammar::compileUpdateColumns()); the connection refuses a collection binding. One row is passed on
     * as a list of one row, so that Laravel does not read it as a list of rows when its first value was a collection.
     *
     * @param array<int|string, mixed> $values
     * @return bool
     */
    public function insert(array $values)
    {
        if ($values === []) {
            return parent::insert($values);
        }

        $rows = is_array(array_first($values)) ? $values : [$values];

        return parent::insert(array_map(
            fn (mixed $row): mixed => is_array($row)
                ? array_map(fn (mixed $value): mixed => $value instanceof Enumerable ? $value->toArray() : $value, $row)
                : $row,
            $rows
        ));
    }

    /**
     * Insert a row and return the value of its key, which the row must carry: ClickHouse has no auto-increment
     * columns, so there is no generated ID to read back.
     *
     * The row is inserted with insert(), and the value of its $sequence key, 'id' when null, is returned as given,
     * such as an int or a UUID string. A row without that key, or with null in it, is refused before anything is
     * sent. Eloquent calls this method to create a model whose $incrementing is true, with the model's key name.
     *
     * @param array<string, mixed> $values
     * @param string|null $sequence The name of the key column
     * @return mixed
     * @throws RuntimeException When the row has no value for the key
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $key = $sequence ?? 'id';

        if (!isset($values[$key])) {
            throw new RuntimeException(
                "ClickHouse has no auto-increment columns, so insertGetId() cannot get the ID of a row without a [{$key}]"
                . ' value. Set the key before inserting, for example with Str::uuid(), and call insert(). On an'
                . ' Eloquent model, set $incrementing to false.'
            );
        }

        $this->insert($values);

        return $values[$key];
    }
}
