<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts;

use App\Domain\Enums\ModuleCode;
use App\Support\Access;

/**
 * Consumer-owned port (like OpenPunchLimit): whether a day of an employee's
 * attendance is already paid on a posted payslip.
 *
 * An overtime decision on the Daily Time Record changes pay only until the
 * day is posted: after that the payslip is frozen and a new decision could
 * never be paid, so DecideOvertime refuses it. The answer must not race a
 * payroll being finalized, so the write guards take payroll's own
 * membership lock first — the same lock every payroll save and finalize
 * takes — and writing callers MUST take it FIRST inside their transaction, before
 * any other read: on MariaDB the first plain SELECT fixes a REPEATABLE READ
 * snapshot, and a snapshot taken before the lock would not see payslips a
 * finalize committed while this transaction waited for it. A transaction
 * that reads before deciding (the bulk action) calls lock() first; a single
 * decision starts with lockAndCovers().
 */
interface PostedPayrollDates
{
    /**
     * Read-only coverage for the screens' controls, scoped to the actor's
     * rows on `$module`: EDTR for the DTR's approval and correction
     * controls, LEAVES for a leave review. Unlike the write guards below,
     * this does not acquire locks. The write still checks under its lock.
     *
     * @param  list<string>  $employeeIds
     * @param  string  $module  a ModuleCode value
     * @return array<string, list<array{start: string, end: string}>>
     */
    public function coveredPeriods(Access $actor, array $employeeIds, string $start, string $end, string $module = ModuleCode::EDTR): array;

    /** Takes payroll's membership lock, held until the caller's transaction ends. */
    public function lock(): void;

    /** Takes the lock (again: re-entrant within one transaction) and answers from the latest committed payslips. */
    public function lockAndCovers(string $employeeId, string $date): bool;

    /**
     * Takes the lock and answers the last day already paid on a posted
     * payslip of this employee that overlaps the dates from `$from` through
     * `$to` (every later date when null) — null when none does. A schedule
     * change reaches all of those dates, so a payroll period posted ahead of
     * it refuses the change as a whole.
     */
    public function lockAndPaidThrough(string $employeeId, string $from, ?string $to = null): ?string;

    /**
     * Takes the lock and answers which dates from `$from` through `$to` a
     * posted payslip of this employee already covers, oldest first — empty
     * when none. A leave approved onto those dates changes the DTR but never
     * the payslip, so ReviewAbsence says so and tells payroll.
     *
     * @return list<string>
     */
    public function lockAndPaidDates(string $employeeId, string $from, string $to): array;
}
