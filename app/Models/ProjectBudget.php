<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $project_id
 * @property int $version
 * @property int $revision
 * @property string $status
 * @property string|null $reason
 * @property int|null $total_minor
 * @property int $created_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectBudget extends BaseModel
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer', 'revision' => 'integer', 'total_minor' => 'integer', 'created_by' => 'integer', 'approved_by' => 'integer', 'approved_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
