<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits;

use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Tuple;

trait TupleCompiler
{
    /**
     * Compiles tuple to string to use this string in query.
     *
     * @param Tuple $tuple
     *
     * @return string
     */
    public function compileTuple(Tuple $tuple): string
    {
        return implode(', ', array_map([$this, 'wrap'], $tuple->getElements()));
    }
}
