<?php

namespace Tests\Models;

use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\Concerns\HasBufferedInserts;

/**
 * A model that uses HasBufferedInserts again, although BaseModel already does.
 *
 * It gets its own copy of the trait's methods, in which self is this class, and its
 * own static buffers, which its children share.
 */
class ExampleReusingBufferedInserts extends BaseModel
{
    use HasBufferedInserts;

    protected $table = 'examples';
}
