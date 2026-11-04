<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A positive custom deduction chosen for one draft payroll member. Its
 * catalog link is retained while the draft exists so the displayed name
 * reflects the current Records Management label; finalization copies that
 * label into an immutable payslip line.
 *
 * Owner: Payroll. Its member scopes the employee and run.
 *
 * @property string $id
 * @property string $member_id
 * @property string $deduction_id
 * @property float $amount
 * @property CarbonImmutable $created_at
 * @property-read PayrollRunMember|null $member
 */
class PayrollRunMemberDeduction extends BaseModel
{
    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'payroll_run_member_deductions';

    /** @var list<string> */
    protected $fillable = [
        'member_id',
        'deduction_id',
        'amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PayrollRunMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(PayrollRunMember::class, 'member_id');
    }
}
