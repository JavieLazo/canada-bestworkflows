<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $project_id
 * @property string $currency
 * @property string|null $opening_on
 * @property int|null $opening_minor
 * @property string|null $opening_reference
 * @property string|null $opening_notes
 * @property int $opening_revision
 * @property array<int, array<string, mixed>>|null $opening_history
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectFinance extends BaseModel
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['opening_minor' => 'integer', 'opening_revision' => 'integer', 'opening_history' => 'array', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
