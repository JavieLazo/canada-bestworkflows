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
    /** @param list<string> $memberIds */
    public static function check(Access $actor, array $memberIds, string $periodStart, string $periodEnd, ?string $excludeRunId): void
    {
        $employees = collect(app(EmployeePayContexts::class)->get($actor, $memberIds));

        $error = PayrollValidation::employeesError($memberIds, $employees, $periodStart, $periodEnd);

        if ($error !== null) {
            throw new RuntimeException($error);
        }

        if ($memberIds === []) {
            return;
        }

        // One employee, one payroll per cut-off. Candidates carry their member
        // ids in position order, shaped like the reference's employee_ids.
        $candidates = PayrollRun::query()
            ->with('members')
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->when($excludeRunId !== null, static fn ($q) => $q->whereKeyNot($excludeRunId))
            ->whereHas('members', static fn ($q) => $q->whereIn('employee_id', $memberIds))
            ->orderBy('created_at')
            ->get()
            ->map(static fn (PayrollRun $r): array => [
                'code' => $r->code,
                'employee_ids' => PayrollData::memberIds($r->members),
            ]);

        $conflict = PayrollValidation::findMembershipConflict($memberIds, $candidates);

        if ($conflict !== null) {
            $employee = $employees->firstWhere('id', $conflict['employeeId']);

            throw new RuntimeException(PayrollValidation::membershipConflictMessage(
                $employee !== null ? EmployeeName::display($employee) : null,
                $conflict['runCode'],
            ));
        }
    }
}
