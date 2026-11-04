<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts;

use App\Domain\Attendance\AttendanceLog;
use App\Domain\Attendance\LeaveRequest;
use App\Domain\Attendance\Shift;

/**
 * Calculation facts only: no persistence methods or private absence reason.
 *
 * `overtimeApprovals` is the Daily Time Record's per-day overtime decision
 * as pay needs it: shift-START date => approved whole minutes, 0 for a
 * rejected day, and no key for a day nobody decided yet. No note, reviewer
 * or computed figure crosses the seam — only what changes pay. Defaulted,
 * so a construction that omits it means "no decisions".
 *
 * `overtimeCategories` is the category an approved day was put under when
 * it is not ordinary overtime (shift-start date => `SEAPORT`): payroll
 * prices such a day at its own rate and never caps it. A day with no entry
 * is ordinary overtime.
 */
final readonly class AttendanceInputs
{
    /**
     * @param  list<AttendanceLog>  $logs
     * @param  list<LeaveRequest>  $leaves
     * @param  array<string, int>  $overtimeApprovals  'Y-m-d' => approved minutes, ksorted
     * @param  array<string, string>  $overtimeCategories  'Y-m-d' => the category, ksorted; ordinary days absent
     */
    public function __construct(public array $logs = [], public ?Shift $shift = null, public array $leaves = [], public array $overtimeApprovals = [], public array $overtimeCategories = []) {}
}
