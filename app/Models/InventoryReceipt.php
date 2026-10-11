<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Freezes the paper issued to an employee; lifecycle changes never rewrite its evidence.
 *
 * @property string $id
 * @property string $code
 * @property string $mr_number
 * @property string $employee_id
 * @property string $issuer_employee_id
 * @property CarbonImmutable $issued_on
 * @property string $office_name
 * @property array<string, mixed> $employee_snapshot
 * @property array<string, mixed> $issuer_snapshot
 * @property string|null $remarks
 * @property string $status
 * @property string|null $corrected_from_id
 * @property int|null $recorded_by
 * @property string $recorder_name
 * @property Collection<int, InventoryReceiptLine> $lines
 */
class InventoryReceipt extends BaseModel
{
    use ScopesToActor;

    protected $fillable = ['code', 'mr_number', 'employee_id', 'issuer_employee_id', 'issued_on', 'office_name', 'employee_snapshot', 'issuer_snapshot', 'remarks', 'status', 'corrected_from_id'];

    protected function casts(): array
    {
        return ['issued_on' => 'immutable_date:Y-m-d', 'recorded_by' => 'integer', 'employee_snapshot' => 'array', 'issuer_snapshot' => 'array', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return HasMany<InventoryReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryReceiptLine::class, 'receipt_id');
    }
}
