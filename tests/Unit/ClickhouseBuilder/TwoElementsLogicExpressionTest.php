<?php

namespace Tests\Unit\ClickhouseBuilder;

use PHPUnit\Framework\TestCase;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Operator;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\TwoElementsLogicExpression;

class TwoElementsLogicExpressionTest extends TestCase
{
    public function getBuilder(): TestBuilder
    {
        return new TestBuilder();
    }

    public function testPlainElements()
    {
        $builder = $this->getBuilder();
        $expression = new TwoElementsLogicExpression($builder);

        $expression->firstElement('column');
        $expression->secondElement('another_column');
        $expression->operator('=');
        $expression->concatOperator(Operator::OR);

        $this->assertEquals('column', $expression->getFirstElement());
        $this->assertEquals('another_column', $expression->getSecondElement());
        $this->assertEquals('=', $expression->getOperator());
        $this->assertEquals(Operator::OR, $expression->getConcatenationOperator());
    }

    public function testBuilderElements()
    {
        $builder = $this->getBuilder();
        $expression = new TwoElementsLogicExpression($builder);

        $expression->firstElementQuery($this->getBuilder()->select('column')->from('table'));
        $expression->secondElementQuery($this->getBuilder()->select('another_column')->from('table'));
        $expression->operator('=');
        $expression->concatOperator(Operator::OR);

        $this->assertEquals('(SELECT `column` FROM `table`)', $expression->getFirstElement());
        $this->assertEquals('(SELECT `another_column` FROM `table`)', $expression->getSecondElement());
        $this->assertEquals('=', $expression->getOperator());
        $this->assertEquals(Operator::OR, $expression->getConcatenationOperator());
    }

    public function testClosureElements()
    {
        $builder = $this->getBuilder();
        $expression = new TwoElementsLogicExpression($builder);

        $expression->firstElementQuery(function ($builder) {
            return $builder->select('column')->from('table');
        });

        $expression->secondElementQuery(function ($builder) {
            return $builder->select('another_column')->from('table');
        });
        $expression->operator('=');
        $expression->concatOperator(Operator::OR);

        $this->assertEquals('(SELECT `column` FROM `table`)', $expression->getFirstElement());
        $this->assertEquals('(SELECT `another_column` FROM `table`)', $expression->getSecondElement());
        $this->assertEquals('=', $expression->getOperator());
        $this->assertEquals(Operator::OR, $expression->getConcatenationOperator());
    }

    public function testOperatorsAreReadInAnyLetterCase()
    {
        $expression = (new TwoElementsLogicExpression($this->getBuilder()))
            ->operator('like')
            ->concatOperator('or');

        $this->assertSame(Operator::LIKE, $expression->getOperator()->getValue());
        $this->assertSame(Operator::OR, $expression->getConcatenationOperator()->getValue());

        $this->assertSame(Operator::NOT_ILIKE, $expression->operator('Not ILike')->getOperator()->getValue());
        $this->assertSame(Operator::AND, $expression->concatOperator('And')->getConcatenationOperator()->getValue());
        $this->assertSame(Operator::LAMBDA, $expression->operator('->')->getOperator()->getValue());
        $this->assertSame(Operator::EQUALS, $expression->operator('=')->getOperator()->getValue());
    }

    public function testAnUnknownOperatorIsNamedAsItWasGiven()
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Value 'xor' is not part of the enum");

        (new TwoElementsLogicExpression($this->getBuilder()))->concatOperator('xor');
    }
}
