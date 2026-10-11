<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * Retains inventory evidence separately from the item’s current custody.
 *
 * @property string $id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property string $location_id
 * @property string $location_name
 * @property CarbonImmutable $counted_on
 * @property string $reference
 * @property string|null $notes
 * @property string $status
 * @property string|null $resolution
 * @property string|null $resolution_reference
 * @property int $actor_id
 * @property string $actor_name
 * @property string|null $actor_employee_id
 * @property int|null $reviewed_by
 * @property string|null $reviewer_name
 * @property CarbonImmutable|null $resolved_at
 */
class InventoryCount extends BaseModel
{
    /** @var list<string> */
    protected $fillable = ['location_id', 'location_name', 'counted_on', 'reference', 'notes', 'status', 'resolution', 'resolution_reference', 'resolved_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'counted_on' => 'immutable_date:Y-m-d',
            'resolved_at' => 'immutable_datetime',
            'actor_id' => 'integer',
            'reviewed_by' => 'integer',
        ];
    }
}
