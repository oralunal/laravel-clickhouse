<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Enums;

/**
 * The precision of the dates that the package writes into the SQL, from the datetime_precision option of a
 * connection: whole seconds, or microseconds for a date with a sub-second part.
 */
enum DateTimePrecision: string
{
    case Second = 'second';
    case Microsecond = 'microsecond';
}
