<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits;

use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\BaseBuilder as Builder;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\UnionType;

trait UnionsComponentCompiler
{
    /**
     * Compiles unions to string to pass this string in query.
     *
     * The queries keep their call order and get no parentheses, so ClickHouse's own precedence applies between
     * them: INTERSECT binds tighter than UNION and EXCEPT, which apply from left to right.
     *
     * A query that has set operations of its own is one operand, so it is put in parentheses:
     * $a->except($b->unionAll($c)) compiles to A EXCEPT (B UNION ALL C). The parentheses are left out where they
     * cannot change the result (see canCompileWithoutParentheses()): $a->unionAll($b->unionAll($c)) compiles to
     * A UNION ALL B UNION ALL C, as it did when UNION ALL was the only set operator. ClickHouse 24.8 rejects
     * SETTINGS after a parenthesized last operand in a sub-query, so such a query with settings() keeps working
     * in from(), whereIn(), withExpression() or another set operation.
     *
     * @param Builder $builder
     * @param array   $unions
     *
     * @return string
     */
    public function compileUnionsComponent(Builder $builder, array $unions): string
    {
        $types = $builder->getUnionTypes();
        $indexes = array_keys($unions);
        $result = [];

        foreach ($indexes as $position => $index) {
            $query = $unions[$index];
            $type = $types[$index] ?? UnionType::UNION_ALL;
            $nextIndex = $indexes[$position + 1] ?? null;
            $nextType = $nextIndex === null ? null : ($types[$nextIndex] ?? UnionType::UNION_ALL);
            $sql = $query->toSql();

            if (!empty($query->getUnions()) && !$this->canCompileWithoutParentheses($query, $type, $nextType)) {
                $sql = "({$sql})";
            }

            $result[] = $type.' '.$sql;
        }

        return implode(' ', $result);
    }

    /**
     * Determine if an operand that has set operations of its own gives the same rows without parentheses.
     *
     * That is the case when the operand is joined with UNION ALL, all of its own set operations are UNION ALL,
     * it has no WITH clause of its own (ClickHouse applies a leading WITH clause to every SELECT of a set
     * operation, but a WITH clause on a later SELECT to that SELECT only), and the next set operation, if any,
     * is not INTERSECT or INTERSECT DISTINCT, which would take the last SELECT of the operand as its left side.
     *
     * @param Builder     $query
     * @param string      $type     The set operator that joins the operand
     * @param string|null $nextType The set operator that follows the operand, or null when it is the last one
     *
     * @return bool
     */
    private function canCompileWithoutParentheses(Builder $query, string $type, ?string $nextType): bool
    {
        if ($type !== UnionType::UNION_ALL || !empty($query->getWiths())) {
            return false;
        }

        foreach ($query->getUnionTypes() as $ownType) {
            if ($ownType !== UnionType::UNION_ALL) {
                return false;
            }
        }

        return !in_array($nextType, [UnionType::INTERSECT, UnionType::INTERSECT_DISTINCT], true);
    }
}
