<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Benefits;

use App\Support\Money;

/**
 * The PHP 90,000 yearly exemption for 13th month pay and other benefits.
 *
 * NIRC Sec. 32(B)(7)(e), as amended by RA 10963 (TRAIN), excludes from gross
 * compensation income the 13th month pay and "other benefits" an employee
 * receives in a calendar year, up to PHP 90,000 in total. Whatever goes over
 * that ceiling is taxable compensation of the payslip that crosses it.
 *
 * DELIBERATE DIVERGENCE: the reference engine has no such rule. It posts
 * `thirteenth_month = 0` and passes `bonus = 0` (payroll.ts:531-532,
 * payroll-server-calculation.ts:109), so nothing it pays could reach the
 * ceiling. Once ems pays either (a 13th month run, a tax-exempt incentive
 * line), the ceiling has to be applied somewhere, and it MUST be applied
 * HERE and nowhere else: every run kind that pays an exempt benefit splits
 * it through this class, which is what keeps one employee's allowance from
 * being spent twice. PayrollData::calculateInputs splits every tax-exempt
 * incentive line through it, and ThirteenthMonthData::calculateInputs every
 * 13th month pay; a regular payslip without an exempt line posts 0 exempt.
 *
 * `usedBefore` is the exempt amount already posted this calendar year, read
 * from finalized payslips (`ExemptBenefitLedger`); `amount` is what this
 * payslip wants to pay tax-free. Only the EXEMPT part counts toward the
 * ceiling, so an employee who has used it up has no allowance left and every
 * further peso is taxable.
 *
 * Pure: no clock, database or framework. Amounts are pesos through
 * Money::round() only; a negative or junk input collapses to 0, as every
 * typed payroll input does.
 */
final class ExemptBenefitCeiling
{
    /** PHP 90,000 per employee per calendar year (RA 10963, Sec. 5). */
    public const float CEILING = 90_000.0;

    private function __construct() {}

    /**
     * Splits one exempt amount into the part still under the ceiling and the
     * part over it.
     */
    public static function split(mixed $usedBefore, mixed $amount): ExemptBenefitSplit
    {
        $used = max(0.0, Money::round($usedBefore));
        $value = max(0.0, Money::round($amount));
        $allowance = max(0.0, Money::round(self::CEILING - $used));
        $exempt = min($value, $allowance);

        return new ExemptBenefitSplit(
            exempt: $exempt,
            taxableExcess: Money::round($value - $exempt),
            usedAfter: Money::round($used + $exempt),
        );
    }

    /**
     * Splits several exempt lines of one payslip IN ORDER: each line spends
     * what is left of the allowance after the lines before it, so the line
     * that crosses the ceiling carries the excess and every line after it is
     * wholly taxable.
     *
     * @param  list<mixed>  $amounts
     * @return list<ExemptBenefitSplit>
     */
    public static function allocate(mixed $usedBefore, array $amounts): array
    {
        $splits = [];
        $used = $usedBefore;

        foreach ($amounts as $amount) {
            $split = self::split($used, $amount);
            $splits[] = $split;
            $used = $split->usedAfter;
        }

        return $splits;
    }
}
