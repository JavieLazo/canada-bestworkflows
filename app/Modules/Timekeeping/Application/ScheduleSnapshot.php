<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\LeaveRequest;
use App\Domain\Attendance\Shift as DomainShift;

/**
 * What `ScheduleGuard` recorded about one employee BEFORE a schedule change
 * wrote anything: the punches it locked, the schedule as it stood, the
 * attendance records of the dates just before the change, and the shifts that
 * already overlapped. `ScheduleGuard::verify()` holds the change to it.
 */
final readonly class ScheduleSnapshot
{
    /**
     * @param  list<DomainLog>  $logs  the locked punch window
     * @param  list<LeaveRequest>  $leaves
     * @param  list<HolidaySetting>  $holidays
     * @param  list<string>  $protected  the dates whose records must not change, oldest first
     * @param  string  $records  those dates' DTR rows and owned punches, as one comparable string
     * @param  list<string>  $overlaps  shifts already overlapping before the change, as comparable keys
     * @param  string  $todayPlace  where today is worked as the schedule stood, as a comparable string
     */
    public function __construct(
        public string $employeeId,
        public string $from,
        public ?string $to,
        public string $today,
        public string $contextFrom,
        public string $horizon,
        public array $logs,
        public array $leaves,
        public array $holidays,
        public ?DomainShift $schedule,
        public array $protected,
        public string $records,
        public array $overlaps,
        public string $todayPlace,
    ) {}
}
