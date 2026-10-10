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
 * It also writes the ClickHouse clauses that the package's own builder writes: FINAL (final()), SAMPLE (sample()),
 * ARRAY JOIN (arrayJoin(), leftArrayJoin()), the ClickHouse joins (anyLeftJoin(), semiLeftJoin(), asofJoin() and
 * the others, each with a *Sub form), PREWHERE (preWhere() and its forms), GLOBAL IN (whereGlobalIn()), empty() and
 * notEmpty() (whereEmpty(), havingEmpty()), LIMIT ... BY (limitBy()), SETTINGS (settings()), WITH
 * (withExpression(), withRecursiveExpression(), withAlias()), INTERSECT and EXCEPT (intersect(), except()), and, for
 * the mutations, ON CLUSTER (onCluster()), lightweight deletes and IN PARTITION (delete(), update()).
 *
 * @property QueryGrammar $grammar
 */
class QueryBuilder extends Builder
{
    /**
     * The bindings of the query by clause, in the order of the clauses in the SQL: WITH first, ARRAY JOIN before
     * JOIN, and PREWHERE before WHERE.
     *
     * @var array<string, array<int, mixed>>
     */
    public $bindings = [
        'with' => [],
        'select' => [],
        'from' => [],
        'arrayJoin' => [],
        'join' => [],
        'prewhere' => [],
        'where' => [],
        'groupBy' => [],
        'having' => [],
        'order' => [],
        'union' => [],
        'unionOrder' => [],
    ];

    /**
     * Whether the table of the FROM clause gets FINAL (see final()).
     *
     * @var bool
     */
    public bool $final = false;

    /**
     * The SAMPLE clause: the coefficient, and the offset or null (see sample()).
     *
     * @var array{coefficient: int|float, offset: int|float|null}|null
     */
    public ?array $sample = null;

    /**
     * The ARRAY JOIN clauses, in the order of the calls (see arrayJoin() and leftArrayJoin()).
     *
     * @var list<array{left: bool, arrays: list<array{array: ExpressionContract|string, as: string|null}>}>
     */
    public array $arrayJoins = [];

    /**
     * The PREWHERE conditions, as Laravel stores the where conditions (see preWhere()).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $prewheres = [];

    /**
     * The LIMIT ... BY clause (see limitBy()).
     *
     * @var array{count: int, offset: int|null, columns: list<ExpressionContract|string>}|null
     */
    public ?array $limitBy = null;

    /**
     * The settings of the SETTINGS clause, by name (see settings()).
     *
     * @var array<string, mixed>
     */
    public array $settings = [];

    /**
     * The entries of the WITH clause, in the order of the calls (see withExpression() and withAlias()).
     *
     * @var list<array{type: string, name: string, query: ExpressionContract|string}>
     */
    public array $withs = [];

    /**
     * Whether a WITH clause is RECURSIVE (see withRecursiveExpression()).
     *
     * @var bool
     */
    public bool $recursiveWith = false;

    /**
     * The cluster that delete(), update() and truncate() send ON CLUSTER to, set by onCluster().
     *
     * @var string|null
     */
    public ?string $onCluster = null;

    /**
     * Whether delete(), update() and truncate() fall back to the connection's default cluster (see
     * Connection::getDefaultCluster()) when onCluster() named none. withoutOnCluster() turns it off.
     *
     * @var bool
     */
    public bool $usesTheDefaultCluster = true;

    /**
     * Whether the delete being compiled is a lightweight DELETE FROM: true or false as delete() was told, or null for
     * the connection's use_lightweight_delete option.
     *
     * @var bool|null
     */
    public ?bool $lightweightDelete = null;

    /**
     * The partition of the delete or the update being compiled, for its IN PARTITION clause, or null.
     *
     * @var int|string|ExpressionContract|null
     */
    public int|string|ExpressionContract|null $partition = null;

    /**
     * Set the table that the query selects from. $final adds FINAL after the table (see final()).
     *
     * @param \Closure|Builder|ExpressionContract|string $table
     * @param string|null $as
     * @param bool|null $final True adds FINAL; null keeps what final() set
     * @return $this
     */
    public function from($table, $as = null, ?bool $final = null)
    {
        parent::from($table, $as);

        if ($final !== null) {
            $this->final($final);
        }

        return $this;
    }

    /**
     * Add FINAL after the table of the FROM clause, so that ClickHouse merges the rows of a ReplacingMergeTree,
     * CollapsingMergeTree or another engine of the MergeTree family before it returns them: from "t" final.
     * false leaves it out. A query that selects from a sub-query cannot have FINAL: compiling it throws.
     *
     * @param bool $final
     * @return $this
     */
    public function final(bool $final = true): static
    {
        $this->final = $final;

        return $this;
    }

    /**
     * Add a SAMPLE clause after the table: sample 0.1, or sample 0.1 offset 0.5. A coefficient above 1 is a number
     * of rows, such as sample(1000000). The table needs a SAMPLE BY key.
     *
     * @param int|float $coefficient
     * @param int|float|null $offset
     * @return $this
     * @throws InvalidArgumentException When the coefficient is not above 0, or the offset is not between 0 and 1
     */
    public function sample(int|float $coefficient, int|float|null $offset = null): static
    {
        if (!($coefficient > 0) || is_infinite((float) $coefficient)) {
            throw new InvalidArgumentException("The SAMPLE coefficient must be a number above 0, [{$coefficient}] given.");
        }

        if ($offset !== null && !($offset >= 0 && $offset <= 1)) {
            throw new InvalidArgumentException("The SAMPLE offset must be a number from 0 to 1, [{$offset}] given.");
        }

        $this->sample = ['coefficient' => $coefficient, 'offset' => $offset];

        return $this;
    }

    /**
     * Add an ARRAY JOIN clause, which repeats each row for each element of the arrays, and drops the rows whose
     * arrays are empty.
     *
     * $arrays is an array column, an expression, a sub-query that returns an array, or a list of these, where a
     * string key is the alias of its array: arrayJoin(['tag' => 'tags', 'scores']) gives array join "tags" as "tag",
     * "scores". $as is the alias of a single array. The aliases are not added to the select list.
     *
     * @param \Closure|Builder|ExpressionContract|string|array<int|string, \Closure|Builder|ExpressionContract|string> $arrays
     * @param string|null $as
     * @return $this
     * @throws InvalidArgumentException When the list is empty
     */
    public function arrayJoin($arrays, ?string $as = null): static
    {
        return $this->addArrayJoin($arrays, $as, false);
    }

    /**
     * Add a LEFT ARRAY JOIN clause: as arrayJoin(), but a row whose arrays are empty is kept once, with the default
     * value of the element type.
     *
     * @param \Closure|Builder|ExpressionContract|string|array<int|string, \Closure|Builder|ExpressionContract|string> $arrays
     * @param string|null $as
     * @return $this
     * @throws InvalidArgumentException When the list is empty
     */
    public function leftArrayJoin($arrays, ?string $as = null): static
    {
        return $this->addArrayJoin($arrays, $as, true);
    }

    /**
     * Add an ARRAY JOIN clause of a sub-query that returns an array: array join (<query>) as "alias".
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @return $this
     */
    public function arrayJoinSub($query, string $as): static
    {
        return $this->addArrayJoin([$as => $this->arrayJoinSubquery($query)], null, false);
    }

    /**
     * Add a LEFT ARRAY JOIN clause of a sub-query that returns an array (see arrayJoinSub()).
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @return $this
     */
    public function leftArrayJoinSub($query, string $as): static
    {
        return $this->addArrayJoin([$as => $this->arrayJoinSubquery($query)], null, true);
    }

    /**
     * Turn a sub-query of an ARRAY JOIN into an expression, with its bindings added to the arrayJoin bindings.
     *
     * @param \Closure|Builder|string $query
     * @return Expression
     */
    protected function arrayJoinSubquery($query): Expression
    {
        [$sql, $bindings] = $this->createSub($query);
        $this->addBinding($bindings, 'arrayJoin');

        return new Expression("({$sql})");
    }

    /**
     * Add an ARRAY JOIN or LEFT ARRAY JOIN clause (see arrayJoin()).
     *
     * @param \Closure|Builder|ExpressionContract|string|array<int|string, \Closure|Builder|ExpressionContract|string> $arrays
     * @param string|null $as
     * @param bool $left
     * @return $this
     * @throws InvalidArgumentException When the list is empty
     */
    protected function addArrayJoin($arrays, ?string $as, bool $left): static
    {
        if (!is_array($arrays)) {
            $arrays = $as === null ? [$arrays] : [$as => $arrays];
        }

        if ($arrays === []) {
            throw new InvalidArgumentException('ARRAY JOIN needs at least one array.');
        }

        $entries = [];
        foreach ($arrays as $alias => $array) {
            if ($this->isQueryable($array)) {
                $array = $this->arrayJoinSubquery($array);
            }

            $entries[] = ['array' => $array, 'as' => is_string($alias) ? $alias : null];
        }

        $this->arrayJoins[] = ['left' => $left, 'arrays' => $entries];

        return $this;
    }

    /**
     * Add an ANY LEFT JOIN: each left row with at most one matching right row.
     *
     * @param ExpressionContract|string $table
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function anyLeftJoin($table, $first, $operator = null, $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'any left');
    }

    /**
     * Add an ALL LEFT JOIN: Laravel's leftJoin(), with the strictness written.
     *
     * @param ExpressionContract|string $table
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function allLeftJoin($table, $first, $operator = null, $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'all left');
    }

    /**
     * Add an INNER JOIN: Laravel's join().
     *
     * @param ExpressionContract|string $table
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function innerJoin($table, $first, $operator = null, $second = null): static
    {
        return $this->join($table, $first, $operator, $second);
    }

    /**
     * Add an ANY INNER JOIN: the rows that match, each left row with at most one right row.
     *
     * @param ExpressionContract|string $table
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function anyInnerJoin($table, $first, $operator = null, $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'any inner');
    }

    /**
     * Add an ALL INNER JOIN: Laravel's join(), with the strictness written.
     *
     * @param ExpressionContract|string $table
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function allInnerJoin($table, $first, $operator = null, $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'all inner');
    }

    /**
     * Add an ANY RIGHT JOIN: each right row with at most one matching left row.
     *
     * @param ExpressionContract|string $table
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function anyRightJoin($table, $first, $operator = null, $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'any right');
    }

    /**
     * Add an ALL RIGHT JOIN: Laravel's rightJoin(), with the strictness written.
     *
     * @param ExpressionContract|string $table
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function allRightJoin($table, $first, $operator = null, $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'all right');
    }

    /**
     * Add a FULL JOIN: the rows of both tables, matched where they match.
     *
     * @param ExpressionContract|string $table
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function fullJoin($table, $first, $operator = null, $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'full');
    }

    /**
     * Add a SEMI LEFT JOIN: the left rows that have a match, each one time, without the right columns' values.
     *
     * @param ExpressionContract|string $table
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function semiLeftJoin($table, $first, $operator = null, $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'semi left');
    }

    /**
     * Add a SEMI RIGHT JOIN: the right rows that have a match.
     *
     * @param ExpressionContract|string $table
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function semiRightJoin($table, $first, $operator = null, $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'semi right');
    }

    /**
     * Add an ANTI LEFT JOIN: the left rows without a match.
     *
     * @param ExpressionContract|string $table
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function antiLeftJoin($table, $first, $operator = null, $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'anti left');
    }

    /**
     * Add an ANTI RIGHT JOIN: the right rows without a match.
     *
     * @param ExpressionContract|string $table
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function antiRightJoin($table, $first, $operator = null, $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'anti right');
    }

    /**
     * Add an ASOF JOIN: each left row with the closest right row. The conditions need one inequality, such as
     * on('trades.ts', '>=', 'quotes.ts'), which selects the closest match; give them in a closure.
     *
     * @param ExpressionContract|string $table
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function asofJoin($table, $first, $operator = null, $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'asof');
    }

    /**
     * Add an ASOF LEFT JOIN: as asofJoin(), and the left rows without a match.
     *
     * @param ExpressionContract|string $table
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function asofLeftJoin($table, $first, $operator = null, $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'asof left');
    }

    /**
     * Add an ANY LEFT JOIN of a sub-query (see anyLeftJoin()).
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function anyLeftJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'any left');
    }

    /**
     * Add an ALL LEFT JOIN of a sub-query (see allLeftJoin()).
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function allLeftJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'all left');
    }

    /**
     * Add an INNER JOIN of a sub-query: Laravel's joinSub().
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function innerJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second);
    }

    /**
     * Add an ANY INNER JOIN of a sub-query (see anyInnerJoin()).
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function anyInnerJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'any inner');
    }

    /**
     * Add an ALL INNER JOIN of a sub-query (see allInnerJoin()).
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function allInnerJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'all inner');
    }

    /**
     * Add an ANY RIGHT JOIN of a sub-query (see anyRightJoin()).
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function anyRightJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'any right');
    }

    /**
     * Add an ALL RIGHT JOIN of a sub-query (see allRightJoin()).
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function allRightJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'all right');
    }

    /**
     * Add a FULL JOIN of a sub-query (see fullJoin()).
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function fullJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'full');
    }

    /**
     * Add a SEMI LEFT JOIN of a sub-query (see semiLeftJoin()).
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function semiLeftJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'semi left');
    }

    /**
     * Add a SEMI RIGHT JOIN of a sub-query (see semiRightJoin()).
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function semiRightJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'semi right');
    }

    /**
     * Add an ANTI LEFT JOIN of a sub-query (see antiLeftJoin()).
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function antiLeftJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'anti left');
    }

    /**
     * Add an ANTI RIGHT JOIN of a sub-query (see antiRightJoin()).
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function antiRightJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'anti right');
    }

    /**
     * Add an ASOF JOIN of a sub-query (see asofJoin()).
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function asofJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'asof');
    }

    /**
     * Add an ASOF LEFT JOIN of a sub-query (see asofLeftJoin()).
     *
     * @param \Closure|Builder|string $query
     * @param string $as
     * @param \Closure|ExpressionContract|string $first
     * @param string|null $operator
     * @param ExpressionContract|string|null $second
     * @return $this
     */
    public function asofLeftJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'asof left');
    }

    /**
     * Add a PREWHERE condition, which ClickHouse runs before it reads the other columns. It takes the arguments of
     * where(), closures and sub-queries included.
     *
     * @param \Closure|ExpressionContract|string|array<int|string, mixed> $column
     * @param mixed $operator
     * @param mixed $value
     * @param string $boolean
     * @return $this
     */
    public function preWhere($column, $operator = null, $value = null, $boolean = 'and'): static
    {
        $arguments = func_get_args();

        return $this->addToPreWheres(fn () => $this->where(...$arguments));
    }

    /**
     * Add a PREWHERE condition with OR (see preWhere()).
     *
     * @param \Closure|ExpressionContract|string|array<int|string, mixed> $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    public function orPreWhere($column, $operator = null, $value = null): static
    {
        $arguments = func_get_args();

        return $this->addToPreWheres(fn () => $this->orWhere(...$arguments));
    }

    /**
     * Add a raw PREWHERE condition, with "?" bindings.
     *
     * @param ExpressionContract|string $sql
     * @param array<int, mixed> $bindings
     * @param string $boolean
     * @return $this
     */
    public function preWhereRaw($sql, $bindings = [], $boolean = 'and'): static
    {
        return $this->addToPreWheres(fn () => $this->whereRaw($sql, $bindings, $boolean));
    }

    /**
     * Add a raw PREWHERE condition with OR (see preWhereRaw()).
     *
     * @param ExpressionContract|string $sql
     * @param array<int, mixed> $bindings
     * @return $this
     */
    public function orPreWhereRaw($sql, $bindings = []): static
    {
        return $this->preWhereRaw($sql, $bindings, 'or');
    }

    /**
     * Add a PREWHERE ... IN condition. It takes the values of whereIn(), sub-queries included.
     *
     * @param ExpressionContract|string $column
     * @param mixed $values
     * @param string $boolean
     * @param bool $not
     * @return $this
     */
    public function preWhereIn($column, $values, $boolean = 'and', $not = false): static
    {
        return $this->addToPreWheres(fn () => $this->whereIn($column, $values, $boolean, $not));
    }

    /**
     * Add a PREWHERE ... IN condition with OR (see preWhereIn()).
     *
     * @param ExpressionContract|string $column
     * @param mixed $values
     * @return $this
     */
    public function orPreWhereIn($column, $values): static
    {
        return $this->preWhereIn($column, $values, 'or');
    }

    /**
     * Add a PREWHERE ... NOT IN condition (see preWhereIn()).
     *
     * @param ExpressionContract|string $column
     * @param mixed $values
     * @param string $boolean
     * @return $this
     */
    public function preWhereNotIn($column, $values, $boolean = 'and'): static
    {
        return $this->preWhereIn($column, $values, $boolean, true);
    }

    /**
     * Add a PREWHERE ... NOT IN condition with OR (see preWhereIn()).
     *
     * @param ExpressionContract|string $column
     * @param mixed $values
     * @return $this
     */
    public function orPreWhereNotIn($column, $values): static
    {
        return $this->preWhereIn($column, $values, 'or', true);
    }

    /**
     * Add a PREWHERE ... IS NULL condition for one column or each column of a list.
     *
     * @param ExpressionContract|string|array<int, ExpressionContract|string> $columns
     * @param string $boolean
     * @param bool $not
     * @return $this
     */
    public function preWhereNull($columns, $boolean = 'and', $not = false): static
    {
        return $this->addToPreWheres(fn () => $this->whereNull($columns, $boolean, $not));
    }

    /**
     * Add a PREWHERE ... IS NULL condition with OR (see preWhereNull()).
     *
     * @param ExpressionContract|string|array<int, ExpressionContract|string> $columns
     * @return $this
     */
    public function orPreWhereNull($columns): static
    {
        return $this->preWhereNull($columns, 'or');
    }

    /**
     * Add a PREWHERE ... IS NOT NULL condition (see preWhereNull()).
     *
     * @param ExpressionContract|string|array<int, ExpressionContract|string> $columns
     * @param string $boolean
     * @return $this
     */
    public function preWhereNotNull($columns, $boolean = 'and'): static
    {
        return $this->preWhereNull($columns, $boolean, true);
    }

    /**
     * Add a PREWHERE ... IS NOT NULL condition with OR (see preWhereNull()).
     *
     * @param ExpressionContract|string|array<int, ExpressionContract|string> $columns
     * @return $this
     */
    public function orPreWhereNotNull($columns): static
    {
        return $this->preWhereNull($columns, 'or', true);
    }

    /**
     * Add a PREWHERE ... BETWEEN condition. It reads the list as whereBetween() does.
     *
     * @param ExpressionContract|string $column
     * @param iterable<int|string, mixed> $values
     * @param string $boolean
     * @param bool $not
     * @return $this
     */
    public function preWhereBetween($column, iterable $values, $boolean = 'and', $not = false): static
    {
        return $this->addToPreWheres(fn () => $this->whereBetween($column, $values, $boolean, $not));
    }

    /**
     * Add a PREWHERE ... BETWEEN condition with OR (see preWhereBetween()).
     *
     * @param ExpressionContract|string $column
     * @param iterable<int|string, mixed> $values
     * @return $this
     */
    public function orPreWhereBetween($column, iterable $values): static
    {
        return $this->preWhereBetween($column, $values, 'or');
    }

    /**
     * Add a PREWHERE ... NOT BETWEEN condition (see preWhereBetween()).
     *
     * @param ExpressionContract|string $column
     * @param iterable<int|string, mixed> $values
     * @param string $boolean
     * @return $this
     */
    public function preWhereNotBetween($column, iterable $values, $boolean = 'and'): static
    {
        return $this->preWhereBetween($column, $values, $boolean, true);
    }

    /**
     * Add a PREWHERE ... NOT BETWEEN condition with OR (see preWhereBetween()).
     *
     * @param ExpressionContract|string $column
     * @param iterable<int|string, mixed> $values
     * @return $this
     */
    public function orPreWhereNotBetween($column, iterable $values): static
    {
        return $this->preWhereBetween($column, $values, 'or', true);
    }

    /**
     * Run a where method so that the conditions it adds go to the PREWHERE clause: the where conditions and their
     * bindings change places with the prewhere conditions while the callback runs.
     *
     * The arguments are passed on as they were given, so that preWhere('a', 1) is where('a', 1), whose second
     * argument is the value.
     *
     * @param \Closure(): mixed $callback
     * @return $this
     */
    protected function addToPreWheres(\Closure $callback): static
    {
        [$this->wheres, $this->prewheres] = [$this->prewheres, $this->wheres];
        [$this->bindings['where'], $this->bindings['prewhere']] = [$this->bindings['prewhere'], $this->bindings['where']];

        try {
            $callback();
        } finally {
            [$this->wheres, $this->prewheres] = [$this->prewheres, $this->wheres];
            [$this->bindings['where'], $this->bindings['prewhere']] = [$this->bindings['prewhere'], $this->bindings['where']];
        }

        return $this;
    }

    /**
     * Add a GLOBAL IN condition: on a Distributed table, ClickHouse runs the sub-query one time and sends its result
     * to all shards. It takes the values of whereIn(), sub-queries included.
     *
     * @param ExpressionContract|string $column
     * @param mixed $values
     * @param string $boolean
     * @param bool $not
     * @return $this
     */
    public function whereGlobalIn($column, $values, $boolean = 'and', $not = false): static
    {
        $this->whereIn($column, $values, $boolean, $not);
        $this->wheres[array_key_last($this->wheres)]['type'] = $not ? 'GlobalNotIn' : 'GlobalIn';

        return $this;
    }

    /**
     * Add a GLOBAL IN condition with OR (see whereGlobalIn()).
     *
     * @param ExpressionContract|string $column
     * @param mixed $values
     * @return $this
     */
    public function orWhereGlobalIn($column, $values): static
    {
        return $this->whereGlobalIn($column, $values, 'or');
    }

    /**
     * Add a GLOBAL NOT IN condition (see whereGlobalIn()).
     *
     * @param ExpressionContract|string $column
     * @param mixed $values
     * @param string $boolean
     * @return $this
     */
    public function whereGlobalNotIn($column, $values, $boolean = 'and'): static
    {
        return $this->whereGlobalIn($column, $values, $boolean, true);
    }

    /**
     * Add a GLOBAL NOT IN condition with OR (see whereGlobalIn()).
     *
     * @param ExpressionContract|string $column
     * @param mixed $values
     * @return $this
     */
    public function orWhereGlobalNotIn($column, $values): static
    {
        return $this->whereGlobalIn($column, $values, 'or', true);
    }

    /**
     * Add an empty(<column>) condition, true for an empty string, array or map, for one column or each column of a
     * list. $not writes notEmpty(<column>).
     *
     * @param ExpressionContract|string|array<int, ExpressionContract|string> $columns
     * @param string $boolean
     * @param bool $not
     * @return $this
     */
    public function whereEmpty($columns, $boolean = 'and', $not = false): static
    {
        foreach (is_array($columns) ? $columns : [$columns] as $column) {
            $this->wheres[] = ['type' => 'Empty', 'column' => $column, 'not' => $not, 'boolean' => $boolean];
        }

        return $this;
    }

    /**
     * Add an empty(<column>) condition with OR (see whereEmpty()).
     *
     * @param ExpressionContract|string|array<int, ExpressionContract|string> $columns
     * @return $this
     */
    public function orWhereEmpty($columns): static
    {
        return $this->whereEmpty($columns, 'or');
    }

    /**
     * Add a notEmpty(<column>) condition (see whereEmpty()).
     *
     * @param ExpressionContract|string|array<int, ExpressionContract|string> $columns
     * @param string $boolean
     * @return $this
     */
    public function whereNotEmpty($columns, $boolean = 'and'): static
    {
        return $this->whereEmpty($columns, $boolean, true);
    }

    /**
     * Add a notEmpty(<column>) condition with OR (see whereEmpty()).
     *
     * @param ExpressionContract|string|array<int, ExpressionContract|string> $columns
     * @return $this
     */
    public function orWhereNotEmpty($columns): static
    {
        return $this->whereEmpty($columns, 'or', true);
    }

    /**
     * Add an empty(<column>) condition to the HAVING clause (see whereEmpty()).
     *
     * @param ExpressionContract|string|array<int, ExpressionContract|string> $columns
     * @param string $boolean
     * @param bool $not
     * @return $this
     */
    public function havingEmpty($columns, $boolean = 'and', $not = false): static
    {
        foreach (is_array($columns) ? $columns : [$columns] as $column) {
            $this->havings[] = ['type' => 'Empty', 'column' => $column, 'not' => $not, 'boolean' => $boolean];
        }

        return $this;
    }

    /**
     * Add an empty(<column>) condition to the HAVING clause with OR (see whereEmpty()).
     *
     * @param ExpressionContract|string|array<int, ExpressionContract|string> $columns
     * @return $this
     */
    public function orHavingEmpty($columns): static
    {
        return $this->havingEmpty($columns, 'or');
    }

    /**
     * Add a notEmpty(<column>) condition to the HAVING clause (see whereEmpty()).
     *
     * @param ExpressionContract|string|array<int, ExpressionContract|string> $columns
     * @param string $boolean
     * @return $this
     */
    public function havingNotEmpty($columns, $boolean = 'and'): static
    {
        return $this->havingEmpty($columns, $boolean, true);
    }

    /**
     * Add a notEmpty(<column>) condition to the HAVING clause with OR (see whereEmpty()).
     *
     * @param ExpressionContract|string|array<int, ExpressionContract|string> $columns
     * @return $this
     */
    public function orHavingNotEmpty($columns): static
    {
        return $this->havingEmpty($columns, 'or', true);
    }

    /**
     * Add a LIMIT ... BY clause: at most $count rows for each value of the columns, after $offset rows of each when
     * given: limit 3 by "user_id", or limit 3 offset 1 by "user_id". It comes before LIMIT.
     *
     * @param int $count
     * @param ExpressionContract|string|array<int, ExpressionContract|string> ...$columns
     * @return $this
     * @throws InvalidArgumentException When the count is below 0 or no column is given
     */
    public function limitBy(int $count, ExpressionContract|string|array ...$columns): static
    {
        return $this->limitByWithOffset($count, null, ...$columns);
    }

    /**
     * Add a LIMIT ... OFFSET ... BY clause (see limitBy()).
     *
     * @param int $count
     * @param int|null $offset
     * @param ExpressionContract|string|array<int, ExpressionContract|string> ...$columns
     * @return $this
     * @throws InvalidArgumentException When the count or the offset is below 0, or no column is given
     */
    public function limitByWithOffset(int $count, ?int $offset, ExpressionContract|string|array ...$columns): static
    {
        $columns = array_values(array_merge(...array_map(
            fn (ExpressionContract|string|array $column): array => is_array($column) ? array_values($column) : [$column],
            $columns
        )));

        if ($count < 0 || ($offset !== null && $offset < 0)) {
            throw new InvalidArgumentException('LIMIT ... BY needs a count and an offset of 0 or more.');
        }

        if ($columns === []) {
            throw new InvalidArgumentException('LIMIT ... BY needs at least one column.');
        }

        $this->limitBy = ['count' => $count, 'offset' => $offset, 'columns' => $columns];

        return $this;
    }

    /**
     * Add settings to the SETTINGS clause: settings(['max_threads' => 4]) or settings('max_threads', 4). A later
     * value of a name replaces the earlier one, and null removes it. Booleans are written as 1 and 0, strings as
     * string literals, backed enums as their values, and expressions, such as DB::raw(), as their SQL.
     *
     * The settings go at the end of this query, also when it is a sub-query. With a set operation, the query is
     * selected from as a sub-query first: select * from ((...) union all (...)) settings ....
     *
     * @param array<string, mixed>|string $settings
     * @param mixed $value The value, when $settings is a name
     * @return $this
     */
    public function settings(array|string $settings, mixed $value = null): static
    {
        foreach (is_string($settings) ? [$settings => $value] : $settings as $name => $settingValue) {
            if ($settingValue === null) {
                unset($this->settings[$name]);
            } else {
                $this->settings[$name] = $settingValue;
            }
        }

        return $this;
    }

    /**
     * Add a named sub-query to the WITH clause: with "name" as (<query>). $query is a builder, a closure that gets a
     * new builder, an expression or SQL. The main query can select from the name.
     *
     * @param string $name
     * @param \Closure|Builder|ExpressionContract|string $query
     * @return $this
     */
    public function withExpression(string $name, $query): static
    {
        return $this->addWith('expression', $name, $query);
    }

    /**
     * Add a named sub-query to a WITH RECURSIVE clause (see withExpression()). The sub-query can select from its own
     * name, usually in a UNION ALL. ClickHouse needs the analyzer, which is on by default from 24.8.
     *
     * @param string $name
     * @param \Closure|Builder|ExpressionContract|string $query
     * @return $this
     */
    public function withRecursiveExpression(string $name, $query): static
    {
        $this->recursiveWith = true;

        return $this->addWith('expression', $name, $query);
    }

    /**
     * Add an alias to the WITH clause: with <value> as "alias". A builder or a closure is a scalar sub-query, an
     * expression is its SQL, and any other value, an array included, is a literal.
     *
     * @param string $alias
     * @param mixed $value
     * @return $this
     */
    public function withAlias(string $alias, mixed $value): static
    {
        if ($this->isQueryable($value) || $value instanceof ExpressionContract) {
            return $this->addWith('alias', $alias, $value);
        }

        $this->withs[] = ['type' => 'alias', 'name' => $alias, 'query' => new Expression($this->grammar->compileLiteral($value))];

        return $this;
    }

    /**
     * Add an entry to the WITH clause, with the bindings of a sub-query.
     *
     * @param string $type 'expression' or 'alias'
     * @param string $name
     * @param \Closure|Builder|ExpressionContract|string $query
     * @return $this
     */
    protected function addWith(string $type, string $name, $query): static
    {
        if ($this->isQueryable($query)) {
            [$query, $bindings] = $this->createSub($query);
            $this->addBinding($bindings, 'with');
            $query = new Expression("({$query})");
        } elseif ($type === 'expression') {
            $query = new Expression('(' . $this->grammar->getValue($query) . ')');
        }

        $this->withs[] = ['type' => $type, 'name' => $name, 'query' => $query];

        return $this;
    }

    /**
     * Add an INTERSECT: the rows of this query that the other query also returns, duplicates included.
     *
     * @param \Closure|Builder $query
     * @return $this
     */
    public function intersect($query): static
    {
        return $this->addSetOperation($query, 'intersect', false);
    }

    /**
     * Add an INTERSECT DISTINCT: as intersect(), each row one time.
     *
     * @param \Closure|Builder $query
     * @return $this
     */
    public function intersectDistinct($query): static
    {
        return $this->addSetOperation($query, 'intersect', true);
    }

    /**
     * Add an EXCEPT: the rows of this query that the other query does not return, duplicates included.
     *
     * @param \Closure|Builder $query
     * @return $this
     */
    public function except($query): static
    {
        return $this->addSetOperation($query, 'except', false);
    }

    /**
     * Add an EXCEPT DISTINCT: as except(), each row one time.
     *
     * @param \Closure|Builder $query
     * @return $this
     */
    public function exceptDistinct($query): static
    {
        return $this->addSetOperation($query, 'except', true);
    }

    /**
     * Add a UNION DISTINCT: Laravel's union(), which removes duplicate rows.
     *
     * @param \Closure|Builder $query
     * @return $this
     */
    public function unionDistinct($query): static
    {
        return $this->union($query);
    }

    /**
     * Add an INTERSECT or an EXCEPT to the unions of the query, which Laravel compiles in the order of the calls.
     *
     * @param \Closure|Builder $query
     * @param string $type 'intersect' or 'except'
     * @param bool $distinct
     * @return $this
     */
    protected function addSetOperation($query, string $type, bool $distinct): static
    {
        if ($query instanceof \Closure) {
            $query($query = $this->newQuery());
        }

        $this->unions[] = ['query' => $query, 'all' => false, 'type' => $type, 'distinct' => $distinct];
        $this->addBinding($query->getBindings(), 'union');

        return $this;
    }

    /**
     * Send delete(), update() and truncate() of this query ON CLUSTER of a cluster: alter table "t" on cluster
     * 'name' delete where ....
     *
     * @param string $cluster
     * @return $this
     */
    public function onCluster(string $cluster): static
    {
        $this->onCluster = $cluster;

        return $this;
    }

    /**
     * Send delete(), update() and truncate() of this query without ON CLUSTER, also when the connection's
     * use_on_cluster option is on.
     *
     * @return $this
     */
    public function withoutOnCluster(): static
    {
        $this->onCluster = null;
        $this->usesTheDefaultCluster = false;

        return $this;
    }

    /**
     * Delete the rows that the where and prewhere conditions match.
     *
     * $lightweight true sends a lightweight delete from "t" where ..., false an alter table "t" delete where ...
     * mutation, and null follows the connection's use_lightweight_delete option. $partition limits the delete to one
     * partition: in partition <partition>, where an int is a number, a string a string literal and an expression
     * its SQL.
     *
     * @param mixed $id
     * @param bool|null $lightweight
     * @param int|string|ExpressionContract|null $partition
     * @return int
     */
    public function delete($id = null, ?bool $lightweight = null, int|string|ExpressionContract|null $partition = null)
    {
        $this->lightweightDelete = $lightweight;
        $this->partition = $partition;

        try {
            return parent::delete($id);
        } finally {
            $this->lightweightDelete = null;
            $this->partition = null;
        }
    }

    /**
     * Update the rows that the where and prewhere conditions match, with an alter table "t" update ... mutation.
     * $partition limits the update to one partition (see delete()).
     *
     * @param array<string, mixed> $values
     * @param int|string|ExpressionContract|null $partition
     * @return int
     */
    public function update(array $values, int|string|ExpressionContract|null $partition = null)
    {
        $this->partition = $partition;

        try {
            return parent::update($values);
        } finally {
            $this->partition = null;
        }
    }

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
     * - limitBy(), which keeps some rows of each group, and arrayJoin(), which repeats the rows and reads the aliases
     *   of its arrays;
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
        if (!empty($this->groups) || $this->distinct === true || $this->groupLimit !== null
            || $this->limitBy !== null || $this->arrayJoins !== []
        ) {
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
