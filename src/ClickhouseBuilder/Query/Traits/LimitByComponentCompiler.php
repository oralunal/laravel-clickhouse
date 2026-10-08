<?php

namespace PhpClickHouseLaravel\ClickhouseBuilder\Query\Traits;

use PhpClickHouseLaravel\ClickhouseBuilder\Query\BaseBuilder as Builder;
use PhpClickHouseLaravel\ClickhouseBuilder\Query\Limit;

trait LimitByComponentCompiler
{
    /**
     * Compiles limit n by to string to pass this string in query.
     *
     * @param Builder $builder
     * @param Limit   $limit
     *
     * @return string
     */
    public function compileLimitByComponent(Builder $builder, Limit $limit): string
    {
        $mainLimit = $this->compileLimitComponent($builder, $limit);
        $columns = '';

        if (!empty($limit->getBy())) {
            $columns = $this->compileColumnsComponent($builder, $limit->getBy());
        }

        return "{$mainLimit} BY {$columns}";
    }
}
