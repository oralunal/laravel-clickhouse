<?php

namespace Tests\Models;

use Oralunal\LaravelClickHouse\BaseModel;

/**
 * @property string $f_string
 * @property int $f_int
 */
class Example4 extends BaseModel
{
    protected $connection = 'clickhouse-cluster';
    protected $table = 'examples4';
}