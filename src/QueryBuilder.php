<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use ClickHouseDB\Statement;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Stringable;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Enums\Format;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use RuntimeException;

/**
 * Laravel's query builder on a ClickHouse connection, as Connection::query(), DB::table() and Eloquent models
 * return it.
 *
 * It is Laravel's builder, so its methods and macros apply, with these differences:
 * - count() without a column and the paginate() total count the rows that get() returns, in a sub-query, when the
 *   select list, the order, distinct(), GROUP BY or a group limit changes them (see countsInASubquery()); a grouped
 *   query without a select list counts its groups. sum(), avg(), min(), max() and count($column) are Laravel's;
 * - whereDay(), whereMonth() and whereYear() compare with numbers, integers for whole numbers, and whereTime() with
 *   a time padded to 'HH:MM:SS' (see addDateBasedWhere());
 * - insert() writes a collection value as an array, as update() does, and takes an insert format, Values or
 *   JSONEachRow; without one, the connection's insert_format decides;
 * - insertGetId() returns the key that the row carries, and refuses a row without one before anything is sent.
 *
 * It also writes the ClickHouse clauses: FINAL (final()), SAMPLE (sample()),
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
     * Handle preWhere<Method>() and orPreWhere<Method>() for each where method of the builder, such as
     * preWhereColumn(), preWhereDate(), preWhereAny() or orPreWhereLike(): the call runs as where<Method>() or
     * orWhere<Method>(), with the conditions in the PREWHERE clause (see addToPreWheres()). Any other call is
     * Laravel's.
     *
     * @param string $method
     * @param array<int, mixed> $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (preg_match('/^(orP|p)reWhere(\w+)$/', $method, $matches) === 1) {
            $whereMethod = ($matches[1] === 'orP' ? 'orWhere' : 'where') . $matches[2];

            if (method_exists($this, $whereMethod)) {
                return $this->addToPreWheres(fn () => $this->{$whereMethod}(...$parameters));
            }
        }

        return parent::__call($method, $parameters);
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
     * The first argument is Laravel's $id, so a bool there is refused before anything is sent: delete(true) would
     * delete the row whose id is 1. Pass the option by name: delete(lightweight: true).
     *
     * @param mixed $id
     * @param bool|null $lightweight
     * @param int|string|ExpressionContract|null $partition
     * @return int
     * @throws InvalidArgumentException When $id is a bool
     */
    public function delete($id = null, ?bool $lightweight = null, int|string|ExpressionContract|null $partition = null)
    {
        if (is_bool($id)) {
            throw new InvalidArgumentException(sprintf(
                'delete() takes the id of a row as its first argument, a bool given. Pass the option by name:'
                . ' delete(lightweight: %s).',
                var_export($id, true)
            ));
        }

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
     * Start a merge of the parts of the table: optimize table "t" [on cluster '<name>'] [partition <partition>]
     * [final]. FINAL merges also the parts that are merged already, so that a ReplacingMergeTree keeps one row of each
     * key. The partition is written as the partition of delete() is. The cluster is that of onCluster(), or the
     * default cluster of the connection's use_on_cluster option.
     *
     * @param bool $final
     * @param int|string|ExpressionContract|null $partition
     * @return bool
     */
    public function optimize(bool $final = false, int|string|ExpressionContract|null $partition = null): bool
    {
        $this->applyBeforeQueryCallbacks();

        return $this->connection->statement($this->grammar->compileOptimize($this, $final, $partition));
    }

    /**
     * Update the rows that the where and prewhere conditions match, with an alter table "t" update ... mutation.
     * $partition limits the update to one partition (see delete()). An update without values is refused before
     * anything is sent.
     *
     * @param array<string, mixed> $values
     * @param int|string|ExpressionContract|null $partition
     * @return int
     * @throws QueryException When $values is empty
     */
    public function update(array $values, int|string|ExpressionContract|null $partition = null)
    {
        if ($values === []) {
            throw QueryException::cannotUpdateEmptyValues();
        }

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
     * Get the values of a column, as Laravel does, read from each row by the name that ClickHouse gives the column.
     * ClickHouse (24.8 checked) names a column of a joined table with its table, such as regression_reads_other.s,
     * and a column of the query's own table without it, where Laravel reads every column by the name after the last
     * dot. A name that the first row holds as given, without its quotes, is read as it is.
     *
     * @param ExpressionContract|string $column
     * @param ExpressionContract|string|null $key
     * @return Collection<array-key, mixed>
     */
    public function pluck($column, $key = null)
    {
        $original = $this->columns;
        $this->columns ??= $key === null || $key === $column ? [$column] : [$column, $key];

        try {
            $queryResult = $this->processor->processSelect($this, $this->runSelect());
        } finally {
            $this->columns = $original;
        }

        if (empty($queryResult)) {
            return new Collection();
        }

        $row = (array) $queryResult[0];
        $column = $this->nameOfAColumnInARow($column, $row);
        $key = $this->nameOfAColumnInARow($key, $row);

        return $this->applyAfterQueryCallbacks(
            is_array($queryResult[0])
                ? $this->pluckFromArrayColumn($queryResult, $column, $key)
                : $this->pluckFromObjectColumn($queryResult, $column, $key)
        );
    }

    /**
     * Get the name of a column in a row of the query (see pluck()): the column as given, without quotes, when the row
     * holds that name and it has no alias, and otherwise the name that Laravel reads.
     *
     * @param ExpressionContract|string|null $column
     * @param array<string, mixed> $row
     * @return string|null
     */
    protected function nameOfAColumnInARow($column, array $row): ?string
    {
        if ($column === null) {
            return null;
        }

        $name = str_replace(['"', '`'], '', (string) ($column instanceof ExpressionContract ? $this->grammar->getValue($column) : $column));

        return !str_contains(strtolower($name), ' as ') && array_key_exists($name, $row)
            ? $name
            : $this->stripTableForPluck($column);
    }

    /**
     * Set the aggregate of the query, as Laravel does, except that a union keeps the order of its first query.
     * Laravel drops the order of the query, which in a union belongs to the first query and decides, with its limit,
     * which rows that query returns: sum() of (... order by "x" limit 1) union all (...) would add another row.
     *
     * @param string $function
     * @param array<int, ExpressionContract|string> $columns
     * @return $this
     */
    protected function setAggregate($function, $columns)
    {
        if (empty($this->unions)) {
            return parent::setAggregate($function, $columns);
        }

        $this->aggregate = ['function' => $function, 'columns' => $columns];

        return $this;
    }

    /**
     * Determine if the query returns a row: select exists(...) as "exists", as Laravel runs it. The answer is read
     * from the only column of the row, whatever its name: without the analyzer (enable_analyzer = 0), ClickHouse
     * (24.8 checked) names the column of exists() over a query with a with expression or a join in(1, _subquery1)
     * instead of exists.
     *
     * @return bool
     */
    public function exists()
    {
        $this->applyBeforeQueryCallbacks();

        $results = $this->connection->select(
            $this->grammar->compileExists($this),
            $this->getBindings(),
            !$this->useWritePdo
        );

        if (!isset($results[0])) {
            return false;
        }

        $row = (array) $results[0];

        return (bool) ($row['exists'] ?? reset($row));
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
     * - withAlias(), whose expression, such as arrayJoin(tags) or count(), ClickHouse only runs when the select list,
     *   a condition or the order reads it;
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
            || array_filter($this->withs, fn (array $with): bool => $with['type'] === 'alias') !== []
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
     * Run the query and return the smi2 statement of its result, in the given format, or in JSON.
     *
     * rawData() of the statement gives the result: the body of a text format, such as CSV, Pretty or XML, and the
     * decoded document of a JSON format. totals() and extremes() read a JSON result. The query runs as the
     * connection's selectStatement() runs it, with FORMAT and the format after the SQL of the query.
     *
     * @param Format|string|null $format A case, or the name of a ClickHouse output format, such as 'TSVRaw'; the
     *                                   name of a case is matched in any letter case
     * @return Statement
     * @throws InvalidArgumentException When the name is no format name, or the connection is not a ClickHouse
     *                                  connection
     */
    public function getStatement(Format|string|null $format = null): Statement
    {
        $connection = $this->connection;
        if (!$connection instanceof Connection) {
            throw new InvalidArgumentException('getStatement() needs a ClickHouse connection.');
        }

        if (is_string($format) && preg_match('/^[A-Za-z][A-Za-z0-9]*\z/', $format) !== 1) {
            throw new InvalidArgumentException("Invalid ClickHouse format name [{$format}].");
        }

        $sql = $this->toSql();
        if ($format !== null) {
            $sql .= ' format ' . ($format instanceof Format ? $format->value : (Format::tryNamed($format)?->value ?? $format));
        }

        return $connection->selectStatement($sql, $this->getBindings());
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
     *   insertJsonEachRow()). The values that both formats take are stored alike, such as floats with every digit,
     *   dates at the connection's datetime_precision into DateTime columns, null, arrays and collections. ClickHouse reads some values differently in JSONEachRow (24.8
     *   checked): a Date column refuses a date with a time, such as a Carbon; a Map column refuses [] and takes an
     *   array with keys, which Values refuses; a Tuple column takes a list, which Values refuses; an integer column
     *   refuses a float with a fraction, which Values cuts off. Raw SQL, such as DB::raw(), and a string that is not
     *   valid UTF-8 are refused before anything is sent.
     *
     * In both formats, every row must have the keys of the first row, in any order, or the rows are refused before
     * anything is sent or logged (see refuseRowsWithOtherKeys()).
     *
     * @param array<int|string, mixed> $values One row, or a list of rows
     * @param Format|string|null $format Format::Values or Format::JsonEachRow, or 'Values' or 'JSONEachRow' in any
     *                                   letter case; null uses the connection's insert_format
     * @return bool
     * @throws InvalidArgumentException When the format is neither Values nor JSONEachRow, or, in JSONEachRow, a
     *                                  value cannot be sent as JSON or the connection is not a ClickHouse connection
     * @throws QueryException When a row's keys differ from the first row's
     * @throws ClientQueryException When a row has no keys, before anything is sent, or, in JSONEachRow, the insert
     *                              fails
     */
    public function insert(array $values, Format|string|null $format = null)
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

        if (in_array([], $rows, true)) {
            throw ClientQueryException::cannotInsertEmptyValues();
        }

        if ($format === Format::JsonEachRow) {
            return $this->insertJsonEachRow($rows);
        }

        $this->refuseRowsWithOtherKeys($rows);

        return parent::insert($rows);
    }

    /**
     * Refuse the rows of a Values insert whose keys differ from the first row's, in any order, as a JSONEachRow
     * insert refuses them (see ClientRequests::encodeJsonEachRow()). Laravel takes the column list from the keys of
     * the first row, and writes the values of each row in the order of its own keys, so ClickHouse (24.8 checked)
     * stores the value of another key in a column of the first row: [['a' => 1], ['b' => 5]] stores 5 in the column
     * a. A row with fewer or more keys fails with SYNTAX_ERROR, and no row is stored. A row that is no array is left
     * to Laravel.
     *
     * @param array<int|string, mixed> $rows
     * @return void
     * @throws QueryException When a row's keys differ from the first row's
     */
    protected function refuseRowsWithOtherKeys(array $rows): void
    {
        $first = null;

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($first === null) {
                $first = $row;

                continue;
            }

            if (count($row) !== count($first) || array_diff_key($row, $first) !== []) {
                throw QueryException::insertKeysDiffer($index, array_keys($row), array_keys($first), Format::Values->value);
            }
        }
    }

    /**
     * Get the input format of an insert: Format::Values or Format::JsonEachRow, from one of them, from a name in any
     * letter case and with surrounding spaces, or, for null, the insert_format option of the connection. A connection
     * other than this package's inserts in Values.
     *
     * @param Format|string|null $format
     * @return Format
     * @throws InvalidArgumentException For any other format
     */
    protected function insertFormat(Format|string|null $format): Format
    {
        if ($format === null) {
            return $this->connection instanceof Connection ? $this->connection->getInsertFormat() : Format::Values;
        }

        $named = $format instanceof Format ? $format : Format::tryNamed($format);
        if ($named === Format::Values || $named === Format::JsonEachRow) {
            return $named;
        }

        throw new InvalidArgumentException(sprintf(
            "insert() takes the format '%s' or '%s', [%s] given.",
            Format::Values->value,
            Format::JsonEachRow->value,
            $format instanceof Format ? $format->value : $format
        ));
    }

    /**
     * Add a whereFullText() condition, as Laravel does, with one binding of the value for each column, since the
     * grammar joins the columns with or, each with the value (see QueryGrammar::whereFullText()). The mode option is
     * checked at once.
     *
     * @param string|array<int, string> $columns
     * @param string $value
     * @param array<string, mixed> $options
     * @param string $boolean
     * @return $this
     * @throws InvalidArgumentException For a mode other than 'all' or 'any'
     */
    public function whereFullText($columns, $value, array $options = [], $boolean = 'and')
    {
        if ($this->grammar instanceof QueryGrammar) {
            $this->grammar->getFullTextFunction($options);
        }

        parent::whereFullText($columns, $value, $options, $boolean);

        for ($column = 1; $column < count((array) $columns); $column++) {
            $this->addBinding($value, 'where');
        }

        return $this;
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
     * @throws QueryException When a row's keys differ from the first row's, or a key is a JSON selector, such as
     *                        payload->a
     * @throws ClientQueryException When a row has no keys, or the insert fails
     */
    protected function insertJsonEachRow(array $rows): bool
    {
        $connection = $this->connection;
        if (!$connection instanceof Connection) {
            throw new InvalidArgumentException(sprintf(
                'Cannot insert in %s on a %s connection: only a ClickHouse connection sends JSONEachRow rows.',
                Format::JsonEachRow->value,
                get_debug_type($connection)
            ));
        }

        if (in_array([], $rows, true)) {
            throw ClientQueryException::cannotInsertEmptyValues();
        }

        $this->applyBeforeQueryCallbacks();

        [$columns, $body] = ClientRequests::encodeJsonEachRow(
            $rows,
            new JsonEachRowEncoder($connection->newLiteralGrammar()->formatDateTime(...))
        );
        if ($this->grammar instanceof QueryGrammar) {
            $this->grammar->refuseJsonPathsInInsert($columns);
        }
        $head = sprintf(
            'insert into %s (%s) format %s',
            $this->grammar->wrapTable($this->from),
            $this->grammar->columnize(array_map(fn (int|string $column): string => (string) $column, $columns)),
            Format::JsonEachRow->value
        );

        $connection->runBeforeExecutingCallbacks($head, []);
        $start = microtime(true);

        if (!$connection->pretending()) {
            ClientRequests::sendInsert($connection->getClient(), $head, $body, Format::JsonEachRow->value);
            $connection->recordsHaveBeenModified();
        }

        $connection->logQuery($head, [], round((microtime(true) - $start) * 1000, 2));

        return true;
    }

    /**
     * Insert the rows of files in an input format, one INSERT per file, sent together by smi2's
     * Client::insertBatchFiles().
     *
     * The statement of each file is insert into "t" ("a", "b") format <format>, with the file as its body; without
     * columns the list is left out. The format name is matched in any letter case.
     *
     * Every path must be a readable file: otherwise nothing is sent. Each file is a separate INSERT, and the files are
     * sent together: smi2 throws for the first file that the server refused, or that timed out, after every file was
     * sent, so the files before and after it may have been inserted. Each statement is logged when every file was
     * inserted. While the connection pretends, the statements are logged and nothing is sent or read. Each file has to
     * be inserted within the connection's timeout_query, and the connection's retries do not apply.
     *
     * @param string|array<int, string> $paths
     * @param Format|string $format TabSeparated, TabSeparatedWithNames, CSV, CSVWithNames, JSONEachRow,
     *                              CSVWithNamesAndTypes or TSVWithNamesAndTypes, as a case or in any letter case
     * @param array<int, string> $columns
     * @return array<string, Statement> The statement of each file, keyed by path
     * @throws InvalidArgumentException For a format that smi2 cannot send from a file, before anything is sent
     * @throws QueryException In a ClickHouse session, or when a path is not a readable file, before anything is sent
     * @throws ClientQueryException When an insert fails or times out
     */
    public function insertFiles(string|array $paths, Format|string $format = Format::Csv, array $columns = []): array
    {
        $connection = $this->connection;
        if (!$connection instanceof Connection) {
            throw new InvalidArgumentException('insertFiles() needs a ClickHouse connection.');
        }

        $name = $format instanceof Format ? $format->value : $format;
        $supported = null;
        foreach (Client::SUPPORTED_FORMATS as $candidate) {
            if (strcasecmp($candidate, trim($name)) === 0) {
                $supported = $candidate;
            }
        }

        if ($supported === null) {
            throw new InvalidArgumentException(sprintf(
                'insertFiles() cannot send the format [%s]: use one of %s, in any letter case.',
                $name,
                implode(', ', Client::SUPPORTED_FORMATS)
            ));
        }

        if ($connection->getClient()->getSession() !== false) {
            throw new QueryException(
                'Cannot insert files in a ClickHouse session: smi2/phpclickhouse sends the files as asynchronous'
                . ' requests, which fail in a session after the file is sent, and ClickHouse runs one query of a'
                . ' session at a time. Insert the files outside session().'
            );
        }

        $table = $this->grammar->wrapTable($this->from);
        $quotedColumns = array_map(fn (int|string $column): string => $this->grammar->wrap((string) $column), array_values($columns));

        if ($connection->pretending()) {
            $sql = $quotedColumns === []
                ? "INSERT INTO {$table} FORMAT {$supported}"
                : "INSERT INTO {$table} ( " . implode(',', $quotedColumns) . " ) FORMAT {$supported}";
            $statements = [];
            foreach ((array) $paths as $path) {
                $connection->logQuery($sql, [], 0.0);
                $statements[$path] = ClientRequests::pretendedStatement($sql);
            }

            return $statements;
        }

        foreach ((array) $paths as $path) {
            if (!is_file($path) || !is_readable($path)) {
                throw new QueryException("Cannot insert the file [{$path}]: it is not a readable file. No file was sent.");
            }
        }

        $statements = $connection->getClient()->insertBatchFiles($table, $paths, $quotedColumns, $supported);
        foreach ($statements as $statement) {
            $connection->logQuery($statement->sql(), [], round($statement->totalTimeRequest() * 1000, 2));
        }
        $connection->recordsHaveBeenModified();

        return $statements;
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
