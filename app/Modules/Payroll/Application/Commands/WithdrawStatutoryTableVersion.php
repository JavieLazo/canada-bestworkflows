<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\PayrollStatutoryTable;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Modules\Payroll\Application\StatutoryVersionGuard;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deletes a Contribution & Tax Table version that has not taken effect
 * yet — a wrong date or wrong figures caught before any payroll could use
 * them. The version before it simply stays in force, as it was going to
 * until the withdrawn one started. A version in force, or one a finalized
 * payroll reaches, is history and is refused (StatutoryVersionGuard). The
 * audit row keeps what was withdrawn, since the row itself is gone.
 *
 * Audited with RequiredAudit on the same transaction.
 */
final class WithdrawStatutoryTableVersion
{
    public function handle(Access $access, string $id): string
    {
        if (! PayrollSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $today = LocalClock::todayKey();

        return DB::transaction(static function () use ($access, $id, $today): string {
            $row = PayrollStatutoryTable::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That table version no longer exists.');
            }
            StatutoryVersionGuard::assertEditable($row, $today);
            $label = StatutoryTables::label($row->kind);
            $from = StatutoryTables::day($row->effective_from->format('Y-m-d'));
            $row->delete();

            RequiredAudit::record($access, AuditEvent::STATUTORY_TABLE_WITHDRAWN, 'payroll_statutory_tables', $row->id,
                "{$label} table version from {$from} withdrawn before taking effect".($row->reference === null ? '' : " ({$row->reference})"));

            return "{$label} table version from {$from} withdrawn.";
        });
    }
}
