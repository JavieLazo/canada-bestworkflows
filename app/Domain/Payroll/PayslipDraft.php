<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Support\Money;

/**
 * One payroll line, in the order the payroll grid reads it.
 *
 * A literal port of the `PayslipDraft` type exported from
 * copems/src/lib/payroll.ts:223-276, plus the two deduction-key lists and the
 * `normalizedEmployeeDeductions()` helper that live beside it (payroll.ts:278-322).
 *
 * Pay is **additive**: a day is worth something only when it is worked (or
 * covered by approved paid leave), and `basic_pay` is the sum of the three day
 * buckets rather than the monthly rate less absences. The buckets are disjoint
 * by construction — a worked holiday is priced once, in `holiday_pay` — so
 * `work_days + holiday_days + rest_days` never counts a day twice.
 *
 * `net_pay` is deliberately UNCLAMPED and can be negative. There is no
 * max(0, …) here and there must never be one: `payrollDraftError()` in the
 * reference refuses to finalize a run whose deductions exceed gross pay rather
 * than silently altering configured deductions.
 *
 * Money is plain PHP float end to end. DECIMAL(12,2) is the storage type;
 * float is the compute type. Never an Eloquent 'decimal:N' cast — that returns
 * a string.
 */
final class PayslipDraft
{
    /**
     * payroll.ts:278-286 — PAYROLL_DEDUCTION_KEYS.
     *
     * THE ORDER IS LOAD-BEARING. `total_deductions` is a left-to-right float
     * sum over exactly this sequence starting from 0; reordering it can move a
     * centavo.
     *
     * @var list<string>
     */
    public const DEDUCTION_KEYS = [
        'late_deduction',
        'undertime_deduction',
        'sss',
        'philhealth',
        'pagibig',
        'withholding_tax',
        'other_deductions',
    ];

    /**
     * payroll.ts:289-295 — EDITABLE_PAYROLL_DEDUCTION_KEYS. The subset a
     * payroll manager may override on an unsaved draft.
     *
     * @var list<string>
     */
    public const EDITABLE_DEDUCTION_KEYS = [
        'sss',
        'philhealth',
        'pagibig',
        'withholding_tax',
        'other_deductions',
    ];

    public function __construct(
        public readonly string $employee_id,
        public readonly string $period_start,
        public readonly string $period_end,
        public readonly float $daily_rate,

        // Day counts. `absence_days` and `unpaid_leave_days` are reported for
        // the payslip but cost nothing on their own — an unworked day simply
        // earns none of the buckets below.
        public readonly float $work_days,
        public readonly float $holiday_days,
        public readonly float $rest_days,
        public readonly float $absence_days,
        public readonly float $unpaid_leave_days,

        // Earnings, in payslip order.
        public readonly float $work_days_pay,
        public readonly float $holiday_pay,
        public readonly float $rest_day_pay,
        /** work_days_pay + holiday_pay + rest_day_pay. */
        public readonly float $basic_pay,
        public readonly float $allowances,
        /** basic_pay + allowances — the "Total" line under Allowance. */
        public readonly float $total_base_pay,
        public readonly float $overtime_hours,
        /** Always the computed amount, whether or not it was acknowledged. */
        public readonly float $overtime_pay,
        /** Overtime reaches gross pay only once someone acknowledges it. */
        public readonly bool $overtime_acknowledged,
        /** Night shift differential. Computed from the shift; a typed figure wins. */
        public readonly float $night_differential,
        /** Hand-entered pay for working a day already filed as leave. */
        public readonly float $leave_pay,
        public readonly float $other_earnings,
        public readonly float $bonus,
        /**
         * KNOWN GAP, ported faithfully: the reference engine hard-wires this to
         * 0 and dismisses 13th-month pay as "processed separately" — separately
         * nowhere. PH law requires it paid by 24 December, and no phase of the
         * port plan builds it (docs/03-OPEN-ISSUES.md, first bullet block).
         * Left at 0 so the two systems reconcile; escalated, not fixed here.
         */
        public readonly float $thirteenth_month,
        public readonly float $gross_pay,

        // Deductions.
        public readonly float $sss,
        public readonly float $sss_er,
        public readonly float $philhealth,
        public readonly float $philhealth_er,
        public readonly float $pagibig,
        public readonly float $pagibig_er,
        public readonly float $withholding_tax,
        public readonly float $other_deductions,
        /**
         * FLOAT, not int, even though `payslips.late_minutes` is an INT column.
         * Every JS number is a double, DayRecord's minute fields are floats for
         * the same reason, and a float lets a pathological NaN reach a zeroed
         * field rather than raising a TypeError inside the finalize
         * transaction. The persistence layer narrows to int at the column.
         */
        public readonly float $late_minutes,
        public readonly float $late_deduction,
        public readonly float $undertime_minutes,
        public readonly float $undertime_deduction,
        public readonly float $total_deductions,
        /** Deliberately unclamped — this CAN be negative. Never add max(0, …). */
        public readonly float $net_pay,
    ) {}

    /**
     * payroll.ts:316-322 — normalizedEmployeeDeductions().
     *
     *     normalized[key] = Math.max(0, round(values[key]));
     *
     * A missing key reads as 0: `round(undefined)` collapses to 0 in the
     * reference because `Number.isFinite(undefined)` is false, and
     * Money::round(null) does the same here.
     *
     * Lives on the DTO rather than on the calculator because both
     * computePayslip() and applyDeductionOverrides() call it.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, float>
     */
    public static function normalizeDeductions(array $values): array
    {
        $normalized = [];

        foreach (self::DEDUCTION_KEYS as $key) {
            $normalized[$key] = max(0.0, Money::round($values[$key] ?? null));
        }

        return $normalized;
    }

    /**
     * Sums the seven deduction lines in PAYROLL_DEDUCTION_KEYS order.
     *
     * payroll.ts:672-674 / 347-349:
     *
     *     round(PAYROLL_DEDUCTION_KEYS.reduce((total, key) => total + normalized[key], 0))
     *
     * @param  array<string, float>  $normalized
     */
    public static function sumDeductions(array $normalized): float
    {
        $total = 0.0;

        foreach (self::DEDUCTION_KEYS as $key) {
            $total += $normalized[$key] ?? 0.0;
        }

        return Money::round($total);
    }

    /**
     * The PHP counterpart of the reference's `{ ...payslip, …changes }` spread.
     *
     * Keys must be constructor parameter names; anything absent is carried
     * through unchanged.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(...array_merge($this->toArray(), $changes));
    }

    /**
     * Column-named payload, in payslip order. The keys are exactly the
     * `payslips` column names, so a persistence layer can hand this straight to
     * a model without a mapping table.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employee_id,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'daily_rate' => $this->daily_rate,

            'work_days' => $this->work_days,
            'holiday_days' => $this->holiday_days,
            'rest_days' => $this->rest_days,
            'absence_days' => $this->absence_days,
            'unpaid_leave_days' => $this->unpaid_leave_days,

            'work_days_pay' => $this->work_days_pay,
            'holiday_pay' => $this->holiday_pay,
            'rest_day_pay' => $this->rest_day_pay,
            'basic_pay' => $this->basic_pay,
            'allowances' => $this->allowances,
            'total_base_pay' => $this->total_base_pay,
            'overtime_hours' => $this->overtime_hours,
            'overtime_pay' => $this->overtime_pay,
            'overtime_acknowledged' => $this->overtime_acknowledged,
            'night_differential' => $this->night_differential,
            'leave_pay' => $this->leave_pay,
            'other_earnings' => $this->other_earnings,
            'bonus' => $this->bonus,
            'thirteenth_month' => $this->thirteenth_month,
            'gross_pay' => $this->gross_pay,

            'sss' => $this->sss,
            'sss_er' => $this->sss_er,
            'philhealth' => $this->philhealth,
            'philhealth_er' => $this->philhealth_er,
            'pagibig' => $this->pagibig,
            'pagibig_er' => $this->pagibig_er,
            'withholding_tax' => $this->withholding_tax,
            'other_deductions' => $this->other_deductions,
            'late_minutes' => $this->late_minutes,
            'late_deduction' => $this->late_deduction,
            'undertime_minutes' => $this->undertime_minutes,
            'undertime_deduction' => $this->undertime_deduction,
            'total_deductions' => $this->total_deductions,
            'net_pay' => $this->net_pay,
        ];
    }
}
