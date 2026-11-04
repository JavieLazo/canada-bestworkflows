<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Payroll\PayrollValidation;
use App\Models\PayrollRun;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Support\Access;
use App\Support\EmployeeName;
use RuntimeException;

/** Membership validation shared by saving and finalization; callers hold the payroll lock. */
final class EnsurePayrollMembership
{
    /**
     * DELIBERATE DIVERGENCE: the reference refuses an employee only on
     * another run with the SAME start and end (requireUniquePayrollMembership).
     * A period that merely overlaps — 1–15 beside 1–31, or 10–25 — would pay
     * the shared days twice, so any run of the same kind whose dates overlap
     * these is a conflict. Kinds are compared apart: the 13th month run spans
     * the whole year and pays no attendance, so it never collides with a
     * cut-off.
     *
     * @param  list<string>  $memberIds
     */
    public static function check(Access $actor, array $memberIds, string $periodStart, string $periodEnd, ?string $excludeRunId, string $kind): void
    {
        $employees = collect(app(EmployeePayContexts::class)->get($actor, $memberIds));

        $error = PayrollValidation::employeesError($memberIds, $employees, $periodStart, $periodEnd);

        if ($error !== null) {
            throw new RuntimeException($error);
        }

        if ($memberIds === []) {
            return;
        }

        // One employee, one payroll per day. Candidates carry their member
        // ids in position order, shaped like the reference's employee_ids.
        $candidates = PayrollRun::query()
            ->with('members')
            ->where('kind', $kind)
            ->whereDate('period_start', '<=', $periodEnd)
            ->whereDate('period_end', '>=', $periodStart)
            ->when($excludeRunId !== null, static fn ($q) => $q->whereKeyNot($excludeRunId))
            ->whereHas('members', static fn ($q) => $q->whereIn('employee_id', $memberIds))
            ->orderBy('period_start')
            ->orderBy('created_at')
            ->get();

        $conflict = PayrollValidation::findMembershipConflict($memberIds, $candidates->map(static fn (PayrollRun $r): array => [
            'code' => $r->code,
            'employee_ids' => PayrollData::memberIds($r->members),
        ]));

        if ($conflict === null) {
            return;
        }

        $employee = $employees->firstWhere('id', $conflict['employeeId']);
        $name = $employee !== null ? EmployeeName::display($employee) : null;
        $run = $candidates->firstWhere('code', $conflict['runCode']);
        $from = $run?->period_start->format('Y-m-d');
        $to = $run?->period_end->format('Y-m-d');

        // The same dates keep the reference's sentence; overlapping ones say which.
        if ($run === null || ($from === $periodStart && $to === $periodEnd)) {
            throw new RuntimeException(PayrollValidation::membershipConflictMessage($name, $conflict['runCode']));
        }

        throw new RuntimeException(($name ?? 'That employee')." is already on {$conflict['runCode']} (".PayrollPeriodText::day((string) $from).' to '.PayrollPeriodText::day((string) $to).'), which overlaps these dates.');
    }
}
