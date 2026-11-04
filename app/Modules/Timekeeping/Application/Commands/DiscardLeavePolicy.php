<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeLeavePolicy;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Discards an employee's leave policy DRAFT. An approved version is never deleted: a later version supersedes it. */
final class DiscardLeavePolicy
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            $row = EmployeeLeavePolicy::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That version no longer exists.');
            }
            if ($row->status !== EmployeeLeavePolicy::DRAFT) {
                throw new RuntimeException('An approved version is never deleted. Prepare a later version to replace it.');
            }
            $from = $row->effective_from->format('Y-m-d');
            $employeeId = $row->employee_id;
            $row->delete();
            DB::afterCommit(static fn () => AuditService::record($access, AuditEvent::LEAVE_POLICY_SAVED, 'employee_leave_policies', $employeeId, "Leave policy draft from {$from} discarded"));

            return "Draft from {$from} discarded.";
        });
    }
}
