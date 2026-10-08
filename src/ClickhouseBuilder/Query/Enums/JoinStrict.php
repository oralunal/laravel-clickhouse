<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums;

use Oralunal\LaravelClickHouse\Enum\Enum;

/**
 * Join strictness.
 */
final class JoinStrict extends Enum
{
    public const ALL = 'ALL';
    public const ANY = 'ANY';
}
