<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Tax;

use App\Domain\Payroll\StatutoryLines;
use App\Domain\Payroll\StatutorySchedule;
use App\Support\Money;

/**
 * The withholding on a payment made outside a regular cut-off — the part of
 * a 13th month pay over the PHP 90,000 yearly exemption.
 *
 * By default, it is taxed at the employee's MARGINAL rate for one
 * cut-off: the tax on the regular taxable pay of the latest
 * finalized regular payslip that year PLUS the excess, less the tax on that
 * regular pay alone — `withholding(R + E) − withholding(R)`, both through
 * StatutoryLines::withholding (the engine's own [R21], so the employee's
 * switches, fixed tax, percentage and annualised table apply exactly as on a
 * payslip). A fixed-tax employee therefore owes nothing more; a percentage
 * one owes that percentage of the excess. Never negative.
 *
 * Pure: no clock, database or framework.
 */
final class SupplementaryWithholding
{
    private function __construct() {}

    /**
     * @param  object  $employee  read structurally, as StatutoryLines::withholding reads it
     * @param  StatutorySchedule|null  $schedule  the withholding table in force; null is the built-in figures
     */
    public static function tax(object $employee, mixed $regularTaxable, mixed $excess, ?StatutorySchedule $schedule = null): float
    {
        $regular = max(0.0, Money::round($regularTaxable));
        $extra = max(0.0, Money::round($excess));
        if ($extra <= 0.0) {
            return 0.0;
        }

        return max(0.0, Money::round(
            StatutoryLines::withholding($employee, $regular + $extra, $schedule) - StatutoryLines::withholding($employee, $regular, $schedule),
        ));
    }
}
