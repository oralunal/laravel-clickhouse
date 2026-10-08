<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums;

use Oralunal\LaravelClickHouse\Enum\Enum;

/**
 * Order directions.
 */
final class OrderDirection extends Enum
{
    public const ASC = 'ASC';
    public const DESC = 'DESC';
}
