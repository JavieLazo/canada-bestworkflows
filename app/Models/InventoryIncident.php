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
 * @property string $item_id
 * @property string|null $employee_id
 * @property string|null $receipt_id
 * @property string $kind
 * @property CarbonImmutable $reported_on
 * @property string $reference
 * @property string $details
 * @property array<string, mixed> $item_snapshot
 * @property array<string, mixed>|null $employee_snapshot
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
class InventoryIncident extends BaseModel
{
    /** @var list<string> */
    protected $fillable = ['item_id', 'employee_id', 'receipt_id', 'kind', 'reported_on', 'reference', 'details', 'item_snapshot', 'employee_snapshot', 'status', 'resolution', 'resolution_reference', 'resolved_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'reported_on' => 'immutable_date:Y-m-d',
            'item_snapshot' => 'array',
            'employee_snapshot' => 'array',
            'resolved_at' => 'immutable_datetime',
            'actor_id' => 'integer',
            'reviewed_by' => 'integer',
        ];
    }
}
