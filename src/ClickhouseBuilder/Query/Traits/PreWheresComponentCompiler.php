<?php

namespace PhpClickHouseLaravel\ClickhouseBuilder\Query\Traits;

use PhpClickHouseLaravel\ClickhouseBuilder\Query\BaseBuilder as Builder;
use PhpClickHouseLaravel\ClickhouseBuilder\Query\TwoElementsLogicExpression;

trait PreWheresComponentCompiler
{
    /**
     * Compiles prewhere to string to pass this string in query.
     *
     * @param Builder                      $builder
     * @param TwoElementsLogicExpression[] $preWheres
     *
     * @return string
     */
    public function compilePrewheresComponent(Builder $builder, array $preWheres): string
    {
        $result = $this->compileTwoElementLogicExpressions($preWheres);

        return "PREWHERE {$result}";
    }
}
