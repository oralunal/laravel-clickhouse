<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits;

use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\ArrayJoinClause;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\BaseBuilder as Builder;

trait ArrayJoinComponentCompiler
{
    /**
     * Compiles join to string to pass this string in query.
     *
     * @param Builder         $query
     * @param ArrayJoinClause $join
     *
     * @return string
     */
    protected function compileArrayJoinComponent(Builder $query, ArrayJoinClause $join): string
    {
        $result = [];

        if (!is_null($join->getType())) {
            $result[] = $join->getType();
        }

        $result[] = 'ARRAY JOIN';
        $result[] = $this->wrap($join->getArrayIdentifier());

        return implode(' ', $result);
    }
}
