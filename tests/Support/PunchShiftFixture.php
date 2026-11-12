<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Shift;

/**
 * A baseline shift for a test that punches and is not about schedules: the
 * punch station refuses a Time In on a date with no shift (RecordPunch), so
 * an employee who punches needs one. FLEXIBLE, every day of the week, so no
 * punch window applies and the test passes at any hour of the real clock.
 */
final class PunchShiftFixture
{
    public static function anyHour(string $employeeId): Shift
    {
        return Shift::query()->create([
            'employee_id' => $employeeId,
            'mode' => 'FLEXIBLE',
            'required_hours' => 8,
            'work_days' => [0, 1, 2, 3, 4, 5, 6],
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'grace_minutes' => 0,
            'break_minutes' => 0,
            'requires_break_punch' => false,
        ]);
    }
}
