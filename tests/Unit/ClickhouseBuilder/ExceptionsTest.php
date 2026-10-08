<?php

namespace Tests\Unit\ClickhouseBuilder;

use PHPUnit\Framework\TestCase;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions\BuilderException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions\GrammarException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions\NotSupportedException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\JoinClause;

class ExceptionsTest extends TestCase
{
    public function getBuilder(): TestBuilder
    {
        return new TestBuilder();
    }

    public function testBuilderException()
    {
        $e = BuilderException::cannotDetermineAliasForColumn();
        $this->assertInstanceOf(BuilderException::class, $e);
    }

    public function testGrammarException()
    {
        $e = GrammarException::missedTableForInsert();
        $this->assertInstanceOf(GrammarException::class, $e);

        $e = GrammarException::wrongFrom();
        $this->assertInstanceOf(GrammarException::class, $e);

        $join = new JoinClause($this->getBuilder());

        $e = GrammarException::wrongJoin($join);
        $this->assertInstanceOf(GrammarException::class, $e);

        $e = GrammarException::ambiguousJoinKeys();
        $this->assertInstanceOf(GrammarException::class, $e);
    }

    public function testNotSupportedException()
    {
        $e = NotSupportedException::transactions();
        $this->assertInstanceOf(NotSupportedException::class, $e);

        $e = NotSupportedException::update();
        $this->assertInstanceOf(NotSupportedException::class, $e);
    }
}
