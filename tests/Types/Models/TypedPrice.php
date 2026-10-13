<?php

declare(strict_types=1);

namespace Tests\Types\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model of the prices table of the SQLite migration of tests/Types/migrations, which Larastan reads with its own
 * rules.
 */
class TypedPrice extends Model
{
    protected $connection = 'sqlite';

    protected $table = 'prices';
}
