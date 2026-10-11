<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
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
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Approves or rejects one employee's overtime on one day, on the Daily Time
 * Record. Payroll pays only the minutes approved here (PayslipCalculator
 * divergence 7) — the reference has no per-day decision, only a run-level
 * "acknowledge" switch, which ems does not have.
 *
 * Who: an all-data EDTR holder, including on their own day. What: the day
 * as the correction dialog computes it (DayCorrections::computedDay), so
 * the dialog and the save agree by construction; at least one whole minute
 * of overtime; an approval of 1..ceiling minutes, where the ceiling is the
 * Formulas OT maximum the engine will cap the day at anyway. Rejecting is
 * always possible.
 *
 * The write takes payroll's membership lock FIRST (PostedPayrollDates), so it
 * is serialised with every payroll save and finalize: a day already on a
 * posted payslip is refused, and a finalize that follows sees the decision.
 * An unchanged decision is a no-op with no audit row; every real decision is
 * one row under RequiredAudit, inside the same transaction, because it
 * authorises pay — a failed audit cancels it. The employee's accounts are told
 * after commit (never the actor), with a link to My Portal, since most
 * employees cannot open the DTR.
 */
final class DecideOvertime
{
    /**
     * The toast sentence. Every refusal throws OvertimeDecisionRefused
     * carrying the user-facing sentence, and writes nothing; a failed audit
     * or query throws its own exception and rolls the decision back.
     *
     * `$onlyIfPending` guards bulk and inline approvals: checked under the row
     * lock, it refuses a day someone decided after the bulk action looked,
     * rather than overwriting that decision.
     */
    public function handle(Access $actor, string $employeeId, string $date, string $decision, mixed $minutes = null, ?string $note = null, bool $notify = true, bool $onlyIfPending = false): string
    {
        if (! $actor->mayReadAllData(ModuleCode::EDTR)) {
            throw new OvertimeDecisionRefused('You are not allowed to decide overtime.');
        }
        if (! PayrollPeriod::isPayrollDate($date) || $date > ManilaClock::todayKey()) {
            throw new OvertimeDecisionRefused('That day has not happened yet.');
        }

        $employee = app(EmployeeDirectory::class)->forTimekeeping($actor, $employeeId);
        if ($employee === null) {
            throw new OvertimeDecisionRefused('That employee no longer exists.');
        }

        $day = app(DayCorrections::class)->computedDay($actor, $employeeId, $date);
        $computedMinutes = $day->overtimeMinutes ?? 0.0;
        $computed = OvertimeApprovalPolicy::wholeMinutes($computedMinutes);
        if ($computed < 1) {
            throw new OvertimeDecisionRefused('This day has no overtime to decide.');
        }
        // ota_computed_chk's bound, refused in words rather than by the database.
        if ($computed > 1440) {
            throw new OvertimeDecisionRefused('This day computed more overtime than a day holds; correct its punches first.');
        }

        $ceiling = OvertimeApprovalPolicy::ceiling($computedMinutes, app(OpenPunchLimit::class)->hours());
        $error = OvertimeApprovalPolicy::error($decision, $minutes, $ceiling);
        if ($error !== null) {
            throw new OvertimeDecisionRefused($error);
        }

        $approved = $decision === OvertimeApproval::APPROVED ? (int) OvertimeApprovalPolicy::minutesOf($minutes) : 0;
        $note = trim((string) $note);
        if (mb_strlen($note) > 500) {
            throw new OvertimeDecisionRefused('Keep the note to 500 characters.');
        }
        $note = $note === '' ? null : $note;

        $name = EmployeeName::display($employee);
        $when = (DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: new DateTimeImmutable('@0'))->format('M d, Y');
        $what = $decision === OvertimeApproval::APPROVED
            ? 'approved '.OvertimeApprovalPolicy::duration($approved).' of '.OvertimeApprovalPolicy::duration($computed).' computed'
            : 'rejected '.OvertimeApprovalPolicy::duration($computed).' computed';

        $changed = DB::transaction(static function () use ($actor, $employeeId, $date, $decision, $computed, $approved, $note, $name, $when, $what, $onlyIfPending): bool {
            // FIRST: payroll's membership lock, then whether the day is paid.
            if (app(PostedPayrollDates::class)->lockAndCovers($employeeId, $date)) {
                throw new OvertimeDecisionRefused('That day is already on a posted payslip, so its overtime can no longer change.');
            }

            $row = OvertimeApproval::query()
                ->where('employee_id', $employeeId)
                ->whereDate('date', $date)
                ->lockForUpdate()
                ->first();

            if ($onlyIfPending && $row !== null) {
                throw new OvertimeDecisionRefused('That day was decided meanwhile.');
            }

            if ($row !== null
                && $row->decision === $decision
                && $row->approved_minutes === $approved
                && $row->computed_minutes === $computed
                && $row->note === $note) {
                return false;
            }

            $row ??= new OvertimeApproval;
            $row->fill([
                'employee_id' => $employeeId,
                'date' => $date,
                'decision' => $decision,
                'computed_minutes' => $computed,
                'approved_minutes' => $approved,
                'note' => $note,
            ]);
            // Actor column: from the session-derived Access, never the request.
            $row->reviewed_by = (int) $actor->userId;
            $row->save();

            RequiredAudit::record($actor, AuditEvent::dtrOvertime($decision), 'overtime_approvals', $employeeId,
                "{$name} · {$when} · {$what}".($note === null ? '' : " · {$note}"));

            return true;
        });

        if (! $changed) {
            return 'Nothing changed.';
        }

        if ($notify) {
            Notifier::employees(
                [$employeeId],
                [$actor->userId],
                $decision === OvertimeApproval::APPROVED ? 'OVERTIME_APPROVED' : 'OVERTIME_REJECTED',
                $decision === OvertimeApproval::APPROVED ? "Overtime approved for {$when}" : "Overtime not approved for {$when}",
                ucfirst($what).'.',
                '/portal',
            );
        }

        return "Overtime {$what} for {$name} on {$when}.";
    }
}
