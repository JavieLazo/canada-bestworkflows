<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Enums\ModuleCode;
use App\Support\Access;

/**
 * Who manages the company-wide Payroll Settings — the Contribution & Tax
 * Tables, the company's and the Employment statuses' Pay Basis & Rates, the
 * Payroll Deductions and the Incentives & Bonuses: whoever manages payroll
 * manages every part of it, so an all-data PAYROLL holder and nobody else.
 * The pages live under Payroll behind `module:PAYROLL`, and this check
 * agrees with that gate, so no role can write a setting it cannot open. One
 * employee's settings, rates and recurring lines are pay data and need the
 * same all-data PAYROLL.
 */
final class PayrollSettingsAccess
{
    public static function mayManage(Access $actor): bool
    {
        return $actor->mayReadAllData(ModuleCode::PAYROLL);
    }
}
