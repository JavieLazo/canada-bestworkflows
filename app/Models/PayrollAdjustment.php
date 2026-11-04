<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the adjustment ledger (2026_11_02_0005): a "difference on a
 * later payroll" for one employee under a category — PENDING once
 * prepared, APPROVED or REJECTED on review, POSTED by the
 * payslip that paid it. Pay data: read behind the payslips' row scope
 * (PAYROLL). `prepared_by`, `approved_by`, their names, `status`,
 * `target_run_id` and `payslip_id` are set by the commands, never filled.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $category_id
 * @property float $amount
 * @property string $reason
 * @property string|null $reference
 * @property string $source
 * @property string $status
 * @property string|null $target_run_id
 * @property string|null $payslip_id
 * @property int|null $prepared_by
 * @property string|null $prepared_by_name
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property CarbonImmutable|null $approved_at
 * @property string|null $review_note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PayrollAdjustmentCategory $category
 */
class PayrollAdjustment extends BaseModel
{
    use ScopesToActor;

    /** @var string */
    protected $table = 'payroll_adjustments';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'category_id', 'amount', 'reason', 'reference', 'source'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'prepared_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PayrollAdjustmentCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PayrollAdjustmentCategory::class, 'category_id');
    }
}
