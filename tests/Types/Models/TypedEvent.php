<?php

declare(strict_types=1);

namespace Tests\Types\Models;

use Oralunal\LaravelClickHouse\Eloquent\Model;

/**
 * A model of the events table of the ClickHouse schema dump of tests/Types/schema, whose columns PHPStan reads.
 */
class TypedEvent extends Model
{
    protected $table = 'events';
}
