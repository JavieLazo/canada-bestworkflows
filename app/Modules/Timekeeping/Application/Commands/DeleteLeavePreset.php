<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\LeavePolicyPreset;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Deletes a leave policy preset. Policies copied from it keep their own params, so nothing else changes. */
final class DeleteLeavePreset
{
    public function handle(Access $access, string $id): string
    {
        if (! LeaveSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            $row = LeavePolicyPreset::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That preset no longer exists.');
            }
            $name = $row->name;
            $row->delete();
            DB::afterCommit(static fn () => AuditService::record($access, AuditEvent::LEAVE_PRESET_SAVED, 'leave_policy_presets', $id, "Preset \"{$name}\" deleted"));

            return "Preset \"{$name}\" deleted.";
        });
    }
}
