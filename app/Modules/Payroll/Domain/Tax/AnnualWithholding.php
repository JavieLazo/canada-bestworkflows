<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Tax;

use App\Domain\Payroll\Statutory;
use App\Domain\Payroll\StatutorySchedule;
use App\Support\Money;

/**
 * DELIBERATE DIVERGENCE 20 — the WHOLE-YEAR withholding method, the
 * estimate PPMC's tax workbook makes: the tax the whole tax year will owe,
 * less what the year's posted payslips already withheld, spread over the
 * payrolls left. The reference's two methods (the semi-monthly table per
 * cut-off, and the ×24 annualizer) never pass through here.
 *
 *  1. The year's taxable pay is PROJECTED: what the year's posted payslips
 *     and approved opening balances already carry, plus this payslip's
 *     taxable pay, plus one more of this payslip's taxable pay for each
 *     regular payroll still to come in the year. A bonus or an overtime
 *     already in this payslip is in the projection once — never repeated
 *     over the payrolls left.
 *  2. The yearly table taxes the projection (Statutory::annualIncomeTax).
 *  3. What the year already withheld comes off.
 *  4. The remainder is spread: EQUAL takes an equal share for this payroll
 *     and each one left; NEXT takes it all now; DECEMBER takes nothing
 *     until the year's last payroll. The year's LAST payroll always takes
 *     the exact remainder.
 *  5. Pesos through Money::round; never below zero. On the year's last
 *     payroll a remainder below zero — the year withheld too much — is the
 *     REFUND, paid on that payslip beside a zero withholding.
 *
 * Pure: the facts are handed in, the clock never read.
 */
final class AnnualWithholding
{
    public const string SPREAD_EQUAL = 'EQUAL';

    public const string SPREAD_NEXT = 'NEXT';

    public const string SPREAD_DECEMBER = 'DECEMBER';

    /** The three ways the remainder is spread, in the order the page offers them. */
    public const array SPREADS = [self::SPREAD_EQUAL, self::SPREAD_NEXT, self::SPREAD_DECEMBER];

    private function __construct() {}

    /**
     * @param  float  $taxablePosted  the year's taxable pay already posted, approved opening balances included
     * @param  float  $taxableThis  this payslip's taxable pay
     * @param  int  $payrollsLeft  the regular payrolls still to come in the year AFTER this one; 0 on the year's last
     * @param  float  $withheldPosted  the tax the year already withheld
     * @param  StatutorySchedule|null  $schedule  the tables in force; null is the built-in figures
     * @return array{withholding: float, refund: float, projected: float, annual_tax: float, remaining: float}
     */
    public static function compute(float $taxablePosted, float $taxableThis, int $payrollsLeft, float $withheldPosted, string $spread, ?StatutorySchedule $schedule = null): array
    {
        $left = max(0, $payrollsLeft);
        $this_ = max(0.0, Money::round($taxableThis));
        $projected = Money::round(max(0.0, $taxablePosted) + $this_ + $this_ * $left);
        $annualTax = Statutory::annualIncomeTax($projected, $schedule);
        $remaining = Money::round($annualTax - max(0.0, $withheldPosted));
        $share = match (true) {
            $left === 0 => $remaining,
            $spread === self::SPREAD_NEXT => $remaining,
            $spread === self::SPREAD_DECEMBER => 0.0,
            default => Money::round($remaining / ($left + 1)),
        };
        $withholding = max(0.0, Money::round($share));
        $refund = $left === 0 && $remaining < 0.0 ? Money::round(-$remaining) : 0.0;

        return ['withholding' => $withholding, 'refund' => $refund, 'projected' => $projected, 'annual_tax' => $annualTax, 'remaining' => $remaining];
    }
}
