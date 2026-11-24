<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The 201 file. 60 columns, the widest table in the system.
 *
 * Field names follow the payroll master sheet the client works from. The
 * contribution switches and `fixed_*` overrides are read by `computePayslip`;
 * an employee with no override falls back to the statutory table.
 *
 * Quirks carried over verbatim from the reference:
 *
 *  - `position`, `position_level` and `employment_status` are TEXT, not foreign
 *    keys into the reference lists. `employment_status` holds a NAME
 *    ("Regular"), but legacy rows hold tokens ("JOB_ORDER"), so every
 *    comparison goes through `statusKey()`.
 *  - DELIBERATE DIVERGENCE — `position_id` links the record to the Position
 *    list (RESTRICT: a held position cannot be deleted) and `position` keeps
 *    its name beside it, written together by EmployeePosition and rewritten
 *    on every holder when the position is renamed; a title typed before the
 *    list existed stays as text with no link. Not fillable: only
 *    EmployeePosition sets it.
 *  - `work_site_id` is nullable in the schema (SET NULL, so deleting a work
 *    site does not delete its employees) while the API requires it on write
 *    (src/lib/api/schemas.ts:21-26). Both are correct; do not "fix" either one
 *    to match the other.
 *  - `allowances` is stored but never paid by the engine, nor shown or edited
 *    (see `EmployeeFields::sections()`). `residence_cert` is not on the
 *    record either, and is equally unused.
 *  - The reference's Payroll Settings columns (`hourly_rate`, the four
 *    grouping codes, the `allow_*` switches, the fixed amounts, `percent_tax`
 *    and the three modes) are not on the record, the hire wizard or the CSV,
 *    but stay on the table: payroll reads the calculation ones
 *    (EmployeePayContextQuery) as the BASELINE of an employee with no
 *    approved Contributions & tax version (Payroll's
 *    PayrollGovDeductionPolicy lays the version in force over them), a new
 *    hire keeps the column defaults, and only the hourly rate is still
 *    written in place (UpdateEmployeePaySettings). No surface writes the
 *    statutory columns or the grouping codes.
 *  - The source's `photo` column held a data: URL. It is a `photo_path` here —
 *    a LONGTEXT costs 788 bytes of inline row width under COMPACT/REDUNDANT
 *    and would fail the CREATE (01-STARTER-PACK.md §6.3).
 *  - The reference's `vacation_credits` and `sick_credits` have no column
 *    here (migration 2026_09_25_0002 drops them) and no place on the record.
 *  - `daily_rate` (Rate Per Day) is NULL when no rate was entered. Payroll
 *    pays a day at it when it holds more than zero and otherwise derives the
 *    day from `monthly_rate`, as the reference does.
 *  - The rest of the paper 201 file (migration 2026_10_01_0001, DELIBERATE
 *    DIVERGENCE — the reference has none of it):
 *    `telephone`, `religion`, `height_cm`, `weight_kg`, `blood_type`, the
 *    spouse, the educational background and `bank_name` ("Bank and
 *    Branch"). All optional. The children are rows of `employee_children`
 *    (`children()`), not a column here; a license or passport is a document
 *    on the Documents card, not a column.
 *  - `address`, `citizenship`, `spouse_name`, `spouse_employment`,
 *    `college`, `college_course`, `college_year_graduated`,
 *    `graduate_school`, `graduate_course` and `graduate_year_graduated` are
 *    legacy values that no form writes; they are read only as fallbacks,
 *    through `EmployeeFields::residentialText()` / `citizenshipText()` /
 *    `spouseNameText()` / `spouseEmploymentText()` / `educationFallback()`.
 *    The address in parts is rows of `employee_addresses` (`addresses()`);
 *    the structured citizenship, the civil-status specification, the "same
 *    as residential" answer, the UMID and PhilSys numbers, the spouse and
 *    the parents in parts and the declarations are the one
 *    `employee_pds_details` row (`pdsDetail()`); the schools, eligibilities,
 *    previous jobs, trainings, voluntary work and other information are
 *    rows of their own tables. The schools and other information load
 *    through `educations()` and `otherItems()`; the eligibilities, previous
 *    jobs, trainings and voluntary work are read by `PdsRows` and written by
 *    `PdsRowWriter` through their own models.
 *
 * @property string $id
 * @property string $employee_code
 * @property string|null $employee_id_no
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property string|null $suffix
 * @property string|null $position
 * @property string|null $position_id
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
 * @property string|null $emergency_address
 * @property string|null $place_of_birth
 * @property string|null $citizenship
 * @property string|null $telephone
 * @property string|null $religion
 * @property float|null $height_cm
 * @property float|null $weight_kg
 * @property string|null $blood_type
 * @property string|null $spouse_name
 * @property string|null $spouse_employment
 * @property string|null $college
 * @property string|null $college_course
 * @property string|null $college_year_graduated
 * @property string|null $graduate_school
 * @property string|null $graduate_course
 * @property string|null $graduate_year_graduated
 * @property CarbonImmutable $date_hired
 * @property CarbonImmutable|null $date_regular
 * @property CarbonImmutable|null $date_resigned
 * @property float $monthly_rate
 * @property float|null $daily_rate
 * @property float $hourly_rate
 * @property float $allowances
 * @property string|null $sss_no
 * @property string|null $philhealth_no
 * @property string|null $pagibig_no
 * @property string|null $tin
 * @property string|null $bank_account_no
 * @property string|null $bank_code
 * @property string|null $bank_type
 * @property string|null $bank_name
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
 * @property-read Shift|null $shift
 * @property-read Collection<int, User> $accounts
 * @property-read Collection<int, AttendanceLog> $logs
 * @property-read Collection<int, AttendanceRemark> $remarks
 * @property-read Collection<int, EmployeeDocument> $documents
 * @property-read Collection<int, EmployeeChild> $children
 * @property-read EmployeePdsDetail|null $pdsDetail
 * @property-read Collection<int, EmployeeAddress> $addresses
 * @property-read Collection<int, EmployeeEducation> $educations
 * @property-read Collection<int, EmployeeOtherItem> $otherItems
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
        'employee_id_no',
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
        'emergency_address',
        'place_of_birth',
        'citizenship',
        'telephone',
        'religion',
        'height_cm',
        'weight_kg',
        'blood_type',
        'spouse_name',
        'spouse_employment',
        'college',
        'college_course',
        'college_year_graduated',
        'graduate_school',
        'graduate_course',
        'graduate_year_graduated',
        'date_hired',
        'date_regular',
        'date_resigned',
        'monthly_rate',
        'daily_rate',
        'hourly_rate',
        'allowances',
        'sss_no',
        'philhealth_no',
        'pagibig_no',
        'tin',
        'bank_account_no',
        'bank_code',
        'bank_type',
        'bank_name',
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
     * PHP string and reproduces the $NaN defect respond.ts:5-8 exists to fix.
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
            'daily_rate' => 'float',
            'height_cm' => 'float',
            'weight_kg' => 'float',
            'hourly_rate' => 'float',
            'allowances' => 'float',
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

    /**
     * ONE OF the employee's default-shift rows. There is a row per dated
     * change (migration 2026_09_30_0004), so the shift in effect on a date
     * comes from `ShiftRoster`, never from this relation.
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

    /** @return HasMany<EmployeeDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'employee_id');
    }

    /**
     * The children on the 201 file, in sheet order. Written only by the
     * Workforce commands that save the Family section.
     *
     * @return HasMany<EmployeeChild, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(EmployeeChild::class, 'employee_id')->orderBy('position');
    }

    /**
     * The Personal Data Sheet lines the columns here do not hold; none until
     * a save carries one. Written only by the Workforce commands that save
     * the record.
     *
     * @return HasOne<EmployeePdsDetail, $this>
     */
    public function pdsDetail(): HasOne
    {
        return $this->hasOne(EmployeePdsDetail::class, 'employee_id');
    }

    /**
     * The residential and permanent addresses in parts, at most one of each.
     * Written only by the Workforce commands that save the record.
     *
     * @return HasMany<EmployeeAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(EmployeeAddress::class, 'employee_id');
    }

    /**
     * The schools of the educational background, any number per level.
     * Written only by the Workforce commands that save the record's lists.
     *
     * @return HasMany<EmployeeEducation, $this>
     */
    public function educations(): HasMany
    {
        return $this->hasMany(EmployeeEducation::class, 'employee_id');
    }

    /**
     * The skills, distinctions and memberships, a line per row. Written only
     * by the Workforce commands that save the record's Other info cards.
     *
     * @return HasMany<EmployeeOtherItem, $this>
     */
    public function otherItems(): HasMany
    {
        return $this->hasMany(EmployeeOtherItem::class, 'employee_id');
    }
}
