<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use App\Support\Access;

/**
 * The salary in force on each day of a period, for payroll: the approved
 * versions of each employee over the period, dated. Facts only — no
 * reasons, references or who recorded them cross the seam.
 */
interface SalaryHistory
{
    /**
     * @param  list<string>  $employeeIds
     * @return array<string, list<array{from: string, monthly_rate: ?float, daily_rate: ?float}>> employee id => the version in force on `$start` (dated as it is), then each later one through `$end`, ascending; employees with no version absent
     */
    public function segments(Access $actor, array $employeeIds, string $start, string $end): array;
}
