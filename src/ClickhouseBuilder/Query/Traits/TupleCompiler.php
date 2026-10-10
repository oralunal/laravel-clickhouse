<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits;

use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Tuple;

trait TupleCompiler
{
    /**
     * Compiles tuple to string to use this string in query.
     *
     * A nested array becomes a tuple in parentheses, so rows can be compared with several columns:
     * [[1, 'a'], [2, 'b']] compiles to (1, 'a'), (2, 'b').
     *
     * @param Tuple $tuple
     *
     * @return string
     */
    public function compileTuple(Tuple $tuple): string
    {
        return implode(', ', array_map(function ($element) {
            return is_array($element) ? '('.$this->compileTuple(new Tuple($element)).')' : $this->wrap($element);
        }, $tuple->getElements()));
    }
}
