<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\LeaveRequest;
use App\Domain\Attendance\Shift;
use App\Modules\Timekeeping\Contracts\AttendanceInputs;
use App\Modules\Workforce\Contracts\EmployeePayData;

/**
 * One settlement a payroll makes: the presumed days of an earlier posted
 * payslip (an advance payroll's, PayrollAdvance), priced again from their
 * finished attendance and compared with what was presumed. Everything the
 * pricing needs is captured here, in the payroll's own evidence
 * (PayrollInputs `settlements`): the employee, Formulas, holidays and Pay
 * Basis & Rates AS THE ADVANCE PAYROLL'S EVIDENCE HELD THEM — the rates in
 * force on the days worked — the schedule and leaves it presumed from, and
 * the days' attendance as it stands now. A replay reads none of it from the
 * live tables.
 *
 * The two sides are priced from different facts on purpose:
 *  - `presumedLines` is what the advance payroll itself wrote into its
 *    evidence when it posted — the presumed days priced by the engine that
 *    paid them, so a later engine change never moves what is settled. It is
 *    null only for a payslip posted before that figure was recorded, which
 *    is then priced again from `employee`, the schedule and the leaves.
 *  - the actual side takes `employee` with `resigned`, today's leaving date,
 *    in place of the captured one: someone who left during the presumed days
 *    is paid for the days before leaving and charged the rest back. The
 *    presumed side keeps the employee as captured, because that is who the
 *    advance payroll paid.
 */
final readonly class SettlementInputs
{
    /**
     * @param  list<array<string, mixed>>  $formulas
     * @param  list<HolidaySetting>  $holidays
     * @param  array{scope: string, version_id: ?string, effective_from: ?string, figures: array<string, mixed>}|null  $payRules
     * @param  list<LeaveRequest>  $presumedLeaves
     * @param  string|null  $resigned  the employee's leaving date as it stands today (`Y-m-d`), applied to the actual side only
     * @param  array<string, float>|null  $presumedLines  the presumed days' lines as the advance payroll's evidence froze them; null when it recorded none
     */
    public function __construct(
        public string $payslipId,
        public string $runCode,
        public string $from,
        public string $to,
        public EmployeePayData $employee,
        public array $formulas,
        public array $holidays,
        public ?array $payRules,
        public ?Shift $presumedShift,
        public array $presumedLeaves,
        public AttendanceInputs $actual,
        public ?string $resigned = null,
        public ?array $presumedLines = null,
    ) {}
}
