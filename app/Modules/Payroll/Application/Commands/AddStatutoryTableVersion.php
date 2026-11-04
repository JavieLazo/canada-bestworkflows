<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\StatutorySchedule;
use App\Models\PayrollStatutoryTable;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds a version of one Contribution & Tax Table: new figures from a date.
 * A version in force never changes its figures, so this is how a table
 * changes. The new version must start after every existing version of its
 * kind, and supersedes the latest from that date: the earlier version's
 * own `covered_through` (the day its figures were last confirmed through)
 * is left as recorded. Draft payrolls whose period starts on or after the
 * date recompute and ask for a fresh review; finalized payrolls keep the
 * figures in their evidence. A version that has not taken effect yet can
 * still be corrected or withdrawn (UpdateStatutoryTableVersion,
 * WithdrawStatutoryTableVersion).
 *
 * Audited with RequiredAudit on the same transaction: a version that cannot
 * be recorded is not saved.
 */
final class AddStatutoryTableVersion
{
    /**
     * @param  array<string, mixed>  $figures  the kind's figures in engine shape (rates as fractions)
     */
    public function handle(Access $access, string $kind, string $effectiveFrom, string $coveredThrough, string $reference, array $figures): string
    {
        if (! PayrollSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! StatutorySchedule::isKind($kind)) {
            throw new RuntimeException('Choose which table this is.');
        }
        if (! PayrollPeriod::isPayrollDate($effectiveFrom) || ! PayrollPeriod::isPayrollDate($coveredThrough)) {
            throw new RuntimeException('Enter the date it takes effect and the date its figures are confirmed through.');
        }
        if ($coveredThrough < $effectiveFrom) {
            throw new RuntimeException('The confirmed-through date cannot fall before the effective date.');
        }
        $reference = trim($reference);
        if (mb_strlen($reference) > 160) {
            throw new RuntimeException('The reference must be at most 160 characters.');
        }
        $figureError = StatutorySchedule::error($kind, $figures);
        if ($figureError !== null) {
            throw new RuntimeException($figureError);
        }
        // Round-trips exactly: what the engine will read is what is saved.
        $figures = StatutorySchedule::fromArrays([...StatutorySchedule::BUILT_IN, $kind => $figures])->toArray()[$kind];

        $label = StatutoryTables::label($kind);
        $row = DB::transaction(static function () use ($access, $kind, $effectiveFrom, $coveredThrough, $reference, $figures, $label): PayrollStatutoryTable {
            // The kind's versions, locked: the latest decides where the new
            // one may start. It stays in force until that day and is not
            // touched — a version's own record never changes under it.
            $latest = PayrollStatutoryTable::query()->where('kind', $kind)->lockForUpdate()->orderByDesc('effective_from')->first();
            if ($latest !== null && $latest->effective_from->format('Y-m-d') >= $effectiveFrom) {
                throw new RuntimeException("A {$label} table version already starts on ".StatutoryTables::day($latest->effective_from->format('Y-m-d')).'. Choose a later date.');
            }

            $row = new PayrollStatutoryTable;
            $row->fill([
                'kind' => $kind, 'effective_from' => $effectiveFrom, 'covered_through' => $coveredThrough,
                'reference' => $reference === '' ? null : $reference, 'params' => $figures,
            ]);
            $row->created_by = $access->userId === null ? null : (int) $access->userId;
            $row->created_by_name = mb_substr($access->fullName, 0, 200);
            $row->save();

            RequiredAudit::record($access, AuditEvent::STATUTORY_TABLE_ADDED, 'payroll_statutory_tables', $row->id,
                "{$label} table version from ".StatutoryTables::day($effectiveFrom).' added, confirmed through '.StatutoryTables::day($coveredThrough)
                .($reference === '' ? '' : " ({$reference})"));

            return $row;
        });

        return "{$label} table version from ".StatutoryTables::day($row->effective_from->format('Y-m-d')).' saved.';
    }
}
