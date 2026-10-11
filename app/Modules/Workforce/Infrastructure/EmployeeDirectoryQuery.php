<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Contracts\EmployeeAttendanceData;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Workforce owns the employee validity check used when filing an absence. */
final class EmployeeDirectoryQuery implements EmployeeDirectory
{
    public function isActiveForAbsence(Access $actor): bool
    {
        abort_unless($actor->hasModule(ModuleCode::PORTAL) && $actor->hasEmployeeLink(), 403);

        return EmployeeProfile::query()->whereKey($actor->ownEmployeeId())->where('is_active', true)->exists();
    }

    public function forTimekeeping(Access $actor, string $employeeId, bool $lock = false): ?EmployeeAttendanceData
    {
        abort_unless($actor->mayReadAllData(ModuleCode::EDTR), 403);

        if ($lock && DB::transactionLevel() === 0) {
            throw new LogicException('Timekeeping employee locks need an existing transaction.');
        }

        return $this->identity($employeeId, $lock);
    }

    public function canLinkAccount(Access $actor, string $employeeId): bool
    {
        abort_unless($actor->mayReadAllData(ModuleCode::USERS), 403);

        return EmployeeProfile::query()->whereKey($employeeId)->where('is_active', true)->exists();
    }

    public function forRoster(Access $actor, string $employeeId, bool $lock = false): ?EmployeeAttendanceData
    {
        abort_unless($actor->mayReadAllData(ModuleCode::ROSTER), 403);

        if ($lock && DB::transactionLevel() === 0) {
            throw new LogicException('Roster employee locks need an existing transaction.');
        }

        return $this->identity($employeeId, $lock);
    }

    private function identity(string $employeeId, bool $lock = false): ?EmployeeAttendanceData
    {
        $employee = EmployeeProfile::query()->whereKey($employeeId)->when($lock, static fn ($query) => $query->lockForUpdate())->first();

        return $employee === null ? null : new EmployeeAttendanceData(
            $employee->id, $employee->first_name, $employee->last_name, $employee->suffix, $employee->work_site_id,
        );
    }
}
