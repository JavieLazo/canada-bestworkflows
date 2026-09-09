<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's membership of one payroll run, plus the manager-entered
 * overrides saved while the run is a draft.
 *
 * NEW TABLE. It replaces `PayrollRun.employee_ids String[]`
 * (prisma/schema.prisma:490) — MySQL has no array type, so this is forced —
 * and folds in `draft_allowances` (one `allowance` column) and
 * `draft_deductions` (the `deduction_*` columns, whose key set is
 * src/lib/payroll-run-schema.ts:7-18) and `draft_earnings` (the `earning_*`
 * columns plus `overtime_acknowledged`, from payroll-run-schema.ts:26-32 —
 * the earnings nothing derives yet, plus the switch that lets computed
 * overtime into gross pay).
 *
 * `deduction_absence` is legacy: the calculation layer ignores it when it
 * recomputes totals (absence moved into Basic pay), and it is carried only so
 * an old draft round-trips without surprising anyone. Ported faithfully
 * because the source still accepts it.
 *
 * `position` preserves member order, which is user-visible.
 *
 * `period_start`/`period_end` are denormalised from the run so that "one
 * payroll per employee per cut-off" (src/lib/payroll-validation.ts:95-122)
 * becomes the `prm_employee_period_unq` database constraint instead of a
 * checked-then-raced read — which matters more on MySQL, where the Postgres
 * advisory lock that protected it is replaced by a `locks` row.
 *
 * All override columns are NULLABLE, and null is meaningful: it is "no
 * override", which is NOT the same as an override of 0 (or, for
 * `overtime_acknowledged`, an explicit false). Do not default them.
 *
 * @property string $id
 * @property string $run_id
 * @property string $employee_id
 * @property int $position
 * @property float|null $allowance
 * @property float|null $deduction_sss
 * @property float|null $deduction_philhealth
 * @property float|null $deduction_pagibig
 * @property float|null $deduction_withholding_tax
 * @property float|null $deduction_other
 * @property float|null $deduction_absence
 * @property float|null $earning_night_differential
 * @property float|null $earning_leave_pay
 * @property float|null $earning_other
 * @property bool|null $overtime_acknowledged
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PayrollRun|null $run
 * @property-read EmployeeProfile|null $employee
 */
class PayrollRunMember extends BaseModel
{
    /** @var string */
    protected $table = 'payroll_run_members';

    /** @var list<string> */
    protected $fillable = [
        'run_id',
        'employee_id',
        'position',
        'allowance',
        'deduction_sss',
        'deduction_philhealth',
        'deduction_pagibig',
        'deduction_withholding_tax',
        'deduction_other',
        'deduction_absence',
        'earning_night_differential',
        'earning_leave_pay',
        'earning_other',
        'overtime_acknowledged',
        'period_start',
        'period_end',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'allowance' => 'float',
            'deduction_sss' => 'float',
            'deduction_philhealth' => 'float',
            'deduction_pagibig' => 'float',
            'deduction_withholding_tax' => 'float',
            'deduction_other' => 'float',
            'deduction_absence' => 'float',
            'earning_night_differential' => 'float',
            'earning_leave_pay' => 'float',
            'earning_other' => 'float',
            // Nullable on purpose: null is "the manager has not touched the
            // switch", which the source distinguishes from an explicit false.
            'overtime_acknowledged' => 'boolean',
            'period_start' => 'immutable_date:Y-m-d',
            'period_end' => 'immutable_date:Y-m-d',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }
}
