<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A posted payslip. Append-only: there is no `updated_at`, because "this row
 * was edited after posting" has to stay a meaningful signal.
 *
 * Storage is DECIMAL(12,2) (day counts (6,2), `overtime_hours` (10,2),
 * `unpaid_leave_days` (5,2)); the CAST is `'float'` and the COMPUTE is plain
 * PHP doubles through `Money::round()`. Those are three separate decisions and
 * all three are deliberate. `decimal:2` is banned — it returns a PHP string,
 * which is the exact $NaN/concatenation defect `src/lib/api/respond.ts:5-8`
 * exists to fix.
 *
 * Quirks carried over verbatim from `PayslipDraft` (src/lib/payroll.ts:223-276):
 *
 *  - The three day buckets are DISJOINT: a worked holiday is counted in
 *    `holiday_days` only.
 *  - `basic_pay` = work_days_pay + holiday_pay + rest_day_pay;
 *    `total_base_pay` = basic_pay + allowances.
 *  - `overtime_pay` reaches `gross_pay` only when `overtime_acknowledged` is
 *    true. Overtime is approved per day on the Daily Time Record, so
 *    `overtime_pay` holds the priced APPROVED minutes and the flag means
 *    "approved overtime reached gross". A payslip posted under the run-level
 *    acknowledgement carries the computed amount and that acknowledgement
 *    instead.
 *  - `sss_er`, `philhealth_er` and `pagibig_er` are reported but are NOT part
 *    of `total_deductions`.
 *  - `net_pay` is deliberately UNCLAMPED and can be negative (payroll.ts:510) —
 *    which is also why the rounding has to be half-up toward +Infinity rather
 *    than half-away-from-zero.
 *  - `bonus` is the TAXED part of the incentives and bonuses: the taxable
 *    lines plus any excess of the tax-exempt ones over the PHP 90,000 ceiling
 *    (`taxable_benefits_excess`); the tax-exempt part is `exempt_benefits`,
 *    added to gross and net after the tax. The lines themselves are itemised
 *    in `payslip_incentives`. `thirteenth_month` is 0 on every regular
 *    payslip and holds the amount on the payslip of a 13th month payroll, a
 *    kind of run no screen creates: there `gross_pay` equals it, no
 *    contributions are taken, and its tax-exempt part and excess are
 *    `exempt_benefits` / `taxable_benefits_excess`.
 *  - `absence_deduction`, `unpaid_leave_deduction` and
 *    `late_undertime_deduction` are the pre-additive engine's lines: late and
 *    undertime charged as one line, and a monthly base reduced for absence.
 *    Nothing writes them; they stay so the payslips that carry them stay
 *    readable, and pinning them to 0 is what stops the reduction (which lives
 *    inside `basic_pay`) being counted twice.
 *
 * `run_id` is nullable — payslips posted before runs existed — and that
 * nullability is load-bearing for the `(employee_id, period_start, period_end,
 * run_id)` unique index, which stops a re-finalise stacking a second set of
 * payslips. MySQL/InnoDB and PostgreSQL AGREE that a UNIQUE index permits
 * unlimited rows where any indexed column is NULL, so pre-run payslips stay
 * distinct exactly as the demo store behaved. Do NOT "improve" this into a NOT
 * NULL column with a sentinel.
 *
 * @property string $id
 * @property string|null $run_id
 * @property string $employee_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property float $daily_rate
 * @property float $work_days
 * @property float $holiday_days
 * @property float $rest_days
 * @property float $absence_days
 * @property float $unpaid_leave_days
 * @property float $paid_leave_days
 * @property float $work_days_pay
 * @property float $holiday_pay
 * @property float $rest_day_pay
 * @property float $basic_pay
 * @property float $allowances
 * @property float $total_base_pay
 * @property float $overtime_hours
 * @property float $overtime_pay
 * @property bool $overtime_acknowledged
 * @property float $night_differential
 * @property float $leave_pay
 * @property float $other_earnings
 * @property float $bonus
 * @property float $thirteenth_month
 * @property float $gross_pay
 * @property float $sss
 * @property float $sss_er
 * @property float $philhealth
 * @property float $philhealth_er
 * @property float $pagibig
 * @property float $pagibig_er
 * @property float $withholding_tax
 * @property float $other_deductions
 * @property float $custom_deductions
 * @property int $late_minutes
 * @property float $late_deduction
 * @property int $undertime_minutes
 * @property float $undertime_deduction
 * @property float $total_deductions
 * @property float $net_pay
 * @property float $late_undertime_deduction
 * @property float $absence_deduction
 * @property float $unpaid_leave_deduction
 * @property float $exempt_benefits
 * @property float|null $ceiling_benefits
 * @property float $taxable_benefits_excess
 * @property float|null $taxable_compensation
 * @property CarbonImmutable|null $attendance_through this member's counted date on an advance payroll (the run's, pulled back before an unfinished day; PayrollAdvance::countedThrough), the day before the period at the earliest; null when the whole period was read
 * @property float $presumed_days days paid ahead of their attendance
 * @property float $settlement the settlement of earlier presumed days inside gross pay, positive or negative
 * @property float $leave_charged_days the days of leave credits the payslip charged for lateness, undertime and absences
 * @property string|null $contribution_month the month ('YYYY-MM') the SSS, PhilHealth and Pag-IBIG shares counted toward; null on a payslip that read no contribution table
 * @property float|null $contribution_base the monthly salary the contribution tables were read at; null on a payslip that read none
 * @property int|null $tax_year the tax year the pay counts in (the year of the pay date); null on a payslip posted before 2026_11_02_0011
 * @property string|null $tax_method how a withholding by table was worked out (TABLE, ANNUALIZED, FIXED, PERCENT, WHOLE_YEAR, NONE); null on a payslip with source deductions
 * @property float $tax_refund the refund a year's last payslip paid back under the whole-year estimate; 0 elsewhere
 * @property float $cpp the employee's Canada Pension Plan contribution, inside total_deductions; 0 on a payslip posted before 2026_11_24_0002
 * @property float $cpp2 the employee's second Canada Pension Plan contribution, on pay above the year's first ceiling
 * @property float $ei the employee's Employment Insurance premium
 * @property float $federal_tax federal income tax deducted, any extra tax the employee asked for included
 * @property float $provincial_tax provincial income tax deducted
 * @property float $cpp_er what the employer pays to the pension plan for this pay; never deducted
 * @property float $cpp2_er the employer's second pension contribution; never deducted
 * @property float $ei_er the employer's insurance premium; never deducted
 * @property float $pensionable_earnings the pay the pension contribution was worked out on
 * @property float $insurable_earnings the pay the insurance premium was worked out on
 * @property CarbonImmutable $created_at
 * @property-read PayrollRun|null $run
 * @property-read EmployeeProfile|null $employee
 * @property-read Collection<int, PayslipDeduction> $deductions
 * @property-read Collection<int, PayslipSettlement> $settlements
 * @property-read PayslipSettlement|null $settledBy
 */
class Payslip extends BaseModel
{
    use ScopesToActor;

    /** Append-only: created_at is filled on insert, updated_at never written. */
    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'payslips';

    /** @var list<string> */
    protected $fillable = [
        'run_id',
        'employee_id',
        'period_start',
        'period_end',
        'daily_rate',
        'work_days',
        'holiday_days',
        'rest_days',
        'absence_days',
        'unpaid_leave_days',
        'paid_leave_days',
        'work_days_pay',
        'holiday_pay',
        'rest_day_pay',
        'basic_pay',
        'allowances',
        'total_base_pay',
        'overtime_hours',
        'overtime_pay',
        'overtime_acknowledged',
        'night_differential',
        'leave_pay',
        'other_earnings',
        'bonus',
        'thirteenth_month',
        'gross_pay',
        'sss',
        'sss_er',
        'philhealth',
        'philhealth_er',
        'pagibig',
        'pagibig_er',
        'withholding_tax',
        'other_deductions',
        'custom_deductions',
        'late_minutes',
        'late_deduction',
        'undertime_minutes',
        'undertime_deduction',
        'total_deductions',
        'net_pay',
        'late_undertime_deduction',
        'absence_deduction',
        'unpaid_leave_deduction',
        // 2026_09_26_0004. FinalizePayroll fills a payslip from
        // PayslipDraft::toArray(), and fill() drops an unlisted key without a
        // word — a missing entry here would post 0 exempt and let the
        // PHP 90,000 ledger undercount forever (PayslipDraftColumnsTest pins it).
        'exempt_benefits',
        'ceiling_benefits',
        'taxable_benefits_excess',
        'taxable_compensation',
        // 2026_10_31_0001, the advance payroll: the run's counted-through
        // date, the days paid ahead of their attendance and the settlement
        // of earlier ones (PayslipDraft::toArray carries the last two).
        'attendance_through',
        'presumed_days',
        'settlement',
        // 2026_10_31_0006: the days of leave credits the payslip charged.
        'leave_charged_days',
        // The contribution month and the salary the tables were read at (2026_11_02_0009).
        'contribution_month',
        'contribution_base',
        'tax_year',
        'tax_method',
        'tax_refund',
        // The source deductions and what they were worked out on (2026_11_24_0002).
        'cpp',
        'cpp2',
        'ei',
        'federal_tax',
        'provincial_tax',
        'cpp_er',
        'cpp2_er',
        'ei_er',
        'pensionable_earnings',
        'insurable_earnings',
    ];

    /**
     * Every money and day-count column is 'float'. NEVER decimal:2.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date:Y-m-d',
            'period_end' => 'immutable_date:Y-m-d',
            'daily_rate' => 'float',
            'work_days' => 'float',
            'holiday_days' => 'float',
            'rest_days' => 'float',
            'absence_days' => 'float',
            'unpaid_leave_days' => 'float',
            'paid_leave_days' => 'float',
            'work_days_pay' => 'float',
            'holiday_pay' => 'float',
            'rest_day_pay' => 'float',
            'basic_pay' => 'float',
            'allowances' => 'float',
            'total_base_pay' => 'float',
            'overtime_hours' => 'float',
            'overtime_pay' => 'float',
            'overtime_acknowledged' => 'boolean',
            'night_differential' => 'float',
            'leave_pay' => 'float',
            'other_earnings' => 'float',
            'bonus' => 'float',
            'thirteenth_month' => 'float',
            'gross_pay' => 'float',
            'sss' => 'float',
            'sss_er' => 'float',
            'philhealth' => 'float',
            'philhealth_er' => 'float',
            'pagibig' => 'float',
            'pagibig_er' => 'float',
            'withholding_tax' => 'float',
            'other_deductions' => 'float',
            'custom_deductions' => 'float',
            'late_minutes' => 'integer',
            'late_deduction' => 'float',
            'undertime_minutes' => 'integer',
            'undertime_deduction' => 'float',
            'total_deductions' => 'float',
            'net_pay' => 'float',
            'late_undertime_deduction' => 'float',
            'absence_deduction' => 'float',
            'unpaid_leave_deduction' => 'float',
            'exempt_benefits' => 'float',
            'ceiling_benefits' => 'float',
            'taxable_benefits_excess' => 'float',
            'taxable_compensation' => 'float',
            'attendance_through' => 'immutable_date:Y-m-d',
            'presumed_days' => 'float',
            'settlement' => 'float',
            'leave_charged_days' => 'float',
            'contribution_base' => 'float',
            'tax_year' => 'integer',
            'tax_method' => 'string',
            'tax_refund' => 'float',
            'cpp' => 'float',
            'cpp2' => 'float',
            'ei' => 'float',
            'federal_tax' => 'float',
            'provincial_tax' => 'float',
            'cpp_er' => 'float',
            'cpp2_er' => 'float',
            'ei_er' => 'float',
            'pensionable_earnings' => 'float',
            'insurable_earnings' => 'float',
            'created_at' => 'immutable_datetime',
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

    /** @return HasMany<PayslipDeduction, $this> */
    public function deductions(): HasMany
    {
        return $this->hasMany(PayslipDeduction::class, 'payslip_id');
    }

    /**
     * The settlements this payslip makes of earlier presumed days.
     *
     * @return HasMany<PayslipSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(PayslipSettlement::class, 'payslip_id');
    }

    /**
     * The settlement of THIS payslip's presumed days, once a later payslip made it.
     *
     * @return HasOne<PayslipSettlement, $this>
     */
    public function settledBy(): HasOne
    {
        return $this->hasOne(PayslipSettlement::class, 'settled_payslip_id');
    }
}
