<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LeaveTravelRequest;
use App\Models\Notification;

/**
 * The seven leave / travel requests from seed.ts buildRequests() — every
 * status the Leave Management screen can show (APPROVED paid, APPROVED
 * unpaid, PENDING whole-day, PENDING half-day, REJECTED, and an approved
 * BUSINESS_TRAVEL), plus the seeded LEAVE_REVIEWED bell entry (ntf-3).
 *
 * seed.ts's at() counts BACKWARDS, so a negative offset is a future date and
 * the larger magnitude is the later day — pending trips run from -4 to -6,
 * not -6 to -4, or the range comes out inverted.
 *
 * `reviewed_by` is an actor column: written through forceFill, never from a
 * request. `is_paid` on req-3 is true, as the reference seeds it (its mk()
 * default marks every APPROVED row paid, business travel included).
 */
final class LeaveSeeder extends PortSeeder
{
    public function run(): void
    {
        $employees = $this->employeeIdsByIndex();
        $adminId = $this->userId('admin@bestworkflows.local');

        // id, employee index, type, from (days ago), to, status, reason, is_paid, day_type
        $rows = [
            ['req-1', 6, 'SICK', 5, 5, 'APPROVED', 'Fever and medical check-up.', true, 'WHOLE_DAY'],
            ['req-2', 3, 'VACATION', -4, -6, 'PENDING', 'Family trip to Baguio.', null, 'WHOLE_DAY'],
            ['req-3', 2, 'BUSINESS_TRAVEL', 2, 2, 'APPROVED', 'Client site inspection in Cavite.', true, 'WHOLE_DAY'],
            ['req-6', 5, 'VACATION', 8, 8, 'APPROVED', 'Family matter — no credits left.', false, 'WHOLE_DAY'],
            ['req-4', 7, 'VACATION', -1, -2, 'PENDING', 'Personal errand.', null, 'WHOLE_DAY'],
            ['req-5', 4, 'SICK', 9, 9, 'REJECTED', 'No medical certificate attached.', null, 'WHOLE_DAY'],
            ['req-7', 1, 'SICK', -3, -3, 'PENDING', 'Dental appointment in the afternoon.', null, 'HALF_DAY'],
        ];

        foreach ($rows as [$id, $empIdx, $type, $from, $to, $status, $reason, $isPaid, $dayType]) {
            if (! isset($employees[$empIdx])) {
                continue;
            }

            $pending = $status === 'PENDING';

            $this->upsert(LeaveTravelRequest::class, [
                'code' => 'LR-'.str_pad(substr($id, 4), 3, '0', STR_PAD_LEFT),
            ], [
                'id' => $id,
                'employee_id' => $employees[$empIdx],
                'request_type' => $type,
                'day_type' => $dayType,
                'start_date' => $this->dateKey($from),
                'end_date' => $this->dateKey($to),
                'reason' => $reason,
                'status' => $status,
                'is_paid' => $isPaid,
                'reviewed_by' => $pending ? null : $adminId,
                'reviewed_at' => $pending ? null : $this->at(3, 10, 0),
                'created_at' => $this->at($from + 4, 9, 12),
            ]);
        }

        // ntf-3 — already read, so the bell shows a mixed state on first run.
        $employeeAccount = $this->userId('employee@bestworkflows.local');

        if ($employeeAccount !== null) {
            $this->upsert(Notification::class, ['id' => 'ntf-3'], [
                'user_id' => $employeeAccount,
                'kind' => 'LEAVE_REVIEWED',
                'title' => 'Your leave request was approved',
                'body' => 'Sick Leave — approved with pay.',
                'href' => '/portal',
                'dedupe_key' => null,
                'read_at' => $this->at(4, 10, 15),
                'created_at' => $this->at(5, 11, 2),
            ]);
        }
    }
}
