<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Payroll\Statutory;
use App\Domain\Payroll\StatutorySchedule;
use App\Models\PayrollStatutoryTable;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sets the figures of one contribution or tax table (SSS, PhilHealth,
 * Pag-IBIG, withholding tax). EACH TABLE HAS ONE SET OF FIGURES, NOT A LIST
 * OF DATED VERSIONS: the new figures replace the ones in use and take effect
 * at once for every draft payroll (which recomputes on its next preview and
 * asks for a fresh review); no effective date is asked. A finalized payroll
 * keeps the figures its evidence captured, so nothing already paid moves.
 * What the figures were is kept in the audit trail alone: the row the change
 * is written on stays, every other row of the kind is removed, and the kept
 * row covers every date they covered.
 *
 * The figures having just been set, they are confirmed through the end of
 * this year at least; a later confirmed date already on record stands.
 *
 * Whoever manages payroll (all-data PAYROLL, PayrollSettingsAccess);
 * RequiredAudit on the same transaction, after payroll's membership lock.
 */
final class ReplaceStatutoryTable
{
    /**
     * @param  array<string, mixed>  $figures  the kind's figures in engine shape (rates as fractions)
     */
    public function handle(Access $access, string $kind, string $reference, array $figures): string
    {
        if (! PayrollSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! StatutorySchedule::isKind($kind)) {
            throw new RuntimeException('Choose which table this is.');
        }
        if (! StatutoryTables::ready()) {
            throw new RuntimeException('The tables can be changed once this installation\'s upgrade finishes.');
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
        $today = ManilaClock::todayKey();
        $yearEnd = CarbonImmutable::parse($today, 'UTC')->endOfYear()->format('Y-m-d');

        DB::transaction(static function () use ($access, $kind, $reference, $figures, $label, $today, $yearEnd): void {
            PayrollData::lockMembership();
            $rows = PayrollStatutoryTable::query()->where('kind', $kind)->lockForUpdate()->orderBy('effective_from')->orderBy('id')->get();
            // The row in use today, else the earliest: the one the change is written on.
            $kept = $rows->last(static fn (PayrollStatutoryTable $row): bool => $row->effective_from->format('Y-m-d') <= $today) ?? $rows->first();
            $removed = 0;

            if ($kept === null) {
                $kept = new PayrollStatutoryTable;
                $kept->fill(['kind' => $kind, 'effective_from' => Statutory::EFFECTIVE_FROM, 'covered_through' => $yearEnd]);
            } else {
                // One row covers every date the list covered.
                $kept->effective_from = $rows->first()->effective_from;
                $removed = PayrollStatutoryTable::query()->where('kind', $kind)->whereKeyNot($kept->id)->delete();
                if ($kept->covered_through->format('Y-m-d') < $yearEnd) {
                    $kept->covered_through = CarbonImmutable::parse($yearEnd, 'UTC');
                }
            }
            $kept->params = $figures;
            $kept->reference = $reference === '' ? null : $reference;
            $kept->created_by = $access->userId === null ? null : (int) $access->userId;
            $kept->created_by_name = mb_substr($access->fullName, 0, 200);
            if (! $kept->save()) {
                throw new RuntimeException('The figures could not be saved.');
            }

            RequiredAudit::record($access, AuditEvent::STATUTORY_TABLE_UPDATED, 'payroll_statutory_tables', $kept->id,
                "{$label} table figures replaced".($reference === '' ? '' : " ({$reference})")
                .($removed > 0 ? ', '.$removed.' earlier or later '.($removed === 1 ? 'version' : 'versions').' removed' : ''));
        });

        return "{$label} figures saved. Draft payrolls now use them.";
    }
}
