<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\PayrollEmployeeBenefit;
use App\Models\PayrollIncentiveType;
use App\Modules\Payroll\Application\BenefitAssignments;
use App\Modules\Payroll\Contracts\Views\BenefitAssignmentView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * The benefits assigned to one employee as the pay page reads them,
 * newest first, drafts included, each with its type's name. Pay data:
 * all-data PAYROLL only. Writes go through the commands.
 */
final class BenefitAssignmentRecords
{
    /** @return Collection<int, BenefitAssignmentView> */
    public function forEmployee(Access $actor, string $employeeId): Collection
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        if (! BenefitAssignments::ready()) {
            return new Collection;
        }
        $rows = PayrollEmployeeBenefit::query()->where('employee_id', $employeeId)
            ->orderByDesc('starts_on')->orderByDesc('id')->get();
        $names = PayrollIncentiveType::query()->whereIn('id', $rows->pluck('type_id')->unique()->all())->pluck('name', 'id');

        return $rows->map(static fn (PayrollEmployeeBenefit $row): BenefitAssignmentView => Snapshot::of($row, BenefitAssignmentView::class, ['type_name' => (string) ($names[$row->type_id] ?? 'A benefit no longer listed')]));
    }

    /** How many assignments wait for approval, across employees. */
    public function draftCount(Access $actor): int
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        if (! BenefitAssignments::ready()) {
            return 0;
        }

        return PayrollEmployeeBenefit::query()->where('status', PayrollEmployeeBenefit::DRAFT)->count();
    }
}
