<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Contracts\AssignmentEmployees;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\TextSort;
use Illuminate\Support\Collection;

/** Keeps canonical employee ids and display names behind Workforce ownership. */
final class AssignmentEmployeeQuery implements AssignmentEmployees
{
    public function identities(array $ids): Collection
    {
        return EmployeeProfile::query()->whereKey($ids)
            ->get(['id', 'employee_code', 'employee_id_no', 'first_name', 'last_name', 'suffix', 'position', 'employment_status', 'is_active', 'photo_path', 'work_site_id'])
            ->map(static fn (EmployeeProfile $row): DirectoryEmployee => Snapshot::of($row, DirectoryEmployee::class));
    }

    public function resolveActive(Access $actor, array $ids): array|string
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PROJECTS), 403);
        if ($ids === []) {
            return [];
        }
        $resolved = array_values(EmployeeProfile::query()->whereKey($ids)->where('is_active', true)
            ->pluck('id')->map(static fn ($id): string => (string) $id)->all());
        if (count($resolved) !== count($ids)) {
            return count($ids) === 1 ? 'Choose an active employee for the task.' : 'Choose active employees for the task.';
        }

        return $resolved;
    }

    public function names(array $ids): array
    {
        $employees = EmployeeProfile::query()->whereKey($ids)->get(['id', 'first_name', 'last_name', 'suffix'])->all();
        usort($employees, static function (EmployeeProfile $a, EmployeeProfile $b): int {
            $last = TextSort::compare($a->last_name, $b->last_name);

            return $last !== 0 ? $last : TextSort::compare($a->first_name, $b->first_name);
        });
        $names = [];
        foreach ($employees as $employee) {
            $names[$employee->id] = EmployeeName::display($employee);
        }

        return $names;
    }
}
