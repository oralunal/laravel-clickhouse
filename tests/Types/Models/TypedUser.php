<?php

declare(strict_types=1);

namespace Tests\Types\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model of the users table of the MySQL schema dump of tests/Types/schema, which Larastan's own parser reads.
 */
class TypedUser extends Model
{
    protected $table = 'users';
}
