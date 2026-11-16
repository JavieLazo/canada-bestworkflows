<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string $contributor
 * @property string $kind
 * @property string|null $unit
 * @property int|null $quantity_scaled
 * @property int|null $committed_minor
 * @property string|null $valuation_basis
 * @property string|null $expected_on
 * @property string|null $notes
 * @property string $status
 * @property int $revision
 * @property array<int, array<string, mixed>>|null $history
 * @property int $created_by
 * @property int $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectContribution extends BaseModel
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['received_minor' => 'integer', 'received_quantity' => 'integer', 'quantity_scaled' => 'integer', 'committed_minor' => 'integer', 'revision' => 'integer', 'history' => 'array', 'created_by' => 'integer', 'updated_by' => 'integer', 'updated_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
