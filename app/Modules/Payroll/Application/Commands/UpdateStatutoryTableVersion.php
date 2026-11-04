<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\StatutorySchedule;
use App\Models\PayrollStatutoryTable;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Modules\Payroll\Application\StatutoryVersionGuard;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Corrects a Contribution & Tax Table version that has not taken effect
 * yet: its effective date, confirmed-through date, reference and figures,
 * in place. Once a version is in force, or a finalized payroll reaches a
 * date it prices, it is history (StatutoryVersionGuard) and the change is
 * a new version instead. The corrected date must still fall after the
 * version before it and before the version after it, and after today, so
 * the order of versions never changes under a draft. Draft payrolls whose
 * period reaches the version recompute and ask for a fresh review.
 *
 * Audited with RequiredAudit on the same transaction.
 */
final class UpdateStatutoryTableVersion
{
    /**
     * @param  array<string, mixed>  $figures  the kind's figures in engine shape (rates as fractions)
     */
    public function handle(Access $access, string $id, string $effectiveFrom, string $coveredThrough, string $reference, array $figures): string
    {
        if (! PayrollSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
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
        $today = ManilaClock::todayKey();
        if ($effectiveFrom <= $today) {
            throw new RuntimeException('The effective date must be after today. A version that takes effect today or earlier is added as a new version.');
        }

        $label = DB::transaction(static function () use ($access, $id, $effectiveFrom, $coveredThrough, $reference, $figures, $today): string {
            $row = PayrollStatutoryTable::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That table version no longer exists.');
            }
            $label = StatutoryTables::label($row->kind);
            $figureError = StatutorySchedule::error($row->kind, $figures);
            if ($figureError !== null) {
                throw new RuntimeException($figureError);
            }
            // Round-trips exactly: what the engine will read is what is saved.
            $figures = StatutorySchedule::fromArrays([...StatutorySchedule::BUILT_IN, $row->kind => $figures])->toArray()[$row->kind];

            StatutoryVersionGuard::assertEditable($row, $today, $effectiveFrom);

            // Its neighbours, locked: the corrected date keeps its place between them.
            $before = $row->effective_from->format('Y-m-d');
            $previous = PayrollStatutoryTable::query()->where('kind', $row->kind)->where('effective_from', '<', $before)->lockForUpdate()->orderByDesc('effective_from')->first();
            $next = PayrollStatutoryTable::query()->where('kind', $row->kind)->where('effective_from', '>', $before)->lockForUpdate()->orderBy('effective_from')->first();
            if ($previous !== null && $previous->effective_from->format('Y-m-d') >= $effectiveFrom) {
                throw new RuntimeException("A {$label} table version already starts on ".StatutoryTables::day($previous->effective_from->format('Y-m-d')).'. Choose a later date.');
            }
            if ($next !== null && $next->effective_from->format('Y-m-d') <= $effectiveFrom) {
                throw new RuntimeException("The next {$label} table version starts on ".StatutoryTables::day($next->effective_from->format('Y-m-d')).'. Choose an earlier date.');
            }

            $row->fill([
                'effective_from' => $effectiveFrom, 'covered_through' => $coveredThrough,
                'reference' => $reference === '' ? null : $reference, 'params' => $figures,
            ]);
            $row->save();

            RequiredAudit::record($access, AuditEvent::STATUTORY_TABLE_UPDATED, 'payroll_statutory_tables', $row->id,
                "{$label} table version from ".StatutoryTables::day($before).' corrected: from '.StatutoryTables::day($effectiveFrom)
                .', confirmed through '.StatutoryTables::day($coveredThrough).($reference === '' ? '' : " ({$reference})"));

            return $label;
        });

        return "{$label} table version from ".StatutoryTables::day($effectiveFrom).' saved.';
    }
}
