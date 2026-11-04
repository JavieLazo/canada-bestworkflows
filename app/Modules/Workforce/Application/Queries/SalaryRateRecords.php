<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeSalaryRate;
use App\Modules\Workforce\Application\SalaryRates;
use App\Modules\Workforce\Contracts\Views\SalaryRateView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * The salary versions as the employee's pay page reads them: one
 * employee's, newest first, pending and rejected included. Pay data: an
 * all-data EMPLOYEES or PAYROLL holder only. Writes go through the
 * commands.
 */
final class SalaryRateRecords
{
    /** @return Collection<int, SalaryRateView> newest first */
    public function versions(Access $actor, string $employeeId): Collection
    {
        abort_unless($actor->mayReadAllData(ModuleCode::EMPLOYEES) || $actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        if (! SalaryRates::ready()) {
            return new Collection;
        }

        return EmployeeSalaryRate::query()->where('employee_id', $employeeId)
            ->orderByDesc('effective_from')->orderByDesc('id')->get()
            ->map(static fn (EmployeeSalaryRate $row): SalaryRateView => Snapshot::of($row, SalaryRateView::class));
    }

    /** How many past-dated changes wait for approval, across employees. */
    public function pendingCount(Access $actor): int
    {
        abort_unless($actor->mayReadAllData(ModuleCode::EMPLOYEES) || $actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        if (! SalaryRates::ready()) {
            return 0;
        }

        return EmployeeSalaryRate::query()->where('status', EmployeeSalaryRate::PENDING)->count();
    }
}
