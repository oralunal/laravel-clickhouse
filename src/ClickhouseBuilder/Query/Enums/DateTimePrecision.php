<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums;

use Oralunal\LaravelClickHouse\Enum\Enum;

/**
 * Precisions with which the grammar writes a DateTimeInterface value, in the value's own time zone.
 *
 * SECOND, the default, writes 'Y-m-d H:i:s' and drops the sub-second part. MICROSECOND writes 'Y-m-d H:i:s.u' for a
 * value with a sub-second part, and 'Y-m-d H:i:s' for a whole second.
 */
final class DateTimePrecision extends Enum
{
    public const SECOND = 'second';
    public const MICROSECOND = 'microsecond';
}
