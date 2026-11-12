<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts;

use App\Support\Access;

/**
 * The leave-credit charge facts payroll captures with a run, and the charge
 * plan made from them (AttendanceChargePlan): what a payroll charges to
 * credits for lateness, undertime and absences before pricing the rest to
 * pay. Everything is detached arrays, so payroll's evidence can carry them
 * and a replay never reads the live policy, table or balances.
 *
 * An employee's entry: `policy_id`, `charges` (the policy's charge rules:
 * enabled, combine, late and absence orders), `conversion` (the table in
 * use when the payroll is worked out — its id, rows, hours_per_day,
 * decimals — or null), `balances` (token => name, available, below_zero_limit, as of
 * the capture, and — only for a leave type with a waiting period, or one
 * not everyone may use — `available_from`, the first day it is the
 * employee's (acquired, or eligible), before which no day is charged to
 * it), `year` (the credits year charged: the period's start
 * year) and `charged` (dates an earlier payslip charged, by kind). An
 * employee whose terms charge nothing has no entry.
 */
interface LeaveChargeSource
{
    /**
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, mixed>>
     */
    public function forPayroll(Access $actor, array $employeeIds, string $periodStart, string $periodEnd): array;

    /**
     * The plan for one employee's entry over the period's days.
     *
     * @param  array<string, mixed>  $entry
     * @param  list<array{date: string, late_minutes: float, undertime_minutes: float, absence_days: float}>  $days
     * @return array{lines: list<array{date: string, kind: string, minutes: ?int, days: float, covered: array<string, float>, uncovered_days: float, fraction: float}>, balances: array<string, float>, notes: list<string>}
     */
    public function plan(array $entry, array $days): array;
}
