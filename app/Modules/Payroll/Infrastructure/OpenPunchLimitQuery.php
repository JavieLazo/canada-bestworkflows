<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Infrastructure;

use App\Domain\Payroll\PayrollRates;
use App\Models\PayrollFormula;
use App\Modules\Timekeeping\Contracts\OpenPunchLimit;

/** Supplies only the operational ceiling; payroll formulas never leave their owner. */
final class OpenPunchLimitQuery implements OpenPunchLimit
{
    public function hours(): float
    {
        return PayrollRates::fromFormulas(array_values(PayrollFormula::query()->get(['key', 'params'])->map->toArray()->all()))->maxOtHours;
    }
}
