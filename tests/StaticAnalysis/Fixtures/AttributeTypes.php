<?php

declare(strict_types=1);

namespace Tests\StaticAnalysis\Fixtures;

use function PHPStan\Testing\assertType;

/**
 * The types PHPStan infers for the attribute API of a model that extends
 * BaseModel. They are the types that Larastan's stub of Eloquent's
 * HasAttributes gave BaseModel when it used that trait, so app code that relies
 * on them keeps passing at level 10. PHPStan reports a type that differs as a
 * phpstan.type error at every level.
 */
class AttributeTypes
{
    public function check(CastingModel $model): void
    {
        assertType('array<string, string>', $model->getCasts());
        assertType('list<string>', $model->getAppends());
        assertType('list<string>', $model->getMutatedAttributes());
        assertType('array<string, mixed>', $model->toArray());
    }
}
