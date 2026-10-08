<?php

namespace PhpClickHouseLaravel\ClickhouseBuilder\Query\Enums;

use PhpClickHouseLaravel\Enum\Enum;

/**
 * Order directions.
 */
final class OrderDirection extends Enum
{
    public const ASC = 'ASC';
    public const DESC = 'DESC';
}
