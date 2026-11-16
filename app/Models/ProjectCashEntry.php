<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $project_id
 * @property string $category_id
 * @property string|null $group_id
 * @property string $category_label
 * @property string|null $group_label
 * @property int $allocation_revision
 * @property string $kind
 * @property CarbonImmutable $occurred_on
 * @property int $amount_minor
 * @property string $description
 * @property string|null $party
 * @property string|null $reference
 * @property string|null $notes
 * @property int $created_by
 * @property string|null $replaces_id
 * @property int|null $voided_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectCashEntry extends BaseModel
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['allocation_revision' => 'integer', 'amount_minor' => 'integer', 'created_by' => 'integer', 'voided_by' => 'integer', 'occurred_on' => 'immutable_date', 'voided_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
