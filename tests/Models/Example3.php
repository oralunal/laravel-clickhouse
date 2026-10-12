<?php

namespace Tests\Models;

use Oralunal\LaravelClickHouse\Eloquent\Model;

class Example3 extends Model
{
    protected $table = 'examples3';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['f_bool' => 'boolean'];
}
