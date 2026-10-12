<?php

namespace Tests\Fixtures;

/**
 * Int-backed enum used to check how the grammar renders enum values.
 */
enum IntBackedEnumFixture: int
{
    case Low = 1;
    case High = 3;
}
