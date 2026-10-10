<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query;

use BadMethodCallException;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\JoinStrict;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\JoinType;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Operator;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\OrderDirection;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\UnionType;
use Stringable;
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\tp;

abstract class BaseBuilder
{
    use Conditionable;

    /**
     * Type of a WITH clause entry added by withExpression() or withRecursiveExpression().
     */
    public const WITH_EXPRESSION = 'expression';

    /**
     * Type of a WITH clause entry added by withAlias().
     */
    public const WITH_ALIAS = 'alias';

    /**
     * WITH clause entries, in call order.
     *
     * @var array<int, array{type: string, name: string, value: mixed, recursive: bool}>
     */
    protected $withs = [];

    /**
     * Columns for select.
     *
     * @var Column[]
     */
    protected $columns = [];

    /**
     * Table to select from.
     *
     * @var From|null
     */
    protected $from = null;

    /**
     * Sample expression.
     *
     * @var float|null
     */
    protected $sample;

    /**
     * Sample offset.
     *
     * @var float|null
     */
    protected $sampleOffset;

    /**
     * Join clauses.
     *
     * @var JoinClause[]|null
     */
    protected $joins;

    /**
     * Array join clause.
     *
     * @var ArrayJoinClause
     */
    protected $arrayJoin;

    /**
     * Prewhere statements.
     *
     * @var TwoElementsLogicExpression[]
     */
    protected $prewheres = [];

    /**
     * Where statements.
     *
     * @var TwoElementsLogicExpression[]
     */
    protected $wheres = [];

    /**
     * Groupings.
     *
     * @var array
     */
    protected $groups = [];

    /**
     * Having statements.
     *
     * @var TwoElementsLogicExpression[]
     */
    protected $havings = [];

    /**
     * Order statements.
     *
     * @var array
     */
    protected $orders = [];

    /**
     * Limit.
     *
     * @var Limit|null
     */
    protected $limit;

    /**
     * Limit n by statement.
     *
     * @var Limit|null
     */
    protected $limitBy;

    /**
     * Queries to union.
     *
     * @var BaseBuilder[]
     */
    protected $unions = [];

    /**
     * Set operator of each query in $unions, under the same key. A missing entry compiles as UNION ALL.
     *
     * @var string[]
     */
    protected $unionTypes = [];

    /**
     * Query format.
     *
     * @var Format|null
     */
    protected $format;

    /**
     * Grammar to build query parts.
     *
     * @var Grammar
     */
    protected $grammar;

    /**
     * Queries which must be run asynchronous.
     *
     * @var array
     */
    protected $async = [];

    /**
     * Cluster name.
     *
     * @var string|null
     */
    protected $onCluster = null;

    /**
     * Names of the WITH clause queries that use INTERSECT or EXCEPT, of the queries this query is nested in.
     *
     * A query made for a closure of a condition, of whereExists(), of a WITH clause entry or of a set operation
     * gets them when it is made (see newNestedQuery()), so that its whereExists() calls compile a sub-query that
     * reads such a query by its name as compileExistsSubQuery() describes.
     *
     * @var array<int, string>
     */
    protected array $outerIntersectOrExceptNames = [];

    /**
     * Set columns for select statement.
     *
     * @param array|mixed $columns
     *
     * @return static
     */
    public function select(...$columns)
    {
        $columns = isset($columns[0]) && is_array($columns[0]) ? $columns[0] : $columns;

        if (empty($columns)) {
            $columns[] = '*';
        }

        $this->columns = $this->processColumns($columns);

        return $this;
    }

    /**
     * Returns query for count total rows without limit.
     *
     * @return static
     */
    public function getCountQuery()
    {
        $without = ['columns' => [], 'limit' => null];

        if (empty($this->groups)) {
            $without['orders'] = [];
        }

        return $this->cloneWithout($without)->select(raw('count() as `count`'));
    }

    /**
     * Clone the query without the given properties.
     *
     * Replacing 'unions' also forgets their set operators, unless 'unionTypes' is given too.
     *
     * @param array $except
     *
     * @return static
     */
    public function cloneWithout(array $except)
    {
        if (array_key_exists('unions', $except) && !array_key_exists('unionTypes', $except)) {
            $except['unionTypes'] = [];
        }

        return tp(
            clone $this,
            function ($clone) use ($except) {
                foreach ($except as $property => $value) {
                    $clone->{$property} = $value;
                }
            }
        );
    }

    /**
     * Add columns to exist select statement.
     *
     * @param array|mixed $columns
     *
     * @return static
     */
    public function addSelect(...$columns)
    {
        $columns = isset($columns[0]) && is_array($columns[0]) ? $columns[0] : $columns;

        if (empty($columns)) {
            $columns[] = '*';
        }

        $this->columns = array_merge($this->columns, $this->processColumns($columns));

        return $this;
    }

    /**
     * A factory method for Column.
     *
     * @return Column
     */
    protected function makeColumn(): Column
    {
        return new Column($this);
    }

    /**
     * Prepares columns given by user to Column objects.
     *
     * @param array $columns
     * @param bool  $withAliases
     *
     * @return array
     */
    protected function processColumns(array $columns, bool $withAliases = true): array
    {
        $result = [];

        foreach ($columns as $column => $value) {
            if ($value instanceof Closure) {
                $columnName = $column;
                $column = $this->makeColumn();

                if (!is_int($columnName)) {
                    $column->name($columnName);
                }

                $column = tp($column, $value);

                if ($column->getSubQuery()) {
                    $column->query($column->getSubQuery());
                }
            }

            if ($value instanceof BaseBuilder) {
                $alias = is_string($column) ? $column : null;
                $column = $this->makeColumn()->query($value);

                if (!is_null($alias) && $withAliases) {
                    $column->as($alias);
                }
            }

            if (is_int($column)) {
                $column = $value;
                $value = null;
            }

            if (!$column instanceof Column) {
                $alias = is_string($value) ? $value : null;

                $column = $this->makeColumn()->name($column);

                if (!is_null($alias) && $withAliases) {
                    $column->as($alias);
                }
            }

            $result[] = $column;
        }

        return $result;
    }

    /**
     * Sets table to from statement.
     *
     * $isFinal true reads the table with FINAL. False and null, the default, leave FINAL out.
     *
     * @param Closure|BaseBuilder|string $table
     * @param string|null                $alias
     * @param bool|null                  $isFinal
     *
     * @return static
     */
    public function from($table, ?string $alias = null, ?bool $isFinal = null)
    {
        $this->from = new From($this);

        /*
         * If builder instance given, then we assume that from section should contain sub-query
         */
        if ($table instanceof BaseBuilder) {
            $this->from->query($table);
        }

        /*
         * If closure given, then we call it and pass From object as argument to
         * set up From object in callback
         */
        if ($table instanceof Closure) {
            $table($this->from);
        }

        /*
         * If given anything that is not builder instance or callback. For example, string,
         * then we assume that table name was given.
         */
        if (!$table instanceof Closure && !$table instanceof BaseBuilder) {
            $this->from->table($table);
        }

        if (!is_null($alias)) {
            $this->from->as($alias);
        }

        if (!is_null($isFinal)) {
            $this->from->final($isFinal);
        }

        /*
         * If subQuery method was executed on From object, then we take subQuery and "execute" it
         */
        if (!is_null($this->from->getSubQuery())) {
            $this->from->query($this->from->getSubQuery());
        }

        return $this;
    }

    /**
     * Alias for from method.
     *
     * $isFinal true reads the table with FINAL. False and null, the default, leave FINAL out.
     *
     * @param             $table
     * @param string|null $alias
     * @param bool|null   $isFinal
     *
     * @return static
     */
    public function table($table, ?string $alias = null, ?bool $isFinal = null)
    {
        return $this->from($table, $alias, $isFinal);
    }

    /**
     * Set sample expression: SAMPLE <coefficient> [OFFSET <offset>].
     *
     * @param float      $coefficient
     * @param float|null $offset
     *
     * @return static
     */
    public function sample(float $coefficient, ?float $offset = null)
    {
        $this->sample = $coefficient;
        $this->sampleOffset = $offset;

        return $this;
    }

    /**
     * Add a named sub-query to the WITH clause: WITH `name` AS (<query>).
     *
     * A string is used as raw SQL, and so are an Expression and a Laravel database expression, such as DB::raw().
     *
     * @param string                                                   $name
     * @param BaseBuilder|Closure|Expression|ExpressionContract|string $query
     *
     * @return static
     */
    public function withExpression(string $name, $query): static
    {
        return $this->addWithExpression($name, $query, false, 'withExpression');
    }

    /**
     * Add a recursive named sub-query to the WITH clause, which then becomes WITH RECURSIVE.
     *
     * A string is used as raw SQL, and so are an Expression and a Laravel database expression, such as DB::raw().
     *
     * @param string                                                   $name
     * @param BaseBuilder|Closure|Expression|ExpressionContract|string $query
     *
     * @return static
     */
    public function withRecursiveExpression(string $name, $query): static
    {
        return $this->addWithExpression($name, $query, true, 'withRecursiveExpression');
    }

    /**
     * Add an aliased expression to the WITH clause: WITH <expression> AS `alias`.
     *
     * A builder or closure becomes a scalar sub-query, an Expression or a Laravel database expression, such as
     * DB::raw(), is used as raw SQL, an array becomes an array literal and any other value is rendered like a where()
     * value.
     *
     * @param string $alias
     * @param mixed  $expression
     *
     * @return static
     */
    public function withAlias(string $alias, $expression): static
    {
        if ($expression instanceof Closure) {
            $expression = tp($this->newNestedQuery(), $expression);
        }

        $this->withs[] = [
            'type'      => self::WITH_ALIAS,
            'name'      => $alias,
            'value'     => $expression,
            'recursive' => false,
        ];

        return $this;
    }

    /**
     * Add a named sub-query to the WITH clause.
     *
     * @param string                                                   $name
     * @param BaseBuilder|Closure|Expression|ExpressionContract|string $query
     * @param bool                                                     $recursive
     * @param string                                                   $method
     *
     * @throws InvalidArgumentException
     *
     * @return static
     */
    protected function addWithExpression(string $name, $query, bool $recursive, string $method): static
    {
        if ($query instanceof Closure) {
            $query = tp($this->newNestedQuery(), $query);
        }

        if (is_string($query)) {
            $query = new Expression($query);
        }

        if (!$query instanceof BaseBuilder && !$query instanceof Expression && !$query instanceof ExpressionContract) {
            throw new InvalidArgumentException(
                "Argument for {$method} must be closure, builder instance, Expression, Laravel database expression, such as DB::raw(), or string."
            );
        }

        $this->withs[] = [
            'type'      => self::WITH_EXPRESSION,
            'name'      => $name,
            'value'     => $query,
            'recursive' => $recursive,
        ];

        return $this;
    }

    /**
     * Add queries to union with.
     *
     * @param self|Closure $query
     *
     * @return static
     */
    public function unionAll($query)
    {
        return $this->addUnion($query, UnionType::UNION_ALL, 'unionAll');
    }

    /**
     * Add a query to combine with UNION DISTINCT.
     *
     * @param self|Closure $query
     *
     * @return static
     */
    public function unionDistinct($query): static
    {
        return $this->addUnion($query, UnionType::UNION_DISTINCT, 'unionDistinct');
    }

    /**
     * Add a query to combine with INTERSECT.
     *
     * @param self|Closure $query
     *
     * @return static
     */
    public function intersect($query): static
    {
        return $this->addUnion($query, UnionType::INTERSECT, 'intersect');
    }

    /**
     * Add a query to combine with INTERSECT DISTINCT.
     *
     * @param self|Closure $query
     *
     * @return static
     */
    public function intersectDistinct($query): static
    {
        return $this->addUnion($query, UnionType::INTERSECT_DISTINCT, 'intersectDistinct');
    }

    /**
     * Add a query to combine with EXCEPT.
     *
     * @param self|Closure $query
     *
     * @return static
     */
    public function except($query): static
    {
        return $this->addUnion($query, UnionType::EXCEPT, 'except');
    }

    /**
     * Add a query to combine with EXCEPT DISTINCT.
     *
     * @param self|Closure $query
     *
     * @return static
     */
    public function exceptDistinct($query): static
    {
        return $this->addUnion($query, UnionType::EXCEPT_DISTINCT, 'exceptDistinct');
    }

    /**
     * Add a query to combine with the given set operator.
     *
     * @param self|Closure $query
     * @param string       $type
     * @param string       $method
     *
     * @throws InvalidArgumentException
     *
     * @return static
     */
    protected function addUnion($query, string $type, string $method): static
    {
        if ($query instanceof Closure) {
            $query = tp($this->newNestedQuery(), $query);
        }

        if (!$query instanceof BaseBuilder) {
            throw new InvalidArgumentException("Argument for {$method} must be closure or builder instance.");
        }

        $this->unions[] = $query;
        $this->unionTypes[array_key_last($this->unions)] = $type;

        return $this;
    }

    /**
     * Set alias for table in from statement.
     *
     * @param string $alias
     *
     * @return static
     */
    public function as(string $alias)
    {
        $this->from->as($alias);

        return $this;
    }

    /**
     * As method alias.
     *
     * @param string $alias
     *
     * @return static
     */
    public function alias(string $alias)
    {
        return $this->as($alias);
    }

    /**
     * Sets final option on from statement.
     *
     * final() and final(true) read the table with FINAL. final(false) leaves FINAL out, also after an earlier
     * final().
     *
     * @param bool $final
     *
     * @return static
     */
    public function final(bool $final = true)
    {
        $this->from->final($final);

        return $this;
    }

    /**
     * Sets on cluster option for query.
     *
     * @param string $clusterName
     *
     * @return static
     */
    public function onCluster(string $clusterName)
    {
        $this->onCluster = $clusterName;

        return $this;
    }

    /**
     * Add array join to query.
     *
     * An array joins several arrays at once, and a string key becomes the alias of its array:
     * ['tag' => 'tags', 'other'] compiles to ARRAY JOIN `tags` AS `tag`, `other`.
     *
     * @param string|Expression|array<int|string, string|Expression> $arrayIdentifier
     *
     * @return static
     */
    public function arrayJoin($arrayIdentifier)
    {
        $this->arrayJoin = new ArrayJoinClause($this);
        $this->arrayJoin->array($arrayIdentifier);

        return $this;
    }

    /**
     * Add left array join to query.
     *
     * Takes the same arguments as arrayJoin().
     *
     * @param string|Expression|array<int|string, string|Expression> $arrayIdentifier
     *
     * @return static
     */
    public function leftArrayJoin($arrayIdentifier)
    {
        $this->arrayJoin = new ArrayJoinClause($this);
        $this->arrayJoin->left()->array($arrayIdentifier);

        return $this;
    }

    /**
     * Add join to query.
     *
     * @param string|self|Closure $table  Table to select from, also may be a sub-query
     * @param string|null         $strict All, any, semi, anti or asof
     * @param string|null         $type   Inner, left, right, full, cross or asof
     * @param array|null          $using  Columns to use for join
     * @param bool                $global Global distribution for right table: true adds GLOBAL; false keeps what
     *                                    a closure set with JoinClause::distributed()
     * @param string|null         $alias  Alias of joined table or sub-query
     *
     * @return static
     */
    public function join(
        $table,
        ?string $strict = null,
        ?string $type = null,
        ?array $using = null,
        bool $global = false,
        ?string $alias = null
    ) {
        $join = new JoinClause($this);

        /*
         * If builder instance given, then we assume that sub-query should be used as table in join
         */
        if ($table instanceof BaseBuilder) {
            $join->query($table);
        }

        /*
         * If closure given, then we call it and pass From object as argument to
         * set up JoinClause object in callback
         */
        if ($table instanceof Closure) {
            $table($join);
        }

        /*
         * If given anything that is not builder instance or callback. For example, string,
         * then we assume that table name was given.
         */
        if (!$table instanceof Closure && !$table instanceof BaseBuilder) {
            $join->table($table);
        }

        /*
         * If using was given, then merge it with using given before, in closure
         */
        if (!is_null($using)) {
            $join->addUsing($using);
        }

        if (!is_null($strict) && is_null($join->getStrict())) {
            $join->strict($strict);
        }

        if (!is_null($type) && is_null($join->getType())) {
            $join->type($type);
        }

        if (!is_null($alias) && is_null($join->getAlias())) {
            $join->as($alias);
        }

        if ($global) {
            $join->distributed(true);
        }

        if (!is_null($join->getSubQuery())) {
            $join->query($join->getSubQuery());
        }

        $this->joins[] = $join;

        return $this;
    }

    /**
     * Left join.
     *
     * Alias for join method, but without specified strictness
     *
     * @param string|self|Closure $table
     * @param string|null         $strict
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function leftJoin($table, ?string $strict = null, ?array $using = null, bool $global = false, ?string $alias = null)
    {
        return $this->join($table, $strict ?? JoinStrict::ALL, JoinType::LEFT, $using, $global, $alias);
    }

    /**
     * Inner join.
     *
     * Alias for join method, but without specified strictness
     *
     * @param string|self|Closure $table
     * @param string|null         $strict
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function innerJoin($table, ?string $strict = null, ?array $using = null, bool $global = false, ?string $alias = null)
    {
        return $this->join($table, $strict ?? JoinStrict::ALL, JoinType::INNER, $using, $global, $alias);
    }

    /**
     * Any left join.
     *
     * Alias for join method, but with specified any strictness
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function anyLeftJoin($table, ?array $using = null, bool $global = false, ?string $alias = null)
    {
        return $this->join($table, JoinStrict::ANY, JoinType::LEFT, $using, $global, $alias);
    }

    /**
     * All left join.
     *
     * Alias for join method, but with specified all strictness.
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function allLeftJoin($table, ?array $using = null, bool $global = false, ?string $alias = null)
    {
        return $this->join($table, JoinStrict::ALL, JoinType::LEFT, $using, $global, $alias);
    }

    /**
     * Any inner join.
     *
     * Alias for join method, but with specified any strictness.
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function anyInnerJoin($table, ?array $using = null, bool $global = false, ?string $alias = null)
    {
        return $this->join($table, JoinStrict::ANY, JoinType::INNER, $using, $global, $alias);
    }

    /**
     * All inner join.
     *
     * Alias for join method, but with specified all strictness.
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function allInnerJoin($table, ?array $using = null, bool $global = false, ?string $alias = null)
    {
        return $this->join($table, JoinStrict::ALL, JoinType::INNER, $using, $global, $alias);
    }

    /**
     * Right join.
     *
     * Alias for join method, with ALL strictness unless another one is given.
     *
     * @param string|self|Closure $table
     * @param string|null         $strict
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function rightJoin($table, ?string $strict = null, ?array $using = null, bool $global = false, ?string $alias = null): static
    {
        return $this->join($table, $strict ?? JoinStrict::ALL, JoinType::RIGHT, $using, $global, $alias);
    }

    /**
     * Full join.
     *
     * Alias for join method, with ALL strictness unless another one is given.
     *
     * @param string|self|Closure $table
     * @param string|null         $strict
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function fullJoin($table, ?string $strict = null, ?array $using = null, bool $global = false, ?string $alias = null): static
    {
        return $this->join($table, $strict ?? JoinStrict::ALL, JoinType::FULL, $using, $global, $alias);
    }

    /**
     * Any right join.
     *
     * Alias for join method, but with specified any strictness.
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function anyRightJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
    {
        return $this->join($table, JoinStrict::ANY, JoinType::RIGHT, $using, $global, $alias);
    }

    /**
     * All right join.
     *
     * Alias for join method, but with specified all strictness.
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function allRightJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
    {
        return $this->join($table, JoinStrict::ALL, JoinType::RIGHT, $using, $global, $alias);
    }

    /**
     * Semi left join: rows of the left table that have a match in the joined table.
     *
     * Alias for join method, but with specified semi strictness.
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function semiLeftJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
    {
        return $this->join($table, JoinStrict::SEMI, JoinType::LEFT, $using, $global, $alias);
    }

    /**
     * Semi right join: rows of the joined table that have a match in the left table.
     *
     * Alias for join method, but with specified semi strictness.
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function semiRightJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
    {
        return $this->join($table, JoinStrict::SEMI, JoinType::RIGHT, $using, $global, $alias);
    }

    /**
     * Anti left join: rows of the left table that have no match in the joined table.
     *
     * Alias for join method, but with specified anti strictness.
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function antiLeftJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
    {
        return $this->join($table, JoinStrict::ANTI, JoinType::LEFT, $using, $global, $alias);
    }

    /**
     * Anti right join: rows of the joined table that have no match in the left table.
     *
     * Alias for join method, but with specified anti strictness.
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function antiRightJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
    {
        return $this->join($table, JoinStrict::ANTI, JoinType::RIGHT, $using, $global, $alias);
    }

    /**
     * Asof join: each row is joined with the closest match of the joined table.
     *
     * Alias for join method, but with specified asof strictness.
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function asofJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
    {
        return $this->join($table, JoinStrict::ASOF, null, $using, $global, $alias);
    }

    /**
     * Asof left join: like asofJoin, but keeps the rows of the left table that have no match.
     *
     * Alias for join method, but with specified asof strictness.
     *
     * @param string|self|Closure $table
     * @param array|null          $using
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function asofLeftJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
    {
        return $this->join($table, JoinStrict::ASOF, JoinType::LEFT, $using, $global, $alias);
    }

    /**
     * Cross join: every row of the left table with every row of the joined table, without join keys.
     *
     * @param string|self|Closure $table
     * @param bool                $global
     * @param string|null         $alias
     *
     * @return static
     */
    public function crossJoin($table, bool $global = false, ?string $alias = null): static
    {
        return $this->join($table, null, JoinType::CROSS, null, $global, $alias);
    }

    /**
     * Get two elements logic expression to put it in the right place.
     *
     *
     * Used in where, prewhere and having methods.
     *
     * An array value is a list of values, which only the IN family and BETWEEN take. With IN, NOT IN, GLOBAL IN or
     * GLOBAL NOT IN it becomes a tuple, and an empty list becomes a bare condition that keeps the concatenation
     * operator: 0 = 1 for IN and GLOBAL IN, 1 = 1 for NOT IN and GLOBAL NOT IN, as in Laravel. With BETWEEN or
     * NOT BETWEEN its first two values, in order and whatever their keys, are the lower and the upper bound, as in
     * Laravel, which also leaves out any further value. A null bound is written as NULL, as Laravel binds it, so
     * the condition matches no row.
     *
     * An array column is the list of conditions that a closure group built (see addCondition()), which compiles in
     * parentheses.
     *
     * @param TwoElementsLogicExpression|string|Closure|self|Expression|ExpressionContract|array $column
     * @param mixed                                                                                $operator
     * @param mixed                                                                                $value
     * @param string                                                                               $concatOperator
     * @param string                                                                               $section
     *
     * @throws InvalidArgumentException when an array value is given with another operator, or when BETWEEN or
     *                                  NOT BETWEEN is given fewer than two values
     *
     * @return TwoElementsLogicExpression
     */
    protected function assembleTwoElementsLogicExpression(
        $column,
        $operator,
        $value,
        string $concatOperator,
        string $section
    ): TwoElementsLogicExpression {
        $expression = new TwoElementsLogicExpression($this);

        /*
         * If user passed TwoElementsLogicExpression as first argument, then we assume that user has set up himself.
         */
        if ($column instanceof TwoElementsLogicExpression && is_null($value)) {
            return $column;
        }

        if ($column instanceof TwoElementsLogicExpression && $value instanceof TwoElementsLogicExpression) {
            $expression->firstElement($column);
            $expression->secondElement($value);
            $expression->operator($operator);
            $expression->concatOperator($concatOperator);

            return $expression;
        }

        /*
         * If closure, then we pass fresh query builder inside and based on their state after evaluating try to assume
         * what user expects to perform.
         * If resulting query builder have elements corresponding to requested section, then we assume that user wanted
         * to just wrap this in parenthesis, otherwise - subquery.
         */
        if ($column instanceof Closure) {
            $query = tp($this->newNestedQuery(), $column);

            if (is_null($query->getFrom()) && empty($query->getColumns())) {
                $expression->firstElement($query->{"get{$section}"}());
            } else {
                $expression->firstElement(new Expression("({$query->toSql()})"));
            }
        }

        /*
         * If as column was passed builder instance, than we perform subquery in first element position.
         */
        if ($column instanceof BaseBuilder) {
            $expression->firstElementQuery($column);
        }

        /*
         * If builder instance given as value, then we assume that sub-query should be used there.
         */
        if ($value instanceof BaseBuilder || $value instanceof Closure) {
            $expression->secondElementQuery($value);
        }

        /*
         * Set up other parameters if none of them was set up before in TwoElementsLogicExpression object
         */
        if (is_null($expression->getFirstElement()) && !is_null($column)) {
            $expression->firstElement(is_string($column) ? new Identifier($column) : $column);
        }

        if (is_null($expression->getSecondElement()) && !is_null($value)) {
            if (is_array($value) && in_array($operator, [Operator::BETWEEN, Operator::NOT_BETWEEN], true)) {
                if (count($value) < 2) {
                    throw new InvalidArgumentException(sprintf(
                        'The %s operator takes two values, the lower and the upper bound, but the array has %d.',
                        $operator,
                        count($value)
                    ));
                }

                [$lowerBound, $upperBound] = array_map(
                    fn (mixed $bound): mixed => $bound ?? new Expression('NULL'),
                    array_slice(array_values($value), 0, 2)
                );

                $value = (new TwoElementsLogicExpression($this))
                    ->firstElement($lowerBound)
                    ->operator(Operator::AND)
                    ->secondElement($upperBound)
                    ->concatOperator($concatOperator);
            }

            if (is_array($value) && in_array(
                $operator,
                [Operator::IN, Operator::NOT_IN, Operator::GLOBAL_IN, Operator::GLOBAL_NOT_IN],
                true
            )) {
                if (empty($value)) {
                    return (new TwoElementsLogicExpression($this))
                        ->firstElement($this->emptyListCondition($operator))
                        ->concatOperator($concatOperator);
                }

                $value = new Tuple($value);
            }

            $expression->secondElement($value);
        }

        $expression->concatOperator($concatOperator);

        if (is_string($operator)) {
            $expression->operator($operator);
        }

        if (is_array($expression->getSecondElement())) {
            throw new InvalidArgumentException(sprintf(
                '%s does not take a list of values, but an array was given. Use IN or NOT IN, or whereIn(), to compare with a list.',
                is_string($operator) ? "The {$operator} operator" : 'A condition without an operator'
            ));
        }

        return $expression;
    }

    /**
     * Build the bare condition that a comparison with an empty list compiles to, as in Laravel.
     *
     * No value is in an empty list, so IN and GLOBAL IN match no row (0 = 1), and NOT IN and GLOBAL NOT IN match
     * every row (1 = 1). ClickHouse rejects an empty IN ().
     *
     * @param string $operator IN, NOT IN, GLOBAL IN or GLOBAL NOT IN
     *
     * @return Expression
     */
    protected function emptyListCondition(string $operator): Expression
    {
        $matchesEveryRow = in_array($operator, [Operator::NOT_IN, Operator::GLOBAL_NOT_IN], true);

        return new Expression($matchesEveryRow ? '1 = 1' : '0 = 1');
    }

    /**
     * Prepare operator for where and prewhere statement.
     *
     * @param mixed  $value
     * @param string $operator
     * @param bool   $useDefault
     *
     * @return array
     */
    protected function prepareValueAndOperator($value, $operator, $useDefault = false): array
    {
        if ($useDefault) {
            $value = $operator;

            if (is_array($value)) {
                $operator = Operator::IN;
            } else {
                $operator = Operator::EQUALS;
            }

            return [$value, $operator];
        }

        return [$value, $operator];
    }

    /**
     * Prepare the value and operator of a where, prewhere or having statement.
     *
     * With two arguments, the second one is the value, compared with = or, for an array, IN. With more arguments
     * and a null value, an operator argument that is not an operator is the value too, as in Laravel, so
     * where('col', 0, null) is where('col', 0). A null operator is the value only for a column given as a string:
     * where('col', null, null) is where('col', null), while whereRaw() and the other calls with an Expression
     * column and no operator stay bare conditions.
     *
     * A collection value, any Illuminate\Support\Enumerable such as a Collection, LazyCollection or Eloquent
     * collection, is converted to an array. Other Arrayable objects, such as Eloquent models, are not: they reach
     * Grammar::wrap(), which throws, instead of becoming a list of their attribute values. An operator is read in any
     * letter case, as Laravel code writes it, so 'like' and 'not in' become LIKE and NOT IN; a value given as the
     * operator argument is kept as it is. The <> operator is written as !=. When null was passed explicitly as the
     * value of a column comparison, = becomes IS NULL and != or <> becomes IS NOT NULL. Only a column given as a
     * string, Identifier, Expression or Laravel database expression, such as DB::raw(), is compared this way.
     *
     * @param mixed $column
     * @param mixed $operator
     * @param mixed $value
     * @param int   $argumentCount Number of arguments the statement method was called with
     *
     * @throws InvalidArgumentException when the IS NULL or IS NOT NULL operator is given a value
     *
     * @return array{0: mixed, 1: mixed}
     */
    protected function prepareConditionValueAndOperator($column, $operator, $value, int $argumentCount): array
    {
        $operatorIsTheValue = $argumentCount == 2
            || ($argumentCount > 2 && is_null($value) && $this->isValueGivenAsOperator($column, $operator));

        if ($operatorIsTheValue && $operator instanceof Enumerable) {
            $operator = $operator->toArray();
        }

        list($value, $operator) = $this->prepareValueAndOperator($value, $operator, $operatorIsTheValue);

        if ($value instanceof Enumerable) {
            $value = $value->toArray();
        }

        if (is_string($operator) && Operator::isValid(strtoupper($operator))) {
            $operator = strtoupper($operator);
        }

        if ($operator === '<>') {
            $operator = Operator::NOT_EQUALS;
        }

        if (!is_null($value) && is_string($operator)
            && in_array(strtoupper($operator), [Operator::IS_NULL, Operator::IS_NOT_NULL], true)
        ) {
            throw new InvalidArgumentException(sprintf(
                'The %s operator does not take a value, but a value of type %s was given. Pass null as the value, or use whereNull() or whereNotNull().',
                strtoupper($operator),
                get_debug_type($value)
            ));
        }

        $isPlainColumn = is_string($column)
            || $column instanceof Identifier
            || $column instanceof Expression
            || $column instanceof ExpressionContract;

        if ($argumentCount < 2 || !is_null($value) || !$isPlainColumn) {
            return [$value, $operator];
        }

        if ($operator === Operator::EQUALS) {
            return [$value, Operator::IS_NULL];
        }

        if ($operator === Operator::NOT_EQUALS) {
            return [$value, Operator::IS_NOT_NULL];
        }

        return [$value, $operator];
    }

    /**
     * Determine if the operator argument of a where, prewhere or having statement is really its value.
     *
     * A string is an operator when it is <> or an Operator value, in any letter case. Any other value is not.
     * A null operator is the value only for a column given as a string.
     *
     * @param mixed $column
     * @param mixed $operator
     *
     * @return bool
     */
    protected function isValueGivenAsOperator($column, $operator): bool
    {
        if (is_null($operator)) {
            return is_string($column);
        }

        return !$this->isConditionOperator($operator);
    }

    /**
     * Determine if a value is a condition operator: <> or an Operator value, in any letter case.
     *
     * @param mixed $operator
     *
     * @return bool
     */
    protected function isConditionOperator(mixed $operator): bool
    {
        return is_string($operator) && ($operator === '<>' || Operator::isValid(strtoupper($operator)));
    }

    /**
     * Add a where, prewhere or having statement.
     *
     * The statement methods and their or* variants pass the number of arguments they were called with, so a call
     * with only a column stays a bare condition, such as WHERE `flag`, whichever method added it.
     *
     * A closure column is called with a new query (see newNestedQuery()). When that query has no from() and no
     * columns, its conditions of this section are a group, which compiles in parentheses; otherwise the query is a
     * sub-query. A group that adds no condition at all, such as where(fn ($query) => $query), adds nothing, as in
     * Laravel; it used to compile to WHERE (), which ClickHouse rejects. A closure that adds conditions to another
     * section only, such as where() conditions inside a preWhere() closure, still compiles to an empty group, so
     * ClickHouse rejects it instead of the conditions being left out. A closure value is called with a new query
     * too, which is a sub-query: whereIn('id', fn ($query) => $query->select('id')->from('u')).
     *
     * An array column is Laravel's array form of where() (see addArrayOfConditions()), unless it is the list of
     * conditions of a closure group.
     *
     * @param string $section        The statements to add to: wheres, prewheres or havings
     * @param mixed  $column
     * @param mixed  $operator
     * @param mixed  $value
     * @param string $concatOperator
     * @param int    $argumentCount  Number of arguments the statement method was called with
     *
     * @throws InvalidArgumentException when an entry of an array column is of another kind
     *
     * @return static
     */
    protected function addCondition(
        string $section,
        $column,
        $operator,
        $value,
        string $concatOperator,
        int $argumentCount
    ): static {
        if (is_array($column) && !$this->isListOfConditions($column)) {
            return $this->addArrayOfConditions($section, $column, $concatOperator);
        }

        if ($column instanceof Closure) {
            $query = tp($this->newNestedQuery(), $column);

            if ($this->addsNoCondition($query)) {
                return $this;
            }

            $column = is_null($query->getFrom()) && empty($query->getColumns())
                ? $query->{"get{$section}"}()
                : $query;
        }

        list($value, $operator) = $this->prepareConditionValueAndOperator($column, $operator, $value, $argumentCount);

        if ($value instanceof Closure) {
            $value = tp($this->newNestedQuery(), $value);
        }

        $this->{$section}[] = $this->assembleTwoElementsLogicExpression(
            $column,
            $operator,
            $value,
            $concatOperator,
            $section
        );

        return $this;
    }

    /**
     * Determine if the query that a closure given to a where, prewhere or having statement built is an empty group:
     * no from(), no columns and no where, prewhere or having condition.
     *
     * @param BaseBuilder $query
     *
     * @return bool
     */
    protected function addsNoCondition(BaseBuilder $query): bool
    {
        return is_null($query->getFrom())
            && empty($query->getColumns())
            && empty($query->getWheres())
            && empty($query->getPreWheres())
            && empty($query->getHavings());
    }

    /**
     * Determine if an array column of a where, prewhere or having statement is the list of conditions that a closure
     * group built: a list that is not empty and holds only TwoElementsLogicExpression objects.
     *
     * @param array<int|string, mixed> $column
     *
     * @return bool
     */
    protected function isListOfConditions(array $column): bool
    {
        if ($column === []) {
            return false;
        }

        foreach ($column as $condition) {
            if (!$condition instanceof TwoElementsLogicExpression) {
                return false;
            }
        }

        return true;
    }

    /**
     * Add a group of statements from Laravel's array form of where(), as Laravel 11 and later add it.
     *
     * Each entry is a [column, value] or [column, operator, value] array, read as where() reads those arguments, or
     * a column => value pair, compared with =. Every entry is joined to the one before it with the boolean of the
     * call, which an array entry can replace with a fourth value. The group compiles in parentheses:
     * where(['a' => 1, 'b' => null]) compiles to (`a` = 1 AND `b` IS NULL), and orWhere([['a', '>', 1], ['b', 2]])
     * to OR (`a` > 1 OR `b` = 2). An empty list adds nothing, like a closure group that adds no condition.
     *
     * @param string                   $section    The statements to add to: wheres, prewheres or havings
     * @param array<int|string, mixed> $conditions
     * @param string                   $boolean
     *
     * @throws InvalidArgumentException when an entry is neither a non-empty array under an integer key nor a value
     *                                  under a column name
     *
     * @return static
     */
    protected function addArrayOfConditions(string $section, array $conditions, string $boolean): static
    {
        return $this->addCondition($section, function (BaseBuilder $query) use ($section, $conditions, $boolean): void {
            foreach ($conditions as $key => $condition) {
                if (is_int($key) && is_array($condition) && $condition !== []) {
                    $arguments = array_values($condition);

                    $query->addCondition(
                        $section,
                        $arguments[0],
                        $arguments[1] ?? null,
                        $arguments[2] ?? null,
                        $arguments[3] ?? $boolean,
                        min(count($arguments), 3)
                    );
                } elseif (is_string($key)) {
                    $query->addCondition($section, $key, Operator::EQUALS, $condition, $boolean, 3);
                } else {
                    throw new InvalidArgumentException(
                        'where() takes a list of [column, value] or [column, operator, value] arrays, or of column => value pairs.'
                    );
                }
            }
        }, null, null, $boolean, 1);
    }

    /**
     * Get a new query for a closure nested in this query: a group or a sub-query of a condition, the sub-query of
     * whereExists(), a WITH clause entry or an operand of a set operation.
     *
     * The new query gets the names of the WITH clause queries around it that use INTERSECT or EXCEPT, as far as
     * they were added before the closure is called (see intersectOrExceptNamesInScope()), so that its whereExists()
     * calls compile a sub-query that reads such a query by its name as compileExistsSubQuery() describes.
     *
     * @return BaseBuilder
     */
    protected function newNestedQuery(): BaseBuilder
    {
        $query = $this->newQuery();
        $query->outerIntersectOrExceptNames = $this->intersectOrExceptNamesInScope();

        return $query;
    }

    /**
     * Get the names by which this query can read a query that uses INTERSECT or EXCEPT: those of its own
     * withExpression() and withRecursiveExpression() entries given a builder or a closure for which
     * usesIntersectOrExcept() is true, and those of the queries it is nested in (see newNestedQuery()).
     *
     * @return array<int, string>
     */
    protected function intersectOrExceptNamesInScope(): array
    {
        $names = $this->outerIntersectOrExceptNames;

        foreach ($this->getWiths() as $with) {
            if ($with['type'] === self::WITH_EXPRESSION
                && $with['value'] instanceof BaseBuilder
                && static::usesIntersectOrExcept($with['value'])
            ) {
                $names[] = $with['name'];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Make a column operand of a comparison of columns: a string is a column name, quoted part by part like the
     * column of where(), and any other value, such as an Expression or a Laravel database expression, is kept as it
     * is.
     *
     * @param mixed $column
     *
     * @return mixed
     */
    protected function columnOperand(mixed $column): mixed
    {
        return is_string($column) ? new Identifier($column) : $column;
    }

    /**
     * Add a where or prewhere statement that compares two columns (see whereColumn()).
     *
     * @param string                                                              $section       The statements to add to: wheres or prewheres
     * @param string|array<int|string, mixed>|Expression|ExpressionContract|null $first
     * @param mixed                                                               $operator
     * @param mixed                                                               $second
     * @param string                                                              $boolean
     * @param int                                                                 $argumentCount Number of arguments the method was called with
     *
     * @throws InvalidArgumentException when a column is missing, or the list is empty or has an entry of another kind
     *
     * @return static
     */
    protected function addColumnComparison(
        string $section,
        mixed $first,
        mixed $operator,
        mixed $second,
        string $boolean,
        int $argumentCount
    ): static {
        if (is_array($first)) {
            return $this->addColumnComparisons($section, $first, $boolean);
        }

        if ($argumentCount === 2 || (is_null($second) && !$this->isConditionOperator($operator))) {
            [$second, $operator] = [$operator, Operator::EQUALS];
        }

        if (is_null($first) || is_null($second)) {
            throw new InvalidArgumentException('whereColumn() compares two columns, but only one was given.');
        }

        return $this->addCondition(
            $section,
            $this->columnOperand($first),
            $operator ?? Operator::EQUALS,
            $this->columnOperand($second),
            $boolean,
            3
        );
    }

    /**
     * Add a group of comparisons of columns from the list that whereColumn() takes.
     *
     * Each entry is a [first, second] or [first, operator, second] array, or a first => second pair. As in Laravel 11
     * and later, every entry is joined to the comparison before it with the boolean of the call, which an array
     * entry can replace with a fourth value.
     *
     * @param string                   $section     The statements to add to: wheres or prewheres
     * @param array<int|string, mixed> $comparisons
     * @param string                   $boolean     The boolean that joins the group to the statements before it,
     *                                              and each comparison to the one before it
     *
     * @throws InvalidArgumentException when the list is empty, has an entry of another kind, or an entry misses a column
     *
     * @return static
     */
    protected function addColumnComparisons(string $section, array $comparisons, string $boolean): static
    {
        if ($comparisons === []) {
            throw new InvalidArgumentException('whereColumn() needs at least one pair of columns, but an empty list was given.');
        }

        return $this->addCondition($section, function (BaseBuilder $query) use ($section, $comparisons, $boolean): void {
            foreach ($comparisons as $key => $comparison) {
                if (is_int($key) && is_array($comparison)) {
                    $arguments = array_values($comparison);

                    $query->addColumnComparison(
                        $section,
                        $arguments[0] ?? null,
                        $arguments[1] ?? null,
                        $arguments[2] ?? null,
                        $arguments[3] ?? $boolean,
                        min(count($arguments), 3)
                    );
                } elseif (is_string($key) && !is_array($comparison)) {
                    $query->addColumnComparison($section, $key, $comparison, null, $boolean, 2);
                } else {
                    throw new InvalidArgumentException(
                        'whereColumn() takes a list of [first, second] or [first, operator, second] arrays, or of first => second pairs.'
                    );
                }
            }
        }, null, null, $boolean, 1);
    }

    /**
     * Add an EXISTS or NOT EXISTS statement on a sub-query (see whereExists()).
     *
     * @param string              $section The statements to add to: wheres, prewheres or havings
     * @param Closure|BaseBuilder $query
     * @param string              $boolean
     * @param bool                $not
     *
     * @throws InvalidArgumentException when the query is neither a closure nor a query builder of this package
     *
     * @return static
     */
    protected function addExistsCondition(string $section, mixed $query, string $boolean, bool $not): static
    {
        if ($query instanceof Closure) {
            $query = tp($this->newNestedQuery(), $query);
        }

        if (!$query instanceof BaseBuilder) {
            throw new InvalidArgumentException(sprintf(
                'whereExists() takes a closure or a query builder of this package, but a value of type %s was given.',
                get_debug_type($query)
            ));
        }

        $sql = static::compileExistsSubQuery($query, $this->intersectOrExceptNamesInScope());

        return $this->addCondition($section, new Expression(($not ? 'NOT ' : '')."EXISTS ({$sql})"), null, null, $boolean, 1);
    }

    /**
     * Compile the sub-query of an EXISTS statement so that ClickHouse compares every column of its INTERSECT and
     * EXCEPT queries.
     *
     * ClickHouse (24.8 checked) runs EXISTS (<sub-query>) as 1 IN (SELECT 1 FROM (<sub-query>) LIMIT 1), and, with
     * the analyzer on, removes the columns that a query does not read from the queries that INTERSECT and EXCEPT
     * compare. EXISTS over a sub-query that uses INTERSECT or EXCEPT, or that reads rows from one, then compares too
     * few columns: EXISTS (<a> EXCEPT <b>) was false while <a> EXCEPT <b> returned a row. So:
     * - a sub-query, or a query combined with it, that reads rows from a query that uses INTERSECT or EXCEPT gets
     *   WHERE NOT ignore(*) in a copy, which reads every column of its FROM clause and its joins, as
     *   Builder::exists() does (see readsAnIntersectOrExceptSource()). Such a query is a sub-query in from() or a
     *   join, a withExpression() of its own, or a table in from() or a join named after a WITH clause query of the
     *   outer queries, whose names $intersectOrExceptNames lists;
     * - a sub-query whose set operations include INTERSECT or EXCEPT is wrapped as SELECT * FROM (<sub-query>)
     *   WHERE NOT ignore(*), which reads every column it returns.
     * Any other sub-query is compiled as it is. NOT ignore(*) is true for every row.
     *
     * @param BaseBuilder        $query
     * @param array<int, string> $intersectOrExceptNames Names of the WITH clause queries that use INTERSECT or EXCEPT,
     *                                                   of the queries the sub-query is nested in
     *
     * @return string
     */
    protected static function compileExistsSubQuery(BaseBuilder $query, array $intersectOrExceptNames = []): string
    {
        $sql = static::readEveryColumnOfIntersectOrExceptSources($query, $intersectOrExceptNames)->toSql();

        return static::combinesWithIntersectOrExcept($query) ? "SELECT * FROM ({$sql}) WHERE NOT ignore(*)" : $sql;
    }

    /**
     * Copy a query and each query combined with it, adding WHERE NOT ignore(*) to those that read rows from a query
     * that uses INTERSECT or EXCEPT (see compileExistsSubQuery()).
     *
     * @param BaseBuilder        $query
     * @param array<int, string> $intersectOrExceptNames Names of the WITH clause queries that use INTERSECT or EXCEPT,
     *                                                   of the queries the query is nested in
     *
     * @return BaseBuilder
     */
    protected static function readEveryColumnOfIntersectOrExceptSources(BaseBuilder $query, array $intersectOrExceptNames = []): BaseBuilder
    {
        $copy = clone $query;
        $copy->unions = array_map(
            fn (BaseBuilder $union): BaseBuilder => static::readEveryColumnOfIntersectOrExceptSources($union, $intersectOrExceptNames),
            $copy->unions
        );

        return static::readsAnIntersectOrExceptSource($copy, $intersectOrExceptNames) ? $copy->whereRaw('NOT ignore(*)') : $copy;
    }

    /**
     * Determine if a query, leaving out the queries combined with it, reads rows from a query that uses INTERSECT or
     * EXCEPT: a sub-query in from() or a join, or a withExpression() of its own, for which usesIntersectOrExcept() is
     * true, or a table in from() or a join whose name, without its alias, is one of the given names.
     *
     * @param BaseBuilder        $query
     * @param array<int, string> $intersectOrExceptNames Names of the WITH clause queries that use INTERSECT or EXCEPT,
     *                                                   of the queries the query is nested in
     *
     * @return bool
     */
    protected static function readsAnIntersectOrExceptSource(BaseBuilder $query, array $intersectOrExceptNames): bool
    {
        $sources = [$query->getFrom()?->getQueryBuilder()];
        $tables = [$query->getFrom()?->getTable()];

        foreach ($query->getJoins() ?? [] as $join) {
            $sources[] = $join->getQueryBuilder();
            $tables[] = $join->getTable();
        }

        foreach ($query->getWiths() as $with) {
            if ($with['type'] === self::WITH_EXPRESSION && $with['value'] instanceof BaseBuilder) {
                $sources[] = $with['value'];
            }
        }

        foreach ($sources as $source) {
            if ($source !== null && static::usesIntersectOrExcept($source)) {
                return true;
            }
        }

        foreach ($tables as $table) {
            if ($table instanceof Identifier
                && in_array(trim(preg_split('/\s+as\s+/i', (string) $table)[0]), $intersectOrExceptNames, true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the set operations of a query, or of a query combined with it, at any depth, include INTERSECT or
     * EXCEPT.
     *
     * @param BaseBuilder $query
     *
     * @return bool
     */
    protected static function combinesWithIntersectOrExcept(BaseBuilder $query): bool
    {
        $intersectOrExcept = [UnionType::INTERSECT, UnionType::INTERSECT_DISTINCT, UnionType::EXCEPT, UnionType::EXCEPT_DISTINCT];

        foreach ($query->getUnionTypes() as $index => $type) {
            if (in_array($type, $intersectOrExcept, true) || static::combinesWithIntersectOrExcept($query->getUnions()[$index])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a group of statements, one per column, with the same operator and value (see whereAll(), whereAny() and
     * whereNone()).
     *
     * Each column is added as the statement method adds it, with at most three arguments, so null, a list, <>, an
     * operator in lower case and raw SQL work as in where(), and the two-argument form keeps its meaning. The group
     * compiles in parentheses, after NOT when $not is true.
     *
     * @param string                                           $section       The statements to add to: wheres or prewheres
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @param mixed                                            $operator
     * @param mixed                                            $value
     * @param string                                           $boolean       The boolean that joins the group to the statements before it
     * @param string                                           $columnBoolean The boolean that joins the statements of the group
     * @param bool                                             $not
     * @param int                                              $argumentCount Number of arguments the method was called with
     *
     * @throws InvalidArgumentException when no column is given, because a group without a condition left out of a
     *                                  delete() or update() would change more rows
     *
     * @return static
     */
    protected function addMultiColumnCondition(
        string $section,
        array $columns,
        mixed $operator,
        mixed $value,
        string $boolean,
        string $columnBoolean,
        bool $not,
        int $argumentCount
    ): static {
        if ($columns === []) {
            throw new InvalidArgumentException('whereAll(), whereAny() and whereNone() need at least one column.');
        }

        $group = $this->newNestedQuery();

        foreach ($columns as $column) {
            $group->addCondition($section, $column, $operator, $value, $columnBoolean, min($argumentCount, 3));
        }

        $conditions = $group->{"get{$section}"}();
        $expression = new TwoElementsLogicExpression($this);

        if ($not) {
            $expression->firstElement(new Expression('NOT'))->secondElement($conditions);
        } else {
            $expression->firstElement($conditions);
        }

        $this->{$section}[] = $expression->concatOperator($boolean);

        return $this;
    }

    /**
     * Add a statement on a part of the date or time of a column (see whereDate(), whereTime(), whereDay(),
     * whereMonth() and whereYear()).
     *
     * As in Laravel, the operator argument is the value, compared with =, when the method is called with two
     * arguments, or when the value is null and the operator argument is no operator. A null value then compares with
     * IS NULL or IS NOT NULL, as where() does. A list of values works with IN, NOT IN, BETWEEN and NOT BETWEEN, and
     * each value in it is written like a single value (see datePartValue()). A column given as a string is a name,
     * quoted part by part like the column of where(); an Expression or a Laravel database expression is raw SQL.
     *
     * @param string                               $section       The statements to add to: wheres or prewheres
     * @param string                               $part          Date, Time, Day, Month or Year
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     * @param string                               $boolean
     * @param int                                  $argumentCount Number of arguments the method was called with
     *
     * @throws InvalidArgumentException when a value cannot be written for the part
     *
     * @return static
     */
    protected function addDatePartCondition(
        string $section,
        string $part,
        string|Expression|ExpressionContract $column,
        mixed $operator,
        mixed $value,
        string $boolean,
        int $argumentCount
    ): static {
        if ($argumentCount === 2 || (is_null($value) && !$this->isConditionOperator($operator))) {
            [$value, $operator] = [$operator, Operator::EQUALS];
        }

        $column = $this->grammar->wrap(is_string($column) ? new Identifier($column) : $column);

        $function = match ($part) {
            'Date' => "toDate32({$column})",
            'Time' => "formatDateTime({$column}, '%H:%i:%S')",
            'Day' => "toDayOfMonth({$column})",
            'Month' => "toMonth({$column})",
            'Year' => "toYear({$column})",
        };

        return $this->addCondition(
            $section,
            new Expression($function),
            $operator,
            $this->datePartValue($part, $value),
            $boolean,
            3
        );
    }

    /**
     * Write a value of a statement on a part of the date or time of a column.
     *
     * - null, an Expression and a Laravel database expression are kept as they are;
     * - a list, or a collection, which becomes an array, has each of its values written by this method, with its
     *   keys kept;
     * - Date: a DateTimeInterface becomes its date, 'Y-m-d', in its own time zone; any other value is kept;
     * - Time: a DateTimeInterface becomes its time of day, 'H:i:s', in its own time zone, and a string or Stringable
     *   time of day, H:i or H:i:s with an optional fraction of a second, becomes 'HH:MM:SS', without the fraction;
     * - Day, Month and Year: a DateTimeInterface becomes the integer of its day of the month, month or year, in its
     *   own time zone, a string or Stringable of digits becomes its integer, and an integer is kept.
     *
     * @param string $part  Date, Time, Day, Month or Year
     * @param mixed  $value
     *
     * @throws InvalidArgumentException when a value of a time of day, a day, a month or a year is of another kind
     *
     * @return mixed
     */
    protected function datePartValue(string $part, mixed $value): mixed
    {
        if ($value instanceof Enumerable) {
            $value = $value->toArray();
        }

        if (is_array($value)) {
            return array_map(fn (mixed $element): mixed => $this->datePartValue($part, $element), $value);
        }

        if (is_null($value) || $value instanceof Expression || $value instanceof ExpressionContract) {
            return $value;
        }

        if ($part === 'Date') {
            return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        if ($part === 'Time') {
            return $this->timeOfDayValue($value);
        }

        return $this->datePartNumberValue($part, $value);
    }

    /**
     * Write the value of a whereTime() statement as 'HH:MM:SS' (see datePartValue()).
     *
     * @param mixed $value
     *
     * @throws InvalidArgumentException when the value is not a time of day or a DateTimeInterface
     *
     * @return string
     */
    protected function timeOfDayValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i:s');
        }

        if ((is_string($value) || $value instanceof Stringable)
            && preg_match('/\A(\d{1,2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?\z/', (string) $value, $matches) === 1
        ) {
            return sprintf('%02d:%s:%s', $matches[1], $matches[2], $matches[3] ?? '00');
        }

        throw new InvalidArgumentException(sprintf(
            "whereTime() takes a time of day, such as '10:20' or '10:20:30', or a date, but %s was given.",
            $this->describeDatePartValue($value)
        ));
    }

    /**
     * Write the value of a whereDay(), whereMonth() or whereYear() statement as an integer (see datePartValue()).
     *
     * @param string $part  Day, Month or Year
     * @param mixed  $value
     *
     * @throws InvalidArgumentException when the value is not an integer, a string of digits or a DateTimeInterface
     *
     * @return int
     */
    protected function datePartNumberValue(string $part, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return (int) $value->format(['Day' => 'j', 'Month' => 'n', 'Year' => 'Y'][$part]);
        }

        if ((is_string($value) || $value instanceof Stringable) && preg_match('/\A\d+\z/', (string) $value) === 1) {
            return (int) (string) $value;
        }

        throw new InvalidArgumentException(sprintf(
            'where%s() takes an integer, a string of digits or a date, but %s was given.',
            $part,
            $this->describeDatePartValue($value)
        ));
    }

    /**
     * Describe a value that a statement on a part of a date cannot take, for the message of its exception.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function describeDatePartValue(mixed $value): string
    {
        if (is_string($value) || $value instanceof Stringable) {
            return "'".$value."'";
        }

        return 'a value of type '.get_debug_type($value);
    }

    /**
     * Determine if the query, a query combined with it, or a sub-query it reads rows from uses INTERSECT or EXCEPT,
     * at any depth.
     *
     * The sub-queries are those of from(), of each join, and of each withExpression() or withRecursiveExpression()
     * given a builder or a closure, which the query can select from by its name.
     *
     * @param BaseBuilder $query
     *
     * @return bool
     */
    protected static function usesIntersectOrExcept(BaseBuilder $query): bool
    {
        $intersectOrExcept = [UnionType::INTERSECT, UnionType::INTERSECT_DISTINCT, UnionType::EXCEPT, UnionType::EXCEPT_DISTINCT];

        foreach ($query->getUnionTypes() as $index => $type) {
            if (in_array($type, $intersectOrExcept, true) || static::usesIntersectOrExcept($query->getUnions()[$index])) {
                return true;
            }
        }

        $subQueries = [];

        foreach ($query->getWiths() as $with) {
            if ($with['type'] === self::WITH_EXPRESSION && $with['value'] instanceof BaseBuilder) {
                $subQueries[] = $with['value'];
            }
        }

        $subQueries[] = $query->getFrom()?->getQueryBuilder();

        foreach ($query->getJoins() ?? [] as $join) {
            $subQueries[] = $join->getQueryBuilder();
        }

        foreach ($subQueries as $subQuery) {
            if ($subQuery !== null && static::usesIntersectOrExcept($subQuery)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize the columns given to a null or empty check to a list.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     *
     * @return array<int, string|Expression>
     */
    protected function columnsToList($columns): array
    {
        return is_array($columns) ? $columns : [$columns];
    }

    /**
     * Build the empty(<column>) or notEmpty(<column>) expression.
     *
     * @param string|Expression $column
     * @param bool              $not
     *
     * @return Expression
     */
    protected function emptyCheckExpression($column, bool $not): Expression
    {
        $function = $not ? 'notEmpty' : 'empty';
        $column = $this->grammar->wrap(is_string($column) ? new Identifier($column) : $column);

        return new Expression("{$function}({$column})");
    }

    /**
     * Add prewhere statement.
     *
     * The column and the value can be raw SQL, as an Expression or a Laravel database expression such as DB::raw(),
     * which is written as raw() is: preWhere(DB::raw('a + 1'), 2) compiles to PREWHERE a + 1 = 2. The operator
     * and the concatenation operator are read in any letter case.
     *
     * @param TwoElementsLogicExpression|self|Closure|string|Expression|ExpressionContract|array $column
     * @param mixed                                                                              $operator
     * @param mixed                                                                              $value
     * @param string                                                                             $concatOperator
     *
     * @return static
     */
    public function preWhere($column, $operator = null, $value = null, string $concatOperator = Operator::AND)
    {
        return $this->addCondition('prewheres', $column, $operator, $value, $concatOperator, func_num_args());
    }

    /**
     * Add prewhere statement "as is".
     *
     * @param string $expression
     *
     * @return static
     */
    public function preWhereRaw(string $expression)
    {
        return $this->preWhere(new Expression($expression));
    }

    /**
     * Add prewhere statement "as is", but with OR operator.
     *
     * @param string $expression
     *
     * @return static
     */
    public function orPreWhereRaw(string $expression)
    {
        return $this->preWhere(new Expression($expression), null, null, Operator::OR);
    }

    /**
     * Add prewhere statement but with OR operator.
     *
     * Takes the same column, operator and value as preWhere().
     *
     * @param TwoElementsLogicExpression|self|Closure|string|Expression|ExpressionContract|array $column
     * @param mixed                                                                              $operator
     * @param mixed                                                                              $value
     *
     * @return static
     */
    public function orPreWhere($column, $operator = null, $value = null)
    {
        return $this->addCondition('prewheres', $column, $operator, $value, Operator::OR, func_num_args());
    }

    /**
     * Add prewhere statement with IN operator.
     *
     * An Arrayable list of values, such as a collection, is converted to an array first. An empty list adds the
     * bare condition 0 = 1, or 1 = 1 with NOT IN, with the given boolean, as in Laravel.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return static
     */
    public function preWhereIn($column, $values, $boolean = Operator::AND, $not = false)
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $type = $not ? Operator::NOT_IN : Operator::IN;

        if (is_array($values)) {
            if (empty($values)) {
                return $this->preWhere($this->emptyListCondition($type), null, null, $boolean);
            }

            $values = new Tuple($values);
        }

        return $this->preWhere($column, $type, $values, $boolean);
    }

    /**
     * Add prewhere statement with IN operator and OR operator.
     *
     * @param $column
     * @param $values
     *
     * @return static
     */
    public function orPreWhereIn($column, $values)
    {
        return $this->preWhereIn($column, $values, Operator::OR);
    }

    /**
     * Add prewhere statement with NOT IN operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static
     */
    public function preWhereNotIn($column, $values, $boolean = Operator::AND)
    {
        return $this->preWhereIn($column, $values, $boolean, true);
    }

    /**
     * Add prewhere statement with NOT IN operator and OR operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static
     */
    public function orPreWhereNotIn($column, $values, $boolean = Operator::OR)
    {
        return $this->preWhereNotIn($column, $values, $boolean);
    }

    /**
     * Add prewhere statement with BETWEEN simulation.
     *
     * The values are read as preWhere() reads a BETWEEN list: its first two values, in order and whatever their
     * keys, are the lower and the upper bound, so ['from' => 1, 'to' => 2] works, and a list of fewer than two
     * values throws.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @throws InvalidArgumentException when the list has fewer than two values
     *
     * @return static
     */
    public function preWhereBetween($column, array $values, $boolean = Operator::AND, $not = false)
    {
        $type = $not ? Operator::NOT_BETWEEN : Operator::BETWEEN;

        return $this->preWhere($column, $type, $values, $boolean);
    }

    /**
     * Add prewhere statement with BETWEEN simulation, but with column names as value.
     *
     * Each value given as a string is a column name; an Expression or a Laravel database expression is raw SQL. The
     * list is read as preWhereBetween() reads it.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @throws InvalidArgumentException when the list has fewer than two values
     *
     * @return static
     */
    public function preWhereBetweenColumns($column, array $values, $boolean = Operator::AND, $not = false)
    {
        $type = $not ? Operator::NOT_BETWEEN : Operator::BETWEEN;

        return $this->preWhere($column, $type, array_map($this->columnOperand(...), $values), $boolean);
    }

    /**
     * Add prewhere statement with NOT BETWEEN simulation, but with column names as value.
     *
     * The list is read as preWhereBetweenColumns() reads it.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     *
     * @throws InvalidArgumentException when the list has fewer than two values
     *
     * @return static
     */
    public function preWhereNotBetweenColumns($column, array $values, $boolean = Operator::AND)
    {
        return $this->preWhereBetweenColumns($column, $values, $boolean, true);
    }

    /**
     * Add prewhere statement with BETWEEN simulation, but with column names as value and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static
     */
    public function orPreWhereBetweenColumns($column, array $values)
    {
        return $this->preWhereBetweenColumns($column, $values, Operator::OR);
    }

    /**
     * Add prewhere statement with NOT BETWEEN simulation, but with column names as value and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static
     */
    public function orPreWhereNotBetweenColumns($column, array $values)
    {
        return $this->preWhereNotBetweenColumns($column, $values, Operator::OR);
    }

    /**
     * Add prewhere statement with BETWEEN simulation and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static
     */
    public function orPreWhereBetween($column, array $values)
    {
        return $this->preWhereBetween($column, $values, Operator::OR);
    }

    /**
     * Add prewhere statement with NOT BETWEEN simulation.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     *
     * @return static
     */
    public function preWhereNotBetween($column, array $values, $boolean = Operator::AND)
    {
        return $this->preWhereBetween($column, $values, $boolean, true);
    }

    /**
     * Add prewhere statement with NOT BETWEEN simulation and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static
     */
    public function orPreWhereNotBetween($column, array $values)
    {
        return $this->preWhereNotBetween($column, $values, Operator::OR);
    }

    /**
     * Add prewhere statement with IS NULL check.
     *
     * An array adds one statement per column, joined with the given boolean.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     * @param string                                          $boolean
     * @param bool                                            $not
     *
     * @return static
     */
    public function preWhereNull($columns, string $boolean = Operator::AND, bool $not = false): static
    {
        foreach ($this->columnsToList($columns) as $column) {
            $this->preWhere($column, $not ? Operator::IS_NOT_NULL : Operator::IS_NULL, null, $boolean);
        }

        return $this;
    }

    /**
     * Add prewhere statement with IS NULL check and OR operator.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     *
     * @return static
     */
    public function orPreWhereNull($columns): static
    {
        return $this->preWhereNull($columns, Operator::OR);
    }

    /**
     * Add prewhere statement with IS NOT NULL check.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     * @param string                                          $boolean
     *
     * @return static
     */
    public function preWhereNotNull($columns, string $boolean = Operator::AND): static
    {
        return $this->preWhereNull($columns, $boolean, true);
    }

    /**
     * Add prewhere statement with IS NOT NULL check and OR operator.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     *
     * @return static
     */
    public function orPreWhereNotNull($columns): static
    {
        return $this->preWhereNotNull($columns, Operator::OR);
    }

    /**
     * Add prewhere statement with empty() check.
     *
     * An array adds one statement per column, joined with the given boolean.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     * @param string                                          $boolean
     * @param bool                                            $not     Check with notEmpty() instead
     *
     * @return static
     */
    public function preWhereEmpty($columns, string $boolean = Operator::AND, bool $not = false): static
    {
        foreach ($this->columnsToList($columns) as $column) {
            $this->preWhere($this->emptyCheckExpression($column, $not), null, null, $boolean);
        }

        return $this;
    }

    /**
     * Add prewhere statement with empty() check and OR operator.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     *
     * @return static
     */
    public function orPreWhereEmpty($columns): static
    {
        return $this->preWhereEmpty($columns, Operator::OR);
    }

    /**
     * Add prewhere statement with notEmpty() check.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     * @param string                                          $boolean
     *
     * @return static
     */
    public function preWhereNotEmpty($columns, string $boolean = Operator::AND): static
    {
        return $this->preWhereEmpty($columns, $boolean, true);
    }

    /**
     * Add prewhere statement with notEmpty() check and OR operator.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     *
     * @return static
     */
    public function orPreWhereNotEmpty($columns): static
    {
        return $this->preWhereNotEmpty($columns, Operator::OR);
    }

    /**
     * Add a prewhere statement that compares two columns.
     *
     * Takes the same columns, operator and boolean as whereColumn(): preWhereColumn('a', 'b') compiles to PREWHERE
     * `a` = `b`.
     *
     * @param string|array<int|string, mixed>|Expression|ExpressionContract $first
     * @param mixed                                                          $operator
     * @param string|Expression|ExpressionContract|null                      $second
     * @param string                                                         $boolean
     *
     * @throws InvalidArgumentException when a column is missing, or the list is empty or has an entry of another kind
     *
     * @return static
     */
    public function preWhereColumn(
        string|array|Expression|ExpressionContract $first,
        mixed $operator = null,
        string|Expression|ExpressionContract|null $second = null,
        string $boolean = Operator::AND
    ): static {
        return $this->addColumnComparison('prewheres', $first, $operator, $second, $boolean, func_num_args());
    }

    /**
     * Add a prewhere statement that compares two columns, with OR operator (see whereColumn()).
     *
     * @param string|array<int|string, mixed>|Expression|ExpressionContract $first
     * @param mixed                                                          $operator
     * @param string|Expression|ExpressionContract|null                      $second
     *
     * @throws InvalidArgumentException when a column is missing, or the list is empty or has an entry of another kind
     *
     * @return static
     */
    public function orPreWhereColumn(
        string|array|Expression|ExpressionContract $first,
        mixed $operator = null,
        string|Expression|ExpressionContract|null $second = null
    ): static {
        return $this->addColumnComparison('prewheres', $first, $operator, $second, Operator::OR, func_num_args());
    }

    /**
     * Add a prewhere statement that holds when every one of the columns matches (see whereAll()).
     *
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @param mixed                                            $operator
     * @param mixed                                            $value
     * @param string                                           $boolean
     *
     * @throws InvalidArgumentException when no column is given
     *
     * @return static
     */
    public function preWhereAll(array $columns, mixed $operator = null, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addMultiColumnCondition('prewheres', $columns, $operator, $value, $boolean, Operator::AND, false, func_num_args());
    }

    /**
     * Add a prewhere statement that holds when every one of the columns matches, with OR operator (see whereAll()).
     *
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @param mixed                                            $operator
     * @param mixed                                            $value
     *
     * @throws InvalidArgumentException when no column is given
     *
     * @return static
     */
    public function orPreWhereAll(array $columns, mixed $operator = null, mixed $value = null): static
    {
        return $this->addMultiColumnCondition('prewheres', $columns, $operator, $value, Operator::OR, Operator::AND, false, func_num_args());
    }

    /**
     * Add a prewhere statement that holds when any of the columns matches (see whereAny()).
     *
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @param mixed                                            $operator
     * @param mixed                                            $value
     * @param string                                           $boolean
     *
     * @throws InvalidArgumentException when no column is given
     *
     * @return static
     */
    public function preWhereAny(array $columns, mixed $operator = null, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addMultiColumnCondition('prewheres', $columns, $operator, $value, $boolean, Operator::OR, false, func_num_args());
    }

    /**
     * Add a prewhere statement that holds when any of the columns matches, with OR operator (see whereAny()).
     *
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @param mixed                                            $operator
     * @param mixed                                            $value
     *
     * @throws InvalidArgumentException when no column is given
     *
     * @return static
     */
    public function orPreWhereAny(array $columns, mixed $operator = null, mixed $value = null): static
    {
        return $this->addMultiColumnCondition('prewheres', $columns, $operator, $value, Operator::OR, Operator::OR, false, func_num_args());
    }

    /**
     * Add a prewhere statement that holds when none of the columns matches (see whereNone()).
     *
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @param mixed                                            $operator
     * @param mixed                                            $value
     * @param string                                           $boolean
     *
     * @throws InvalidArgumentException when no column is given
     *
     * @return static
     */
    public function preWhereNone(array $columns, mixed $operator = null, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addMultiColumnCondition('prewheres', $columns, $operator, $value, $boolean, Operator::OR, true, func_num_args());
    }

    /**
     * Add a prewhere statement that holds when none of the columns matches, with OR operator (see whereNone()).
     *
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @param mixed                                            $operator
     * @param mixed                                            $value
     *
     * @throws InvalidArgumentException when no column is given
     *
     * @return static
     */
    public function orPreWhereNone(array $columns, mixed $operator = null, mixed $value = null): static
    {
        return $this->addMultiColumnCondition('prewheres', $columns, $operator, $value, Operator::OR, Operator::OR, true, func_num_args());
    }

    /**
     * Add a prewhere statement on the date of a column (see whereDate()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     * @param string                               $boolean
     *
     * @return static
     */
    public function preWhereDate(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addDatePartCondition('prewheres', 'Date', $column, $operator, $value, $boolean, func_num_args());
    }

    /**
     * Add a prewhere statement on the date of a column, with OR operator (see whereDate()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     *
     * @return static
     */
    public function orPreWhereDate(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDatePartCondition('prewheres', 'Date', $column, $operator, $value, Operator::OR, func_num_args());
    }

    /**
     * Add a prewhere statement on the time of day of a column (see whereTime()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     * @param string                               $boolean
     *
     * @throws InvalidArgumentException when a value is not a time of day, a date or raw SQL
     *
     * @return static
     */
    public function preWhereTime(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addDatePartCondition('prewheres', 'Time', $column, $operator, $value, $boolean, func_num_args());
    }

    /**
     * Add a prewhere statement on the time of day of a column, with OR operator (see whereTime()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     *
     * @throws InvalidArgumentException when a value is not a time of day, a date or raw SQL
     *
     * @return static
     */
    public function orPreWhereTime(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDatePartCondition('prewheres', 'Time', $column, $operator, $value, Operator::OR, func_num_args());
    }

    /**
     * Add a prewhere statement on the day of the month of a column (see whereDay()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     * @param string                               $boolean
     *
     * @throws InvalidArgumentException when a value is not an integer, a string of digits, a date or raw SQL
     *
     * @return static
     */
    public function preWhereDay(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addDatePartCondition('prewheres', 'Day', $column, $operator, $value, $boolean, func_num_args());
    }

    /**
     * Add a prewhere statement on the day of the month of a column, with OR operator (see whereDay()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     *
     * @throws InvalidArgumentException when a value is not an integer, a string of digits, a date or raw SQL
     *
     * @return static
     */
    public function orPreWhereDay(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDatePartCondition('prewheres', 'Day', $column, $operator, $value, Operator::OR, func_num_args());
    }

    /**
     * Add a prewhere statement on the month of a column (see whereMonth()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     * @param string                               $boolean
     *
     * @throws InvalidArgumentException when a value is not an integer, a string of digits, a date or raw SQL
     *
     * @return static
     */
    public function preWhereMonth(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addDatePartCondition('prewheres', 'Month', $column, $operator, $value, $boolean, func_num_args());
    }

    /**
     * Add a prewhere statement on the month of a column, with OR operator (see whereMonth()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     *
     * @throws InvalidArgumentException when a value is not an integer, a string of digits, a date or raw SQL
     *
     * @return static
     */
    public function orPreWhereMonth(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDatePartCondition('prewheres', 'Month', $column, $operator, $value, Operator::OR, func_num_args());
    }

    /**
     * Add a prewhere statement on the year of a column (see whereYear()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     * @param string                               $boolean
     *
     * @throws InvalidArgumentException when a value is not an integer, a string of digits, a date or raw SQL
     *
     * @return static
     */
    public function preWhereYear(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addDatePartCondition('prewheres', 'Year', $column, $operator, $value, $boolean, func_num_args());
    }

    /**
     * Add a prewhere statement on the year of a column, with OR operator (see whereYear()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     *
     * @throws InvalidArgumentException when a value is not an integer, a string of digits, a date or raw SQL
     *
     * @return static
     */
    public function orPreWhereYear(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDatePartCondition('prewheres', 'Year', $column, $operator, $value, Operator::OR, func_num_args());
    }

    /**
     * Add where statement.
     *
     * The column and the value can be raw SQL, as an Expression or a Laravel database expression such as DB::raw(),
     * which is written as raw() is: where(DB::raw('a + 1'), 2) compiles to WHERE a + 1 = 2, and where('a',
     * DB::raw('b')) to WHERE `a` = b. The operator and the concatenation operator are read in any letter case, as
     * Laravel code writes them: where('name', 'like', 'a%') compiles to `name` LIKE 'a%'.
     *
     * An array column is Laravel's array form, a group of [column, value] or [column, operator, value] arrays, or of
     * column => value pairs, joined with the concatenation operator (see addArrayOfConditions()): where(['a' => 1,
     * 'b' => 2]) compiles to WHERE (`a` = 1 AND `b` = 2). preWhere(), having() and their or variants take it too.
     *
     * @param TwoElementsLogicExpression|string|Closure|self|Expression|ExpressionContract|array $column
     * @param mixed                                                                              $operator
     * @param mixed                                                                              $value
     * @param string                                                                             $concatOperator
     *
     * @return static
     */
    public function where($column, $operator = null, $value = null, string $concatOperator = Operator::AND)
    {
        return $this->addCondition('wheres', $column, $operator, $value, $concatOperator, func_num_args());
    }

    /**
     * Add where statement "as is".
     *
     * @param string $expression
     *
     * @return static
     */
    public function whereRaw(string $expression)
    {
        return $this->where(new Expression($expression));
    }

    /**
     * Add where statement "as is" with OR operator.
     *
     * @param string $expression
     *
     * @return static
     */
    public function orWhereRaw(string $expression)
    {
        return $this->where(new Expression($expression), null, null, Operator::OR);
    }

    /**
     * Add where statement with OR operator.
     *
     * Takes the same column, operator and value as where().
     *
     * @param TwoElementsLogicExpression|string|Closure|self|Expression|ExpressionContract|array $column
     * @param mixed                                                                              $operator
     * @param mixed                                                                              $value
     *
     * @return static
     */
    public function orWhere($column, $operator = null, $value = null)
    {
        return $this->addCondition('wheres', $column, $operator, $value, Operator::OR, func_num_args());
    }

    /**
     * Add where statement with IN operator.
     *
     * An Arrayable list of values, such as a collection, is converted to an array first. An empty list adds the
     * bare condition 0 = 1, or 1 = 1 with NOT IN, with the given boolean, as in Laravel: it matches no row with
     * IN and every row with NOT IN.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return static
     */
    public function whereIn($column, $values, $boolean = Operator::AND, $not = false)
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $type = $not ? Operator::NOT_IN : Operator::IN;

        if (is_array($values)) {
            if (empty($values)) {
                return $this->where($this->emptyListCondition($type), null, null, $boolean);
            }

            $values = new Tuple($values);
        }

        return $this->where($column, $type, $values, $boolean);
    }

    /**
     * Add where statement with GLOBAL option and IN operator.
     *
     * An Arrayable list of values, such as a collection, is converted to an array first. An empty list adds the
     * bare condition 0 = 1, or 1 = 1 with GLOBAL NOT IN, with the given boolean, as whereIn() does.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return static
     */
    public function whereGlobalIn($column, $values, $boolean = Operator::AND, $not = false)
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $type = $not ? Operator::GLOBAL_NOT_IN : Operator::GLOBAL_IN;

        if (is_array($values)) {
            if (empty($values)) {
                return $this->where($this->emptyListCondition($type), null, null, $boolean);
            }

            $values = new Tuple($values);
        }

        return $this->where($column, $type, $values, $boolean);
    }

    /**
     * Add where statement with GLOBAL option and IN operator and OR operator.
     *
     * @param $column
     * @param $values
     *
     * @return static
     */
    public function orWhereGlobalIn($column, $values)
    {
        return $this->whereGlobalIn($column, $values, Operator::OR);
    }

    /**
     * Add where statement with GLOBAL option and NOT IN operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static
     */
    public function whereGlobalNotIn($column, $values, $boolean = Operator::AND)
    {
        return $this->whereGlobalIn($column, $values, $boolean, true);
    }

    /**
     * Add where statement with GLOBAL option and NOT IN operator and OR operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static
     */
    public function orWhereGlobalNotIn($column, $values, $boolean = Operator::OR)
    {
        return $this->whereGlobalNotIn($column, $values, $boolean);
    }

    /**
     * Add where statement with IN operator and OR operator.
     *
     * @param $column
     * @param $values
     *
     * @return static
     */
    public function orWhereIn($column, $values)
    {
        return $this->whereIn($column, $values, Operator::OR);
    }

    /**
     * Add where statement with NOT IN operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static
     */
    public function whereNotIn($column, $values, $boolean = Operator::AND)
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Add where statement with NOT IN operator and OR operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static
     */
    public function orWhereNotIn($column, $values, $boolean = Operator::OR)
    {
        return $this->whereNotIn($column, $values, $boolean);
    }

    /**
     * Add where statement with BETWEEN simulation.
     *
     * The values are read as where() reads a BETWEEN list: its first two values, in order and whatever their keys,
     * are the lower and the upper bound, so ['from' => 1, 'to' => 2] works, and a list of fewer than two values
     * throws.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @throws InvalidArgumentException when the list has fewer than two values
     *
     * @return static
     */
    public function whereBetween($column, array $values, $boolean = Operator::AND, $not = false)
    {
        $operator = $not ? Operator::NOT_BETWEEN : Operator::BETWEEN;

        return $this->where($column, $operator, $values, $boolean);
    }

    /**
     * Add where statement with BETWEEN simulation, but with column names as value.
     *
     * Each value given as a string is a column name; an Expression or a Laravel database expression is raw SQL. The
     * list is read as whereBetween() reads it.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @throws InvalidArgumentException when the list has fewer than two values
     *
     * @return static
     */
    public function whereBetweenColumns($column, array $values, $boolean = Operator::AND, $not = false)
    {
        $type = $not ? Operator::NOT_BETWEEN : Operator::BETWEEN;

        return $this->where($column, $type, array_map($this->columnOperand(...), $values), $boolean);
    }

    /**
     * Add where statement with BETWEEN simulation, but with column names as value and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static
     */
    public function orWhereBetweenColumns($column, array $values)
    {
        return $this->whereBetweenColumns($column, $values, Operator::OR);
    }

    /**
     * Add where statement with BETWEEN simulation and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static
     */
    public function orWhereBetween($column, array $values)
    {
        return $this->whereBetween($column, $values, Operator::OR);
    }

    /**
     * Add where statement with NOT BETWEEN simulation.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     *
     * @return static
     */
    public function whereNotBetween($column, array $values, $boolean = Operator::AND)
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * Add prewhere statement with NOT BETWEEN simulation and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static
     */
    public function orWhereNotBetween($column, array $values)
    {
        return $this->whereNotBetween($column, $values, Operator::OR);
    }

    /**
     * Add where statement with IS NULL check.
     *
     * An array adds one statement per column, joined with the given boolean.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     * @param string                                          $boolean
     * @param bool                                            $not
     *
     * @return static
     */
    public function whereNull($columns, string $boolean = Operator::AND, bool $not = false): static
    {
        foreach ($this->columnsToList($columns) as $column) {
            $this->where($column, $not ? Operator::IS_NOT_NULL : Operator::IS_NULL, null, $boolean);
        }

        return $this;
    }

    /**
     * Add where statement with IS NULL check and OR operator.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     *
     * @return static
     */
    public function orWhereNull($columns): static
    {
        return $this->whereNull($columns, Operator::OR);
    }

    /**
     * Add where statement with IS NOT NULL check.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     * @param string                                          $boolean
     *
     * @return static
     */
    public function whereNotNull($columns, string $boolean = Operator::AND): static
    {
        return $this->whereNull($columns, $boolean, true);
    }

    /**
     * Add where statement with IS NOT NULL check and OR operator.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     *
     * @return static
     */
    public function orWhereNotNull($columns): static
    {
        return $this->whereNotNull($columns, Operator::OR);
    }

    /**
     * Add where statement with empty() check.
     *
     * An array adds one statement per column, joined with the given boolean.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     * @param string                                          $boolean
     * @param bool                                            $not     Check with notEmpty() instead
     *
     * @return static
     */
    public function whereEmpty($columns, string $boolean = Operator::AND, bool $not = false): static
    {
        foreach ($this->columnsToList($columns) as $column) {
            $this->where($this->emptyCheckExpression($column, $not), null, null, $boolean);
        }

        return $this;
    }

    /**
     * Add where statement with empty() check and OR operator.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     *
     * @return static
     */
    public function orWhereEmpty($columns): static
    {
        return $this->whereEmpty($columns, Operator::OR);
    }

    /**
     * Add where statement with notEmpty() check.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     * @param string                                          $boolean
     *
     * @return static
     */
    public function whereNotEmpty($columns, string $boolean = Operator::AND): static
    {
        return $this->whereEmpty($columns, $boolean, true);
    }

    /**
     * Add where statement with notEmpty() check and OR operator.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     *
     * @return static
     */
    public function orWhereNotEmpty($columns): static
    {
        return $this->whereNotEmpty($columns, Operator::OR);
    }

    /**
     * Add a where statement that compares two columns.
     *
     * whereColumn('a', 'b') compiles to `a` = `b`, and whereColumn('a', '<', 'b') to `a` < `b`. As in Laravel, the
     * operator is = when the method is called with two arguments, or when the second column is null and the
     * operator argument is no operator: whereColumn('a', 'b', null) compiles to `a` = `b`. A column given as a
     * string is a name, quoted part by part like the column of where(), so whereColumn('t.a', '>', 't.b') compiles
     * to `t`.`a` > `t`.`b`; an Expression or a Laravel database expression, such as DB::raw(), is raw SQL. The
     * operator is read as where() reads it.
     *
     * A list of [first, second] or [first, operator, second] arrays, or of first => second pairs, adds one group of
     * comparisons, each joined to the one before it with the boolean of the call, as in Laravel 11 and later:
     * whereColumn([['a', 'b'], ['a', '<=', 'b']]) compiles to (`a` = `b` AND `a` <= `b`), and
     * whereColumn(['a' => 'b', 'c' => 'd'], null, null, 'or') to (`a` = `b` OR `c` = `d`). An array entry can name
     * its own boolean as a fourth value, which Laravel 13 does not take: [['a', 'b'], ['c', '>', 'd', 'or']].
     *
     * @param string|array<int|string, mixed>|Expression|ExpressionContract $first
     * @param mixed                                                          $operator
     * @param string|Expression|ExpressionContract|null                      $second
     * @param string                                                         $boolean
     *
     * @throws InvalidArgumentException when a column is missing, or the list is empty or has an entry of another kind
     *
     * @return static
     */
    public function whereColumn(
        string|array|Expression|ExpressionContract $first,
        mixed $operator = null,
        string|Expression|ExpressionContract|null $second = null,
        string $boolean = Operator::AND
    ): static {
        return $this->addColumnComparison('wheres', $first, $operator, $second, $boolean, func_num_args());
    }

    /**
     * Add a where statement that compares two columns, with OR operator.
     *
     * Takes the same columns and operator as whereColumn(). The comparisons of a list are joined with OR too:
     * orWhereColumn([['a', 'b'], ['c', 'd']]) adds OR (`a` = `b` OR `c` = `d`).
     *
     * @param string|array<int|string, mixed>|Expression|ExpressionContract $first
     * @param mixed                                                          $operator
     * @param string|Expression|ExpressionContract|null                      $second
     *
     * @throws InvalidArgumentException when a column is missing, or the list is empty or has an entry of another kind
     *
     * @return static
     */
    public function orWhereColumn(
        string|array|Expression|ExpressionContract $first,
        mixed $operator = null,
        string|Expression|ExpressionContract|null $second = null
    ): static {
        return $this->addColumnComparison('wheres', $first, $operator, $second, Operator::OR, func_num_args());
    }

    /**
     * Add a where statement that holds when a sub-query returns a row: EXISTS (<sub-query>).
     *
     * A closure is called with a new query, as the closures of whereIn() are, and the sub-query is compiled when
     * the method is called. whereExists(fn ($query) => $query->from('u')->where('v', 'p')) compiles to EXISTS
     * (SELECT * FROM `u` WHERE `v` = 'p').
     *
     * ClickHouse (24.8 checked) leaves columns out of the queries that INTERSECT and EXCEPT compare inside EXISTS,
     * so a sub-query that uses INTERSECT or EXCEPT, in itself or in a query it reads rows from, is compiled to read
     * all their columns (see compileExistsSubQuery()): a sub-query with EXCEPT becomes EXISTS (SELECT * FROM
     * (<sub-query>) WHERE NOT ignore(*)), and EXISTS (SELECT * FROM `w`), where `w` is a withExpression() of this
     * query that uses EXCEPT, becomes EXISTS (SELECT * FROM `w` WHERE NOT ignore(*)). A query the sub-query reads
     * rows from is a sub-query in its from() or a join, a withExpression() of its own, or a withExpression() of this
     * query, or of a query that this query is nested in through a closure, read by its name. Such a withExpression()
     * counts only when it was added before whereExists() is called. An INTERSECT or EXCEPT written as raw SQL is not
     * detected: add whereRaw('NOT ignore(*)') to the query that reads it.
     *
     * ClickHouse has no correlated sub-queries: a sub-query that refers to a column of the outer query, such as
     * whereColumn('orders.user_id', 'users.id') in a whereExists() on users, fails. A select fails at once. A
     * delete(false) or update() returns without an error, because ClickHouse runs the mutation in the background;
     * there it fails and holds back every later mutation of the table, which then changes nothing either, until it
     * is killed with KILL MUTATION. delete(true) throws, but leaves such a mutation too.
     *
     * @param Closure|BaseBuilder $query
     * @param string              $boolean
     * @param bool                $not     Add NOT EXISTS instead
     *
     * @throws InvalidArgumentException when the query is neither a closure nor a query builder of this package
     *
     * @return static
     */
    public function whereExists(mixed $query, string $boolean = Operator::AND, bool $not = false): static
    {
        return $this->addExistsCondition('wheres', $query, $boolean, $not);
    }

    /**
     * Add a where statement that holds when a sub-query returns a row, with OR operator.
     *
     * @param Closure|BaseBuilder $query
     * @param bool                $not   Add NOT EXISTS instead
     *
     * @throws InvalidArgumentException when the query is neither a closure nor a query builder of this package
     *
     * @return static
     */
    public function orWhereExists(mixed $query, bool $not = false): static
    {
        return $this->addExistsCondition('wheres', $query, Operator::OR, $not);
    }

    /**
     * Add a where statement that holds when a sub-query returns no row: NOT EXISTS (<sub-query>).
     *
     * @param Closure|BaseBuilder $query
     * @param string              $boolean
     *
     * @throws InvalidArgumentException when the query is neither a closure nor a query builder of this package
     *
     * @return static
     */
    public function whereNotExists(mixed $query, string $boolean = Operator::AND): static
    {
        return $this->addExistsCondition('wheres', $query, $boolean, true);
    }

    /**
     * Add a where statement that holds when a sub-query returns no row, with OR operator.
     *
     * @param Closure|BaseBuilder $query
     *
     * @throws InvalidArgumentException when the query is neither a closure nor a query builder of this package
     *
     * @return static
     */
    public function orWhereNotExists(mixed $query): static
    {
        return $this->addExistsCondition('wheres', $query, Operator::OR, true);
    }

    /**
     * Add a where statement that holds when every one of the columns matches, as a group in parentheses.
     *
     * Each column is compared as where() compares it, with the same operator and value: whereAll(['a', 'b'], '>',
     * 1) compiles to (`a` > 1 AND `b` > 1), and whereAll(['a', 'b'], 1) to (`a` = 1 AND `b` = 1).
     *
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @param mixed                                            $operator
     * @param mixed                                            $value
     * @param string                                           $boolean
     *
     * @throws InvalidArgumentException when no column is given
     *
     * @return static
     */
    public function whereAll(array $columns, mixed $operator = null, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addMultiColumnCondition('wheres', $columns, $operator, $value, $boolean, Operator::AND, false, func_num_args());
    }

    /**
     * Add a where statement that holds when every one of the columns matches, with OR operator.
     *
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @param mixed                                            $operator
     * @param mixed                                            $value
     *
     * @throws InvalidArgumentException when no column is given
     *
     * @return static
     */
    public function orWhereAll(array $columns, mixed $operator = null, mixed $value = null): static
    {
        return $this->addMultiColumnCondition('wheres', $columns, $operator, $value, Operator::OR, Operator::AND, false, func_num_args());
    }

    /**
     * Add a where statement that holds when any of the columns matches, as a group in parentheses.
     *
     * Each column is compared as where() compares it, with the same operator and value: whereAny(['a', 'b'], 3)
     * compiles to (`a` = 3 OR `b` = 3).
     *
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @param mixed                                            $operator
     * @param mixed                                            $value
     * @param string                                           $boolean
     *
     * @throws InvalidArgumentException when no column is given
     *
     * @return static
     */
    public function whereAny(array $columns, mixed $operator = null, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addMultiColumnCondition('wheres', $columns, $operator, $value, $boolean, Operator::OR, false, func_num_args());
    }

    /**
     * Add a where statement that holds when any of the columns matches, with OR operator.
     *
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @param mixed                                            $operator
     * @param mixed                                            $value
     *
     * @throws InvalidArgumentException when no column is given
     *
     * @return static
     */
    public function orWhereAny(array $columns, mixed $operator = null, mixed $value = null): static
    {
        return $this->addMultiColumnCondition('wheres', $columns, $operator, $value, Operator::OR, Operator::OR, false, func_num_args());
    }

    /**
     * Add a where statement that holds when none of the columns matches.
     *
     * Each column is compared as where() compares it, with the same operator and value: whereNone(['a', 'b'], 1)
     * compiles to NOT (`a` = 1 OR `b` = 1).
     *
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @param mixed                                            $operator
     * @param mixed                                            $value
     * @param string                                           $boolean
     *
     * @throws InvalidArgumentException when no column is given
     *
     * @return static
     */
    public function whereNone(array $columns, mixed $operator = null, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addMultiColumnCondition('wheres', $columns, $operator, $value, $boolean, Operator::OR, true, func_num_args());
    }

    /**
     * Add a where statement that holds when none of the columns matches, with OR operator.
     *
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @param mixed                                            $operator
     * @param mixed                                            $value
     *
     * @throws InvalidArgumentException when no column is given
     *
     * @return static
     */
    public function orWhereNone(array $columns, mixed $operator = null, mixed $value = null): static
    {
        return $this->addMultiColumnCondition('wheres', $columns, $operator, $value, Operator::OR, Operator::OR, true, func_num_args());
    }

    /**
     * Add a where statement on the date of a column: toDate32(<column>).
     *
     * whereDate('created_at', '2024-01-05') compiles to toDate32(`created_at`) = '2024-01-05'. A DateTimeInterface
     * value is written as its date in its own time zone, while ClickHouse reads the column in the time zone of the
     * column or of the server. toDate32() is used rather than toDate(), which wraps around for dates before 1970 or
     * after 2149; it works on Date, Date32, DateTime and DateTime64 columns and uses the primary key as toDate()
     * does. The operator and value are read as for where() (see addDatePartCondition()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     * @param string                               $boolean
     *
     * @return static
     */
    public function whereDate(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addDatePartCondition('wheres', 'Date', $column, $operator, $value, $boolean, func_num_args());
    }

    /**
     * Add a where statement on the date of a column, with OR operator (see whereDate()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     *
     * @return static
     */
    public function orWhereDate(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDatePartCondition('wheres', 'Date', $column, $operator, $value, Operator::OR, func_num_args());
    }

    /**
     * Add a where statement on the time of day of a column, to the second: formatDateTime(<column>, '%H:%i:%S').
     *
     * whereTime('created_at', '>=', '10:20') compiles to formatDateTime(`created_at`, '%H:%i:%S') >= '10:20:00'. A
     * time is written as HH:MM:SS: '9:05' becomes '09:05:00' and a fraction of a second is left out. A
     * DateTimeInterface value is written as its time of day in its own time zone, while formatDateTime() reads the
     * column in the time zone of the column or of the server. toTime() is not used: it returns a DateTime in the
     * server's time zone, which does not match the column's time of day in another time zone. Any other value
     * throws.
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     * @param string                               $boolean
     *
     * @throws InvalidArgumentException when a value is not a time of day, a date or raw SQL
     *
     * @return static
     */
    public function whereTime(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addDatePartCondition('wheres', 'Time', $column, $operator, $value, $boolean, func_num_args());
    }

    /**
     * Add a where statement on the time of day of a column, with OR operator (see whereTime()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     *
     * @throws InvalidArgumentException when a value is not a time of day, a date or raw SQL
     *
     * @return static
     */
    public function orWhereTime(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDatePartCondition('wheres', 'Time', $column, $operator, $value, Operator::OR, func_num_args());
    }

    /**
     * Add a where statement on the day of the month of a column: toDayOfMonth(<column>).
     *
     * whereDay('created_at', '05') compiles to toDayOfMonth(`created_at`) = 5. The value is written as an integer,
     * because ClickHouse does not compare the number with a string such as '05'; a DateTimeInterface value gives its
     * day of the month in its own time zone. Any other value throws.
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     * @param string                               $boolean
     *
     * @throws InvalidArgumentException when a value is not an integer, a string of digits, a date or raw SQL
     *
     * @return static
     */
    public function whereDay(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addDatePartCondition('wheres', 'Day', $column, $operator, $value, $boolean, func_num_args());
    }

    /**
     * Add a where statement on the day of the month of a column, with OR operator (see whereDay()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     *
     * @throws InvalidArgumentException when a value is not an integer, a string of digits, a date or raw SQL
     *
     * @return static
     */
    public function orWhereDay(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDatePartCondition('wheres', 'Day', $column, $operator, $value, Operator::OR, func_num_args());
    }

    /**
     * Add a where statement on the month of a column: toMonth(<column>).
     *
     * whereMonth('created_at', '>', '01') compiles to toMonth(`created_at`) > 1. The value is written as an
     * integer, as whereDay() writes it.
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     * @param string                               $boolean
     *
     * @throws InvalidArgumentException when a value is not an integer, a string of digits, a date or raw SQL
     *
     * @return static
     */
    public function whereMonth(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addDatePartCondition('wheres', 'Month', $column, $operator, $value, $boolean, func_num_args());
    }

    /**
     * Add a where statement on the month of a column, with OR operator (see whereMonth()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     *
     * @throws InvalidArgumentException when a value is not an integer, a string of digits, a date or raw SQL
     *
     * @return static
     */
    public function orWhereMonth(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDatePartCondition('wheres', 'Month', $column, $operator, $value, Operator::OR, func_num_args());
    }

    /**
     * Add a where statement on the year of a column: toYear(<column>).
     *
     * whereYear('created_at', 2024) compiles to toYear(`created_at`) = 2024. The value is written as an integer, as
     * whereDay() writes it.
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     * @param string                               $boolean
     *
     * @throws InvalidArgumentException when a value is not an integer, a string of digits, a date or raw SQL
     *
     * @return static
     */
    public function whereYear(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
    {
        return $this->addDatePartCondition('wheres', 'Year', $column, $operator, $value, $boolean, func_num_args());
    }

    /**
     * Add a where statement on the year of a column, with OR operator (see whereYear()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param mixed                                $operator
     * @param mixed                                $value
     *
     * @throws InvalidArgumentException when a value is not an integer, a string of digits, a date or raw SQL
     *
     * @return static
     */
    public function orWhereYear(string|Expression|ExpressionContract $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDatePartCondition('wheres', 'Year', $column, $operator, $value, Operator::OR, func_num_args());
    }

    /**
     * Add a where statement that matches a pattern: ILIKE, or LIKE when it is case-sensitive, as in Laravel.
     *
     * whereLike('name', 'a%') compiles to `name` ILIKE 'a%', and whereLike('name', 'a%', true) to `name` LIKE 'a%'.
     * ClickHouse can use the primary key for a LIKE 'prefix%' condition, but not for ILIKE.
     *
     * @param string|Expression|ExpressionContract $column
     * @param string                               $value
     * @param bool                                 $caseSensitive
     * @param string                               $boolean
     * @param bool                                 $not           Use NOT ILIKE or NOT LIKE instead
     *
     * @return static
     */
    public function whereLike(
        string|Expression|ExpressionContract $column,
        string $value,
        bool $caseSensitive = false,
        string $boolean = Operator::AND,
        bool $not = false
    ): static {
        $operator = match (true) {
            $caseSensitive && $not => Operator::NOT_LIKE,
            $caseSensitive => Operator::LIKE,
            $not => Operator::NOT_ILIKE,
            default => Operator::ILIKE,
        };

        return $this->addCondition('wheres', $column, $operator, $value, $boolean, 3);
    }

    /**
     * Add a where statement that matches a pattern, with OR operator (see whereLike()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param string                               $value
     * @param bool                                 $caseSensitive
     *
     * @return static
     */
    public function orWhereLike(string|Expression|ExpressionContract $column, string $value, bool $caseSensitive = false): static
    {
        return $this->whereLike($column, $value, $caseSensitive, Operator::OR);
    }

    /**
     * Add a where statement that does not match a pattern: NOT ILIKE, or NOT LIKE when it is case-sensitive.
     *
     * @param string|Expression|ExpressionContract $column
     * @param string                               $value
     * @param bool                                 $caseSensitive
     * @param string                               $boolean
     *
     * @return static
     */
    public function whereNotLike(
        string|Expression|ExpressionContract $column,
        string $value,
        bool $caseSensitive = false,
        string $boolean = Operator::AND
    ): static {
        return $this->whereLike($column, $value, $caseSensitive, $boolean, true);
    }

    /**
     * Add a where statement that does not match a pattern, with OR operator (see whereNotLike()).
     *
     * @param string|Expression|ExpressionContract $column
     * @param string                               $value
     * @param bool                                 $caseSensitive
     *
     * @return static
     */
    public function orWhereNotLike(string|Expression|ExpressionContract $column, string $value, bool $caseSensitive = false): static
    {
        return $this->whereLike($column, $value, $caseSensitive, Operator::OR, true);
    }

    /**
     * Add having statement.
     *
     * The column and the value can be raw SQL, as an Expression or a Laravel database expression such as DB::raw(),
     * which is written as raw() is: having(DB::raw('count()'), '>', 1) compiles to HAVING count() > 1. The operator
     * and the concatenation operator are read in any letter case.
     *
     * @param TwoElementsLogicExpression|string|Closure|self|Expression|ExpressionContract|array $column
     * @param mixed                                                                              $operator
     * @param mixed                                                                              $value
     * @param string                                                                             $concatOperator
     *
     * @return static
     */
    public function having($column, $operator = null, $value = null, string $concatOperator = Operator::AND)
    {
        return $this->addCondition('havings', $column, $operator, $value, $concatOperator, func_num_args());
    }

    /**
     * Add having statement "as is".
     *
     * @param string $expression
     *
     * @return static
     */
    public function havingRaw(string $expression)
    {
        return $this->having(new Expression($expression));
    }

    /**
     * Add having statement "as is" with OR operator.
     *
     * @param string $expression
     *
     * @return static
     */
    public function orHavingRaw(string $expression)
    {
        return $this->having(new Expression($expression), null, null, Operator::OR);
    }

    /**
     * Add having statement with OR operator.
     *
     * Takes the same column, operator and value as having().
     *
     * @param TwoElementsLogicExpression|string|Closure|self|Expression|ExpressionContract|array $column
     * @param mixed                                                                              $operator
     * @param mixed                                                                              $value
     *
     * @return static
     */
    public function orHaving($column, $operator = null, $value = null)
    {
        return $this->addCondition('havings', $column, $operator, $value, Operator::OR, func_num_args());
    }

    /**
     * Add having statement with IN operator.
     *
     * An Arrayable list of values, such as a collection, is converted to an array first. An empty list adds the
     * bare condition 0 = 1, or 1 = 1 with NOT IN, with the given boolean, as in Laravel.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return static
     */
    public function havingIn($column, $values, $boolean = Operator::AND, $not = false)
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $type = $not ? Operator::NOT_IN : Operator::IN;

        if (is_array($values)) {
            if (empty($values)) {
                return $this->having($this->emptyListCondition($type), null, null, $boolean);
            }

            $values = new Tuple($values);
        }

        return $this->having($column, $type, $values, $boolean);
    }

    /**
     * Add having statement with IN operator and OR operator.
     *
     * @param $column
     * @param $values
     *
     * @return static
     */
    public function orHavingIn($column, $values)
    {
        return $this->havingIn($column, $values, Operator::OR);
    }

    /**
     * Add having statement with NOT IN operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static
     */
    public function havingNotIn($column, $values, $boolean = Operator::AND)
    {
        return $this->havingIn($column, $values, $boolean, true);
    }

    /**
     * Add having statement with NOT IN operator and OR operator.
     *
     * @param        $column
     * @param        $values
     * @param string $boolean
     *
     * @return static
     */
    public function orHavingNotIn($column, $values, $boolean = Operator::OR)
    {
        return $this->havingNotIn($column, $values, $boolean);
    }

    /**
     * Add having statement with BETWEEN simulation.
     *
     * The values are read as having() reads a BETWEEN list: its first two values, in order and whatever their keys,
     * are the lower and the upper bound, so ['from' => 1, 'to' => 2] works, and a list of fewer than two values
     * throws.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @throws InvalidArgumentException when the list has fewer than two values
     *
     * @return static
     */
    public function havingBetween($column, array $values, $boolean = Operator::AND, $not = false)
    {
        $operator = $not ? Operator::NOT_BETWEEN : Operator::BETWEEN;

        return $this->having($column, $operator, $values, $boolean);
    }

    /**
     * Add having statement with BETWEEN simulation, but with column names as value.
     *
     * Each value given as a string is a column name; an Expression or a Laravel database expression is raw SQL. The
     * list is read as havingBetween() reads it.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @throws InvalidArgumentException when the list has fewer than two values
     *
     * @return static
     */
    public function havingBetweenColumns($column, array $values, $boolean = Operator::AND, $not = false)
    {
        $type = $not ? Operator::NOT_BETWEEN : Operator::BETWEEN;

        return $this->having($column, $type, array_map($this->columnOperand(...), $values), $boolean);
    }

    /**
     * Add having statement with BETWEEN simulation, but with column names as value and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static
     */
    public function orHavingBetweenColumns($column, array $values)
    {
        return $this->havingBetweenColumns($column, $values, Operator::OR);
    }

    /**
     * Add having statement with BETWEEN simulation and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static
     */
    public function orHavingBetween($column, array $values)
    {
        return $this->havingBetween($column, $values, Operator::OR);
    }

    /**
     * Add having statement with NOT BETWEEN simulation.
     *
     * @param        $column
     * @param array  $values
     * @param string $boolean
     *
     * @return static
     */
    public function havingNotBetween($column, array $values, $boolean = Operator::AND)
    {
        return $this->havingBetween($column, $values, $boolean, true);
    }

    /**
     * Add having statement with NOT BETWEEN simulation and OR operator.
     *
     * @param       $column
     * @param array $values
     *
     * @return static
     */
    public function orHavingNotBetween($column, array $values)
    {
        return $this->havingNotBetween($column, $values, Operator::OR);
    }

    /**
     * Add having statement with IS NULL check.
     *
     * An array adds one statement per column, joined with the given boolean.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     * @param string                                          $boolean
     * @param bool                                            $not
     *
     * @return static
     */
    public function havingNull($columns, string $boolean = Operator::AND, bool $not = false): static
    {
        foreach ($this->columnsToList($columns) as $column) {
            $this->having($column, $not ? Operator::IS_NOT_NULL : Operator::IS_NULL, null, $boolean);
        }

        return $this;
    }

    /**
     * Add having statement with IS NULL check and OR operator.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     *
     * @return static
     */
    public function orHavingNull($columns): static
    {
        return $this->havingNull($columns, Operator::OR);
    }

    /**
     * Add having statement with IS NOT NULL check.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     * @param string                                          $boolean
     *
     * @return static
     */
    public function havingNotNull($columns, string $boolean = Operator::AND): static
    {
        return $this->havingNull($columns, $boolean, true);
    }

    /**
     * Add having statement with IS NOT NULL check and OR operator.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     *
     * @return static
     */
    public function orHavingNotNull($columns): static
    {
        return $this->havingNotNull($columns, Operator::OR);
    }

    /**
     * Add having statement with empty() check.
     *
     * An array adds one statement per column, joined with the given boolean.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     * @param string                                          $boolean
     * @param bool                                            $not     Check with notEmpty() instead
     *
     * @return static
     */
    public function havingEmpty($columns, string $boolean = Operator::AND, bool $not = false): static
    {
        foreach ($this->columnsToList($columns) as $column) {
            $this->having($this->emptyCheckExpression($column, $not), null, null, $boolean);
        }

        return $this;
    }

    /**
     * Add having statement with empty() check and OR operator.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     *
     * @return static
     */
    public function orHavingEmpty($columns): static
    {
        return $this->havingEmpty($columns, Operator::OR);
    }

    /**
     * Add having statement with notEmpty() check.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     * @param string                                          $boolean
     *
     * @return static
     */
    public function havingNotEmpty($columns, string $boolean = Operator::AND): static
    {
        return $this->havingEmpty($columns, $boolean, true);
    }

    /**
     * Add having statement with notEmpty() check and OR operator.
     *
     * @param string|Expression|array<int, string|Expression> $columns
     *
     * @return static
     */
    public function orHavingNotEmpty($columns): static
    {
        return $this->havingNotEmpty($columns, Operator::OR);
    }

    /**
     * Add dictionary value to select statement.
     *
     * The dictionary and attribute names are written as escaped string literals and the alias, which defaults to
     * the attribute name, as one quoted identifier.
     *
     * @param string       $dict
     * @param string       $attribute
     * @param array|string $key
     * @param string       $as
     *
     * @return static
     */
    public function addSelectDict(string $dict, string $attribute, $key, ?string $as = null)
    {
        if (is_null($as)) {
            $as = $attribute;
        }

        $id = is_array($key) ? 'tuple('.implode(
            ', ',
            array_map([$this->grammar, 'wrap'], $key)
        ).')' : $this->grammar->wrap($key);

        $dictionaryName = $this->grammar->wrap($dict);
        $attributeName = $this->grammar->wrap($attribute);
        $alias = $this->grammar->quoteIdentifier($as);

        return $this
            ->addSelect(new Expression("dictGetString({$dictionaryName}, {$attributeName}, {$id}) as {$alias}"));
    }

    /**
     * Add where on dictionary value in where statement.
     *
     * The attribute, operator, value and concatenation operator work as the arguments of where(), except that the
     * condition names the attribute as one quoted identifier, the alias that addSelectDict() selects, so a name with
     * a dot or ' as ' is not split into parts.
     *
     * @param              $dict
     * @param              $attribute
     * @param array|string $key
     * @param              $operator
     * @param              $value
     * @param string       $concatOperator
     *
     * @return static
     */
    public function whereDict(
        string $dict,
        string $attribute,
        $key,
        $operator = null,
        $value = null,
        string $concatOperator = Operator::AND
    ) {
        $this->addSelectDict($dict, $attribute, $key);

        return $this->addDictCondition($attribute, $operator, $value, $concatOperator, func_num_args() - 2);
    }

    /**
     * Add where on dictionary value in where statement and OR operator.
     *
     * The attribute, operator and value work as the arguments of orWhere(), except that the condition names the
     * attribute as one quoted identifier, the alias that addSelectDict() selects, so a name with a dot or ' as ' is
     * not split into parts.
     *
     * @param $dict
     * @param $attribute
     * @param $key
     * @param $operator
     * @param $value
     *
     * @return static
     */
    public function orWhereDict(
        string $dict,
        string $attribute,
        $key,
        $operator = null,
        $value = null
    ) {
        $this->addSelectDict($dict, $attribute, $key);

        return $this->addDictCondition($attribute, $operator, $value, Operator::OR, func_num_args() - 2);
    }

    /**
     * Add the where statement of whereDict() or orWhereDict() on the alias that addSelectDict() selected.
     *
     * The column is the attribute name as one quoted identifier, written as addSelectDict() writes the alias, and
     * reaches addCondition() as an Expression. addCondition() takes a null operator as the value only for a column
     * given as a string, so a null operator with a null value is made the two-argument form here, as where() does
     * with the attribute name: whereDict($dict, $attribute, $key, null, null) is whereDict($dict, $attribute, $key,
     * null) and compares with IS NULL.
     *
     * @param string $attribute
     * @param mixed  $operator
     * @param mixed  $value
     * @param string $concatOperator
     * @param int    $argumentCount  Number of arguments the statement method was called with, not counting the
     *                               dictionary name and the key
     *
     * @return static
     */
    protected function addDictCondition(
        string $attribute,
        $operator,
        $value,
        string $concatOperator,
        int $argumentCount
    ): static {
        if ($argumentCount > 2 && is_null($operator) && is_null($value)) {
            $argumentCount = 2;
        }

        return $this->addCondition(
            'wheres',
            new Expression($this->grammar->quoteIdentifier($attribute)),
            $operator,
            $value,
            $concatOperator,
            $argumentCount
        );
    }

    /**
     * Add request which must be runned asynchronous.
     *
     * get() runs only this query, never the queries added here. Without an argument the method returns the new
     * query it adds, not this one.
     *
     * @deprecated Run the queries at the same time with Oralunal\LaravelClickHouse\Parallel::get() or
     *             Parallel::getRows() instead, for example Parallel::get([$query, $otherQuery]).
     *
     * @param Closure|self|null $asyncQueries
     *
     * @return static
     */
    public function asyncWithQuery($asyncQueries = null)
    {
        if (is_null($asyncQueries)) {
            return $this->async[] = $this->newQuery();
        }

        if ($asyncQueries instanceof Closure) {
            $asyncQueries = tp($this->newQuery(), $asyncQueries);
        }

        if ($asyncQueries instanceof BaseBuilder) {
            $this->async[] = $asyncQueries;
        } else {
            throw new \InvalidArgumentException('Argument for async method must be Closure, Builder or nothing');
        }

        return $this;
    }

    /**
     * Add limit statement.
     *
     * @param int      $limit
     * @param int|null $offset
     *
     * @return static
     */
    public function limit(int $limit, ?int $offset = null)
    {
        $this->limit = new Limit($limit, $offset);

        return $this;
    }

    /**
     * Add limit n by statement.
     *
     * @param int   $count
     * @param array ...$columns
     *
     * @return static
     */
    public function limitBy(int $count, ...$columns)
    {
        $columns = isset($columns[0]) && is_array($columns[0]) ? $columns[0] : $columns;

        $this->limitBy = new Limit($count, null, $this->processColumns($columns, false));

        return $this;
    }

    /**
     * Alias for limit method.
     *
     * @param int      $limit
     * @param int|null $offset
     *
     * @return static
     */
    public function take(int $limit, ?int $offset = null)
    {
        return $this->limit($limit, $offset);
    }

    /**
     * Alias for limitBy method.
     *
     * @param int   $count
     * @param array ...$columns
     *
     * @return static
     */
    public function takeBy(int $count, ...$columns)
    {
        return $this->limitBy($count, ...$columns);
    }

    /**
     * Add group by statement.
     *
     * @param $columns
     *
     * @return static
     */
    public function groupBy(...$columns)
    {
        $columns = isset($columns[0]) && is_array($columns[0]) ? $columns[0] : $columns;

        $this->groups = $this->processColumns($columns, false);

        return $this;
    }

    /**
     * Add group by statement to exist group statements.
     *
     * @param $columns
     *
     * @return static
     */
    public function addGroupBy(...$columns)
    {
        $columns = isset($columns[0]) && is_array($columns[0]) ? $columns[0] : $columns;

        $this->groups = array_merge($this->groups, $this->processColumns($columns, false));

        return $this;
    }

    /**
     * Add order by statement.
     *
     * A string is a column name; an Expression or a Laravel database expression, such as DB::raw(), is raw SQL.
     *
     * @param string|Closure|Expression|ExpressionContract $column
     * @param string                                       $direction
     * @param string|null                                  $collate
     *
     * @return static
     */
    public function orderBy($column, string $direction = 'asc', ?string $collate = null)
    {
        $column = $this->processColumns([$column], false)[0];

        $direction = new OrderDirection(strtoupper($direction));

        $this->orders[] = [$column, $direction, $collate];

        return $this;
    }

    /**
     * Add order by statement "as is".
     *
     * @param string $expression
     *
     * @return static
     */
    public function orderByRaw(string $expression)
    {
        $column = $this->processColumns([new Expression($expression)], false)[0];
        $this->orders[] = [$column, null, null];

        return $this;
    }

    /**
     * Add ASC order statement.
     *
     * @param             $column
     * @param string|null $collate
     *
     * @return static
     */
    public function orderByAsc($column, ?string $collate = null)
    {
        return $this->orderBy($column, OrderDirection::ASC, $collate);
    }

    /**
     * Add DESC order statement.
     *
     * @param             $column
     * @param string|null $collate
     *
     * @return static
     */
    public function orderByDesc($column, ?string $collate = null)
    {
        return $this->orderBy($column, OrderDirection::DESC, $collate);
    }

    /**
     * Order the rows at random: ORDER BY rand().
     *
     * ClickHouse has no seeded random function: rand(42) gives another order on every run, so a seed throws instead
     * of being ignored. For an order that a seed repeats, order by a hash of the seed and a key, such as
     * orderByRaw('cityHash64(42, id)').
     *
     * @param string|int|null $seed Only the empty string, the default, and null are taken
     *
     * @throws InvalidArgumentException when a seed is given
     *
     * @return static
     */
    public function inRandomOrder(string|int|null $seed = ''): static
    {
        if ($seed !== '' && $seed !== null) {
            throw new InvalidArgumentException(
                'ClickHouse has no seeded random function, so inRandomOrder() cannot take a seed. For a repeatable order, '
                ."order by a hash of the seed and a key: orderByRaw('cityHash64(42, id)')."
            );
        }

        return $this->orderByRaw('rand()');
    }

    /**
     * Order the rows by a column, latest first: ORDER BY `created_at` DESC by default.
     *
     * @param string|Expression|ExpressionContract $column
     *
     * @return static
     */
    public function latest(string|Expression|ExpressionContract $column = 'created_at'): static
    {
        return $this->orderBy($column, OrderDirection::DESC);
    }

    /**
     * Order the rows by a column, oldest first: ORDER BY `created_at` ASC by default.
     *
     * @param string|Expression|ExpressionContract $column
     *
     * @return static
     */
    public function oldest(string|Expression|ExpressionContract $column = 'created_at'): static
    {
        return $this->orderBy($column, OrderDirection::ASC);
    }

    /**
     * Set query result format.
     *
     * The name is matched in any letter case, as ClickHouse matches format names, and the query is compiled with the
     * canonical name: format('jsoneachrow') compiles to FORMAT JSONEachRow, and format('json') to FORMAT JSON.
     *
     * @param string $format A name of the Format enum, in any letter case
     *
     * @throws \UnexpectedValueException When the Format enum has no format of that name
     *
     * @return static
     */
    public function format(string $format)
    {
        $this->format = Format::named($format);

        return $this;
    }

    /**
     * Get the SQL representation of the query.
     *
     * @return string
     */
    public function toSql(): string
    {
        return $this->grammar->compileSelect($this);
    }

    /**
     * Get an array of the SQL queries from all added async builders.
     *
     * The queries are in the order of getAsyncQueries().
     *
     * @deprecated Run the queries at the same time with Oralunal\LaravelClickHouse\Parallel::get() or
     *             Parallel::getRows() instead.
     *
     * @return array<int, array{query: string}>
     */
    public function toAsyncSqls(): array
    {
        return array_map(
            function ($query) {
                /** @var self $query */
                return ['query' => $query->toSql()];
            },
            $this->getAsyncQueries()
        );
    }

    /**
     * Get the queries of the client-based query builder for all added async builders.
     *
     * That query builder was removed in 2.0.0, so this method always throws.
     *
     * @deprecated Run the queries at the same time with Oralunal\LaravelClickHouse\Parallel::get() instead, for
     *             example Parallel::get([$query, $otherQuery]), or compile each one with toSql().
     *
     * @throws BadMethodCallException always
     *
     * @return array
     */
    public function toAsyncQueries(): array
    {
        throw new BadMethodCallException(
            'toAsyncQueries() needs the client-based query builder that 2.0.0 removed. Run the queries at the same '
            .'time with Oralunal\LaravelClickHouse\Parallel::get([$query, $otherQuery]), or compile each one with toSql().'
        );
    }

    /**
     * Get columns for select statement.
     *
     * @return array
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Get order statements.
     *
     * @return array
     */
    public function getOrders(): array
    {
        return $this->orders;
    }

    /**
     * Get group statements.
     *
     * @return array
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * Get having statements.
     *
     * @return array
     */
    public function getHavings(): array
    {
        return $this->havings;
    }

    /**
     * Get prewhere statements.
     *
     * @return array
     */
    public function getPreWheres(): array
    {
        return $this->prewheres;
    }

    /**
     * Get where statements.
     *
     * @return array
     */
    public function getWheres(): array
    {
        return $this->wheres;
    }

    /**
     * Get cluster name.
     *
     * @return null|string
     */
    public function getOnCluster(): ?string
    {
        return $this->onCluster;
    }

    /**
     * Get From object.
     *
     * @return From|null
     */
    public function getFrom(): ?From
    {
        return $this->from;
    }

    /**
     * Get ArrayJoinClause.
     *
     * @return null|ArrayJoinClause
     */
    public function getArrayJoin(): ?ArrayJoinClause
    {
        return $this->arrayJoin;
    }

    /**
     * Get JoinClause.
     *
     * @return JoinClause[]|null
     */
    public function getJoins(): ?array
    {
        return $this->joins;
    }

    /**
     * Get limit statement.
     *
     * @return Limit
     */
    public function getLimit(): ?Limit
    {
        return $this->limit;
    }

    /**
     * Get limit by statement.
     *
     * @return Limit
     */
    public function getLimitBy(): ?Limit
    {
        return $this->limitBy;
    }

    /**
     * Get sample statement.
     *
     * @return float|null
     */
    public function getSample(): ?float
    {
        return $this->sample;
    }

    /**
     * Get sample offset.
     *
     * @return float|null
     */
    public function getSampleOffset(): ?float
    {
        return $this->sampleOffset;
    }

    /**
     * Get query unions.
     *
     * @return array
     */
    public function getUnions(): array
    {
        return $this->unions;
    }

    /**
     * Get the set operator of each query returned by getUnions(), under the same key.
     *
     * @return string[]
     */
    public function getUnionTypes(): array
    {
        $types = [];

        foreach (array_keys($this->unions) as $index) {
            $types[$index] = $this->unionTypes[$index] ?? UnionType::UNION_ALL;
        }

        return $types;
    }

    /**
     * Get the WITH clause entries, in call order.
     *
     * @return array<int, array{type: string, name: string, value: mixed, recursive: bool}>
     */
    public function getWiths(): array
    {
        return $this->withs;
    }

    /**
     * Get format.
     *
     * @return null|Format
     */
    public function getFormat(): ?Format
    {
        return $this->format;
    }

    /**
     * Gather all builders from builder. Including nested in async builders.
     *
     * This query comes first, then the queries added with asyncWithQuery(), the last added first, each followed by
     * the queries added to it, in the same order: after adding $b and then $c, the result is [$this, $c, $b].
     *
     * @deprecated Run the queries at the same time with Oralunal\LaravelClickHouse\Parallel::get() or
     *             Parallel::getRows() instead.
     *
     * @return array<int, BaseBuilder>
     */
    public function getAsyncQueries(): array
    {
        $result = [];

        foreach ($this->async as $query) {
            $result = array_merge($query->getAsyncQueries(), $result);
        }

        return array_merge([$this], $result);
    }
}
