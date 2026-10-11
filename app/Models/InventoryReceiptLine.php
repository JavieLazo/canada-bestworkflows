<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * Keeps each original item on its receipt after return, transfer or correction.
 *
 * @property string $id
 * @property string $receipt_id
 * @property string $item_id
 * @property string $status
 * @property array<string, mixed> $item_snapshot
 * @property CarbonImmutable|null $ended_on
 */
class InventoryReceiptLine extends BaseModel
{
    protected $fillable = ['receipt_id', 'item_id', 'status', 'item_snapshot', 'ended_on'];

    protected function casts(): array
    {
        return ['item_snapshot' => 'array', 'ended_on' => 'immutable_date:Y-m-d', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
