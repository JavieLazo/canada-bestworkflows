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
 * @property CarbonImmutable $disposed_on
 * @property string $method
 * @property string $reason
 * @property string $authorization_reference
 * @property string $authorized_by_employee_id
 * @property array<string, mixed> $authorized_by_snapshot
 * @property array<string, mixed> $item_snapshot
 * @property int $actor_id
 * @property string $actor_name
 */
class InventoryDisposal extends BaseModel
{
    /** @var list<string> */
    protected $fillable = ['item_id', 'disposed_on', 'method', 'reason', 'authorization_reference', 'authorized_by_employee_id', 'authorized_by_snapshot', 'item_snapshot'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'disposed_on' => 'immutable_date:Y-m-d',
            'authorized_by_snapshot' => 'array',
            'item_snapshot' => 'array',
            'actor_id' => 'integer',
        ];
    }
}
