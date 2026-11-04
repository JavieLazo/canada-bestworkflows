<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeLeavePolicy;
use App\Modules\Timekeeping\Application\LeavePolicyStore;
use App\Modules\Timekeeping\Application\Queries\LeavePresetRecords;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Approves an employee's leave policy draft: from its effective date the
 * earning job earns by it and payroll charges by it, and it never changes.
 * Any all-data LEAVES holder, the preparer and the employee themselves
 * included — one administrator must be able to run the whole flow alone;
 * a RequiredAudit row on the same transaction, so a version that cannot
 * be recorded is not approved.
 */
final class ApproveLeavePolicy
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! LeavePolicyStore::ready()) {
            throw new RuntimeException('Leave policies can be approved once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            $row = EmployeeLeavePolicy::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That version no longer exists.');
            }
            if ($row->status !== EmployeeLeavePolicy::DRAFT) {
                throw new RuntimeException('That version is already approved.');
            }
            $employee = app(OperationalDirectory::class)->find($access, $row->employee_id);
            if ($employee === null) {
                throw new RuntimeException('That employee no longer exists.');
            }
            $row->status = EmployeeLeavePolicy::APPROVED;
            $row->approved_by = $access->userId === null ? null : (int) $access->userId;
            $row->approved_by_name = $access->fullName;
            $row->approved_at = CarbonImmutable::now('UTC');
            if (! $row->save()) {
                throw new RuntimeException('The policy could not be approved.');
            }
            $name = EmployeeName::display($employee);
            $from = $row->effective_from->format('Y-m-d');
            $summary = LeavePolicyStore::policy($row)->describe(LeavePresetRecords::typeNames());
            RequiredAudit::record($access, AuditEvent::LEAVE_POLICY_APPROVED, 'employee_leave_policies', $row->employee_id,
                "{$name} · leave policy from {$from} approved (prepared by ".($row->prepared_by_name ?? 'an unnamed account')."): {$summary}");

            return "Leave policy from {$from} approved for {$name}.";
        });
    }
}
