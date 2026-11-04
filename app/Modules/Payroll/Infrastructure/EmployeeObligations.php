<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Infrastructure;

use App\Models\PayrollDeduction;
use App\Models\PayrollRecurringLine;
use App\Models\PayslipDeduction;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\RecurringLines;
use App\Platform\Retention\EmployeeObligationSource;
use App\Support\ManilaClock;
use App\Support\Money;

/**
 * Keeps an employee's unpaid recurring deductions — a loan's balance — in
 * view when they are made inactive: a leaving employee is normally on no
 * further payroll, so what is left is settled some other way. The line's
 * name, note and balance are shown to whoever closes the record in Employee
 * Records, with or without Payroll access, because that person has to know
 * what is still owed.
 *
 * Nothing to lock: a balance moves only when a payroll is finalized, under
 * the payroll membership lock. Making the employee inactive does not take
 * them off a draft payroll, so a balance read here can still move if such a
 * draft is finalized afterwards.
 */
final class EmployeeObligations implements EmployeeObligationSource
{
    public function lock(): void {}

    public function outstanding(string $employeeId): array
    {
        if (! RecurringLines::ready()) {
            return [];
        }
        $lines = PayrollRecurringLine::query()->where('employee_id', $employeeId)->whereNotNull('total')
            ->where(static fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', ManilaClock::todayKey()))
            ->orderBy('starts_on')->get();
        if ($lines->isEmpty()) {
            return [];
        }
        $paid = PayslipDeduction::query()->whereIn('recurring_line_id', $lines->pluck('id')->all())
            ->groupBy('recurring_line_id')->selectRaw('recurring_line_id, SUM(amount) AS paid_total')
            ->toBase()->pluck('paid_total', 'recurring_line_id');
        $names = PayrollDeduction::query()->whereIn('id', $lines->pluck('deduction_id')->filter()->unique()->values()->all())->pluck('name', 'id');

        $rows = [];
        foreach ($lines as $line) {
            $left = RecurringLines::rule($line)->left(Money::round($paid[$line->id] ?? 0.0));
            if ($left === null || $left <= 0.0) {
                continue;
            }
            $rows[] = [
                'label' => ($names[$line->deduction_id] ?? 'Recurring deduction').($line->note !== null ? ' · '.$line->note : ''),
                'reference' => PayrollData::peso($left).' left to pay',
            ];
        }

        return $rows;
    }
}
