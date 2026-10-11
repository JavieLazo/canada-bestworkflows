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
 * @property string $count_id
 * @property string $item_id
 * @property string|null $employee_id
 * @property string|null $receipt_id
 * @property array<string, mixed> $item_snapshot
 * @property string $result
 * @property string|null $notes
 */
class InventoryCountLine extends BaseModel
{
    /** @var list<string> */
    protected $fillable = ['count_id', 'item_id', 'employee_id', 'receipt_id', 'item_snapshot', 'result', 'notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'item_snapshot' => 'array',
        ];
    }
}
