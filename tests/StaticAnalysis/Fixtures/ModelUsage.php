<?php

declare(strict_types=1);

namespace Tests\StaticAnalysis\Fixtures;

/**
 * Calls the public attribute API of BaseModel the way an app does.
 */
class ModelUsage
{
    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $model = CastingModel::make(['id' => 1, 'name' => ' a ']);
        $model->is_active = true;
        $model->title = 'Hello';
        $model->fill(['payload' => ['a' => 1]]);
        $model->setAttribute('id', 2);

        return [
            'id' => $model->id,
            'label' => $model->label,
            'attribute' => $model->getAttribute('name'),
            'attributes' => $model->getAttributes(),
            'array' => $model->toArray(),
            'attributesToArray' => $model->attributesToArray(),
            'casts' => $model->getCasts(),
            'hasCast' => $model->hasCast('is_active'),
            'dateFormat' => $model->getDateFormat(),
            'dirty' => $model->isDirty(),
            'original' => $model->getOriginal(),
            'hidden' => $model->makeVisible('secret')->getHidden(),
            'isset' => isset($model->title),
            'empty' => (new EmptyModel())->toArray(),
        ];
    }
}
