<?php

namespace Tests\Fixtures;

/**
 * String-backed enum used to check how the grammar renders enum values.
 */
enum StringBackedEnumFixture: string
{
    case Active = 'active';
    case Paused = 'paused';
}
