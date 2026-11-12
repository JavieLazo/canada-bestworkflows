<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\OvertimeApproval;
use App\Modules\Timekeeping\Application\OvertimeDecisionRefused;
use App\Modules\Timekeeping\Application\Queries\DayCorrections;
use App\Modules\Timekeeping\Contracts\OpenPunchLimit;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Modules\Timekeeping\Domain\Attendance\OvertimeApprovalPolicy;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\DB;

/**
 * "Approve all unreviewed" / "Reject all unreviewed" for one employee over a
 * period of the Daily Time Record.
 *
 * Undecided overtime is excluded from payroll, and once a payslip covers
 * the day it can no longer be decided. This optionally decides every
 * PENDING day (at least one whole minute of overtime and no decision yet)
 * in one go: an approval at the day's full
 * approvable figure (the ceiling: the computed minutes, capped by the
 * Formulas OT maximum), or an explicit rejection. Approve before posting to
 * include the overtime; days already decided keep their decision.
 *
 * Every day goes through DecideOvertime itself, so the rules, the posted-day
 * refusal, the lock order and the one-RequiredAudit-row-per-decision are the
 * same as a single decision; a day that command refuses (already on a posted
 * payslip, for one) is counted as skipped. All the days share ONE
 * transaction, so a failed audit or query decides none of them. The employee
 * is told ONCE, after the commit, rather than once per day.
 */
final class DecidePendingOvertime
{
    /** @return array{decided: int, skipped: int} */
    public function handle(Access $actor, string $employeeId, string $start, string $end, string $decision): array
    {
        if (! $actor->mayReadAllData(ModuleCode::EDTR)) {
            throw new OvertimeDecisionRefused('You are not allowed to decide overtime.');
        }
        if (! in_array($decision, OvertimeApprovalPolicy::DECISIONS, true)) {
            throw new OvertimeDecisionRefused('Choose whether to approve or reject the overtime.');
        }
        if (! PayrollPeriod::isPayrollDate($start) || ! PayrollPeriod::isPayrollDate($end) || $start > $end) {
            throw new OvertimeDecisionRefused('Choose a valid period.');
        }
        if (count(CalendarDays::each($start, $end)) > 62) {
            throw new OvertimeDecisionRefused('Decide at most two months at a time.');
        }
        if (app(EmployeeDirectory::class)->forTimekeeping($actor, $employeeId) === null) {
            throw new OvertimeDecisionRefused('That employee no longer exists.');
        }

        // Nothing after today has overtime to decide.
        $today = ManilaClock::todayKey();
        $end = $end > $today ? $today : $end;
        if ($start > $end) {
            return ['decided' => 0, 'skipped' => 0];
        }

        $decided = OvertimeApproval::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::EDTR)
            ->where('employee_id', $employeeId)
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->get(['date'])
            ->map(static fn (OvertimeApproval $row): string => $row->date->format('Y-m-d'))
            ->all();

        $cap = app(OpenPunchLimit::class)->hours();

        // ONE transaction for the whole period: every day's decision is a
        // savepoint inside it, so a refused day (OvertimeDecisionRefused) is
        // skipped while a failed audit or query rolls EVERY day back rather
        // than leaving the period half-decided.
        [$done, $skipped] = DB::transaction(static function () use ($actor, $employeeId, $start, $end, $decision, $decided, $cap): array {
            // Payroll's membership lock is the FIRST statement, before the
            // first computedDay() read: on MariaDB that read fixes the
            // snapshot, and one fixed before a running finalize committed
            // would not show the payslip it posted.
            app(PostedPayrollDates::class)->lock();

            $done = 0;
            $skipped = 0;

            foreach (CalendarDays::each($start, $end) as $date) {
                if (in_array($date, $decided, true)) {
                    continue;
                }

                $minutes = app(DayCorrections::class)->computedDay($actor, $employeeId, $date)->overtimeMinutes ?? 0.0;
                if (! OvertimeApprovalPolicy::decidable($minutes)) {
                    continue;
                }

                $ceiling = OvertimeApprovalPolicy::ceiling($minutes, $cap);
                if ($decision === OvertimeApproval::APPROVED && $ceiling < 1) {
                    $skipped++;

                    continue;
                }

                try {
                    app(DecideOvertime::class)->handle(
                        $actor, $employeeId, $date, $decision,
                        $decision === OvertimeApproval::APPROVED ? $ceiling : null,
                        null,
                        notify: false,
                        onlyIfPending: true,
                    );
                    $done++;
                } catch (OvertimeDecisionRefused) {
                    // Already on a posted payslip, decided by someone else
                    // since the read above, or otherwise refused: nothing
                    // was written for this day. Anything else propagates and
                    // undoes the whole period.
                    $skipped++;
                }
            }

            return [$done, $skipped];
        });

        if ($done > 0) {
            $days = $done === 1 ? '1 day' : "{$done} days";
            Notifier::employees(
                [$employeeId],
                [$actor->userId],
                $decision === OvertimeApproval::APPROVED ? 'OVERTIME_APPROVED' : 'OVERTIME_REJECTED',
                $decision === OvertimeApproval::APPROVED ? "Overtime approved for {$days}" : "Overtime not approved for {$days}",
                null,
                '/portal',
            );
        }

        return ['decided' => $done, 'skipped' => $skipped];
    }
}
