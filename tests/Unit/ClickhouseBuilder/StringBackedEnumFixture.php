<?php

namespace Tests\Unit\ClickhouseBuilder;

/**
 * String-backed enum used to check how the grammar renders enum values.
 */
enum StringBackedEnumFixture: string
{
    case Active = 'active';
    case Paused = 'paused';
}
