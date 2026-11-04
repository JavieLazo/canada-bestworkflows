<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\PayrollPaySchedule;
use App\Models\PayrollRun;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\PaySchedules;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deletes a pay schedule that is not the default and that no payroll took
 * its dates from: a run names the schedule it was made from, and that
 * record stays. Whoever manages the Payroll Settings; RequiredAudit on the
 * same transaction.
 */
final class DeletePaySchedule
{
    public function handle(Access $access, string $id): string
    {
        if (! PayrollSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! PaySchedules::ready()) {
            throw new RuntimeException('Pay schedules can be set up once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            $row = PayrollPaySchedule::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That pay schedule no longer exists.');
            }
            if ($row->is_default) {
                throw new RuntimeException('The default schedule cannot be deleted. Make another schedule the default first.');
            }
            $runs = PayrollRun::query()->where('schedule_id', $row->id)->count();
            if ($runs > 0) {
                throw new RuntimeException($runs === 1 ? 'One payroll took its dates from this schedule, so it stays on record.' : "{$runs} payrolls took their dates from this schedule, so it stays on record.");
            }
            $name = $row->name;
            if (! $row->delete()) {
                throw new RuntimeException('The pay schedule could not be deleted.');
            }
            RequiredAudit::record($access, AuditEvent::PAY_SCHEDULE_DELETED, 'payroll_pay_schedules', $id, "Pay schedule \"{$name}\" deleted");

            return "Pay schedule \"{$name}\" deleted.";
        });
    }
}
