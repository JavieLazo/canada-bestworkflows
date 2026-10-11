<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\AttendanceRemark;
use App\Support\Access;
use App\Support\AuditService;

/** Writes HR remarks within the Timekeeping boundary. */
final class SaveRemark
{
    public function handle(Access $actor, string $targetId, string $date, mixed $value): ?string
    {
        if (! $actor->mayReadAllData(ModuleCode::EDTR) || $targetId === '' || ! PayrollPeriod::isPayrollDate($date)) {
            return null;
        }
        $note = trim(is_string($value) ? $value : '');
        $stored = AttendanceRemark::query()
            ->where('employee_id', $targetId)
            ->whereDate('date', $date)
            ->first();

        if ($note === trim($stored->note ?? '')) {
            return $note;
        }

        if ($note === '') {
            $stored?->delete();
        } else {
            $remark = $stored ?? new AttendanceRemark;
            $remark->fill(['employee_id' => $targetId, 'date' => $date, 'note' => $note]);
            // Actor column from the session, never the request.
            $remark->author_id = $actor->userId === null ? null : (int) $actor->userId;
            $remark->save();
        }

        AuditService::record(
            $actor,
            AuditEvent::dtrRemark($note !== ''),
            'attendance_remarks',
            $targetId,
            $date.($note !== '' ? ' — '.$note : ''),
        );

        return $note;
    }
}
