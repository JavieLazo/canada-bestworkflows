<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Models\LeaveEarningPosting;
use Illuminate\Support\Facades\Schema;

/**
 * The attendance charges a finalized payroll posted to leave credits
 * (`leave_attendance_charges`), as the rest of Timekeeping reads them:
 * whether the table exists yet, and which year's row holds the balance a
 * payroll charges.
 */
final class LeaveCharges
{
    private function __construct() {}

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('leave_attendance_charges'));
    }

    /**
     * The year whose row holds an employee's LIVE balance for a payroll
     * starting in `$startYear`: that year, moved forward past every year
     * the earning job has already closed (PostLeaveEarnings, period
     * `YYYY-YE`), because a closed year's whole balance was carried into
     * the next and its row is history. Credits never lapse, so the live
     * row is the one a charge must move — a December cut-off settled in
     * January charges the new year once the old one is closed.
     */
    public static function liveYear(string $employeeId, int $startYear): int
    {
        $year = $startYear;
        // Bounded: a chain of closed years is at most as long as the ledger.
        while ($year < $startYear + 50 && LeaveEarningPosting::query()->where('employee_id', $employeeId)->where('period', $year.'-YE')->exists()) {
            $year++;
        }

        return $year;
    }
}
