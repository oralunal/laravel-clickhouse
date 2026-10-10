<?php

namespace Tests\Models;

/**
 * A child of a model that uses HasBufferedInserts again, with a table of its own.
 *
 * It inherits its parent's copy of the trait, so it buffers in its parent's static
 * buffers, under its own class name.
 */
class ExampleReusingBufferedInsertsChild extends ExampleReusingBufferedInserts
{
    protected $table = 'examples3';
}
