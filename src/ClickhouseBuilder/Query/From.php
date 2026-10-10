<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\tp;

class From
{
    /**
     * Table name.
     *
     * @var Identifier|Expression|ExpressionContract|null
     */
    private $table;

    /**
     * Table alias.
     *
     * @var Identifier|null
     */
    private $alias;

    /**
     * Final option.
     *
     * @var bool|null
     */
    private $final;

    /**
     * BaseBuilder is needed to pass bindings in main query.
     *
     * @var BaseBuilder
     */
    private $query;

    /**
     * Query which was made with sub-query BaseBuilder.
     *
     * @var BaseBuilder|null
     */
    private $subQuery;

    /**
     * Builder whose SQL was compiled into the FROM clause as a sub-query.
     *
     * @var BaseBuilder|null
     */
    private $queryBuilder;

    /**
     * From constructor.
     *
     * @param BaseBuilder $query
     */
    public function __construct(BaseBuilder $query)
    {
        $this->query = $query;
    }

    /**
     * Set table name.
     *
     * A string is a table name, quoted part by part, so db.events becomes `db`.`events`. An Expression or a Laravel
     * database expression, such as DB::raw('numbers(10)'), is raw SQL, written as it is.
     *
     * @param string|Expression|ExpressionContract $table
     *
     * @return From
     */
    public function table($table): self
    {
        if (is_string($table)) {
            $table = new Identifier($table);
        }

        $this->table = $table;
        $this->queryBuilder = null;

        return $this;
    }

    /**
     * Set table alias.
     *
     * @param string $alias
     *
     * @return From
     */
    public function as(string $alias): self
    {
        $this->alias = new Identifier($alias);

        return $this;
    }

    /**
     * Set final option.
     *
     * Used in CollapsingMergeTree tables. True writes FINAL after the table; false leaves it out.
     *
     * @param bool $isFinal
     *
     * @return From
     */
    public function final(bool $isFinal = true): self
    {
        $this->final = $isFinal;

        return $this;
    }

    /**
     * Use remote function to get data from remote server without table with Distributed engine.
     *
     * @param string      $expression
     * @param string      $database
     * @param string      $table
     * @param string|null $user
     * @param string|null $password
     *
     * @return From
     */
    public function remote(string $expression, string $database, string $table, ?string $user = null, ?string $password = null): self
    {
        $remote = "remote('{$expression}', {$database}, {$table}";

        if (!is_null($user)) {
            $remote .= ", {$user}";
        }

        if (!is_null($password)) {
            $remote .= ", {$password}";
        }

        $remote .= ')';

        return $this->table(new Expression($remote));
    }

    /**
     * Creates temp table with Merge engine
     * Structure takes from first table in regular expression.
     *
     * @param string $database
     * @param string $regexp
     *
     * @return From
     */
    public function merge(string $database, string $regexp): self
    {
        return $this->table(new Expression("merge({$database}, '{$regexp}')"));
    }

    /**
     * Executes sub-query in from statement.
     *
     * @param \Closure|BaseBuilder|null $query
     *
     * @return From|BaseBuilder
     */
    public function query($query = null)
    {
        if (is_null($query)) {
            return $this->subQuery();
        }

        if ($query instanceof \Closure) {
            $query = tp($this->query->newQuery(), $query);
        }

        if ($query instanceof BaseBuilder) {
            if (is_null($this->alias) && !is_null($this->table)) {
                $this->as($this->table);
            }

            $this->table(new Expression("({$query->toSql()})"));
            $this->queryBuilder = $query;
        }

        return $this;
    }

    /**
     * Get sub-query.
     *
     * @return BaseBuilder
     */
    public function subQuery(): BaseBuilder
    {
        return $this->subQuery = $this->query->newQuery();
    }

    /**
     * Get table name.
     *
     * @return Expression|ExpressionContract|Identifier|null
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
     * Get final option.
     *
     * Null when final() was not called. Only true writes FINAL.
     *
     * @return bool|null
     */
    public function getFinal(): ?bool
    {
        return $this->final;
    }

    /**
     * Get sub-query BaseBuilder.
     *
     * @return null|BaseBuilder
     */
    public function getSubQuery(): ?BaseBuilder
    {
        return $this->subQuery;
    }

    /**
     * Get the builder whose SQL was compiled into the FROM clause as a sub-query.
     *
     * It is set by from($builder), query($builder) and the closure forms, and is null when the FROM clause
     * names a table or a table function.
     *
     * @return BaseBuilder|null
     */
    public function getQueryBuilder(): ?BaseBuilder
    {
        return $this->queryBuilder;
    }
}
