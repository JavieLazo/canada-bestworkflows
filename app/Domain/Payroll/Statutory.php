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
 * itself — see tests/Feature/GoldenVectorTest.php.
 */
final class Statutory
{
    public const EFFECTIVE_FROM = '2025-01-01';

    public const VERIFIED_THROUGH = '2026-12-31';

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
    public static function sss(mixed $monthlyCompensation): array
    {
        $salary = self::compensation($monthlyCompensation);

        if ($salary === 0.0) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        $msc = min(35000.0, max(5000.0, floor(($salary + 250) / 500) * 500));
        $ec = $msc <= 14500 ? 10 : 30;

        return [
            'employee' => Money::round($msc * 0.05),
            'employer' => Money::round($msc * 0.1 + $ec),
        ];
    }

    /**
     * PhilHealth's 5% premium, equally shared, with the current salary floor/cap.
     *
     * @return array{employee: float, employer: float}
     */
    public static function philhealth(mixed $monthlyCompensation): array
    {
        $salary = self::compensation($monthlyCompensation);

        if ($salary === 0.0) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        $premiumBase = min(100000.0, max(10000.0, $salary));
        $share = Money::round($premiumBase * 0.025);

        return ['employee' => $share, 'employer' => $share];
    }

    /**
     * Pag-IBIG Circular 460: 1% EE through P1,500, otherwise 2%, capped at P10,000.
     *
     * @return array{employee: float, employer: float}
     */
    public static function pagibig(mixed $monthlyCompensation): array
    {
        $salary = self::compensation($monthlyCompensation);

        if ($salary === 0.0) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }

        $fundSalary = min(10000.0, $salary);

        return [
            'employee' => Money::round($fundSalary * ($salary <= 1500 ? 0.01 : 0.02)),
            'employer' => Money::round($fundSalary * 0.02),
        ];
    }

    /** BIR Annex E semi-monthly withholding table, effective January 1, 2023. */
    public static function semiMonthlyWithholdingTax(mixed $taxableCompensation): float
    {
        $taxable = self::compensation($taxableCompensation);

        if ($taxable <= 10417) {
            return 0.0;
        }
        if ($taxable <= 16667) {
            return Money::round(($taxable - 10417) * 0.15);
        }
        if ($taxable <= 33333) {
            return Money::round(937.5 + ($taxable - 16667) * 0.2);
        }
        if ($taxable <= 83333) {
            return Money::round(4270.7 + ($taxable - 33333) * 0.25);
        }
        if ($taxable <= 333333) {
            return Money::round(16770.7 + ($taxable - 83333) * 0.3);
        }

        return Money::round(91770.7 + ($taxable - 333333) * 0.35);
    }

    /** TRAIN annual income-tax table, effective January 1, 2023. */
    public static function annualIncomeTax(mixed $taxableIncome): float
    {
        $taxable = self::compensation($taxableIncome);

        if ($taxable <= 250000) {
            return 0.0;
        }
        if ($taxable <= 400000) {
            return Money::round(($taxable - 250000) * 0.15);
        }
        if ($taxable <= 800000) {
            return Money::round(22500 + ($taxable - 400000) * 0.2);
        }
        if ($taxable <= 2000000) {
            return Money::round(102500 + ($taxable - 800000) * 0.25);
        }
        if ($taxable <= 8000000) {
            return Money::round(402500 + ($taxable - 2000000) * 0.3);
        }

        return Money::round(2202500 + ($taxable - 8000000) * 0.35);
    }

    /** Per-cutoff withholding after annualizing a semi-monthly taxable amount. */
    public static function annualizedSemiMonthlyWithholdingTax(mixed $taxableCompensation): float
    {
        return Money::round(self::annualIncomeTax(self::compensation($taxableCompensation) * 24) / 24);
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
