<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/** Who a payroll's add-employees dialog may offer: the ACTIVE employees. */
final class PayrollEmployees
{
    /** @return Collection<int, DirectoryEmployee> */
    public function list(Access $actor): Collection
    {
        return $actor->mayReadAllData(ModuleCode::PAYROLL)
            ? EmployeeProfile::query()->where('is_active', true)
                ->get(['id', 'employee_code', 'employee_id_no', 'first_name', 'last_name', 'suffix', 'position', 'is_active', 'photo_path'])
                ->map(static fn (EmployeeProfile $row): DirectoryEmployee => Snapshot::of($row, DirectoryEmployee::class))
            : collect();
    }

    public function active(Access $actor, string $id): bool
    {
        return $actor->mayReadAllData(ModuleCode::PAYROLL) && EmployeeProfile::query()->whereKey($id)->where('is_active', true)->exists();
    }
}
