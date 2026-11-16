<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $project_id
 * @property string $contribution_id
 * @property string $occurred_on
 * @property int|null $quantity_scaled
 * @property int|null $value_minor
 * @property string|null $valuation_basis
 * @property string|null $reference
 * @property string|null $notes
 * @property string|null $replaces_id
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectContributionDelivery extends BaseModel
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity_scaled' => 'integer', 'value_minor' => 'integer', 'voided_at' => 'immutable_datetime', 'voided_by' => 'integer', 'created_by' => 'integer', 'updated_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
