<?php

namespace Tests\Unit\ClickhouseBuilder;

use PHPUnit\Framework\TestCase;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\From;

class FromTest extends TestCase
{
    public function getBuilder(): TestBuilder
    {
        return new TestBuilder();
    }

    public function testFromTable()
    {
        $from = new From($this->getBuilder());
        $from->table('table');
        $from->as('alias');
        $from->final(true);

        $this->assertEquals('table', $from->getTable());
        $this->assertEquals('alias', $from->getAlias());
        $this->assertTrue($from->getFinal());
    }

    public function testMerge()
    {
        $from = new From($this->getBuilder());
        $from->merge('database', 'test-.*');

        $this->assertEquals('merge(database, \'test-.*\')', $from->getTable());
    }

    public function testRemote()
    {
        $from = new From($this->getBuilder());
        $from->remote('test', 'database', 'table');

        $this->assertEquals('remote(\'test\', database, table)', $from->getTable());

        $from->remote('test', 'database', 'table', 'default', 'password');

        $this->assertEquals('remote(\'test\', database, table, default, password)', $from->getTable());
    }

    public function testQuery()
    {
        $from = new From($this->getBuilder());
        $subQuery = $from->query()->select('column')->from('table');

        $this->assertInstanceOf(TestBuilder::class, $subQuery);
        $this->assertEquals('SELECT `column` FROM `table`', $subQuery->toSql());

        $from = $from->query(function ($subQuery) {
            $subQuery->select('column')->from('table');
        });

        $this->assertInstanceOf(From::class, $from);
        $this->assertInstanceOf(Expression::class, $from->getTable());
        $this->assertEquals('(SELECT `column` FROM `table`)', $from->getTable()->getValue());

        $builder = $this->getBuilder();
        $builder->select('another_column')->from('another_table');

        $from = $from->query($builder);
        $this->assertInstanceOf(From::class, $from);
        $this->assertEquals('(SELECT `another_column` FROM `another_table`)', $from->getTable()->getValue());
    }
}
