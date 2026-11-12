<?php

declare(strict_types=1);

namespace App\Livewire\Payroll\Support;

use App\Domain\Payroll\StatutorySchedule;
use App\Livewire\Records\Support\DeductionText;
use App\Livewire\Records\Support\Hris;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Contracts\Views\DeductionColumnView;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Workforce\Contracts\EmployeePayData;
use App\Support\Money;

/**
 * Where each figure on a draft payroll's grid comes from, so the grid can
 * mark the ones that are not simply the company settings — typed on this
 * payroll, the employee's own pay settings (a statutory line switched off
 * or fixed), or a recurring line — and explain any marked figure: the
 * order payroll checks, which one it used and what that one holds.
 *
 * Read only from what the draft's calculation already has — the captured
 * inputs (employees' settings, the table versions in force, the recurring
 * lines), the typed overrides and the computed lines — so the explanation
 * names exactly what the figure was computed from. It moves no peso, and
 * nothing here is stored in the payroll's evidence.
 */
final class FigureSources
{
    public const string TYPED = 'typed';

    public const string OWN = 'own';

    public const string OFF = 'off';

    public const string RECURRING = 'recurring';

    /** The statutory cells, by the field the grid edits, with the table each is computed from. */
    public const array STATUTORY = [
        'sss' => StatutorySchedule::SSS,
        'philhealth' => StatutorySchedule::PHILHEALTH,
        'pagibig' => StatutorySchedule::PAGIBIG,
        'withholding_tax' => StatutorySchedule::WITHHOLDING_TAX,
    ];

    /** How a mark reads on the grid: pill tier and label. */
    public const array LABELS = [
        self::TYPED => ['pill-caution', 'Typed'],
        self::OWN => ['pill-primary', 'Own'],
        self::OFF => ['pill-primary', 'Own · off'],
        self::RECURRING => ['pill-muted', 'Recurring'],
    ];

    /**
     * The marked cells: employee id => cell => source. A cell is a
     * statutory field (`sss`, …) or `row:{deduction id}`; `incentives`
     * counts the employee's recurring incentive lines. Unmarked cells come
     * from the company settings.
     *
     * @param  iterable<EmployeePayData>  $employees
     * @param  array<string, array<string, mixed>>  $draftDeductions  typed statutory figures, employee id => field => amount
     * @param  array<string, list<array<string, mixed>>>  $customDeductionLines  the named deduction lines, as the calculation returns them
     * @param  array<string, list<array<string, mixed>>>  $incentiveLines  the incentive lines, as the calculation returns them
     * @return array<string, array<string, string|int>>
     */
    public static function marks(iterable $employees, array $draftDeductions, array $customDeductionLines, array $incentiveLines): array
    {
        $marks = [];
        foreach ($employees as $employee) {
            $id = $employee->id;
            foreach (array_keys(self::STATUTORY) as $field) {
                $source = isset($draftDeductions[$id][$field]) ? self::TYPED : self::ownSource($employee, $field);
                if ($source !== null) {
                    $marks[$id][$field] = $source;
                }
            }
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
     * Why one cell holds its amount: payroll's checks in order, the one it
     * used marked `used`, those after it `unused`, an empty one `none`.
     *
     * @param  array<string, mixed>  $draftDeductions
     * @param  array<string, array<string, mixed>>  $draftCustomDeductions
     * @param  array<string, list<array<string, mixed>>>  $customDeductionLines
     * @return array{title: string, amount: float, steps: list<array{label: string, detail: string, state: string}>}|null
     */
    public static function explain(
        string $cell,
        EmployeePayData $employee,
        string $employeeName,
        float $amount,
        PayrollInputs $inputs,
        array $draftDeductions,
        array $draftCustomDeductions,
        array $customDeductionLines,
        ?DeductionColumnView $column,
    ): ?array {
        $id = $employee->id;
        if (isset(self::STATUTORY[$cell])) {
            $label = match ($cell) {
                'sss' => 'SSS', 'philhealth' => 'PhilHealth', 'pagibig' => 'Pag-IBIG', default => 'Withholding tax',
            };
            $typed = isset($draftDeductions[$id][$cell]);
            $own = self::ownSource($employee, $cell);
            $table = $inputs->statutoryTables[self::STATUTORY[$cell]] ?? null;
            $steps = [
                self::step('Typed on this payroll', $typed ? Hris::peso(Money::round($draftDeductions[$id][$cell])).' typed in the cell.' : 'Nothing typed in this cell.', $typed),
                self::step('Their own pay settings', self::ownText($employee, $cell), $own !== null),
                self::step('Company: the '.$label.' table', $table !== null && $table['effective_from'] !== null ? 'The version from '.PayrollPeriodText::day((string) $table['effective_from']).'.' : 'The built-in figures.', true),
            ];

            return ['title' => $label.' · '.$employeeName, 'amount' => $amount, 'steps' => self::resolve($steps)];
        }
        if (! str_starts_with($cell, 'row:') || $column === null) {
            return null;
        }
        $typeId = substr($cell, 4);
        $typed = isset($draftCustomDeductions[$id][$typeId]);
        $steps = [self::step('Typed on this payroll', $typed ? Hris::peso(Money::round($draftCustomDeductions[$id][$typeId])).' typed in the cell.' : 'Nothing typed in this cell.', $typed)];
        $line = null;
        foreach ($customDeductionLines[$id] ?? [] as $candidate) {
            if ($candidate['type_id'] === $typeId) {
                $line = $candidate;
            }
        }
        $recurring = null;
        foreach ($inputs->recurringLines[$id] ?? [] as $entry) {
            if ($entry['type_id'] === $typeId && ($line['recurring_line_id'] ?? null) === $entry['line_id']) {
                $recurring = $entry;
            }
        }
        if ($recurring !== null) {
            $left = $recurring['total'] === null ? null : Money::round(max(0.0, $recurring['total'] - $recurring['paid_before']));
            $steps[] = self::step('Their recurring deduction or allowance', Hris::peso($recurring['amount']).' a payroll'
                .($left !== null ? ', '.Hris::peso($left).' balance before this one' : ', no total').'.', true);
        } elseif ($column->isPercent() && is_array($column->percent)) {
            $steps[] = self::step('Company: the '.$column->name.' rule', DeductionText::percent($column->percent).'.', true);
        }

        return ['title' => $column->name.' · '.$employeeName, 'amount' => $amount, 'steps' => self::resolve($steps)];
    }

    /** What makes a statutory line the employee's own, or null when it is the company's. */
    private static function ownSource(EmployeePayData $employee, string $field): ?string
    {
        if ($field === 'withholding_tax') {
            if (! $employee->allow_withholding_tax) {
                return self::OFF;
            }

            return $employee->annualize_tax || $employee->fixed_tax > 0.0 || $employee->percent_tax > 0.0 ? self::OWN : null;
        }
        if (! $employee->{"allow_{$field}"}) {
            return self::OFF;
        }
        $mode = $employee->{"{$field}_mode"};
        $fixed = $employee->{"fixed_{$field}_ee"} > 0.0 || $employee->{"fixed_{$field}_er"} > 0.0;

        return $fixed || ($mode !== null && $mode !== GovDeductionSettings::MODE_SEMI_MONTHLY) ? self::OWN : null;
    }

    /** The employee's own setting for a statutory line, in words. */
    private static function ownText(EmployeePayData $employee, string $field): string
    {
        if ($field === 'withholding_tax') {
            return match (true) {
                ! $employee->allow_withholding_tax => 'Withholding tax is switched off for them.',
                $employee->fixed_tax > 0.0 => Hris::peso($employee->fixed_tax).' on every payslip.',
                $employee->percent_tax > 0.0 => Hris::percentText($employee->percent_tax / 100).' of taxable compensation.',
                $employee->annualize_tax => 'Annualized per cut-off (×24).',
                default => 'None: the company\'s way.',
            };
        }
        if (! $employee->{"allow_{$field}"}) {
            return 'Switched off for them: not deducted.';
        }
        $parts = [];
        if ($employee->{"fixed_{$field}_ee"} > 0.0) {
            $parts[] = Hris::peso($employee->{"fixed_{$field}_ee"}).' a month, employee share';
        }
        if ($employee->{"fixed_{$field}_er"} > 0.0) {
            $parts[] = Hris::peso($employee->{"fixed_{$field}_er"}).' employer share';
        }
        $mode = $employee->{"{$field}_mode"};
        if ($mode !== null && $mode !== GovDeductionSettings::MODE_SEMI_MONTHLY) {
            $parts[] = 'the whole month on the second cut-off';
        } elseif ($parts !== []) {
            $parts[] = 'half on each cut-off';
        }

        return $parts === [] ? 'None: the company\'s way.' : ucfirst(implode(', ', $parts)).'.';
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
