<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use App\Support\Access;

/** A narrow identity lookup; no salary, personal records, or Eloquent access. */
interface EmployeeDirectory
{
    public function isActiveForAbsence(Access $actor): bool;

    /** With lock=true, inside an existing transaction: a correction waits for a schedule change on the employee, and the reverse. */
    public function forTimekeeping(Access $actor, string $employeeId, bool $lock = false): ?EmployeeAttendanceData;

    public function canLinkAccount(Access $actor, string $employeeId): bool;

    /** With lock=true, serialize roster changes on the employee inside an existing transaction. */
    public function forRoster(Access $actor, string $employeeId, bool $lock = false): ?EmployeeAttendanceData;
}
