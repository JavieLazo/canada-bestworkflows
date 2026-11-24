<?php

declare(strict_types=1);

namespace App\Livewire\Payroll\Support;

use App\Livewire\Records\Support\DeductionText;
use App\Livewire\Records\Support\Hris;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Contracts\Views\DeductionColumnView;
use App\Modules\Workforce\Contracts\EmployeePayData;
use App\Support\Money;

/**
 * Where each figure on a draft payroll's grid comes from, so the grid can
 * mark the ones that are not simply the company settings — typed on this
 * payroll, or a recurring line — and explain any marked figure: the order
 * payroll checks, which one it used and what that one holds.
 *
 * Read only from what the draft's calculation already has — the captured
 * inputs (the recurring lines), the typed amounts and the computed lines —
 * so the explanation names exactly what the figure was computed from. It
 * moves no money, and nothing here is stored in the payroll's evidence.
 */
final class FigureSources
{
    public const string TYPED = 'typed';

    public const string RECURRING = 'recurring';

    /** How a mark reads on the grid: pill tier and label. */
    public const array LABELS = [
        self::TYPED => ['pill-caution', 'Typed'],
        self::RECURRING => ['pill-muted', 'Recurring'],
    ];

    /**
     * The marked cells: employee id => cell => source. A cell is
     * `row:{deduction id}`; `incentives` counts the employee's recurring
     * incentive lines. Unmarked cells come from the company settings.
     *
     * @param  iterable<EmployeePayData>  $employees
     * @param  array<string, list<array<string, mixed>>>  $customDeductionLines  the named deduction lines, as the calculation returns them
     * @param  array<string, list<array<string, mixed>>>  $incentiveLines  the incentive lines, as the calculation returns them
     * @return array<string, array<string, string|int>>
     */
    public static function marks(iterable $employees, array $customDeductionLines, array $incentiveLines): array
    {
        $marks = [];
        foreach ($employees as $employee) {
            $id = $employee->id;
            foreach ($customDeductionLines[$id] ?? [] as $line) {
                if (! $line['automatic']) {
                    $marks[$id]['row:'.$line['type_id']] = self::TYPED;
                } elseif (($line['recurring_line_id'] ?? null) !== null) {
                    $marks[$id]['row:'.$line['type_id']] = self::RECURRING;
                }
            }
            $recurring = count(array_filter($incentiveLines[$id] ?? [], static fn (array $line): bool => ($line['recurring_line_id'] ?? null) !== null));
            if ($recurring > 0) {
                $marks[$id]['incentives'] = $recurring;
            }
        }

        return $marks;
    }

    /**
     * Why one Payroll Deductions cell holds its amount: payroll's checks in
     * order, the one it used marked `used`, those after it `unused`, an
     * empty one `none`.
     *
     * @param  array<string, array<string, mixed>>  $draftCustomDeductions
     * @param  array<string, list<array<string, mixed>>>  $customDeductionLines
     * @return array{title: string, amount: float, steps: list<array{label: string, detail: string, state: string}>}|null
     */
    public static function explain(
        string $cell,
        string $employeeId,
        string $employeeName,
        float $amount,
        PayrollInputs $inputs,
        array $draftCustomDeductions,
        array $customDeductionLines,
        ?DeductionColumnView $column,
    ): ?array {
        if (! str_starts_with($cell, 'row:') || $column === null) {
            return null;
        }
        $typeId = substr($cell, 4);
        $typed = isset($draftCustomDeductions[$employeeId][$typeId]);
        $steps = [self::step('Typed on this payroll', $typed ? Hris::money(Money::round($draftCustomDeductions[$employeeId][$typeId])).' typed in the cell.' : 'Nothing typed in this cell.', $typed)];
        $line = null;
        foreach ($customDeductionLines[$employeeId] ?? [] as $candidate) {
            if ($candidate['type_id'] === $typeId) {
                $line = $candidate;
            }
        }
        $recurring = null;
        foreach ($inputs->recurringLines[$employeeId] ?? [] as $entry) {
            if ($entry['type_id'] === $typeId && ($line['recurring_line_id'] ?? null) === $entry['line_id']) {
                $recurring = $entry;
            }
        }
        if ($recurring !== null) {
            $left = $recurring['total'] === null ? null : Money::round(max(0.0, $recurring['total'] - $recurring['paid_before']));
            $steps[] = self::step('Their recurring deduction or allowance', Hris::money($recurring['amount']).' a payroll'
                .($left !== null ? ', '.Hris::money($left).' balance before this one' : ', no total').'.', true);
        } elseif ($column->isPercent() && is_array($column->percent)) {
            $steps[] = self::step('Company: the '.$column->name.' rule', DeductionText::percent($column->percent).'.', true);
        }

        return ['title' => $column->name.' · '.$employeeName, 'amount' => $amount, 'steps' => self::resolve($steps)];
    }

    /** @return array{label: string, detail: string, state: string} */
    private static function step(string $label, string $detail, bool $set): array
    {
        return ['label' => $label, 'detail' => $detail, 'state' => $set ? 'set' : 'none'];
    }

    /**
     * The first step that is set is the one payroll used; every later one is
     * not used.
     *
     * @param  list<array{label: string, detail: string, state: string}>  $steps
     * @return list<array{label: string, detail: string, state: string}>
     */
    private static function resolve(array $steps): array
    {
        $used = false;
        foreach ($steps as $index => $step) {
            if ($step['state'] !== 'set') {
                continue;
            }
            $steps[$index]['state'] = $used ? 'unused' : 'used';
            $used = true;
        }

        return $steps;
    }
}
