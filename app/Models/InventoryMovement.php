<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * Append-only custody evidence with actor identity supplied by the command.
 *
 * @property string $id
 * @property string $item_id
 * @property string|null $receipt_id
 * @property string $event_type
 * @property string|null $from_employee_id
 * @property string|null $to_employee_id
 * @property CarbonImmutable $occurred_on
 * @property string|null $remarks
 * @property int|null $actor_id
 * @property string $actor_name
 */
class InventoryMovement extends BaseModel
{
    public const UPDATED_AT = null;

    protected $fillable = ['item_id', 'receipt_id', 'event_type', 'from_employee_id', 'to_employee_id', 'occurred_on', 'remarks'];

    protected function casts(): array
    {
        return ['occurred_on' => 'immutable_date:Y-m-d', 'created_at' => 'immutable_datetime'];
    }
}
