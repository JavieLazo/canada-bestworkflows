<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\LeaveTravelRequest;
use App\Support\Access;
use App\Support\AuditService;
use RuntimeException;

/** Preserves self-only withdrawal and the conditional pending-state update. */
final class WithdrawAbsence
{
    public function handle(Access $actor, string $id): void
    {
        if (! $actor->hasModule(ModuleCode::PORTAL)) {
            throw new RuntimeException('You do not have access to that.');
        }
        $request = LeaveTravelRequest::query()->whereKey($id)->first();
        if ($request === null) {
            throw new RuntimeException('That request no longer exists.');
        }
        if (! $actor->hasEmployeeLink() || $request->employee_id !== $actor->ownEmployeeId()) {
            throw new RuntimeException('You do not have access to that.');
        }
        if ($request->status !== 'PENDING') {
            throw new RuntimeException('Only a pending request can be withdrawn.');
        }
        $won = LeaveTravelRequest::query()->whereKey($id)
            ->where('employee_id', $actor->ownEmployeeId())->where('status', 'PENDING')
            ->update(['status' => 'WITHDRAWN', 'is_paid' => null, 'reviewed_at' => null, 'reviewed_by' => null]);
        if ($won === 0) {
            throw new RuntimeException('That request changed before it was withdrawn.');
        }
        AuditService::record($actor, AuditEvent::REQUEST_WITHDRAWN, 'leave_travel_requests', $id,
            "Request {$request->code} withdrawn by {$actor->fullName}");
        Notifier::moduleHolders(ModuleCode::LEAVES, $actor->userId, 'LEAVE_WITHDRAWN',
            "Request {$request->code} withdrawn", "{$actor->fullName} withdrew the pending request.", '/leaves');
    }
}
