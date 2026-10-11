<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\LeaveTravelRequest;
use App\Models\Shift;
use App\Models\User;

/**
 * Starter audit trail entries from seed.ts audit_logs (1456-1497), so the
 * Audit Trail screen is not empty on first run.
 *
 * The reference's aud-2 ("5 payslips posted for the previous cut-off") is NOT
 * ported: it narrates the finalized run this seed deliberately does not
 * create, and the trail must not attest to a posting that never happened.
 *
 * actor_id / actor_name are set directly — AuditService is for live
 * mutations, where the actor comes from the session.
 */
final class AuditLogSeeder extends PortSeeder
{
    public function run(): void
    {
        $employees = $this->employeeIdsByIndex();
        $admin = User::query()->where('email', 'admin@bestworkflows.local')->first();
        $supervisor = User::query()->where('email', 'supervisor@bestworkflows.local')->first();

        if ($admin !== null) {
            $this->upsert(AuditLog::class, ['id' => 'aud-1'], [
                'actor_id' => $admin->id,
                'actor_name' => (string) $admin->email,
                'action' => 'LOGIN_SUCCESS',
                'entity' => 'user_accounts',
                'entity_id' => (string) $admin->id,
                'details' => 'Maria Santos signed in as System Admin',
                'created_at' => $this->at(0, 8, 5),
            ]);
        }

        if ($supervisor !== null) {
            $requestId = $this->seedId(LeaveTravelRequest::class, 'req-1');

            $this->upsert(AuditLog::class, ['id' => 'aud-3'], [
                'actor_id' => $supervisor->id,
                'actor_name' => (string) $supervisor->email,
                'action' => 'REQUEST_APPROVED',
                'entity' => 'leave_travel_requests',
                'entity_id' => $requestId === null ? null : (string) $requestId,
                'details' => 'Sick Leave approved for Liza Domingo',
                'created_at' => $this->at(5, 11, 2),
            ]);
        }

        if ($admin !== null && isset($employees[3])) {
            $this->upsert(AuditLog::class, ['id' => 'aud-4'], [
                'actor_id' => $admin->id,
                'actor_name' => (string) $admin->email,
                'action' => 'SHIFT_UPDATED',
                'entity' => 'shifts',
                'entity_id' => $this->seedId(Shift::class, 'shift-4'),
                'details' => 'Paolo Bautista shift set to 07:00–16:00 (grace 10m)',
                'created_at' => $this->at(8, 14, 20),
            ]);
        }
    }
}
