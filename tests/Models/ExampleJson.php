<?php

namespace Tests\Models;

use Oralunal\LaravelClickHouse\Eloquent\Model;

class ExampleJson extends Model
{
    protected $table = 'json_examples';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['f_flag' => 'boolean'];
}
