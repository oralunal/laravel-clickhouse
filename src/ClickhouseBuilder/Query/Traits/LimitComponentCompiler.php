<?php

namespace PhpClickHouseLaravel\ClickhouseBuilder\Query\Traits;

use PhpClickHouseLaravel\ClickhouseBuilder\Query\BaseBuilder as Builder;
use PhpClickHouseLaravel\ClickhouseBuilder\Query\Limit;

trait LimitComponentCompiler
{
    /**
     * Compiles limit to string to pass this string in query.
     *
     * @param Builder $builder
     * @param Limit   $limit
     *
     * @return string
     */
    public function compileLimitComponent(Builder $builder, Limit $limit): string
    {
        $limitElements = [];

        if (!is_null($limit->getOffset())) {
            $limitElements[] = $limit->getOffset();
        }

        if (!is_null($limit->getLimit())) {
            $limitElements[] = $limit->getLimit();
        }

        return 'LIMIT '.implode(', ', $limitElements);
    }
}
