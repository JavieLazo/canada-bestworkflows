<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Models\PayrollDeduction;
use App\Modules\Payroll\Contracts\Views\DeductionColumnView;
use App\Modules\Payroll\Contracts\Views\PayslipDeductionView;
use App\Modules\Payroll\Domain\DeductionCalculation;
use App\Support\CodeSort;
use Illuminate\Support\Collection;

/**
 * The Deductions group of the Basic Payroll grid, built from the Payroll
 * Deductions list: ONE COLUMN PER ROW, in the list's code order, with the
 * fixed Other Deductions, Undertimes, Late and Total after them. The one
 * row that heads no column is the LATE_UNDERTIME one: its money is the two
 * attendance columns, which show while the row exists. Nothing here is a
 * peso: the columns only say what each cell shows.
 *
 * THE LIST IS THE WHOLE TRUTH: a computed deduction whose row is gone is
 * switched off (`PayrollData::switchOff`), so no column stands in for it —
 * there is no fallback. Two shapes, one order:
 *
 *  - a DRAFT reads the list as it stands, so a row renamed, added or
 *    deleted in Records is on the next render;
 *  - a FINALIZED run is history: its columns are the list AS IT STOOD AT
 *    FINALIZATION, read back from the evidence payload's `deduction_columns`
 *    (`fromEvidence`), so a later change to the list changes nothing on it.
 *    A run whose evidence lacks that key had every computed deduction on:
 *    it shows the five under their default labels plus the typed lines its
 *    payslips posted (`fromPosted`) — the labels it was issued with.
 */
final class DeductionColumns
{
    private function __construct() {}

    /**
     * The draft grid's columns: every row of the list, code order, plus the
     * fallbacks.
     *
     * @return Collection<int, DeductionColumnView>
     */
    public static function fromCatalog(): Collection
    {
        $percentReady = PayrollData::percentDeductionsReady();
        $rows = PayrollDeduction::query()->get($percentReady ? ['id', 'code', 'name', 'calculation', 'percent'] : ['id', 'code', 'name', 'calculation'])
            ->map(static fn (PayrollDeduction $row): DeductionColumnView => new DeductionColumnView([
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'calculation' => $row->calculation,
                'percent' => $percentReady && is_array($row->percent) ? $row->percent : null,
            ]));

        return self::arrange($rows);
    }

    /**
     * A finalized run's columns as its evidence recorded them: every row the
     * list had at finalization, automatic and typed alike, frozen. A posted
     * line whose code the record lacks (it cannot happen, but a copy is a
     * copy) is appended so its money always has a column.
     *
     * @param  list<mixed>  $recorded  the payload's `deduction_columns`, as decoded
     * @param  iterable<PayslipDeductionView>  $postedLines  the payslips' posted copies
     * @return Collection<int, DeductionColumnView>
     */
    public static function fromEvidence(array $recorded, iterable $postedLines): Collection
    {
        $columns = [];
        foreach ($recorded as $entry) {
            if (! is_array($entry) || ! is_string($entry['code'] ?? null) || ! is_string($entry['name'] ?? null)) {
                continue;
            }
            $calculation = $entry['calculation'] ?? null;
            $percent = $entry['percent'] ?? null;
            $columns[] = new DeductionColumnView([
                'id' => null,
                'code' => $entry['code'],
                'name' => $entry['name'],
                'calculation' => is_string($calculation) && DeductionCalculation::isValid($calculation) ? $calculation : null,
                'percent' => is_array($percent) ? $percent : null,
            ]);
        }

        $known = [];
        foreach ($columns as $column) {
            $known[$column->key()] = true;
        }
        foreach ($postedLines as $line) {
            $code = (string) $line->deduction_code;
            if (! isset($known['row:'.$code])) {
                $known['row:'.$code] = true;
                $columns[] = new DeductionColumnView(['id' => null, 'code' => $code, 'name' => (string) $line->name, 'calculation' => null, 'percent' => null]);
            }
        }

        return self::arrange(new Collection($columns));
    }

    /**
     * A run whose evidence records no columns: every computed deduction was
     * on at its finalization, so all five under the labels the run was
     * issued with, and the typed lines its payslips posted.
     *
     * @param  iterable<PayslipDeductionView>  $postedLines  the payslips' posted copies
     * @return Collection<int, DeductionColumnView>
     */
    public static function fromPosted(iterable $postedLines): Collection
    {
        $columns = [];
        foreach (DeductionCalculation::all() as $calculation) {
            $columns[] = new DeductionColumnView(['id' => null, 'code' => '', 'name' => DeductionCalculation::defaultLabel($calculation), 'calculation' => $calculation, 'percent' => null]);
        }
        $posted = [];
        foreach ($postedLines as $line) {
            $code = (string) $line->deduction_code;
            // The first payslip's spelling wins; every payslip of one run
            // posted the same copy.
            $posted[$code] ??= new DeductionColumnView(['id' => null, 'code' => $code, 'name' => (string) $line->name, 'calculation' => null, 'percent' => null]);
        }

        return self::arrange((new Collection($columns))->concat(array_values($posted)));
    }

    /**
     * Code order; a blank code (the computed deductions of `fromPosted`)
     * sorts first, in calculation order.
     *
     * @param  Collection<int, DeductionColumnView>  $columns
     * @return Collection<int, DeductionColumnView>
     */
    private static function arrange(Collection $columns): Collection
    {
        $rank = array_flip(DeductionCalculation::all());

        return $columns
            ->sort(static function (DeductionColumnView $a, DeductionColumnView $b) use ($rank): int {
                if ($a->code === '' && $b->code === '') {
                    return ($rank[$a->calculation] ?? PHP_INT_MAX) <=> ($rank[$b->calculation] ?? PHP_INT_MAX);
                }
                if ($a->code === '' || $b->code === '') {
                    return $a->code === '' ? -1 : 1;
                }

                return CodeSort::compare($a->code, $b->code) ?: strcmp((string) $a->id, (string) $b->id);
            })
            ->values();
    }
}
