<?php

namespace Tests\Unit\ClickhouseBuilder;

use PHPUnit\Framework\TestCase;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\JoinStrict;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\JoinType;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Operator;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Identifier;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\JoinClause;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\TwoElementsLogicExpression;

class JoinClauseTest extends TestCase
{
    public function getBuilder(): TestBuilder
    {
        return new TestBuilder();
    }

    public function testSettersGetters()
    {
        $join = new JoinClause($this->getBuilder());
        $join->table('table');
        $join->using(['column', 'another_column']);
        $join->addUsing('third_column');
        $join->addUsing(new Identifier('other_column'));

        $this->assertEquals('table', $join->getTable());
        $this->assertEquals(['column', 'another_column', 'third_column', 'other_column'], array_map(function ($using) {
            return (string) $using;
        }, $join->getUsing()));

        $join = new JoinClause($this->getBuilder());
        $join->on('first_column', '=', 'second_column');
        $join->on('first_column', '=', 'third_column');
        $this->assertEquals([
            (new TwoElementsLogicExpression($this->getBuilder()))->firstElement(new Identifier('first_column'))->operator('=')->secondElement(new Identifier('second_column'))->concatOperator(Operator::AND),
            (new TwoElementsLogicExpression($this->getBuilder()))->firstElement(new Identifier('first_column'))->operator('=')->secondElement(new Identifier('third_column'))->concatOperator(Operator::AND),
        ], $join->getOnClauses());

        $join->strict(JoinStrict::ALL);

        $this->assertEquals(JoinStrict::ALL, (string) $join->getStrict());

        $join->type(JoinType::LEFT);

        $this->assertEquals(JoinType::LEFT, (string) $join->getType());

        $join->any();
        $this->assertEquals(JoinStrict::ANY, (string) $join->getStrict());

        $join->all();
        $this->assertEquals(JoinStrict::ALL, (string) $join->getStrict());

        $join->inner();
        $this->assertEquals(JoinType::INNER, (string) $join->getType());

        $join->left();
        $this->assertEquals(JoinType::LEFT, (string) $join->getType());

        $join->distributed(true);
        $this->assertTrue($join->isDistributed());

        $alias = 'test';
        $join->as($alias);
        $this->assertEquals($join->getAlias(), $alias);

        $alias = 'test1';
        $join->subQuery($alias);
        $this->assertEquals($join->getAlias(), $alias);
    }

    public function testQuery()
    {
        $join = new JoinClause($this->getBuilder());
        $join = $join->query();

        $this->assertInstanceOf(TestBuilder::class, $join);

        $join = new JoinClause($this->getBuilder());
        $join->query(function ($join) {
            $join->table('table');
        });

        $this->assertEquals('(SELECT * FROM `table`)', (string) $join->getTable());
    }

    public function testSubQuery()
    {
        $join = new JoinClause($this->getBuilder());
        $join->query();

        $subQuery = $join->getSubQuery();

        $this->assertInstanceOf(TestBuilder::class, $subQuery);
    }

    public function testGetQueryBuilderOfABuilder(): void
    {
        $subQuery = $this->getBuilder()->select('column')->from('table2');
        $builder = $this->getBuilder()->from('table')->join($subQuery, 'any', 'left', ['column']);

        $this->assertSame($subQuery, $builder->getJoins()[0]->getQueryBuilder());
        $this->assertNull($builder->getJoins()[0]->getSubQuery());
        $this->assertEquals('SELECT * FROM `table` ANY LEFT JOIN (SELECT `column` FROM `table2`) USING `column`', $builder->toSql());

        $join = new JoinClause($this->getBuilder());
        $join->query($subQuery);

        $this->assertSame($subQuery, $join->getQueryBuilder());

        $join = new JoinClause($this->getBuilder());
        $join->table($subQuery);

        $this->assertSame($subQuery, $join->getQueryBuilder());
        $this->assertEquals('(SELECT `column` FROM `table2`)', (string) $join->getTable());
    }

    public function testGetQueryBuilderOfAJoinHelper(): void
    {
        $subQuery = $this->getBuilder()->select('column')->from('table2');

        $builder = $this->getBuilder()->from('table')->allInnerJoin($subQuery, ['column'], false, 'alias');

        $this->assertSame($subQuery, $builder->getJoins()[0]->getQueryBuilder());
        $this->assertEquals(
            'SELECT * FROM `table` ALL INNER JOIN (SELECT `column` FROM `table2`) AS `alias` USING `column`',
            $builder->toSql()
        );

        $helpers = [
            'leftJoin'      => [$subQuery, null, ['column']],
            'innerJoin'     => [$subQuery, null, ['column']],
            'rightJoin'     => [$subQuery, null, ['column']],
            'fullJoin'      => [$subQuery, null, ['column']],
            'anyLeftJoin'   => [$subQuery, ['column']],
            'allLeftJoin'   => [$subQuery, ['column']],
            'anyInnerJoin'  => [$subQuery, ['column']],
            'anyRightJoin'  => [$subQuery, ['column']],
            'allRightJoin'  => [$subQuery, ['column']],
            'semiLeftJoin'  => [$subQuery, ['column']],
            'semiRightJoin' => [$subQuery, ['column']],
            'antiLeftJoin'  => [$subQuery, ['column']],
            'antiRightJoin' => [$subQuery, ['column']],
            'asofJoin'      => [$subQuery, ['column']],
            'asofLeftJoin'  => [$subQuery, ['column']],
            'crossJoin'     => [$subQuery],
        ];

        foreach ($helpers as $method => $arguments) {
            $builder = $this->getBuilder()->from('table')->{$method}(...$arguments);

            $this->assertSame($subQuery, $builder->getJoins()[0]->getQueryBuilder(), $method);
        }
    }

    public function testGetQueryBuilderOfAClosure(): void
    {
        $builder = $this->getBuilder()->from('table')->allInnerJoin(function (JoinClause $join) {
            $join->query()->select('column')->from('table2');
        }, ['column']);
        $join = $builder->getJoins()[0];

        $this->assertInstanceOf(TestBuilder::class, $join->getQueryBuilder());
        $this->assertSame($join->getSubQuery(), $join->getQueryBuilder());
        $this->assertEquals('SELECT * FROM `table` ALL INNER JOIN (SELECT `column` FROM `table2`) USING `column`', $builder->toSql());

        $builder = $this->getBuilder()->from('table')->anyLeftJoin(function (JoinClause $join) {
            $join->subQuery('alias')->select('column')->from('table2');
        }, ['column']);
        $join = $builder->getJoins()[0];

        $this->assertInstanceOf(TestBuilder::class, $join->getQueryBuilder());
        $this->assertSame($join->getSubQuery(), $join->getQueryBuilder());
        $this->assertEquals(
            'SELECT * FROM `table` ANY LEFT JOIN (SELECT `column` FROM `table2`) AS `alias` USING `column`',
            $builder->toSql()
        );

        $subQuery = $this->getBuilder()->select('column')->from('table2');
        $builder = $this->getBuilder()->from('table')->join(function (JoinClause $join) use ($subQuery) {
            $join->query($subQuery)->as('alias');
        }, 'all', 'left', ['column']);

        $this->assertSame($subQuery, $builder->getJoins()[0]->getQueryBuilder());
        $this->assertEquals(
            'SELECT * FROM `table` ALL LEFT JOIN (SELECT `column` FROM `table2`) AS `alias` USING `column`',
            $builder->toSql()
        );

        $join = new JoinClause($this->getBuilder());
        $join->query(function (TestBuilder $query) {
            $query->select('column')->from('table2');
        });

        $this->assertInstanceOf(TestBuilder::class, $join->getQueryBuilder());
        $this->assertEquals('SELECT `column` FROM `table2`', $join->getQueryBuilder()->toSql());
    }

    public function testGetQueryBuilderOfATableIsNull(): void
    {
        $this->assertNull((new JoinClause($this->getBuilder()))->getQueryBuilder());
        $this->assertNull($this->getBuilder()->from('table')->allLeftJoin('table2', ['column'])->getJoins()[0]->getQueryBuilder());
        $this->assertNull(
            $this->getBuilder()->from('table')->crossJoin(new Expression('numbers(3)'))->getJoins()[0]->getQueryBuilder()
        );

        $join = new JoinClause($this->getBuilder());
        $join->query($this->getBuilder()->select('column')->from('table2'));
        $join->table('table3');

        $this->assertNull($join->getQueryBuilder());

        $builder = $this->getBuilder()->from('table')->join(function (JoinClause $join) {
            $join->query($this->getBuilder()->select('column')->from('table2'));
            $join->table('table3');
        }, 'all', 'left', ['column']);

        $this->assertNull($builder->getJoins()[0]->getQueryBuilder());
        $this->assertEquals('SELECT * FROM `table` ALL LEFT JOIN `table3` USING `column`', $builder->toSql());

        $join = new JoinClause($this->getBuilder());
        $join->table($this->getBuilder()->select('column')->from('table2'));
        $join->table(new Expression('numbers(3)'));

        $this->assertNull($join->getQueryBuilder());
    }
}
