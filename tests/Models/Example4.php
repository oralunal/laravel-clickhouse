<?php

namespace Tests\Models;

use Oralunal\LaravelClickHouse\Eloquent\Model;

class Example4 extends Model
{
    protected $connection = 'clickhouse-cluster';

    protected $table = 'examples4';

    protected $guarded = [];

    public $timestamps = false;
}
