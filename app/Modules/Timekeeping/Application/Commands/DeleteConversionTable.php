<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\LeaveConversionTable;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Discards a conversion table DRAFT. An approved version is never deleted: a later version supersedes it. */
final class DeleteConversionTable
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
                throw new RuntimeException('An approved version is never deleted. Add a later version to replace it.');
            }
            $from = $row->effective_from->format('Y-m-d');
            $row->delete();
            DB::afterCommit(static fn () => AuditService::record($access, AuditEvent::LEAVE_CONVERSION_TABLE_DELETED, 'leave_conversion_tables', $id, "Conversion table draft from {$from} discarded"));

            return "Draft from {$from} discarded.";
        });
    }
}
