<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\ThirteenthMonth;

use App\Support\Money;

/**
 * The 13th month pay: one twelfth of the basic pay an employee earned in the
 * calendar year (PD 851 and its rules, as DOLE reads "basic salary").
 *
 * "Basic pay earned" is what EMS's own FINALIZED regular payslips of the year
 * paid as `work_days_pay + leave_pay` — the days worked and the paid leave —
 * plus what the payroll officer typed as earned OUTSIDE EMS that year.
 * Holiday pay, rest day pay, overtime, night differential, allowances and
 * incentives are NOT basic salary and are excluded. DELIBERATE DIVERGENCE:
 * the reference posts `thirteenth_month = 0` on every payslip (payroll.ts:531)
 * and has no 13th month run.
 *
 * Pure: no clock, database or framework. Pesos through Money::round() only;
 * a negative or junk basis counts as 0, as every typed payroll input does.
 */
final class ThirteenthMonthPay
{
    private function __construct() {}

    /** The year's basic pay: EMS's and the typed outside figure, never below 0 each. */
    public static function basis(mixed $emsBasicPay, mixed $outsideBasicPay): float
    {
        return Money::round(max(0.0, Money::round($emsBasicPay)) + max(0.0, Money::round($outsideBasicPay)));
    }

    /** One twelfth of that, to the centavo. */
    public static function amount(mixed $emsBasicPay, mixed $outsideBasicPay): float
    {
        return Money::round(self::basis($emsBasicPay, $outsideBasicPay) / 12);
    }
}
