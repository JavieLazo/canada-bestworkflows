<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\LeaveConversionTable;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Approves a conversion table draft: from then on it converts every
 * attendance charge dated on or after its effective date, and it never
 * changes. Any all-data LEAVES holder approves, the preparer included —
 * one administrator must be able to run the whole flow alone — and the
 * approval is a RequiredAudit row on the same transaction, so a version
 * that cannot be recorded is not approved.
 */
final class ApproveConversionTable
{
    public function handle(Access $access, string $id): string
    {
        if (! LeaveSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            $row = LeaveConversionTable::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That version no longer exists.');
            }
            if ($row->status !== LeaveConversionTable::DRAFT) {
                throw new RuntimeException('That version is already approved.');
            }
            $row->status = LeaveConversionTable::APPROVED;
            $row->approved_by = $access->userId === null ? null : (int) $access->userId;
            $row->approved_by_name = $access->fullName;
            $row->approved_at = CarbonImmutable::now('UTC');
            if (! $row->save()) {
                throw new RuntimeException('The version could not be approved.');
            }
            $from = $row->effective_from->format('Y-m-d');
            RequiredAudit::record($access, AuditEvent::LEAVE_CONVERSION_TABLE_APPROVED, 'leave_conversion_tables', $row->id,
                "Conversion table from {$from} approved (prepared by ".($row->created_by_name ?? 'an unnamed account').')');

            return "Conversion table from {$from} approved.";
        });
    }
}
