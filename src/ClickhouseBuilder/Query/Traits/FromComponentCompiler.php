<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits;

use Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions\GrammarException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\BaseBuilder;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\From;

trait FromComponentCompiler
{
    /**
     * Compiles from statement.
     *
     * FINAL is written only when the final option is true, so final(false) and a false $isFinal given to from()
     * or table() leave it out, as when the option was never set.
     *
     * @param BaseBuilder $builder
     * @param             $from
     *
     * @return string
     */
    public function compileFromComponent(BaseBuilder $builder, From $from): string
    {
        $this->verifyFrom($from);

        $table = $from->getTable();
        $alias = $from->getAlias();
        $final = $from->getFinal();

        $fromSection = '';
        $fromSection .= "FROM {$this->wrap($table)}";

        if (!is_null($alias)) {
            $fromSection .= " AS {$this->wrap($alias)}";
        }

        if ($final === true) {
            $fromSection .= ' FINAL';
        }

        return $fromSection;
    }

    /**
     * Verifies from.
     *
     * @param From $from
     *
     * @throws GrammarException
     */
    private function verifyFrom(From $from)
    {
        if (is_null($from->getTable())) {
            throw GrammarException::wrongFrom();
        }
    }
}
