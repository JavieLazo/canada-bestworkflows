<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Support\Money;

/**
 * The per-payslip statutory lines of copems/src/lib/payroll.ts, as public
 * pure functions: `contribution()` (payroll.ts:201-212), the month-end test
 * (payroll.ts:587), the taxable base (payroll.ts:644-647) and the withholding
 * choice (payroll.ts:648-660).
 *
 * They live here, not inside PayslipCalculator, because more than one
 * caller must apply the SAME arithmetic: PayslipCalculator::compute() itself,
 * and the payroll application when an officer overtypes an employee share and
 * the tax is re-derived from the shares actually posted
 * (PayrollData::rederiveWithholding). Withholding on a supplementary payment
 * — the part of a benefit over the PHP 90,000 ceiling — must call it too when
 * it is built. One body, so they cannot drift.
 *
 * Every line is a literal port, including the JavaScript coercions:
 *
 *  - `contribution()` returns the monthly or half-monthly figure UNROUNDED;
 *    the caller rounds it, exactly as payroll.ts rounds each call site
 *    ([R15]–[R20] in PayslipCalculator).
 *  - `withholding()` holds the reference's [R21] rounding site
 *    (payroll.ts:652) — the one Money::round() of the tax.
 *  - A switch or a mode is read with JavaScript truthiness: `null`, `''`,
 *    `false` and `0` are false, and any other string — `'QUARTERLY'`
 *    included, and `'0'` too — is true. So a null or empty mode behaves as
 *    SEMI_MONTHLY, and every other mode as "whole month on the payslip whose
 *    period ends after the 15th", exactly as the reference treats them.
 *  - An amount is read like JavaScript `Number()`: junk and non-finite
 *    values count as 0.
 *
 * Pure: no clock, database or framework. Money only through Money::round().
 */
final class StatutoryLines
{
    /** Half the month's share on the first cut-off, the rest on the month-ending one (divergence 19). */
    public const string MODE_HALF_THEN_REST = 'HALF_THEN_REST';

    /** The whole month's share on the first cut-off, the rest (if any) on the month-ending one (divergence 19). */
    public const string MODE_WHOLE_FIRST = 'WHOLE_FIRST';

    private function __construct() {}

    /**
     * payroll.ts:587 — `Number(periodEnd.slice(8, 10)) > 15`. A MONTHLY-mode
     * employee is charged the whole month only on a payslip whose period
     * ends after the 15th. It reads the END day alone, as the reference does.
     */
    public static function endsMonth(string $periodEnd): bool
    {
        return (int) substr($periodEnd, 8, 2) > 15;
    }

    /**
     * payroll.ts:201-212, UNROUNDED. `$allow` is the employee's switch and
     * `$hasGrossPay` the reference's `gross_pay > 0` gate (payroll.ts:588),
     * combined as `allow && hasGrossPay`. A fixed monthly figure above 0
     * replaces the table's; a truthy mode other than SEMI_MONTHLY charges the
     * whole month on a month-ending payslip and nothing on the other; anything
     * else charges half.
     */
    public static function contribution(
        mixed $allow,
        bool $hasGrossPay,
        mixed $fixedMonthly,
        float $computedMonthly,
        mixed $mode,
        bool $endsMonth,
        float $postedSoFar = 0.0,
    ): float {
        if (! self::truthy($allow) || ! $hasGrossPay) {
            return 0.0;
        }

        $fixed = self::num($fixedMonthly);
        $monthly = $fixed > 0 ? $fixed : $computedMonthly;

        // DELIBERATE DIVERGENCE 19: two more splits of the month's share,
        // each needing what this month's posted payslips already took
        // (`$postedSoFar`, from the evidence). HALF_THEN_REST takes half on
        // the first cut-off and the REST on the month-ending one; WHOLE_FIRST
        // takes the whole on the first and the rest — nothing, once the
        // first posted — on the second, so a skipped first still pays the
        // month by its end. Neither literal reaches the reference's paths
        // below, which keep its operations.
        if ($mode === self::MODE_HALF_THEN_REST) {
            return $endsMonth ? max($monthly - $postedSoFar, 0.0) : $monthly / 2;
        }
        if ($mode === self::MODE_WHOLE_FIRST) {
            return $endsMonth ? max($monthly - $postedSoFar, 0.0) : $monthly;
        }

        if (self::truthy($mode) && $mode !== 'SEMI_MONTHLY') {
            return $endsMonth ? $monthly : 0.0;
        }

        return $monthly / 2;
    }

    /**
     * payroll.ts:644-647 — gross less the employee's three shares, less late
     * and undertime, floored at 0. The association is the reference's: the
     * three shares are added first.
     */
    public static function taxable(float $gross, float $sss, float $philhealth, float $pagibig, float $late, float $undertime): float
    {
        return max($gross - ($sss + $philhealth + $pagibig) - $late - $undertime, 0.0);
    }

    /**
     * payroll.ts:648-660 — the employee's withholding on `$taxable`, rounded
     * once ([R21] payroll.ts:652). No switch, no tax. A fixed tax above 0
     * wins; then a percentage above 0; otherwise the BIR table — the
     * annualized one when `annualize_tax` is on, the semi-monthly Annex E
     * one otherwise.
     *
     * @param  object  $employee  read structurally: allow_withholding_tax, fixed_tax, percent_tax, annualize_tax
     * @param  StatutorySchedule|null  $schedule  the tables in force; null is the reference's built-in figures
     */
    public static function withholding(object $employee, float $taxable, ?StatutorySchedule $schedule = null): float
    {
        $graduated = self::truthy($employee->annualize_tax ?? null)
            ? Statutory::annualizedSemiMonthlyWithholdingTax($taxable, $schedule)
            : Statutory::semiMonthlyWithholdingTax($taxable, $schedule);

        $fixed = self::num($employee->fixed_tax ?? null);
        $percent = self::num($employee->percent_tax ?? null);

        return Money::round(                                                      // [R21] payroll.ts:652
            ! self::truthy($employee->allow_withholding_tax ?? null)
                ? 0.0
                : ($fixed > 0
                    ? $fixed
                    : ($percent > 0 ? ($taxable * $percent) / 100 : $graduated)),
        );
    }

    /** JavaScript truthiness for the scalars a switch or a mode can hold. */
    public static function truthy(mixed $value): bool
    {
        return match (true) {
            $value === null => false,
            is_bool($value) => $value,
            is_int($value), is_float($value) => $value != 0 && ! is_nan((float) $value),
            is_string($value) => $value !== '',
            default => true,
        };
    }

    /** JavaScript `Number()` for an amount: junk and non-finite values read as 0. */
    private static function num(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : 0.0;
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
