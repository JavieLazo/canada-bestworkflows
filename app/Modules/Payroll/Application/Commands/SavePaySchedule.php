<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\PayrollPaySchedule;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\PaySchedules;
use App\Modules\Payroll\Domain\Schedules\PaySchedule;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Adds or edits a pay schedule: its name, its figures (PaySchedule, read
 * strictly) and whether it is the company's default — exactly one is. A
 * schedule's edit changes no payroll already made: a run keeps the pay
 * date and contribution month it recorded. Whoever manages the Payroll
 * Settings (PayrollSettingsAccess); RequiredAudit on the same transaction.
 */
final class SavePaySchedule
{
    public const int NAME_MAX = 120;

    /**
     * @param  array<string, mixed>  $figures  the schedule's figures in PaySchedule shape
     * @return string the schedule's id
     */
    public function handle(Access $access, ?string $id, string $name, array $figures, bool $isDefault): string
    {
        if (! PayrollSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! PaySchedules::ready()) {
            throw new RuntimeException('Pay schedules can be set up once this installation\'s upgrade finishes.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new RuntimeException('Name the schedule, in 1 to '.self::NAME_MAX.' characters.');
        }
        try {
            $schedule = PaySchedule::fromArray($figures);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }

        return DB::transaction(static function () use ($access, $id, $name, $schedule, $isDefault): string {
            $row = $id === null ? null : PayrollPaySchedule::query()->whereKey($id)->lockForUpdate()->first();
            if ($id !== null && $row === null) {
                throw new RuntimeException('That pay schedule no longer exists.');
            }
            if (PayrollPaySchedule::query()->where('name', $name)->when($row !== null, static fn ($q) => $q->whereKeyNot($row->id))->exists()) {
                throw new RuntimeException('A pay schedule with that name already exists.');
            }
            $row ??= new PayrollPaySchedule;
            $wasDefault = $row->exists && $row->is_default;
            if ($wasDefault && ! $isDefault) {
                throw new RuntimeException('Make another schedule the default first; one schedule is always the default.');
            }
            $row->fill(['name' => $name, 'params' => $schedule->toArray()]);
            $row->is_default = $isDefault || ! PayrollPaySchedule::query()->where('is_default', true)->when($row->exists, static fn ($q) => $q->whereKeyNot($row->id))->exists();
            if (! $row->exists) {
                $row->created_by = $access->userId === null ? null : (int) $access->userId;
                $row->created_by_name = mb_substr($access->fullName, 0, 200);
            }
            $row->save();
            if ($row->is_default) {
                PayrollPaySchedule::query()->whereKeyNot($row->id)->where('is_default', true)->update(['is_default' => false]);
            }

            RequiredAudit::record($access, AuditEvent::PAY_SCHEDULE_SAVED, 'payroll_pay_schedules', $row->id,
                "Pay schedule \"{$name}\" ".($id === null ? 'added' : 'changed').($row->is_default ? ', the default' : '').': '.$schedule->describe());

            return $row->id;
        });
    }
}
