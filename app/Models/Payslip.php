<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A posted payslip. Append-only: there is no `updated_at`, because "this row
 * was edited after posting" has to stay a meaningful signal.
 *
 * Storage is DECIMAL(12,2) (day counts (6,2), `overtime_hours` (10,2),
 * `unpaid_leave_days` (5,2)); the CAST is `'float'` and the COMPUTE is plain
 * PHP doubles through `Money::round()`. Those are three separate decisions and
 * all three are deliberate. `decimal:2` is banned — it returns a PHP string,
 * which is the exact ₱NaN/concatenation defect `src/lib/api/respond.ts:5-8`
 * exists to fix.
 *
 * Quirks carried over verbatim from `PayslipDraft` (src/lib/payroll.ts:223-276):
 *
 *  - The three day buckets are DISJOINT: a worked holiday is counted in
 *    `holiday_days` only.
 *  - `basic_pay` = work_days_pay + holiday_pay + rest_day_pay;
 *    `total_base_pay` = basic_pay + allowances.
 *  - `overtime_pay` is always the computed amount; it reaches `gross_pay` only
 *    when `overtime_acknowledged` is true.
 *  - `sss_er`, `philhealth_er` and `pagibig_er` are reported but are NOT part
 *    of `total_deductions`.
 *  - `net_pay` is deliberately UNCLAMPED and can be negative (payroll.ts:510) —
 *    which is also why the rounding has to be half-up toward +Infinity rather
 *    than half-away-from-zero.
 *  - `bonus` and `thirteenth_month` are always 0 on the server path.
 *  - `absence_deduction`, `unpaid_leave_deduction` and
 *    `late_undertime_deduction` were written by the pre-additive engine, which
 *    charged late and undertime as one line and reduced a monthly base for
 *    absence. Nothing writes them now; they are kept so payslips posted before
 *    the change stay readable, and pinning them to 0 is what stops the
 *    reduction (which now lives inside `basic_pay`) being counted twice.
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
 * @property int $late_minutes
 * @property float $late_deduction
 * @property int $undertime_minutes
 * @property float $undertime_deduction
 * @property float $total_deductions
 * @property float $net_pay
 * @property float $late_undertime_deduction
 * @property float $absence_deduction
 * @property float $unpaid_leave_deduction
 * @property CarbonImmutable $created_at
 * @property-read PayrollRun|null $run
 * @property-read EmployeeProfile|null $employee
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
        'late_minutes',
        'late_deduction',
        'undertime_minutes',
        'undertime_deduction',
        'total_deductions',
        'net_pay',
        'late_undertime_deduction',
        'absence_deduction',
        'unpaid_leave_deduction',
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
            'late_minutes' => 'integer',
            'late_deduction' => 'float',
            'undertime_minutes' => 'integer',
            'undertime_deduction' => 'float',
            'total_deductions' => 'float',
            'net_pay' => 'float',
            'late_undertime_deduction' => 'float',
            'absence_deduction' => 'float',
            'unpaid_leave_deduction' => 'float',
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
}
