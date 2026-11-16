<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $project_id
 * @property string $entry_id
 * @property int $revision
 * @property string $source_kind
 * @property string|null $source_id
 * @property string|null $source_label
 * @property string|null $installment_id
 * @property string|null $installment_label
 * @property string|null $group_id
 * @property string|null $group_label
 * @property int $amount_minor
 * @property string|null $reason
 * @property int $created_by
 * @property CarbonImmutable $created_at
 */
class ProjectCashAllocation extends BaseModel
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revision' => 'integer', 'amount_minor' => 'integer', 'created_by' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
