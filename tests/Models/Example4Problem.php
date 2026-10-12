<?php

namespace Tests\Models;

use Oralunal\LaravelClickHouse\Eloquent\Model;

class Example4Problem extends Model
{
    protected $connection = 'problem-clickhouse-cluster';

    protected $table = 'examples4';

    protected $guarded = [];

    public $timestamps = false;
}
