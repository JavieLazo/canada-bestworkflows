<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\EmployeeLeavePolicy;
use App\Modules\Timekeeping\Application\LeavePolicyInput;
use App\Modules\Timekeeping\Application\LeavePolicyStore;
use App\Modules\Timekeeping\Application\Queries\LeavePresetRecords;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Saves one employee's leave policy version as a DRAFT, from a date: a new
 * one, or the employee's existing draft for that date edited in place. All-
 * data LEAVES, for any employee including the preparer's own. The params are
 * validated against the leave types' credit rules, exactly as a preset is
 * (LeavePolicyInput). An approved version never changes; a draft
 * on an approved version's date is refused — prepare a later date.
 */
final class SaveLeavePolicy
{
    public const int MAX_REASON = 500;

    /**
     * @param  array<string, mixed>  $params
     * @return string the version's id
     */
    public function handle(Access $access, string $employeeId, string $effectiveFrom, array $params, string $reason): string
    {
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! LeavePolicyStore::ready()) {
            throw new RuntimeException('Leave policies can be prepared once this installation\'s upgrade finishes.');
        }
        $employee = app(OperationalDirectory::class)->find($access, $employeeId);
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        $effectiveFrom = trim($effectiveFrom);
        if (! PayrollPeriod::isPayrollDate($effectiveFrom)) {
            throw new RuntimeException('Choose the date this policy takes effect.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::MAX_REASON) {
            throw new RuntimeException('Say why this version is made, in 1 to '.self::MAX_REASON.' characters.');
        }
        $policy = LeavePolicyInput::validated($params);
        $name = EmployeeName::display($employee);

        return DB::transaction(static function () use ($access, $employeeId, $effectiveFrom, $policy, $reason, $name): string {
            $row = EmployeeLeavePolicy::query()->where('employee_id', $employeeId)->where('effective_from', $effectiveFrom)->lockForUpdate()->first();
            if ($row !== null && $row->status !== EmployeeLeavePolicy::DRAFT) {
                throw new RuntimeException('An approved version already takes effect on that date and never changes. Prepare one from a later date.');
            }
            $row ??= new EmployeeLeavePolicy(['employee_id' => $employeeId, 'effective_from' => $effectiveFrom]);
            $row->fill(['params' => $policy->toArray(), 'reason' => $reason, 'status' => EmployeeLeavePolicy::DRAFT]);
            $row->prepared_by = $access->userId === null ? null : (int) $access->userId;
            $row->prepared_by_name = $access->fullName;
            if (! $row->save()) {
                throw new RuntimeException('The policy could not be saved.');
            }
            $summary = $policy->describe(LeavePresetRecords::typeNames());
            DB::afterCommit(static fn () => AuditService::record($access, AuditEvent::LEAVE_POLICY_SAVED, 'employee_leave_policies', $employeeId,
                "{$name} · leave policy draft from {$effectiveFrom}: {$summary} ({$reason})"));

            return $row->id;
        });
    }
}
