<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\DtrSummary;

/** The linked employee's computed attendance, detached from management and persistence. */
final readonly class OwnAttendanceView
{
    /**
     * @param  list<DayRecord>  $days
     * @param  array<string, OvertimeDayView>  $overtimeByDate  Decision status and minutes only, with no review metadata.
     * @param  array{days: int, late: float, undertime: float, absences: int}  $unscheduled
     */
    public function __construct(
        public array $days,
        public DtrSummary $totals,
        public int $absences,
        public array $overtimeByDate,
        public array $unscheduled,
    ) {}
}
