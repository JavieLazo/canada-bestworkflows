<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * One custom deduction line captured on a posted payslip. Code and name are
 * copied from the catalog at finalization so later catalog edits cannot
 * change the explanation on an issued payslip.
 *
 * Owner: Payroll. The payslip scopes the employee and run.
 *
 * @property string $id
 * @property string $payslip_id
 * @property int $position
 * @property string $deduction_code
 * @property string $name
 * @property float $amount
 * @property float|null $employer_amount
 * @property string|null $recurring_line_id the recurring line it paid (2026_10_30_0003)
 * @property string|null $adjustment_id the adjustment it paid (2026_11_02_0005); NULL on a line that pays none
 * @property CarbonImmutable $created_at
 */
class PayslipDeduction extends BaseModel
{
    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'payslip_deductions';

    /** @var list<string> */
    protected $fillable = [
        'payslip_id',
        'position',
        'deduction_code',
        'name',
        'amount',
        'employer_amount',
        'recurring_line_id',
        // The adjustment this line paid (2026_11_02_0005); NULL on a line that pays none.
        'adjustment_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'amount' => 'float',
            'employer_amount' => 'float',
            'created_at' => 'immutable_datetime',
        ];
    }
}
