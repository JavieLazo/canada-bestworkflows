<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\PayrollStatutoryTable;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves the date a table's latest version is known good through — what HR
 * does after checking that the figures still hold, so payroll can run for
 * later periods without a new version. The figures do not change. Only the
 * latest version of a kind can be extended: an earlier one ends where the
 * next begins. Audited with RequiredAudit on the same transaction.
 */
final class ExtendStatutoryCoverage
{
    public function handle(Access $access, string $id, string $coveredThrough): string
    {
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! PayrollPeriod::isPayrollDate($coveredThrough)) {
            throw new RuntimeException('Enter the date the table is known good through.');
        }

        return DB::transaction(static function () use ($access, $id, $coveredThrough): string {
            $row = PayrollStatutoryTable::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That table version no longer exists.');
            }
            $label = StatutoryTables::label($row->kind);
            $later = PayrollStatutoryTable::query()->where('kind', $row->kind)->where('effective_from', '>', $row->effective_from->format('Y-m-d'))->lockForUpdate()->exists();
            if ($later) {
                throw new RuntimeException("A later {$label} table version already starts; extend that one.");
            }
            if ($coveredThrough < $row->effective_from->format('Y-m-d')) {
                throw new RuntimeException('The covered-through date cannot fall before the effective date.');
            }
            $before = $row->covered_through->format('Y-m-d');
            if ($coveredThrough < $before) {
                throw new RuntimeException("The {$label} table is already covered through ".StatutoryTables::day($before).'. Choose a later date.');
            }
            $row->covered_through = CarbonImmutable::parse($coveredThrough, 'UTC');
            $row->save();

            RequiredAudit::record($access, AuditEvent::STATUTORY_TABLE_EXTENDED, 'payroll_statutory_tables', $row->id,
                "{$label} table version from ".StatutoryTables::day($row->effective_from->format('Y-m-d')).' covered through '
                .StatutoryTables::day($before).', now through '.StatutoryTables::day($coveredThrough));

            return "{$label} table now covers through ".StatutoryTables::day($coveredThrough).'.';
        });
    }
}
