<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Modules\Timekeeping\Domain\Roster\DateRuns;
use App\Modules\Timekeeping\Infrastructure\NoShiftDates;

/**
 * A leave or official business is NOT APPROVED while any of its dates has
 * no shift. Such a date is judged by the engine's own default week, so
 * approving would charge credits for days nobody scheduled — and, unpaid,
 * read days as leave without pay that the employee was never due to work.
 * Filing is never refused for it, and a disapproval or a recommendation
 * needs no schedule: the request waits until the schedule is set, through
 * the Shift Roster (a past date with no shift can be given one there).
 *
 * `ReviewAbsence` asks under its locks before it approves; the review dialog
 * asks the same question first (`AbsenceRecords::noShiftHold`).
 */
final class LeaveScheduleHold
{
    /**
     * The request's dates with no shift, oldest first.
     *
     * @return list<string>
     */
    public static function dates(string $employeeId, string $start, string $end): array
    {
        return app(NoShiftDates::class)->between([$employeeId], $start, $end)[$employeeId] ?? [];
    }

    /** @param  list<string>  $dates */
    public static function sentence(string $name, array $dates): string
    {
        return $name.' has no shift on '.DateRuns::describe($dates).'. Set the schedule in Shift Roster before approving.';
    }
}
