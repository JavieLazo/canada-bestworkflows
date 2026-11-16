<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string|null $period_id
 * @property string|null $period_label
 * @property string|null $period_starts_on
 * @property string|null $period_ends_on
 * @property string|null $funding_id
 * @property string|null $contribution_id
 * @property string|null $source_label
 * @property string $id
 * @property string $project_id
 * @property string $budget_id
 * @property string $category_id
 * @property string|null $group_id
 * @property string $category_label
 * @property string|null $group_label
 * @property string $label
 * @property string $basis
 * @property int|null $quantity_scaled
 * @property int|null $frequency
 * @property int|null $unit_minor
 * @property int $total_minor
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectBudgetLine extends BaseModel
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity_scaled' => 'integer', 'frequency' => 'integer', 'unit_minor' => 'integer', 'total_minor' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
