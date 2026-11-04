<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Modules\Workforce\Application\SalaryRates;
use App\Modules\Workforce\Contracts\SalaryHistory;
use App\Support\Access;

/** Keeps the salary versions inside Workforce; gated exactly like the pay contexts. */
final class SalaryHistoryQuery implements SalaryHistory
{
    public function segments(Access $actor, array $employeeIds, string $start, string $end): array
    {
        abort_unless($actor->hasModule(ModuleCode::PAYROLL), 403);
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            abort_unless($actor->hasEmployeeLink() && array_diff($employeeIds, [$actor->ownEmployeeId()]) === [], 403);
        }

        return SalaryRates::segments($employeeIds, $start, $end);
    }
}
