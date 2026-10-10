<?php

namespace Tests\Models;

/**
 * A child of a model that uses HasBufferedInserts again, which points at a table that does not exist, so its
 * flush fails. Used by buffered-insert tests to verify failure-path behavior.
 */
class ExampleReusingBufferedInsertsNonExistent extends ExampleReusingBufferedInserts
{
    protected $table = 'examples_nonexistent_for_buffer_tests';
}
