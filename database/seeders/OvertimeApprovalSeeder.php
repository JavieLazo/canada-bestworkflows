<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Enums\ModuleCode;
use App\Models\OvertimeApproval;
use App\Models\User;
use App\Modules\Timekeeping\Application\Queries\DayCorrections;
use App\Modules\Timekeeping\Contracts\OpenPunchLimit;
use App\Modules\Timekeeping\Domain\Attendance\OvertimeApprovalPolicy;
use App\Support\Access;

/**
 * Approves the demo punches' overtime in full — DEMO ONLY, never reached from
 * ProductionSeeder: on a real installation overtime is excluded unless a
 * person approves it on the Daily Time Record before payroll is posted.
 *
 * AttendanceSeeder punches every demo employee out 5 to 29 minutes after
 * their shift, so a seeded fixed-shift weekday carries a little overtime.
 * These approvals give the demo payroll examples of paid overtime.
 * Undecided overtime pays nothing without blocking finalization, so the demo
 * approvals are examples, not a gate.
 *
 * Each day is computed exactly as the DTR computes it
 * (DayCorrections::computedDay) and approved at its full approvable figure —
 * OvertimeApprovalPolicy::ceiling, the same figure "Approve all pending"
 * uses. The approver is the first demo account (admin, then supervisor) that
 * may decide EDTR overtime and is not the employee, exactly the rule
 * DecideOvertime applies; no such account, no decision.
 *
 * Rows are written through upsert() keyed on a stable seed key per employee and
 * day, and a day that already has a decision — seeded, or made during a
 * walkthrough under a minted id — is left alone, so a re-run neither
 * duplicates nor overrides anything. No audit row and no notification: the
 * demo audit trail is AuditLogSeeder's.
 */
final class OvertimeApprovalSeeder extends PortSeeder
{
    /** The demo accounts that may approve, in order of preference. */
    private const APPROVERS = ['admin@bestworkflows.local', 'supervisor@bestworkflows.local'];

    public function run(): void
    {
        $employees = $this->employeeIdsByIndex();

        /** @var list<User> $approvers */
        $approvers = array_values(array_filter(array_map(
            static fn (string $email): ?User => User::query()->where('email', $email)->where('is_active', true)->first(),
            self::APPROVERS,
        )));

        if ($employees === [] || $approvers === []) {
            return;
        }

        // AttendanceSeeder's window: today and the thirteen days before it.
        $from = $this->dateKey(13);
        $to = $this->dateKey(0);
        $cap = app(OpenPunchLimit::class)->hours();

        foreach ($employees as $idx => $employeeId) {
            $approver = $this->approverFor($approvers, $employeeId);
            if ($approver === null) {
                continue;
            }
            $access = Access::forActor($approver);

            $decided = OvertimeApproval::query()
                ->where('employee_id', $employeeId)
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->get(['date'])
                ->map(static fn (OvertimeApproval $row): string => $row->date->format('Y-m-d'))
                ->all();

            foreach (CalendarDays::each($from, $to) as $date) {
                if (in_array($date, $decided, true)) {
                    continue;
                }

                $minutes = app(DayCorrections::class)->computedDay($access, $employeeId, $date)->overtimeMinutes ?? 0.0;
                $ceiling = OvertimeApprovalPolicy::ceiling($minutes, $cap);
                if (! OvertimeApprovalPolicy::decidable($minutes) || $ceiling < 1) {
                    continue;
                }

                $this->upsert(OvertimeApproval::class, ['id' => 'ota-'.($idx + 1).'-'.$date], [
                    'employee_id' => $employeeId,
                    'date' => $date,
                    'decision' => OvertimeApproval::APPROVED,
                    'computed_minutes' => OvertimeApprovalPolicy::wholeMinutes($minutes),
                    'approved_minutes' => $ceiling,
                    'note' => null,
                    'reviewed_by' => (int) $approver->getKey(),
                ]);
            }
        }
    }

    /** @param list<User> $approvers */
    private function approverFor(array $approvers, string $employeeId): ?User
    {
        foreach ($approvers as $user) {
            if ((string) $user->employee_id !== $employeeId && Access::forActor($user)->mayReadAllData(ModuleCode::EDTR)) {
                return $user;
            }
        }

        return null;
    }
}
