<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits;

use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\BaseBuilder as Builder;

trait SampleComponentCompiler
{
    /**
     * Compiles sample to string to pass this string in query.
     *
     * @param Builder    $builder
     * @param float|null $sample
     *
     * @return string
     */
    public function compileSampleComponent(Builder $builder, ?float $sample = null): string
    {
        $offset = $builder->getSampleOffset();

        if (is_null($offset)) {
            return "SAMPLE {$sample}";
        }

        return "SAMPLE {$sample} OFFSET {$offset}";
    }
}
