<?php

namespace Tests\Models;

use Oralunal\LaravelClickHouse\BaseModel;

/**
 * @property string $f_string
 * @property int $f_int
 */
class Example4Problem extends BaseModel
{
    protected $connection = 'problem-clickhouse-cluster';
    protected $table = 'examples4';
}