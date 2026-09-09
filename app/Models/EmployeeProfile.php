<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The 201 file. 57 columns, the widest table in the system.
 *
 * Field names follow the payroll master sheet the client works from. The
 * contribution switches and `fixed_*` overrides are read by `computePayslip`;
 * an employee with no override falls back to the statutory table.
 *
 * Quirks carried over verbatim from the reference:
 *
 *  - `position`, `position_level` and `employment_status` are TEXT, not foreign
 *    keys into the reference lists. Renaming a list option must not orphan a
 *    record. `employment_status` holds a NAME ("Regular"), but legacy rows hold
 *    tokens ("JOB_ORDER"), so every comparison goes through `statusKey()`.
 *  - `work_site_id` is nullable in the schema (SET NULL, so deleting a work
 *    site does not delete its employees) while the API requires it on write
 *    (src/lib/api/schemas.ts:21-26). Both are correct; do not "fix" either one
 *    to match the other.
 *  - `allowances` is stored and displayed but never paid by the engine.
 *  - The source's `photo` column held a data: URL. It is a `photo_path` here —
 *    a LONGTEXT costs 788 bytes of inline row width under COMPACT/REDUNDANT
 *    and would fail the CREATE (01-STARTER-PACK.md §6.3).
 *
 * @property string $id
 * @property string $employee_code
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property string|null $suffix
 * @property string|null $position
 * @property string|null $position_level
 * @property string $employment_status
 * @property bool $is_active
 * @property string|null $work_site_id
 * @property string|null $photo_path
 * @property CarbonImmutable|null $birth_date
 * @property string|null $sex
 * @property string|null $civil_status
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $residence_cert
 * @property string|null $emergency_name
 * @property string|null $emergency_relation
 * @property string|null $emergency_phone
 * @property CarbonImmutable $date_hired
 * @property CarbonImmutable|null $date_regular
 * @property CarbonImmutable|null $date_resigned
 * @property float $monthly_rate
 * @property float $hourly_rate
 * @property float $allowances
 * @property string|null $sss_no
 * @property string|null $philhealth_no
 * @property string|null $pagibig_no
 * @property string|null $tin
 * @property string|null $bank_account_no
 * @property string|null $bank_code
 * @property string|null $bank_type
 * @property float $vacation_credits
 * @property float $sick_credits
 * @property string|null $pay_code
 * @property string|null $agency_code
 * @property string|null $section_code
 * @property string|null $tax_code
 * @property bool $allow_sss
 * @property bool $allow_philhealth
 * @property bool $allow_pagibig
 * @property bool $allow_withholding_tax
 * @property bool $annualize_tax
 * @property float $fixed_tax
 * @property float $percent_tax
 * @property float $fixed_sss_ee
 * @property float $fixed_sss_er
 * @property float $fixed_philhealth_ee
 * @property float $fixed_philhealth_er
 * @property float $fixed_pagibig_ee
 * @property float $fixed_pagibig_er
 * @property string|null $sss_mode
 * @property string|null $philhealth_mode
 * @property string|null $pagibig_mode
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WorkSite|null $workSite
 * @property-read Shift|null $shift
 * @property-read Collection<int, User> $accounts
 * @property-read Collection<int, AttendanceLog> $logs
 * @property-read Collection<int, AttendanceRemark> $remarks
 * @property-read Collection<int, LeaveTravelRequest> $requests
 * @property-read Collection<int, Payslip> $payslips
 * @property-read Collection<int, EmployeeDocument> $documents
 * @property-read Collection<int, ProjectTask> $taskLoad
 * @property-read Collection<int, PayrollRunMember> $payrollMemberships
 * @property-read Collection<int, PayrollRun> $payrollRuns
 */
class EmployeeProfile extends BaseModel
{
    /** @var string */
    protected $table = 'employee_profiles';

    /**
     * Caps for every one of these live in `EmployeeInput`
     * (src/lib/api/schemas.ts:11-82) and are transcribed into the FormRequest
     * rules, not here.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_code',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'position',
        'position_level',
        'employment_status',
        'is_active',
        'work_site_id',
        'photo_path',
        'birth_date',
        'sex',
        'civil_status',
        'address',
        'phone',
        'email',
        'residence_cert',
        'emergency_name',
        'emergency_relation',
        'emergency_phone',
        'date_hired',
        'date_regular',
        'date_resigned',
        'monthly_rate',
        'hourly_rate',
        'allowances',
        'sss_no',
        'philhealth_no',
        'pagibig_no',
        'tin',
        'bank_account_no',
        'bank_code',
        'bank_type',
        'vacation_credits',
        'sick_credits',
        'pay_code',
        'agency_code',
        'section_code',
        'tax_code',
        'allow_sss',
        'allow_philhealth',
        'allow_pagibig',
        'allow_withholding_tax',
        'annualize_tax',
        'fixed_tax',
        'percent_tax',
        'fixed_sss_ee',
        'fixed_sss_er',
        'fixed_philhealth_ee',
        'fixed_philhealth_er',
        'fixed_pagibig_ee',
        'fixed_pagibig_er',
        'sss_mode',
        'philhealth_mode',
        'pagibig_mode',
    ];

    /**
     * Every DECIMAL is cast to 'float', never decimal:2 — that cast returns a
     * PHP string and reproduces the ₱NaN defect respond.ts:5-8 exists to fix.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'birth_date' => 'immutable_date:Y-m-d',
            'date_hired' => 'immutable_date:Y-m-d',
            'date_regular' => 'immutable_date:Y-m-d',
            'date_resigned' => 'immutable_date:Y-m-d',
            'monthly_rate' => 'float',
            'hourly_rate' => 'float',
            'allowances' => 'float',
            'vacation_credits' => 'float',
            'sick_credits' => 'float',
            'allow_sss' => 'boolean',
            'allow_philhealth' => 'boolean',
            'allow_pagibig' => 'boolean',
            'allow_withholding_tax' => 'boolean',
            'annualize_tax' => 'boolean',
            'fixed_tax' => 'float',
            'percent_tax' => 'float',
            'fixed_sss_ee' => 'float',
            'fixed_sss_er' => 'float',
            'fixed_philhealth_ee' => 'float',
            'fixed_philhealth_er' => 'float',
            'fixed_pagibig_ee' => 'float',
            'fixed_pagibig_er' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WorkSite, $this> */
    public function workSite(): BelongsTo
    {
        return $this->belongsTo(WorkSite::class, 'work_site_id');
    }

    /**
     * One row per employee — the shift they are rostered on.
     *
     * @return HasOne<Shift, $this>
     */
    public function shift(): HasOne
    {
        return $this->hasOne(Shift::class, 'employee_id');
    }

    /**
     * The login accounts linked to this employee (`UserAccount[]` in the source).
     *
     * @return HasMany<User, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(User::class, 'employee_id');
    }

    /** @return HasMany<AttendanceLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'employee_id');
    }

    /** @return HasMany<AttendanceRemark, $this> */
    public function remarks(): HasMany
    {
        return $this->hasMany(AttendanceRemark::class, 'employee_id');
    }

    /** @return HasMany<LeaveTravelRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(LeaveTravelRequest::class, 'employee_id');
    }

    /** @return HasMany<Payslip, $this> */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'employee_id');
    }

    /** @return HasMany<EmployeeDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'employee_id');
    }

    /**
     * `task_load` in the source: the tasks assigned to this employee.
     *
     * @return HasMany<ProjectTask, $this>
     */
    public function taskLoad(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'assignee_id');
    }

    /**
     * Membership rows on payroll runs. No equivalent in the source, where the
     * membership was a Postgres `String[]` on `payroll_runs` with no
     * referential integrity — MySQL has no array type, and the source schema
     * comment asked for this change anyway.
     *
     * @return HasMany<PayrollRunMember, $this>
     */
    public function payrollMemberships(): HasMany
    {
        return $this->hasMany(PayrollRunMember::class, 'employee_id');
    }

    /** @return BelongsToMany<PayrollRun, $this> */
    public function payrollRuns(): BelongsToMany
    {
        // No ->using(PayrollRunMember::class): the pivot is a first-class model
        // with its own ULID and its own uniques, not an Eloquent Pivot subclass.
        return $this->belongsToMany(PayrollRun::class, 'payroll_run_members', 'employee_id', 'run_id')
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
            ]);
    }
}
