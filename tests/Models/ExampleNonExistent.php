<?php

namespace Tests\Models;

use Oralunal\LaravelClickHouse\Eloquent\Model;

class ExampleNonExistent extends Model
{
    protected $table = 'examples_nonexistent_for_buffer_tests';

    protected $guarded = [];

    public $timestamps = false;
}
