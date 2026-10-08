<?php

namespace PhpClickHouseLaravel\ClickhouseBuilder\Query\Traits;

use PhpClickHouseLaravel\ClickhouseBuilder\Query\BaseBuilder;
use PhpClickHouseLaravel\ClickhouseBuilder\Query\Column;

trait ColumnsComponentCompiler
{
    use ColumnCompiler;

    /**
     * Compiles columns for select statement.
     *
     * @param BaseBuilder $builder
     * @param Column[]    $columns
     *
     * @return string
     */
    private function compileColumnsComponent(BaseBuilder $builder, array $columns): string
    {
        $compiledColumns = [];

        foreach ($columns as $column) {
            $compiledColumns[] = $this->compileColumn($column);
        }

        return implode(', ', $compiledColumns);
    }
}
