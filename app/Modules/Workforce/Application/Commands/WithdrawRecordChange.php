<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRecordChangeRequest;
use App\Platform\Records\AccountEmployeeLinks;
use App\Support\Access;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** A linked employee may withdraw a pending proposal without deleting its evidence. */
final class WithdrawRecordChange
{
    public function handle(Access $access, string $id): void
    {
        abort_unless(($access->hasModule(ModuleCode::PROFILE) || $access->hasModule(ModuleCode::EMPLOYEES)) && $access->hasEmployeeLink(), 403);
        DB::transaction(static function () use ($access, $id): void {
            abort_unless(app(AccountEmployeeLinks::class)->isActiveLinked((int) $access->userId, $access->ownEmployeeId()), 403);
            EmployeeProfile::query()->whereKey($access->ownEmployeeId())->lockForUpdate()->firstOrFail();
            $request = EmployeeRecordChangeRequest::query()->where('employee_id', $access->ownEmployeeId())->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($request->status !== EmployeeRecordChangeRequest::PENDING) {
                throw new RuntimeException('This request has already been resolved.');
            }
            $request->status = EmployeeRecordChangeRequest::WITHDRAWN;
            $request->pending_employee_id = null;
            $request->withdrawn_at = CarbonImmutable::now()->utc();
            $request->withdrawn_by = (int) $access->userId;
            $request->withdrawn_by_name = $access->fullName;
            $request->save();
            AuditService::record($access, AuditEvent::PROFILE_UPDATED, 'employee_record_change_requests', $request->id, 'Employee record change withdrawn');
        });
    }
}
