<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions;

use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\JoinType;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\JoinClause;

class GrammarException extends Exception
{
    public static function wrongJoin(JoinClause $joinClause): self
    {
        $whatMissing = [];
        $isCrossJoin = (string) $joinClause->getType() === JoinType::CROSS;

        if (is_null($joinClause->getStrict()) && !$isCrossJoin) {
            $whatMissing[] = 'strict';
        }

        if (is_null($joinClause->getType())) {
            $whatMissing[] = 'type';
        }

        if (is_null($joinClause->getTable())) {
            $whatMissing[] = 'table or subquery';
        }

        if (is_null($joinClause->getUsing()) && is_null($joinClause->getOnClauses()) && !$isCrossJoin) {
            $whatMissing[] = 'using or on clauses';
        }

        $whatMissing = implode(', ', $whatMissing);

        return new static("Missed required segments for 'JOIN' section. Missed: {$whatMissing}");
    }

    public static function ambiguousJoinKeys(): self
    {
        return new static('You cannot use using and on clauses as join keys for the same join.');
    }

    public static function wrongCrossJoin(): self
    {
        return new static('A CROSS JOIN takes no strictness and no using or on clauses.');
    }

    public static function wrongFrom(): self
    {
        return new static("Missed table or subquery for 'FROM' section.");
    }

    public static function missedTableForInsert(): self
    {
        return new static('Missed table for insert statement.');
    }

    public static function missedWhereForDelete(): self
    {
        return new static('Missed where section for delete statement.');
    }
}
