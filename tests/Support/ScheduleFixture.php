<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\EmployeeProfile;
use App\Models\Shift;

/**
 * A schedule for a test that is not about schedules. A payroll is not
 * finalized, and a leave or official business is not approved, while a
 * date has no shift (ScheduleHold, LeaveScheduleHold), so an employee who
 * is paid or reviewed needs one. `standard()` gives them a standing shift
 * with exactly the figures of the engine's own default week, Shift::default()
 * — 08:00–17:00, Monday to Friday, 15 minutes' grace, no break, fixed — so
 * every DTR, leave cost and payslip figure is what it was with no shift at
 * all; only the dates stop being "no shift".
 *
 * Written as the baseline row (no effective date), like PunchShiftFixture.
 */
final class ScheduleFixture
{
    public static function standard(EmployeeProfile|string $employee): Shift
    {
        return Shift::query()->create([
            'employee_id' => $employee instanceof EmployeeProfile ? (string) $employee->getKey() : $employee,
            'mode' => 'FIXED',
            'work_days' => [1, 2, 3, 4, 5],
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'grace_minutes' => 15,
            'break_minutes' => 0,
            'requires_break_punch' => false,
        ]);
    }
}
