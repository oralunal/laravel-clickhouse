<?php

namespace Tests\Unit\ClickhouseBuilder;

use PHPUnit\Framework\TestCase;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Identifier;

class IdentifierTest extends TestCase
{
    public function testToString()
    {
        $identifier = new Identifier('column');

        $this->assertEquals('column', (string) $identifier);
    }
}
