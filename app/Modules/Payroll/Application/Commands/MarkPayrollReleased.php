<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunStatus;
use App\Models\PayrollRun;
use App\Modules\Payroll\Application\PaySchedules;
use App\Support\Access;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records that a finalized payroll's pay went out: who marked it and when.
 * A mark, not a state — it locks nothing and changes no figure, so it is
 * audited best-effort like the other marks. Once; all-data PAYROLL.
 */
final class MarkPayrollReleased
{
    public function handle(Access $access, string $runId): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! PaySchedules::ready()) {
            throw new RuntimeException('A payroll can be marked released once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $runId): string {
            $run = PayrollRun::query()->whereKey($runId)->lockForUpdate()->first();
            if ($run === null) {
                throw new RuntimeException('That payroll no longer exists.');
            }
            if ($run->status !== PayrollRunStatus::FINALIZED) {
                throw new RuntimeException('Only a finalized payroll is released.');
            }
            if ($run->released_at !== null) {
                throw new RuntimeException('This payroll is already marked released.');
            }
            $run->released_at = CarbonImmutable::now('UTC');
            $run->released_by = $access->userId === null ? null : (int) $access->userId;
            $run->released_by_name = mb_substr($access->fullName, 0, 200);
            $run->save();
            AuditService::record($access, AuditEvent::PAYROLL_RELEASED, 'payroll_runs', $run->id, "Payroll {$run->code} {$run->name} marked released");

            return "Payroll {$run->code} marked released.";
        });
    }
}
