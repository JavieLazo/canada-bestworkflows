<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunStatus;
use App\Models\PayrollRun;
use App\Modules\Payroll\Application\PayrollData;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Deletes drafts only, serialized with saving and finalization. */
final class DeletePayroll
{
    public function handle(Access $actor, string $id): string
    {
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $run = DB::transaction(function () use ($id, $actor): PayrollRun {
            PayrollData::lockMembership();

            $existing = PayrollRun::query()->whereKey($id)->first();

            if ($existing === null) {
                throw new RuntimeException('That payroll no longer exists.');
            }

            if ($existing->status === PayrollRunStatus::FINALIZED) {
                // Divergence: the reference deletes finalized runs and their
                // posted payslips. Refused — finalized runs are immutable.
                throw new RuntimeException('A finalized payroll is immutable and cannot be deleted.');
            }

            $deleted = PayrollRun::query()
                ->whereKey($id)
                ->where('status', PayrollRunStatus::DRAFT)
                ->delete();

            if ($deleted === 0) {
                throw new RuntimeException('That payroll changed before deletion completed.');
            }
            RequiredAudit::record($actor, AuditEvent::PAYROLL_RUN_DELETED, 'payroll_runs', $existing->id,
                "{$existing->name} (".strtolower($existing->status).') deleted');

            return $existing;
        });

        return $run->name;
    }
}
