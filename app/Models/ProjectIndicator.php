<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $project_id
 * @property string|null $outcome_id
 * @property string|null $group_id
 * @property string|null $task_id
 * @property string|null $outcome_label
 * @property string|null $work_label
 * @property string $name
 * @property string|null $description
 * @property string $kind
 * @property string|null $unit
 * @property string $aggregation
 * @property string $direction
 * @property int|null $target_scaled
 * @property string|null $target_text
 * @property string|null $starts_on
 * @property string|null $ends_on
 * @property bool $evidence_required
 * @property string $status
 * @property int $revision
 * @property int $plan_version
 * @property array<int, array<string, mixed>>|null $history
 * @property int $created_by
 * @property int $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectIndicator extends BaseModel
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['actual_scaled' => 'integer', 'entry_count' => 'integer', 'target_scaled' => 'integer', 'evidence_required' => 'boolean', 'revision' => 'integer', 'plan_version' => 'integer', 'history' => 'array', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
