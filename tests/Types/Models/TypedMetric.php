<?php

declare(strict_types=1);

namespace Tests\Types\Models;

use Oralunal\LaravelClickHouse\Eloquent\Model;

/**
 * A model of the metrics table of the ClickHouse migrations of tests/Types/migrations, whose columns PHPStan reads.
 */
class TypedMetric extends Model
{
    protected $table = 'metrics';
}
