<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query;

use Closure;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\JoinStrict;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\JoinType;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Operator;
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\array_flatten;
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\tp;

class JoinClause
{
    /**
     * GLOBAL option.
     *
     * @var bool
     */
    private $global = false;

    /**
     * Join strictness.
     *
     * @var JoinStrict|null
     */
    private $strict;

    /**
     * Join type.
     *
     * @var JoinType|null
     */
    private $type;

    /**
     * Table for join.
     *
     * @var Identifier|Expression|ExpressionContract|null
     */
    private $table;

    /**
     * Column which used to join rows between tables.
     *
     * Требуется, что бы колонки с обоих сторон назывались одинаково.
     *
     * @var array|null
     */
    private $using;

    /**
     * Builder which initiated join.
     *
     * @var BaseBuilder
     */
    private $query;

    /**
     * Used for sub-query which executed not in callback.
     *
     * @var BaseBuilder|null
     */
    private $subQuery;

    /**
     * Builder whose SQL was compiled into the JOIN clause as a sub-query.
     *
     * @var BaseBuilder|null
     */
    private $queryBuilder;

    /**
     * Join alias.
     *
     * @var Identifier
     */
    private $alias;

    /**
     * On clauses for joining rows between tables.
     *
     * @var TwoElementsLogicExpression[]|null
     */
    private $onClauses;

    /**
     * JoinClause constructor.
     *
     * @param BaseBuilder $query
     */
    public function __construct(BaseBuilder $query)
    {
        $this->query = $query;
    }

    /**
     * Set table for join.
     *
     * A builder is compiled as a sub-query and kept for getQueryBuilder(); a table name or an expression clears it.
     * A string is a table name, with an optional alias after AS. An Expression or a Laravel database expression,
     * such as DB::raw(), is raw SQL, written as it is.
     *
     * @param string|Expression|ExpressionContract|BaseBuilder $table
     *
     * @return JoinClause
     */
    public function table($table): self
    {
        $queryBuilder = null;

        if (is_string($table)) {
            list($table, $alias) = $this->decomposeJoinExpressionToTableAndAlias($table);

            if (!is_null($alias)) {
                $this->as($alias);
            }

            $table = new Identifier($table);
        } elseif ($table instanceof BaseBuilder) {
            $queryBuilder = $table;
            $table = new Expression("({$table->toSql()})");
        }

        $this->table = $table;
        $this->queryBuilder = $queryBuilder;

        return $this;
    }

    /**
     * Set column to use for join rows.
     *
     * @param array ...$columns
     *
     * @return JoinClause
     */
    public function using(...$columns): self
    {
        $this->using = $this->stringsToIdentifiers(array_flatten($columns));

        return $this;
    }

    /**
     * Set "on" clause for join.
     *
     * A column given as a string is a name, quoted part by part; an Expression or a Laravel database expression,
     * such as DB::raw(), is raw SQL. The operator and the concatenation operator are read in any letter case.
     *
     * @param string|Expression|ExpressionContract $first
     * @param string                               $operator
     * @param string|Expression|ExpressionContract $second
     * @param string                               $concatOperator
     *
     * @return JoinClause
     */
    public function on($first, string $operator, $second, string $concatOperator = Operator::AND): self
    {
        $expression = (new TwoElementsLogicExpression($this->query))
            ->firstElement(is_string($first) ? new Identifier($first) : $first)
            ->operator($operator)
            ->secondElement(is_string($second) ? new Identifier($second) : $second)
            ->concatOperator($concatOperator);

        $this->onClauses[] = $expression;

        return $this;
    }

    /**
     * Add column to using statement.
     *
     * @param string|array $columns
     *
     * @return JoinClause
     */
    public function addUsing(...$columns): self
    {
        $this->using = array_merge($this->using ?? [], $this->stringsToIdentifiers(array_flatten($columns)));

        return $this;
    }

    /**
     * Set join strictness.
     *
     * @param string $strict
     *
     * @return JoinClause
     */
    public function strict(string $strict): self
    {
        $this->strict = new JoinStrict(strtoupper($strict));

        return $this;
    }

    /**
     * Set join type.
     *
     * @param string $type
     *
     * @return JoinClause
     */
    public function type(string $type): self
    {
        $this->type = new JoinType(strtoupper($type));

        return $this;
    }

    /**
     * Set ALL strictness.
     *
     * @return JoinClause
     */
    public function all(): self
    {
        return $this->strict(JoinStrict::ALL);
    }

    /**
     * Set ANY strictness.
     *
     * @return JoinClause
     */
    public function any(): self
    {
        return $this->strict(JoinStrict::ANY);
    }

    /**
     * Set SEMI strictness.
     *
     * @return JoinClause
     */
    public function semi(): self
    {
        return $this->strict(JoinStrict::SEMI);
    }

    /**
     * Set ANTI strictness.
     *
     * @return JoinClause
     */
    public function anti(): self
    {
        return $this->strict(JoinStrict::ANTI);
    }

    /**
     * Set ASOF strictness.
     *
     * @return JoinClause
     */
    public function asof(): self
    {
        return $this->strict(JoinStrict::ASOF);
    }

    /**
     * Set INNER join type.
     *
     * @return JoinClause
     */
    public function inner(): self
    {
        return $this->type(JoinType::INNER);
    }

    /**
     * Set LEFT join type.
     *
     * @return JoinClause
     */
    public function left(): self
    {
        return $this->type(JoinType::LEFT);
    }

    /**
     * Set RIGHT join type.
     *
     * @return JoinClause
     */
    public function right(): self
    {
        return $this->type(JoinType::RIGHT);
    }

    /**
     * Set FULL join type.
     *
     * @return JoinClause
     */
    public function full(): self
    {
        return $this->type(JoinType::FULL);
    }

    /**
     * Set CROSS join type, which takes no strictness and no join keys.
     *
     * @return JoinClause
     */
    public function cross(): self
    {
        return $this->type(JoinType::CROSS);
    }

    /**
     * Set GLOBAL option.
     *
     * @param bool $global
     *
     * @return JoinClause
     */
    public function distributed(bool $global = false): self
    {
        $this->global = $global;

        return $this;
    }

    /**
     * Set sub-query as table to select from.
     *
     * @param Closure|BaseBuilder|null $query
     *
     * @return JoinClause|BaseBuilder
     */
    public function query($query = null)
    {
        if (is_null($query)) {
            return $this->subQuery();
        }

        if ($query instanceof Closure) {
            $query = tp($this->query->newQuery(), $query);
        }

        if ($query instanceof BaseBuilder) {
            $this->table(new Expression("({$query->toSql()})"));
            $this->queryBuilder = $query;
        }

        return $this;
    }

    /**
     * Get sub-query builder.
     *
     * @param string|null $alias
     *
     * @return BaseBuilder
     */
    public function subQuery(?string $alias = null): BaseBuilder
    {
        if ($alias) {
            $this->as($alias);
        }

        return $this->subQuery = $this->query->newQuery();
    }

    /**
     * Set join alias.
     *
     * @param string $alias
     *
     * @return $this
     */
    public function as(string $alias): self
    {
        $this->alias = new Identifier($alias);

        return $this;
    }

    /**
     * Get using columns.
     *
     * @return array|null
     */
    public function getUsing(): ?array
    {
        return $this->using;
    }

    /**
     * Get on clauses.
     *
     * @return array|null
     */
    public function getOnClauses(): ?array
    {
        return $this->onClauses;
    }

    /**
     * Get flag to use or not to use GLOBAL option.
     *
     * @return bool
     */
    public function isDistributed(): bool
    {
        return $this->global;
    }

    /**
     * Get join strictness.
     *
     * @return JoinStrict|null
     */
    public function getStrict(): ?JoinStrict
    {
        return $this->strict;
    }

    /**
     * Get join type.
     *
     * @return JoinType|null
     */
    public function getType(): ?JoinType
    {
        return $this->type;
    }

    /**
     * Get sub-query.
     *
     * @return BaseBuilder|null
     */
    public function getSubQuery(): ?BaseBuilder
    {
        return $this->subQuery;
    }

    /**
     * Get the builder whose SQL was compiled into the JOIN clause as a sub-query.
     *
     * It is set by table($builder) and query($builder), so by join($builder) and the join helpers given a builder,
     * by query(Closure), and by the closure forms that call query() or subQuery() without an argument once join()
     * compiles that sub-query. It is null when the join names a table or raw SQL, and after table() replaces a
     * sub-query with one of those.
     *
     * @return BaseBuilder|null
     */
    public function getQueryBuilder(): ?BaseBuilder
    {
        return $this->queryBuilder;
    }

    /**
     * Get table to select from.
     *
     * @return Identifier|Expression|ExpressionContract|null
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * Get alias.
     *
     * @return Identifier
     */
    public function getAlias(): ?Identifier
    {
        return $this->alias;
    }

    /**
     * Converts strings to Identifier objects.
     *
     * @param array $array
     *
     * @return array
     */
    private function stringsToIdentifiers(array $array): array
    {
        return array_map(
            function ($element) {
                if (is_string($element)) {
                    return new Identifier($element);
                } else {
                    return $element;
                }
            },
            $array
        );
    }

    /**
     * Tries to decompose string join expression to table name and alias.
     *
     * @param string $table
     *
     * @return array
     */
    private function decomposeJoinExpressionToTableAndAlias(string $table): array
    {
        if (strpos(strtolower($table), ' as ') !== false) {
            return array_map('trim', preg_split('/\s+as\s+/i', $table));
        }

        return [$table, null];
    }
}
