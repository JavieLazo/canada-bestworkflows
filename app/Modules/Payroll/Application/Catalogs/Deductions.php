<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Catalogs;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunStatus;
use App\Models\PayrollDeduction;
use App\Models\PayrollRunMember;
use App\Models\PayrollRunMemberDeduction;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Domain\DeductionCalculation;
use App\Modules\Payroll\Domain\Deductions\PercentDeduction;
use App\Platform\Audit\RequiredAudit;
use App\Platform\Records\ReferenceCatalog;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns this catalog's persistence while sharing its name/description editor
 * mechanics. Every row is a column of the Basic Payroll grid, and a row's
 * `calculation` is what tells a computed column (the statutory tables, the
 * DTR minutes) from one an officer types per employee. No screen offers the
 * calculation: the migration and the seeder set it, and this class keeps it
 * by NAME — a new row named exactly as a computed deduction is known
 * (`DeductionCalculation::defaultLabel`, case-insensitively) takes that
 * calculation when no row holds it, which is how a deleted SSS comes back.
 *
 * A change here reaches every DRAFT payroll at once and no FINALIZED one:
 * a draft reads the list as it stands, while a finalized run reads the list
 * its evidence recorded. So:
 *
 *  - deleting a COMPUTED row switches that deduction OFF on every draft
 *    (`PayrollData::switchOff`): the column goes, the engine's line is
 *    exempt for everyone, the tax is re-derived, and any typed figure a
 *    draft still held for that column is cleared here, with a required
 *    audit row per draft;
 *  - deleting a TYPED row that drafts charge takes its typed lines off
 *    those drafts the same way;
 *  - both happen in ONE transaction under the payroll membership lock, so a
 *    draft never keeps a line for a column it no longer has;
 *  - a row a FINALIZED run's members charge cannot be deleted: that run is
 *    history (rename instead), and the FK RESTRICT is the backstop.
 */
final class Deductions extends ReferenceCatalog
{
    /**
     * Add or rename through the shared editor. A NEW row named as a computed
     * deduction is known, while no row carries that calculation, becomes
     * that computed deduction again — the way back after a delete.
     *
     * @param  array<string, mixed>  $extra
     */
    public function save(Access $actor, ?string $id, string $name, string $description, array $extra = []): string
    {
        if ($id === null && ! array_key_exists('calculation', $extra)) {
            $calculation = self::calculationForName($name);
            if ($calculation !== null && ! PayrollDeduction::query()->where('calculation', $calculation)->exists()) {
                $extra['calculation'] = $calculation;
            }
        }
        // A percentage rule (PercentDeduction) belongs to an officer's own
        // row: never to a computed one, whose money the engine prices.
        if (array_key_exists('percent', $extra)) {
            if (! PayrollData::percentDeductionsReady()) {
                unset($extra['percent']);
            } elseif ($extra['percent'] !== null) {
                $error = PercentDeduction::error($extra['percent']);
                if ($error !== null) {
                    throw new RuntimeException($error);
                }
                $extra['percent'] = PercentDeduction::fromArray($extra['percent'])->toArray();
                $computed = $id === null ? ($extra['calculation'] ?? null) : PayrollDeduction::query()->whereKey($id)->value('calculation');
                if ($computed !== null) {
                    throw new RuntimeException('A computed deduction prices itself; choose another name for a percentage deduction.');
                }
            }
        }

        return parent::save($actor, $id, $name, $description, $extra);
    }

    /** The computed deduction a name spells (its default label), or null. */
    public static function calculationForName(string $name): ?string
    {
        $needle = mb_strtolower(trim($name));
        foreach (DeductionCalculation::all() as $calculation) {
            if (mb_strtolower(DeductionCalculation::defaultLabel($calculation)) === $needle) {
                return $calculation;
            }
        }

        return null;
    }

    public function delete(Access $actor, string $id): string
    {
        if (! $actor->mayReadAllData(ModuleCode::RECORDS)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }

        $row = PayrollDeduction::query()->find($id);

        try {
            return DB::transaction(function () use ($actor, $id, $row): string {
                PayrollData::lockMembership();
                if ($row !== null) {
                    if (self::linesOn($id, PayrollRunStatus::FINALIZED)->exists()) {
                        throw new RuntimeException("{$row->name} is on a finalized payroll, so it cannot be deleted. Rename it instead.");
                    }
                    $this->removeDraftLines($actor, $row, 'was deleted');
                    $this->clearDraftOverrides($actor, $row);
                }

                return parent::delete($actor, $id);
            });
        } catch (QueryException $e) {
            // The draft FK is the backstop for a save that races this check.
            if (($e->errorInfo[0] ?? null) === '23000') {
                throw new RuntimeException('This deduction is on a payroll, so it cannot be deleted. Rename it instead.', previous: $e);
            }

            throw $e;
        }
    }

    /**
     * Take a typed row's lines off every DRAFT payroll, one required audit
     * row per draft — the draft's own change, recorded like a save. Called
     * inside the membership-locked transaction only.
     */
    private function removeDraftLines(Access $actor, PayrollDeduction $row, string $because): void
    {
        $lines = self::linesOn($row->id, PayrollRunStatus::DRAFT)->with('member.run')->get();
        if ($lines->isEmpty()) {
            return;
        }

        $perRun = [];
        foreach ($lines as $line) {
            $run = $line->member?->run;
            if ($run === null) {
                continue;
            }
            $perRun[$run->id] ??= ['name' => $run->name, 'count' => 0];
            $perRun[$run->id]['count']++;
        }

        PayrollRunMemberDeduction::query()->whereIn('id', $lines->pluck('id')->all())->delete();

        foreach ($perRun as $runId => ['name' => $name, 'count' => $count]) {
            $noun = $count === 1 ? 'line' : 'lines';
            RequiredAudit::record($actor, AuditEvent::PAYROLL_RUN_UPDATED, 'payroll_runs', $runId,
                "{$name} saved; {$count} {$row->name} {$noun} removed because the deduction {$because} in Records");
        }
    }

    /**
     * A computed row is being deleted: the figures officers typed over its
     * column on DRAFT payrolls have no column to live in and are cleared
     * (NULL = "as computed", and the computed line is now exempt), one
     * required audit row per draft. Late / Undertime has no typed column.
     */
    private function clearDraftOverrides(Access $actor, PayrollDeduction $row): void
    {
        $key = $row->calculation === null ? null : DeductionCalculation::statutoryKey($row->calculation);
        $column = $key === null ? null : (PayrollData::DEDUCTION_COLUMNS[$key] ?? null);
        if ($column === null) {
            return;
        }

        $members = PayrollRunMember::query()->whereNotNull($column)
            ->whereHas('run', static fn (Builder $q): Builder => $q->where('status', PayrollRunStatus::DRAFT))
            ->with('run')->get();
        if ($members->isEmpty()) {
            return;
        }

        $perRun = [];
        foreach ($members as $member) {
            if ($member->run === null) {
                continue;
            }
            $perRun[$member->run->id] ??= ['name' => $member->run->name, 'count' => 0];
            $perRun[$member->run->id]['count']++;
        }

        PayrollRunMember::query()->whereIn('id', $members->pluck('id')->all())->update([$column => null]);

        foreach ($perRun as $runId => ['name' => $name, 'count' => $count]) {
            $noun = $count === 1 ? 'figure' : 'figures';
            RequiredAudit::record($actor, AuditEvent::PAYROLL_RUN_UPDATED, 'payroll_runs', $runId,
                "{$name} saved; {$count} typed {$row->name} {$noun} cleared because the deduction was deleted in Records and is switched off");
        }
    }

    /**
     * The typed lines a row has on members of runs in one status.
     *
     * @return Builder<PayrollRunMemberDeduction>
     */
    private static function linesOn(string $deductionId, string $status): Builder
    {
        return PayrollRunMemberDeduction::query()->where('deduction_id', $deductionId)
            ->whereHas('member.run', static fn (Builder $q): Builder => $q->where('status', $status));
    }

    protected function model(): string
    {
        return PayrollDeduction::class;
    }

    protected function prefix(): string
    {
        return 'DED';
    }

    protected function noun(): string
    {
        return 'deduction';
    }
}
