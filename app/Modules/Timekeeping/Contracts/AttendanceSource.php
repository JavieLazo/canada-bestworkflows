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
     * @param  list<string>  $employeeIds
     * @return array<string, AttendanceInputs>
     */
    public function forPayroll(Access $actor, array $employeeIds, string $start, string $end): array;
}
