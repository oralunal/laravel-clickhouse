<?php

declare(strict_types=1);

namespace Tests\Types\Data;

use Tests\Types\Models\TypedHit;
use Tests\Types\Models\TypedMetric;
use Tests\Types\Models\TypedPrice;

use function PHPStan\Testing\assertType;

/**
 * The columns of a model, as Larastan reads them from the ClickHouse migrations of tests/Types/migrations with the
 * migrations parser of the extension.
 */
function columnsOfTheClickHouseMigrations(TypedHit $hit, TypedMetric $metric): void
{
    assertType('mixed', $hit->id);
    assertType('string', $hit->price);
    assertType('mixed', $hit->big);
    assertType('int', $hit->signed_big);
    assertType('int<0, max>', $hit->counter);
    assertType('int<0, max>', $hit->year);
    assertType('float', $hit->ratio);
    assertType("'high'|'low'", $hit->level);
    assertType('string|null', $hit->name);
    assertType('string', $hit->at);
    assertType('string', $hit->likeable_type);
    assertType('mixed', $hit->likeable_id);
    assertType('mixed', $hit->typed_user_id);

    assertType('string', $metric->ip);
    assertType('mixed', $metric->tags);
    assertType('mixed', $metric->total);
    assertType("'a'|'b'", $metric->kind);
    assertType('int<0, max>|null', $metric->doubled);
    assertType('string|null', $metric->heading);
}

/**
 * Larastan still reads the migrations of other connections with its own rules.
 */
function columnsOfOtherMigrations(TypedPrice $price): void
{
    assertType('float', $price->amount);
}
