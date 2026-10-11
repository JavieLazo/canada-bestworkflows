<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\LeaveTravelRequest;
use App\Modules\Timekeeping\Domain\Absence\AbsencePolicy;
use App\Support\Access;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use RuntimeException;

/** The absence review boundary, including self-review and stale-decision protection. */
final class ReviewAbsence
{
    public function handle(Access $actor, string $id, string $status, ?bool $isPaid = null): string
    {
        if (! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! in_array($status, ['APPROVED', 'REJECTED'], true)) {
            throw new RuntimeException('Choose a valid review decision.');
        }
        $existing = LeaveTravelRequest::query()->whereKey($id)->first();
        if ($existing === null) {
            throw new RuntimeException('That request no longer exists.');
        }
        if ($actor->employeeId !== null && $existing->employee_id === $actor->employeeId) {
            throw new RuntimeException('You cannot review your own request.');
        }
        if ($existing->status !== 'PENDING') {
            throw new RuntimeException('That request has already been reviewed.');
        }
        $isOb = $existing->request_type === AbsencePolicy::OFFICIAL_BUSINESS;
        if ($status === 'APPROVED' && ! $isOb && $isPaid === null) {
            throw new RuntimeException('Choose whether approved leave is with or without pay.');
        }
        $normalizedPaid = ($status === 'REJECTED' || $isOb) ? null : $isPaid;
        $won = LeaveTravelRequest::query()->whereKey($id)->where('status', 'PENDING')->update([
            'status' => $status,
            'is_paid' => $normalizedPaid,
            'reviewed_by' => (int) $actor->userId,
            'reviewed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'),
        ]);
        if ($won === 0) {
            throw new RuntimeException('That request has already been reviewed.');
        }
        AuditService::record($actor, $status === 'APPROVED' ? AuditEvent::REQUEST_APPROVED : AuditEvent::REQUEST_REJECTED,
            'leave_travel_requests', $id, 'Request '.$existing->code.' '.strtolower($status));
        Notifier::employee($existing->employee_id, 'LEAVE_REVIEWED', 'Request '.$existing->code.' '.strtolower($status),
            $status !== 'APPROVED' || $isOb ? null : ($normalizedPaid ? 'Approved with pay' : 'Approved without pay'), '/portal');

        return $status === 'REJECTED' ? 'rejected'
            : ($isOb ? 'approved as official business' : ($normalizedPaid ? 'approved with pay' : 'approved without pay'));
    }
}
