<?php

declare(strict_types=1);

namespace Tests\StaticAnalysis\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasAttributes;

/**
 * Uses Eloquent's HasAttributes without extending Model, so Larastan reports
 * class.missingExtends here. The test expects that error: it proves that the
 * analysis loaded Larastan's stubs.
 */
class ControlUsesEloquentHasAttributes
{
    use HasAttributes;
}
