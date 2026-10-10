<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Exception\QueryException as ClientQueryException;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Stringable;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
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
 * - insert() writes a collection value as an array, as update() does, and takes an insert format, Values or
 *   JSONEachRow, as the package builder's insert() does; without one, the connection's insert_format decides;
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
     * - an order that defines an alias, calls arrayJoin(), adds rows with WITH FILL, limits the rows with raw
     *   LIMIT, LIMIT ... BY, OFFSET or FETCH, or changes them with a raw UNION, INTERSECT or EXCEPT (see
     *   QueryGrammar::hasAnOrderThatDefinesAnAlias(), hasAnOrderThatCallsArrayJoin(), hasAnOrderThatAddsRows() and
     *   hasAnOrderThatLimitsRows()).
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
     * Insert one row, or a list of rows, and return true, as Laravel's insert() does.
     *
     * A collection value, such as a Collection or a LazyCollection, is inserted as the array of its toArray(), as
     * update() writes it (see QueryGrammar::compileUpdateColumns()); the connection refuses a collection binding. One
     * row is passed on as a list of one row, so that Laravel does not read it as a list of rows when its first value
     * was a collection. An empty array inserts nothing and returns true.
     *
     * The format is $format, or else the insert_format option of the connection (see Connection::getInsertFormat()),
     * Values when it is left out:
     * - Values: Laravel's insert, insert into "t" ("a", "b") values (?, ?), whose bindings the connection writes as
     *   ClickHouse literals;
     * - JSONEachRow: insert into "t" ("a", "b") format JSONEachRow, followed by one JSON object per row (see
     *   insertJsonEachRow()), as the package builder's insert() sends it. The values that both formats take are
     *   stored alike, such as floats with every digit, dates at the connection's datetime_precision into DateTime
     *   columns, null, arrays and collections. ClickHouse reads some values differently in JSONEachRow (24.8
     *   checked): a Date column refuses a date with a time, such as a Carbon; a Map column refuses [] and takes an
     *   array with keys, which Values refuses; a Tuple column takes a list, which Values refuses; an integer column
     *   refuses a float with a fraction, which Values cuts off. Raw SQL, such as DB::raw(), and a string that is not
     *   valid UTF-8 are refused before anything is sent.
     *
     * @param array<int|string, mixed> $values One row, or a list of rows
     * @param string|null $format 'Values' or 'JSONEachRow', in any letter case; null uses the connection's
     *                            insert_format
     * @return bool
     * @throws InvalidArgumentException When the format is neither Values nor JSONEachRow, or, in JSONEachRow, a
     *                                  value cannot be sent as JSON or the connection is not a ClickHouse connection
     * @throws QueryException When, in JSONEachRow, a row's keys differ from the first row's
     * @throws ClientQueryException When, in JSONEachRow, a row has no keys, or the insert fails
     */
    public function insert(array $values, ?string $format = null)
    {
        $format = $this->insertFormat($format);

        if ($values === []) {
            return parent::insert($values);
        }

        $rows = array_map(
            fn (mixed $row): mixed => is_array($row)
                ? array_map(fn (mixed $value): mixed => $value instanceof Enumerable ? $value->toArray() : $value, $row)
                : $row,
            is_array(array_first($values)) ? $values : [$values]
        );

        return $format === Format::JSON_EACH_ROW ? $this->insertJsonEachRow($rows) : parent::insert($rows);
    }

    /**
     * Get the input format of an insert: 'Values' or 'JSONEachRow', as Format spells it, from a name in any letter
     * case and with surrounding spaces, or, for null, the insert_format option of the connection. A connection other
     * than this package's inserts in Values.
     *
     * @param string|null $format
     * @return string Format::VALUES or Format::JSON_EACH_ROW
     * @throws InvalidArgumentException For any other format
     */
    protected function insertFormat(?string $format): string
    {
        if ($format === null) {
            return $this->connection instanceof Connection ? $this->connection->getInsertFormat() : Format::VALUES;
        }

        foreach ([Format::VALUES, Format::JSON_EACH_ROW] as $allowed) {
            if (strcasecmp(trim($format), $allowed) === 0) {
                return $allowed;
            }
        }

        throw new InvalidArgumentException(sprintf(
            "insert() takes the format '%s' or '%s', [%s] given.",
            Format::VALUES,
            Format::JSON_EACH_ROW,
            $format
        ));
    }

    /**
     * Insert rows as JSONEachRow: insert into <table> (<keys of the first row>) format JSONEachRow, written by this
     * builder's grammar, with the rows as one JSON object per line in the request body (see
     * ClientRequests::encodeJsonEachRow() and sendInsert()). Every row must have the first row's keys, in any order.
     *
     * The beforeQuery() callbacks of the builder run first, as in Laravel's insert(). Then the rows are checked and
     * encoded, before anything is sent or logged, and the INSERT runs as the connection runs a statement: the
     * beforeExecuting() callbacks of the connection get it, and it is logged once, with no bindings, when it
     * succeeds. While the connection pretends, the rows are still checked and encoded, so that a row the real insert
     * refuses is refused, and the INSERT is logged and not sent.
     *
     * @param array<int|string, mixed> $rows
     * @return bool
     * @throws InvalidArgumentException When the connection is not a ClickHouse connection, a row is not an array, or
     *                                  a value cannot be sent as JSON
     * @throws QueryException When a row's keys differ from the first row's
     * @throws ClientQueryException When a row has no keys, or the insert fails
     */
    protected function insertJsonEachRow(array $rows): bool
    {
        $connection = $this->connection;
        if (!$connection instanceof Connection) {
            throw new InvalidArgumentException(sprintf(
                'Cannot insert in %s on a %s connection: only a ClickHouse connection sends JSONEachRow rows.',
                Format::JSON_EACH_ROW,
                get_debug_type($connection)
            ));
        }

        if (in_array([], $rows, true)) {
            throw ClientQueryException::cannotInsertEmptyValues();
        }

        $this->applyBeforeQueryCallbacks();

        [$columns, $body] = ClientRequests::encodeJsonEachRow(
            $rows,
            new JsonEachRowEncoder($connection->newBuilderGrammar()->formatDateTime(...))
        );
        $head = sprintf(
            'insert into %s (%s) format %s',
            $this->grammar->wrapTable($this->from),
            $this->grammar->columnize(array_map(fn (int|string $column): string => (string) $column, $columns)),
            Format::JSON_EACH_ROW
        );

        $connection->runBeforeExecutingCallbacks($head, []);
        $start = microtime(true);

        if (!$connection->pretending()) {
            ClientRequests::sendInsert($connection->getClient(), $head, $body, Format::JSON_EACH_ROW);
            $connection->recordsHaveBeenModified();
        }

        $connection->logQuery($head, [], round((microtime(true) - $start) * 1000, 2));

        return true;
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
