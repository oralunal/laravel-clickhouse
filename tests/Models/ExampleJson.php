<?php

namespace Tests\Models;

use Oralunal\LaravelClickHouse\BaseModel;

/**
 * A model of the json_examples table that sends its inserts as JSONEachRow, whatever the connection's
 * insert_format is.
 */
class ExampleJson extends BaseModel
{
    protected $table = 'json_examples';

    protected $insertFormat = 'JSONEachRow';

    protected $casts = ['f_flag' => 'boolean'];
}
