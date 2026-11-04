<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\LeaveType;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Sets one leave type's credit rules (LeaveTypeCredits): whether it earns,
 * its unit, what may be charged to it, its charge order and its below-zero
 * limit. Whoever manages Leave Settings or leave (LeaveSettingsAccess).
 * The rules apply from the next earning posting and the next payroll
 * finalized; nothing already posted changes. Audited once per save that
 * changes something.
 */
final class SaveLeaveTypeCredits
{
    /** @param  array<string, mixed>  $input */
    public function handle(Access $access, string $typeId, array $input): string
    {
        if (! LeaveSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! Schema::hasColumn('leave_types', 'earns_credits')) {
            throw new RuntimeException('Leave credit rules can be set once this installation\'s upgrade finishes.');
        }
        $error = LeaveTypeCredits::error($input);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        $rules = LeaveTypeCredits::fromInput($input);

        return DB::transaction(static function () use ($access, $typeId, $rules): string {
            $type = LeaveType::query()->whereKey($typeId)->lockForUpdate()->first();
            if ($type === null) {
                throw new RuntimeException('That leave type no longer exists.');
            }
            $values = $rules->toArray();
            if (! Schema::hasColumn('leave_types', 'can_cash_out')) {
                // The window before the cron migrates 2026_10_31_0008.
                unset($values['can_cash_out']);
            }
            if (! Schema::hasColumn('leave_types', 'year_end')) {
                // The window before the cron migrates 2026_11_02_0017.
                unset($values['year_end']);
            }
            $before = $type->only(array_keys($values));
            $type->forceFill($values);
            $changed = $type->isDirty();
            if ($changed && ! $type->save()) {
                throw new RuntimeException('The leave type could not be saved.');
            }
            $name = (string) $type->getAttribute('name');
            if ($changed) {
                DB::afterCommit(static fn () => AuditService::record($access, AuditEvent::LEAVE_TYPE_CREDITS_SET, 'leave_types', $type->id, "{$name} · ".$rules->describe()));
            }
            unset($before);

            return $changed ? "Credit rules saved for {$name}." : "No change to {$name}.";
        });
    }
}
