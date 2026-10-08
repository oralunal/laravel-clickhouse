<?php

namespace Tests\Models;

use Oralunal\LaravelClickHouse\BaseModel;

class Example2 extends BaseModel
{
    protected $connection = 'clickhouse2';
    protected $table = 'examples2';
}