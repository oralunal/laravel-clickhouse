<?php

namespace Tests\Unit\ClickhouseBuilder;

use PhpClickHouseLaravel\ClickhouseBuilder\Query\BaseBuilder;
use PhpClickHouseLaravel\ClickhouseBuilder\Query\Grammar;

/**
 * Minimal concrete builder that compiles SQL without a ClickHouse client.
 */
class TestBuilder extends BaseBuilder
{
    public function __construct()
    {
        $this->grammar = new Grammar();
    }

    public function newQuery(): static
    {
        return new static();
    }
}
