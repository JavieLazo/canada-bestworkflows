<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $project_id
 * @property string $template_id
 * @property string $period_id
 * @property string|null $budget_id
 * @property string $title
 * @property string $status
 * @property bool $work
 * @property bool $financial
 * @property string|null $summary
 * @property string|null $issues
 * @property string|null $corrective_action
 * @property string|null $amendment_reason
 * @property int $revision
 * @property string|null $current_submission_id
 * @property int $created_by
 * @property int $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectReport extends BaseModel
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['work' => 'boolean', 'financial' => 'boolean', 'revision' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
