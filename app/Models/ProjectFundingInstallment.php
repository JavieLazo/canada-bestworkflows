<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $project_id
 * @property string $funding_id
 * @property string $name
 * @property int $planned_minor
 * @property string|null $planned_on
 * @property string|null $conditions
 * @property string $status
 * @property int $revision
 * @property array<int, array<string, mixed>>|null $history
 * @property int $created_by
 * @property int $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectFundingInstallment extends BaseModel
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['received_minor' => 'integer', 'received_quantity' => 'integer', 'planned_minor' => 'integer', 'revision' => 'integer', 'history' => 'array', 'created_by' => 'integer', 'updated_by' => 'integer', 'updated_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
