<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums;

use Oralunal\LaravelClickHouse\Enum\Enum;

/**
 * Set operators that combine a query with the queries added by unionAll(), intersect(), except() and their variants.
 */
final class UnionType extends Enum
{
    public const UNION_ALL = 'UNION ALL';
    public const UNION_DISTINCT = 'UNION DISTINCT';
    public const INTERSECT = 'INTERSECT';
    public const INTERSECT_DISTINCT = 'INTERSECT DISTINCT';
    public const EXCEPT = 'EXCEPT';
    public const EXCEPT_DISTINCT = 'EXCEPT DISTINCT';
}
