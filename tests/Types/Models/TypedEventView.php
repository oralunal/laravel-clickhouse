<?php

declare(strict_types=1);

namespace Tests\Types\Models;

use Oralunal\LaravelClickHouse\Eloquent\Model;

/**
 * A model of a view of the ClickHouse schema dump of tests/Types/schema.
 */
class TypedEventView extends Model
{
    protected $table = 'events_view';
}
