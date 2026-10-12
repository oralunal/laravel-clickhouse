<?php

namespace Tests\Models;

use Oralunal\LaravelClickHouse\Eloquent\Model;

class Example2 extends Model
{
    protected $connection = 'clickhouse2';

    protected $table = 'examples2';

    protected $guarded = [];

    public $timestamps = false;
}
