<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current inventory register; receipt lines preserve issue-time evidence.
 *
 * @property InventoryCategory|null $category
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $serial_no
 * @property CarbonImmutable $acquired_on
 * @property float $cost
 * @property string|null $category_id
 * @property string|null $location_id
 * @property string $condition
 * @property string $status
 * @property string|null $employee_id
 * @property string|null $current_receipt_id
 * @property string|null $remarks
 */
class InventoryItem extends BaseModel
{
    use ScopesToActor;

    protected $fillable = ['code', 'name', 'serial_no', 'acquired_on', 'cost', 'category_id', 'location_id', 'condition', 'status', 'employee_id', 'current_receipt_id', 'remarks'];

    protected function casts(): array
    {
        return ['cost' => 'float', 'acquired_on' => 'immutable_date:Y-m-d', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<InventoryCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }
}
