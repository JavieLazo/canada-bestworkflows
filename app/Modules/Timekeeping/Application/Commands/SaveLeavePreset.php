<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\LeavePolicyPreset;
use App\Modules\Timekeeping\Application\LeavePolicyInput;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Modules\Timekeeping\Application\Queries\LeavePresetRecords;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Saves a leave policy preset: a named set of rules HR copies onto an
 * employee's policy. Validated against the leave types' credit rules
 * (only a type that earns may earn here; only a type that may be charged
 * may be in a charge order). Changing a preset changes no policy.
 */
final class SaveLeavePreset
{
    public const int MAX_NAME = 120;

    /** @param  array<string, mixed>  $params */
    public function handle(Access $access, ?string $id, string $name, array $params): string
    {
        if (! LeaveSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! LeavePresetRecords::ready()) {
            throw new RuntimeException('Presets can be saved once this installation\'s upgrade finishes.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw new RuntimeException('Give the preset a name of 1 to '.self::MAX_NAME.' characters.');
        }
        $policy = LeavePolicyInput::validated($params);

        return DB::transaction(static function () use ($access, $id, $name, $policy): string {
            $row = $id === null ? new LeavePolicyPreset : LeavePolicyPreset::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That preset no longer exists.');
            }
            if (LeavePolicyPreset::query()->where('name', $name)->when($row->exists, static fn ($q) => $q->whereKeyNot($row->id))->exists()) {
                throw new RuntimeException('A preset with that name already exists.');
            }
            $row->fill(['name' => $name, 'params' => $policy->toArray()]);
            if (! $row->exists) {
                $row->created_by = $access->userId === null ? null : (int) $access->userId;
                $row->created_by_name = $access->fullName;
            }
            if (! $row->save()) {
                throw new RuntimeException('The preset could not be saved.');
            }
            $summary = $policy->describe(LeavePresetRecords::typeNames());
            DB::afterCommit(static fn () => AuditService::record($access, AuditEvent::LEAVE_PRESET_SAVED, 'leave_policy_presets', $row->id, "Preset \"{$name}\" saved: {$summary}"));

            return "Preset \"{$name}\" saved.";
        });
    }
}
