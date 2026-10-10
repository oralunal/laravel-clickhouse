<?php

namespace Tests\Models;

/**
 * A child of a model that uses HasBufferedInserts again, which reads from examples
 * and inserts into examples3.
 */
class ExampleReusingBufferedInsertsWithTableForInserts extends ExampleReusingBufferedInserts
{
    protected $tableForInserts = 'examples3';
}
