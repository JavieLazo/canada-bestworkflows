<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Support\Money;

/**
 * A literal port of copems/src/lib/payroll-statutory.ts.
 *
 * Every arithmetic step, every constant and every rounding boundary is
 * transcribed rather than reimplemented. Where the TS calls money(), this
 * calls Money::round(). PHP's round() is banned in this namespace and an
 * architecture test enforces it.
 *
 * Divergence is proven absent by golden vectors emitted from the TS engine
 * itself — see tests/Unit/GoldenVectorTest.php. PayslipCalculator reads these
 * tables through StatutoryLines.
 *
 * DELIBERATE DIVERGENCE: the figures are not constants here but a
 * StatutorySchedule each function is handed — the Contribution & Tax Tables
 * the application keeps as dated versions. Without one, every function
 * computes from `StatutorySchedule::builtIn()`, the figures the reference
 * carries, so the golden vectors and every caller that passes nothing see
 * the reference arithmetic unchanged; a schedule built from the same figures
 * performs the same float operations (StatutorySchedule's docblock says how).
 * EFFECTIVE_FROM and VERIFIED_THROUGH are the built-in schedule's coverage,
 * read only when no table versions answer (PayrollPeriod::cutoffError).
 */
final class Statutory
{
    public const EFFECTIVE_FROM = '2025-01-01';

    public const VERIFIED_THROUGH = '2026-12-31';

    private static ?StatutorySchedule $builtIn = null;

    /** The reference's figures, built once. */
    public static function builtIn(): StatutorySchedule
    {
        return self::$builtIn ??= StatutorySchedule::builtIn();
    }

    /** TS: compensation() — Math.max(0, Number.isFinite(v) ? v : 0) */
    private static function compensation(mixed $value): float
    {
        $f = is_numeric($value) ? (float) $value : 0.0;

        if (! is_finite($f)) {
            $f = 0.0;
        }

        return max(0.0, $f);
    }

    /**
     * SSS Circular 2024-006, effective January 2025: 15% of the applicable MSC,
     * split 5% employee / 10% employer. The employer share also includes EC.
     *
     * @return array{employee: float, employer: float}
     */
    public static function sss(mixed $monthlyCompensation, ?StatutorySchedule $schedule = null): array
    {
        $salary = self::compensation($monthlyCompensation);

        if ($salary === 0.0) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        // Built in: min(35000, max(5000, floor((salary + 250) / 500) * 500)), EC 10 up to 14500, else 30.
        $t = ($schedule ?? self::builtIn())->sss;
        $msc = min($t['msc_max'], max($t['msc_min'], floor(($salary + $t['offset']) / $t['step']) * $t['step']));
        $ec = $msc <= $t['ec_threshold'] ? $t['ec_low'] : $t['ec_high'];

        return [
            'employee' => Money::round($msc * $t['ee_rate']),
            'employer' => Money::round($msc * $t['er_rate'] + $ec),
        ];
    }

    /**
     * PhilHealth's 5% premium, equally shared, with the current salary floor/cap.
     *
     * @return array{employee: float, employer: float}
     */
    public static function philhealth(mixed $monthlyCompensation, ?StatutorySchedule $schedule = null): array
    {
        $salary = self::compensation($monthlyCompensation);

        if ($salary === 0.0) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        // Built in: 2.5% a side on the salary clamped to 10,000–100,000.
        $t = ($schedule ?? self::builtIn())->philhealth;
        $premiumBase = min($t['cap'], max($t['floor'], $salary));

        return [
            'employee' => Money::round($premiumBase * $t['ee_rate']),
            'employer' => Money::round($premiumBase * $t['er_rate']),
        ];
    }

    /**
     * Pag-IBIG Circular 460: 1% EE through P1,500, otherwise 2%, capped at P10,000.
     *
     * @return array{employee: float, employer: float}
     */
    public static function pagibig(mixed $monthlyCompensation, ?StatutorySchedule $schedule = null): array
    {
        $salary = self::compensation($monthlyCompensation);

        if ($salary === 0.0) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        // Built in: 1% through 1,500 else 2%, employer 2%, on the salary capped at 10,000.
        $t = ($schedule ?? self::builtIn())->pagibig;
        $fundSalary = min($t['cap'], $salary);

        return [
            'employee' => Money::round($fundSalary * ($salary <= $t['low_line'] ? $t['ee_low_rate'] : $t['ee_rate'])),
            'employer' => Money::round($fundSalary * $t['er_rate']),
        ];
    }

    /**
     * BIR Annex E semi-monthly withholding table (built in: the brackets
     * effective January 1, 2023): the reference's if/elseif ladder, one
     * bracket at a time, rounded once.
     */
    public static function semiMonthlyWithholdingTax(mixed $taxableCompensation, ?StatutorySchedule $schedule = null): float
    {
        $taxable = self::compensation($taxableCompensation);

        return Money::round(StatutorySchedule::bracketTax(($schedule ?? self::builtIn())->withholding['semi_monthly'], $taxable));
    }

    /** TRAIN annual income-tax table (built in: effective January 1, 2023). */
    public static function annualIncomeTax(mixed $taxableIncome, ?StatutorySchedule $schedule = null): float
    {
        $taxable = self::compensation($taxableIncome);

        return Money::round(StatutorySchedule::bracketTax(($schedule ?? self::builtIn())->withholding['annual'], $taxable));
    }

    /** Per-cutoff withholding after annualizing a semi-monthly taxable amount. */
    public static function annualizedSemiMonthlyWithholdingTax(mixed $taxableCompensation, ?StatutorySchedule $schedule = null): float
    {
        return Money::round(self::annualIncomeTax(self::compensation($taxableCompensation) * 24, $schedule) / 24);
    }

    /**
     * The SEMI_MONTHLY path of copems/src/lib/payroll.ts contribution():
     *
     *     return monthly / 2;          // then round() at the call site
     *
     * This is where the money actually diverges. An already-2dp-rounded table
     * value divided by 2 manufactures an exact half-centavo whenever the last
     * centavo digit is odd, and a half-centavo is precisely where PHP's
     * round($x, 2) and JS's Math.round(x * 100) / 100 disagree.
     *
     * Verified at monthly_rate 10241: PhilHealth monthly employee share is
     * 256.03, halved is 128.015, and the engine yields 128.01 where PHP's
     * round() yields 128.02.
     */
    public static function semiMonthlyShare(mixed $monthly): float
    {
        return Money::round(Money::round($monthly) / 2);
    }

    public static function scheduleError(string $periodStart): ?string
    {
        if ($periodStart < self::EFFECTIVE_FROM) {
            return 'Automatic statutory schedules are supported from '.self::EFFECTIVE_FROM.' onward.';
        }
        if ($periodStart > self::VERIFIED_THROUGH) {
            return 'Automatic statutory schedules are verified only through '.self::VERIFIED_THROUGH.'.';
        }

        return null;
    }
}
