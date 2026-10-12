<?php

namespace Tests\Models;

use Oralunal\LaravelClickHouse\Eloquent\Model;

class Example extends Model
{
    protected $table = 'examples';

    protected $guarded = [];

    public $timestamps = false;
}
