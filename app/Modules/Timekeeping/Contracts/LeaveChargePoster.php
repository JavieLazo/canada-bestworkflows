<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts;

use App\Support\Access;

/**
 * Posts a finalized payslip's charges to leave credits: one
 * `leave_attendance_charges` row per day and kind, a CHARGE ledger entry
 * per leave type covered, and the allocation moved. Called inside payroll's
 * finalization transaction, after payroll's own membership lock; it takes
 * the leave-credits lock itself. A day charged twice fails the whole
 * finalization, which is the guard against double charging.
 */
interface LeaveChargePoster
{
    /**
     * @param  list<array{date: string, kind: string, minutes: ?int, days: float, covered: array<string, float>, uncovered_days: float, fraction: float}>  $lines
     * @param  array<string, mixed>  $entry  the captured facts the lines were planned from (LeaveChargeSource)
     */
    public function post(Access $actor, string $employeeId, string $payslipId, string $runCode, array $lines, array $entry): void;
}
