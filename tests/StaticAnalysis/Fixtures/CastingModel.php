<?php

declare(strict_types=1);

namespace Tests\StaticAnalysis\Fixtures;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Oralunal\LaravelClickHouse\BaseModel;

/**
 * A model that uses the attribute features of BaseModel: $casts, $hidden,
 * $appends, an accessor, a mutator and an Attribute method.
 *
 * @property int $id
 * @property bool $is_active
 * @property array<string, mixed> $payload
 * @property string $name
 * @property string $title
 * @property-read string $label
 */
class CastingModel extends BaseModel
{
    protected $table = 'casting_models';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['secret'];

    /**
     * @var list<string>
     */
    protected $appends = ['label'];

    protected $dateFormat = 'Y-m-d H:i:s';

    public function getLabelAttribute(): string
    {
        return 'label:' . $this->name;
    }

    public function setNameAttribute(?string $value): void
    {
        $this->attributes['name'] = trim((string) $value);
    }

    /**
     * @return Attribute<string, string>
     */
    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): string => is_string($value) ? ucfirst($value) : '',
            set: fn (mixed $value): string => is_string($value) ? strtolower($value) : '',
        );
    }
}
