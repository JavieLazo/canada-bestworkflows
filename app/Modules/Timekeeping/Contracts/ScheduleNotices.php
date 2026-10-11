<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts;

use App\Support\Access;
use Carbon\CarbonImmutable;

/**
 * What a payroll draft should know about its members' schedules before it is
 * finalized (D7 and Q7) — facts only, no schedule, hours or amounts cross
 * this seam.
 *
 *   - dates NOBODY ASSIGNED A SCHEDULE FOR: the engine judges them by its own
 *     default, and that fallback must not silently become absences, lateness
 *     or deductions;
 *   - schedule changes SAVED AFTER the draft was: the draft's amounts already
 *     follow the schedule each date has now, but the person finalizing must
 *     be told the ground moved.
 *
 * Scoped like AttendanceSource::forPayroll: PAYROLL, and either all-data or
 * only the actor's own employee.
 */
interface ScheduleNotices
{
    /**
     * @param  list<string>  $employeeIds
     * @return array<string, int> employee id => dates in [$start, $end], up to today, with no assigned schedule; only employees with at least one
     */
    public function unscheduledDays(Access $actor, array $employeeIds, string $start, string $end): array;

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, CarbonImmutable> employee id => the latest moment (UTC) after `$since` that a schedule change reaching a date of the months [$start, $end] touch was saved, revised or cancelled
     */
    public function changedSince(Access $actor, array $employeeIds, string $start, string $end, CarbonImmutable $since): array;
}
