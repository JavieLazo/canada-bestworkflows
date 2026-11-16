<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $project_id
 * @property string $indicator_id
 * @property bool $evidence_required
 * @property int $plan_version
 * @property string $occurred_on
 * @property int|null $value_scaled
 * @property string|null $value_text
 * @property string|null $state
 * @property string|null $narrative
 * @property string|null $issues
 * @property string|null $corrective_action
 * @property string|null $reference
 * @property int $created_by
 * @property string|null $replaces_id
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectResultEntry extends BaseModel
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['evidence_required' => 'boolean', 'plan_version' => 'integer', 'value_scaled' => 'integer', 'created_by' => 'integer', 'voided_at' => 'immutable_datetime', 'voided_by' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
