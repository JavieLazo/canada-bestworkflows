<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\OvertimeApproval;
use App\Modules\Timekeeping\Application\OvertimeCreditColumn;
use App\Support\Access;

/**
 * The Compensatory Leave hours an employee has EARNED: the minutes of
 * approved overtime the Daily Time Record credited instead of paying
 * (OvertimeApproval::$credited_minutes), summed across every approved day.
 * Read with the overtime decisions' row scope (EDTR, or the employee's own
 * rows), so My Portal shows an employee their own figure. Earned only —
 * the hours are never used, expired or settled here.
 */
final class CompensatoryHours
{
    /** The minutes credited so far, 0 before the column exists or when nothing was credited. */
    public function earnedMinutes(Access $actor, string $employeeId): int
    {
        if (! OvertimeCreditColumn::present()) {
            return 0;
        }

        return (int) OvertimeApproval::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::EDTR)
            ->where('employee_id', $employeeId)
            ->where('decision', OvertimeApproval::APPROVED)
            ->sum('credited_minutes');
    }
}
