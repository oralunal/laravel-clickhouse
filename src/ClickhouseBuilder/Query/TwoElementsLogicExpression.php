<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query;

use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Operator;
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\tp;

class TwoElementsLogicExpression
{
    /**
     * First element.
     *
     * May be array or TwoElementsLogicExpression.
     *
     * @var mixed
     */
    private $firstElement;

    /**
     * Operator.
     *
     * @var Operator
     */
    private $operator;

    /**
     * Second element.
     *
     * @var mixed
     */
    private $secondElement;

    /**
     * Operator which concatenates main statement.
     *
     * May be OR or AND
     *
     * @var Operator
     */
    private $concatenationOperator;

    /**
     * Builder.
     *
     * @var BaseBuilder
     */
    private $query;

    /**
     * TwoElementsLogicExpression constructor.
     *
     * @param BaseBuilder $query
     */
    public function __construct(BaseBuilder $query)
    {
        $this->query = $query;
    }

    /**
     * Set first element.
     *
     * @param mixed $element
     *
     * @return $this
     */
    public function firstElement($element)
    {
        $this->firstElement = $element;

        return $this;
    }

    /**
     * Operator between two elements.
     *
     * The operator is read in any letter case, as Laravel code writes it: 'like' and 'not in' are LIKE and NOT IN.
     *
     * @param string $operator
     *
     * @throws \UnexpectedValueException When the Operator enum has no such operator, in any letter case
     *
     * @return $this
     */
    public function operator(string $operator)
    {
        $this->operator = $this->toOperator($operator);

        return $this;
    }

    /**
     * Set second element.
     *
     * @param mixed $element
     *
     * @return $this
     */
    public function secondElement($element)
    {
        $this->secondElement = $element;

        return $this;
    }

    /**
     * Set concatenate operator.
     *
     * The operator is read in any letter case, as Laravel code writes its booleans: 'and' and 'or' are AND and OR.
     *
     * @param string $operator
     *
     * @throws \UnexpectedValueException When the Operator enum has no such operator, in any letter case
     *
     * @return $this
     */
    public function concatOperator(string $operator)
    {
        $this->concatenationOperator = $this->toOperator($operator);

        return $this;
    }

    /**
     * Make the Operator of an operator given in any letter case.
     *
     * The operator is upper-cased when the Operator enum has the upper-case form, so a symbol such as = or -> is
     * kept as it is, and the exception for an unknown operator names it as it was given.
     *
     * @param string $operator
     *
     * @throws \UnexpectedValueException When the Operator enum has no such operator, in any letter case
     *
     * @return Operator
     */
    protected function toOperator(string $operator): Operator
    {
        $upperCaseOperator = strtoupper($operator);

        return new Operator(Operator::isValid($upperCaseOperator) ? $upperCaseOperator : $operator);
    }

    /**
     * Build query string for first element.
     *
     * @param \Closure|BaseBuilder $query
     *
     * @return TwoElementsLogicExpression
     */
    public function firstElementQuery($query): self
    {
        if ($query instanceof \Closure) {
            $query = tp($this->query->newQuery(), $query);
        }

        if ($query instanceof BaseBuilder) {
            $this->firstElement(new Expression("({$query->toSql()})"));
        }

        return $this;
    }

    /**
     * Build query string for second element.
     *
     * @param $query
     *
     * @return TwoElementsLogicExpression
     */
    public function secondElementQuery($query): self
    {
        if ($query instanceof \Closure) {
            $query = tp($this->query->newQuery(), $query);
        }

        if ($query instanceof BaseBuilder) {
            $this->secondElement(new Expression("({$query->toSql()})"));
        }

        return $this;
    }

    /**
     * Get first element.
     *
     * @return mixed
     */
    public function getFirstElement()
    {
        return $this->firstElement;
    }

    /**
     * Get operator.
     *
     * @return mixed
     */
    public function getOperator(): ?Operator
    {
        return $this->operator;
    }

    /**
     * Get seconds element.
     *
     * @return mixed
     */
    public function getSecondElement()
    {
        return $this->secondElement;
    }

    /**
     * Get concatenation operator.
     *
     * @return mixed
     */
    public function getConcatenationOperator(): Operator
    {
        return $this->concatenationOperator;
    }
}
