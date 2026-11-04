<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One settlement a posted payslip carries: the presumed days of an earlier
 * posted payslip (an advance payroll's "attendance counted through" date,
 * PayrollAdvance), priced again from their finished attendance. `amount` is
 * what the days turned out to be worth less what was presumed, negative
 * when they were worth less; it is already inside the settling payslip's
 * `settlement` and gross pay. `details` itemises both figures by line.
 *
 * Written only by FinalizePayroll, in the finalize transaction, and never
 * changed: a posted payslip is immutable, and so is what it settled. The
 * settled payslip is settled ONCE (`settled_payslip_id` is unique).
 *
 * @property string $id
 * @property string $payslip_id
 * @property string $settled_payslip_id
 * @property string $employee_id
 * @property string $settled_code
 * @property CarbonImmutable $from_date
 * @property CarbonImmutable $to_date
 * @property float $presumed_amount
 * @property float $actual_amount
 * @property float $amount
 * @property array<string, array<string, float>> $details
 * @property CarbonImmutable $created_at
 * @property-read Payslip|null $payslip
 * @property-read Payslip|null $settled
 */
class PayslipSettlement extends BaseModel
{
    public const UPDATED_AT = null;

    protected $table = 'payslip_settlements';

    protected $fillable = [
        'payslip_id',
        'settled_payslip_id',
        'employee_id',
        'settled_code',
        'from_date',
        'to_date',
        'presumed_amount',
        'actual_amount',
        'amount',
        'details',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_date' => 'immutable_date:Y-m-d',
            'to_date' => 'immutable_date:Y-m-d',
            'presumed_amount' => 'float',
            'actual_amount' => 'float',
            'amount' => 'float',
            'details' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payslip, $this> */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class, 'payslip_id');
    }

    /** @return BelongsTo<Payslip, $this> */
    public function settled(): BelongsTo
    {
        return $this->belongsTo(Payslip::class, 'settled_payslip_id');
    }
}
