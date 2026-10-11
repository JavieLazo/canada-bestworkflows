<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named payroll: who is on it, for which cut-off, and whether it is still a
 * draft. Finalising one posts a payslip per member.
 *
 * FOUR source columns do not live on this model:
 *
 *  - `employee_ids String[]` — MySQL has no array type, so membership is the
 *    `payroll_run_members` pivot. That also closes the referential-integrity
 *    hole the source schema comment admits to ("deleting an employee leaves a
 *    dangling id here"), and makes `requirePayrollOverrideMembers`
 *    structurally unnecessary. Member ORDER is user-visible
 *    (`calculatePayrollDrafts` maps over `employee_ids` in order), so the pivot
 *    carries a `position` column and `members()` orders by it.
 *  - `draft_deductions Json`, `draft_allowances Json` and `draft_earnings Json`
 *    are all keyed by employee id, so all three normalise onto the same pivot
 *    as real columns: `allowance`, `deduction_*`, `earning_*` and
 *    `overtime_acknowledged`. Nothing here decodes JSON per row, and
 *    `requirePayrollOverrideMembers` — which existed only to check that every
 *    override key was actually a member — becomes structurally impossible to
 *    violate.
 *
 * `status` is a `PayrollRunStatus` (DRAFT | FINALIZED), stored as a varchar —
 * a MySQL ENUM sorts by declaration ordinal, so `ORDER BY status` would
 * silently differ from Postgres. Cast as a plain string until
 * `app/Enums/PayrollRunStatus.php` exists.
 *
 * `finalized_by` is an ACTOR column: session-supplied, never mass-assigned.
 * `code` and `name` are both unique — never write this table with `upsert()`.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property string $status
 * @property string $kind REGULAR | THIRTEENTH_MONTH (PayrollRunKind, 2026_09_26_0010); fixed at creation
 * @property CarbonImmutable|null $finalized_at
 * @property int|null $finalized_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $finalizer
 * @property-read Collection<int, Payslip> $payslips
 * @property-read Collection<int, PayrollRunMember> $members
 * @property-read Collection<int, EmployeeProfile> $employees
 */
class PayrollRun extends BaseModel
{
    /** @var string */
    protected $table = 'payroll_runs';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'period_start',
        'period_end',
        'status',
        'finalized_at',
        'kind',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date:Y-m-d',
            'period_end' => 'immutable_date:Y-m-d',
            'finalized_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /** @return HasMany<Payslip, $this> */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'run_id');
    }

    /**
     * Ordered, because the drafts array order is user-visible.
     *
     * @return HasMany<PayrollRunMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(PayrollRunMember::class, 'run_id')->orderBy('position');
    }

    /** @return BelongsToMany<EmployeeProfile, $this> */
    public function employees(): BelongsToMany
    {
        // No ->using(): the pivot is a first-class model with its own ULID.
        return $this->belongsToMany(EmployeeProfile::class, 'payroll_run_members', 'run_id', 'employee_id')
            ->withPivot([
                'id',
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
            ])
            ->orderBy('payroll_run_members.position');
    }
}
