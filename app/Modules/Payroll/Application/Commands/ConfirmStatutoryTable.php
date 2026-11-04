<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\PayrollStatutoryTable;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records that a table's latest version was checked and its figures still
 * hold through a later date (`covered_through`) — what HR does after
 * looking at the current government schedule. The figures do not change
 * and nothing is unblocked by it: the version stays in force regardless,
 * and the date only decides until when payroll runs without the reminder
 * to check. Only the latest version of a kind can be confirmed, because an
 * earlier one ends where the next begins. Audited with RequiredAudit on the
 * same transaction.
 */
final class ConfirmStatutoryTable
{
    public function handle(Access $access, string $id, string $coveredThrough): string
    {
        if (! PayrollSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! PayrollPeriod::isPayrollDate($coveredThrough)) {
            throw new RuntimeException('Enter the date the figures are confirmed through.');
        }

        return DB::transaction(static function () use ($access, $id, $coveredThrough): string {
            $row = PayrollStatutoryTable::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That table version no longer exists.');
            }
            $label = StatutoryTables::label($row->kind);
            $later = PayrollStatutoryTable::query()->where('kind', $row->kind)->where('effective_from', '>', $row->effective_from->format('Y-m-d'))->lockForUpdate()->exists();
            if ($later) {
                throw new RuntimeException("A later {$label} table version already starts; confirm that one.");
            }
            if ($coveredThrough < $row->effective_from->format('Y-m-d')) {
                throw new RuntimeException('The confirmed-through date cannot fall before the effective date.');
            }
            $before = $row->covered_through->format('Y-m-d');
            if ($coveredThrough <= $before) {
                throw new RuntimeException("The {$label} table is already confirmed through ".StatutoryTables::day($before).'. Choose a later date.');
            }
            $row->covered_through = CarbonImmutable::parse($coveredThrough, 'UTC');
            $row->save();

            RequiredAudit::record($access, AuditEvent::STATUTORY_TABLE_CONFIRMED, 'payroll_statutory_tables', $row->id,
                "{$label} table version from ".StatutoryTables::day($row->effective_from->format('Y-m-d')).' confirmed through '
                .StatutoryTables::day($coveredThrough).' (was '.StatutoryTables::day($before).')');

            return "{$label} table confirmed through ".StatutoryTables::day($coveredThrough).'.';
        });
    }
}
