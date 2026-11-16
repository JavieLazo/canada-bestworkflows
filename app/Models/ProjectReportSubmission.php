<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $project_id
 * @property string $report_id
 * @property int $version
 * @property int $template_version
 * @property bool $work
 * @property bool $financial
 * @property list<string> $field_ids
 * @property string $payload
 * @property string $sha256
 * @property string $status
 * @property int $submitted_by
 * @property int $reviewer_id
 * @property list<array<string, mixed>>|null $reviewer_history
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_notes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectReportSubmission extends BaseModel
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer', 'template_version' => 'integer', 'work' => 'boolean', 'financial' => 'boolean', 'field_ids' => 'array', 'reviewer_history' => 'array', 'submitted_by' => 'integer', 'reviewer_id' => 'integer', 'reviewed_by' => 'integer', 'reviewed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
