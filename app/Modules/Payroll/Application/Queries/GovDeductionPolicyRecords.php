<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\PayrollGovDeductionPolicy;
use App\Modules\Payroll\Application\GovDeductionPolicyStore;
use App\Modules\Payroll\Contracts\Views\GovDeductionPolicyView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * The Contributions & tax versions as the employee's pay page and the
 * Payroll Settings read them: one employee's, newest first, drafts
 * included; every employee's drafts, for the queue. Pay data (SEC-01):
 * all-data PAYROLL only. Writes go through the commands.
 */
final class GovDeductionPolicyRecords
{
    /** @return Collection<int, GovDeductionPolicyView> newest first */
    public function versions(Access $actor, string $employeeId): Collection
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        if (! GovDeductionPolicyStore::ready()) {
            return new Collection;
        }

        return PayrollGovDeductionPolicy::query()->where('employee_id', $employeeId)
            ->orderByDesc('effective_from')->orderByDesc('id')->get()
            ->map(static fn (PayrollGovDeductionPolicy $row): GovDeductionPolicyView => Snapshot::of($row, GovDeductionPolicyView::class));
    }

    /**
     * The approved version in force for each of these employees on a day.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, GovDeductionPolicyView>
     */
    public function inForce(Access $actor, array $employeeIds, string $date): array
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        $out = [];
        foreach (GovDeductionPolicyStore::forEmployees($employeeIds, $date) as $employeeId => $row) {
            $out[$employeeId] = Snapshot::of($row, GovDeductionPolicyView::class);
        }

        return $out;
    }

    /**
     * The employees with a draft waiting, for the list.
     *
     * @return list<string>
     */
    public function employeesWithDrafts(Access $actor): array
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        if (! GovDeductionPolicyStore::ready()) {
            return [];
        }

        return array_values(array_map('strval', PayrollGovDeductionPolicy::query()->where('status', PayrollGovDeductionPolicy::DRAFT)->distinct()->pluck('employee_id')->all()));
    }
}
