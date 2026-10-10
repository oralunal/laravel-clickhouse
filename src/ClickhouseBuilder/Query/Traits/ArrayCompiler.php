<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits;

trait ArrayCompiler
{
    /**
     * Compiles a PHP array to a ClickHouse array literal, for example [1, 2] or ['a', 'b'].
     *
     * Keys are ignored and nested arrays become nested array literals.
     *
     * @param array $elements
     *
     * @return string
     */
    public function compileArray(array $elements): string
    {
        return '['.implode(', ', array_map(function ($element) {
            return is_array($element) ? $this->compileArray($element) : $this->wrap($element);
        }, $elements)).']';
    }
}
