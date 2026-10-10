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
        $result[] = implode(', ', array_map(function (array $array) {
            $compiled = (string) $this->wrap($array['array']);

            if (!is_null($array['alias'])) {
                $compiled .= " AS {$this->wrap($array['alias'])}";
            }

            return $compiled;
        }, $join->getArrays()));

        return implode(' ', $result);
    }
}
