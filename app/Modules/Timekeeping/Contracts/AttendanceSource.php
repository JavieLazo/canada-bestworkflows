<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts;

use App\Support\Access;

/** Attendance inputs are shared by authorized DTR, own-portal and payroll operations. */
interface AttendanceSource
{
    public function forDtr(Access $actor, string $employeeId, string $start, string $end): AttendanceInputs;

    /** The linked employee only, including for all-data holders; null when unlinked. */
    public function forOwnPortal(Access $actor, string $start, string $end): ?AttendanceInputs;

    /**
     * The facts a payroll prices the period from. With `$punchesThrough` (a
     * 'Y-m-d' inside the period — an advance payroll's "attendance counted
     * through" date), the punches and overtime decisions stop at that day:
     * the next day's are read only up to its first Time In, which is the
     * night's carry-over, so what is punched after the counted day never
     * reaches the facts. Leaves and the schedule still cover the whole
     * period, which prices an approved leave or a rest day after it as what
     * it is.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, AttendanceInputs>
     */
    public function forPayroll(Access $actor, array $employeeIds, string $start, string $end, ?string $punchesThrough = null): array;
}
