<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRecordChangeRequest;
use App\Platform\Notifications\NotificationRecipients;
use App\Platform\Notifications\Notifier;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Persists frozen proposals after the caller has authorized and locked the employee. */
final class RecordChangeSubmission
{
    /** @param array<string, mixed> $baseline
     * @param  array<string, mixed>  $proposed
     * @param  array<string, mixed>  $form
     * @param  list<array{field:string,label:string,before:?string,after:?string,before_known:bool,after_known:bool}>  $changes
     */
    public function store(Access $actor, EmployeeProfile $employee, string $kind, string $section, string $operation, ?string $rowId, array $baseline, array $proposed, array $form, array $changes, string $reason = ''): EmployeeRecordChangeRequest
    {
        abort_unless($actor->isSignedIn() && $actor->hasEmployeeLink() && $actor->ownEmployeeId() === $employee->id, 403);
        $reason = trim($reason);
        if (mb_strlen($reason) > 1000) {
            throw new RuntimeException('The reason must be at most 1,000 characters.');
        }
        if ($changes === []) {
            throw new RuntimeException('There are no changes to submit.');
        }
        if (EmployeeRecordChangeRequest::query()->where('pending_employee_id', $employee->id)->exists()) {
            throw new RuntimeException('This employee already has a pending record change request. Withdraw it or wait for its review.');
        }
        $request = new EmployeeRecordChangeRequest;
        $request->fill([
            'employee_id' => $employee->id, 'employee_name' => EmployeeName::full($employee),
            'kind' => $kind, 'section' => $section, 'operation' => $operation, 'row_id' => $rowId,
            'baseline' => $baseline, 'proposed' => $proposed, 'form' => $form,
            'changes' => $changes, 'reason' => $reason,
        ]);
        $request->pending_employee_id = $employee->id;
        $request->status = EmployeeRecordChangeRequest::PENDING;
        $request->requested_by = (int) $actor->userId;
        $request->requested_by_name = $actor->fullName;
        $request->save();
        AuditService::record($actor, AuditEvent::PROFILE_UPDATED, 'employee_record_change_requests', $request->id, 'Employee record change requested — '.$section);
        DB::afterCommit(static function () use ($actor, $request): void {
            try {
                $recipients = app(NotificationRecipients::class);
                $ownAccounts = $recipients->employees([$request->employee_id], []);
                foreach (array_diff($recipients->moduleHolders(ModuleCode::EMPLOYEES, $actor->userId), $ownAccounts) as $accountId) {
                    Notifier::account($accountId, 'RECORD_CHANGE_REQUESTED', 'Employee record change awaiting review', 'A linked employee submitted a record change request.', '/employees/change-requests?request='.$request->id);
                }
            } catch (Throwable $error) {
                Log::error('[record-changes] reviewer notification failed', ['error' => $error->getMessage()]);
            }
        });

        return $request;
    }
}
