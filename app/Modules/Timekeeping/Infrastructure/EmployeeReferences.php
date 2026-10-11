<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Infrastructure;

use App\Models\AttendanceLog;
use App\Models\LeaveTravelRequest;
use App\Models\RosterDayOverride;
use App\Models\RosterLocation;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Platform\Retention\EmployeeReferenceSource;

/** Preserves attendance history and saved schedules before employee deletion is considered. */
final class EmployeeReferences implements EmployeeReferenceSource
{
    public function references(string $employeeId): bool
    {
        return AttendanceLog::query()->where('employee_id', $employeeId)->exists()
            || LeaveTravelRequest::query()->where('employee_id', $employeeId)->exists()
            || Shift::query()->where('employee_id', $employeeId)->exists()
            || RosterLocation::query()->where('employee_id', $employeeId)->exists()
            || RosterShift::query()->where('employee_id', $employeeId)->exists()
            || RosterDayOverride::query()->where('employee_id', $employeeId)->exists();
    }
}
