<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits;

use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\BaseBuilder as Builder;

trait WithsComponentCompiler
{
    /**
     * Compiles the WITH clause which is placed before SELECT.
     *
     * The clause becomes WITH RECURSIVE when any of its entries is recursive. Each name is quoted as one
     * identifier by quoteIdentifier(), even when it contains a dot or ' as '.
     *
     * @param Builder                                                                    $builder
     * @param array<int, array{type: string, name: string, value: mixed, recursive: bool}> $withs
     *
     * @return string
     */
    public function compileWithsComponent(Builder $builder, array $withs): string
    {
        $isRecursive = false;
        $entries = [];

        foreach ($withs as $with) {
            $name = $this->quoteIdentifier($with['name']);

            if ($with['type'] === Builder::WITH_ALIAS) {
                $entries[] = "{$this->compileWithAliasValue($with['value'])} AS {$name}";
            } else {
                $entries[] = "{$name} AS ({$this->compileWithExpressionQuery($with['value'])})";
            }

            $isRecursive = $isRecursive || $with['recursive'];
        }

        return ($isRecursive ? 'WITH RECURSIVE ' : 'WITH ').implode(', ', $entries);
    }

    /**
     * Compiles the query of a named sub-query in the WITH clause.
     *
     * @param Builder|\Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression $query
     *
     * @return string
     */
    private function compileWithExpressionQuery($query): string
    {
        if ($query instanceof Builder) {
            return $query->toSql();
        }

        return (string) $this->wrap($query);
    }

    /**
     * Compiles the value of an aliased expression in the WITH clause.
     *
     * A builder becomes a scalar sub-query and an array becomes an array literal.
     *
     * @param mixed $value
     *
     * @return string
     */
    private function compileWithAliasValue($value): string
    {
        if ($value instanceof Builder) {
            return "({$value->toSql()})";
        }

        if (is_array($value)) {
            return $this->compileArray($value);
        }

        return (string) $this->wrap($value);
    }
}
