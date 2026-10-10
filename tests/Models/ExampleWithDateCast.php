<?php

namespace Tests\Models;

use Oralunal\LaravelClickHouse\BaseModel;

/**
 * A model with date and time casts and no $dateFormat, so its casts follow the datetime_precision of its
 * connection. The table is created by the test that uses it.
 */
class ExampleWithDateCast extends BaseModel
{
    protected $table = 'date_cast_examples';

    protected $casts = ['d6' => 'datetime', 'dt' => 'datetime', 'd' => 'date'];
}
