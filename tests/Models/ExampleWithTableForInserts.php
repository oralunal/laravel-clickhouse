<?php

namespace Tests\Models;

use Oralunal\LaravelClickHouse\BaseModel;

/**
 * A model that reads from examples and inserts into examples3.
 */
class ExampleWithTableForInserts extends BaseModel
{
    protected $table = 'examples';
    protected $tableForInserts = 'examples3';
}
