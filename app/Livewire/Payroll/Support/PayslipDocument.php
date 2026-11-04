<?php

declare(strict_types=1);

namespace App\Livewire\Payroll\Support;

use App\Domain\Enums\PayrollRunKind;
use App\Livewire\Records\Support\BenefitText;
use App\Modules\Payroll\Contracts\Views\DeductionColumnView;
use App\Modules\Payroll\Contracts\Views\PayslipDeductionView;
use App\Modules\Payroll\Contracts\Views\PayslipIncentiveView;
use App\Modules\Payroll\Contracts\Views\PayslipSettlementView;
use App\Modules\Payroll\Contracts\Views\PayslipView;
use App\Modules\Payroll\Domain\DeductionCalculation;
use App\Support\EmployeeIdentity;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Formats issued payroll facts for the same document in Payroll, Portal and
 * Reports. Presentation only — NEVER recalculate a posted amount or total.
 *
 * @phpstan-type Line array{label: string, value: string, quantity?: string, note?: string, style?: string, excluded?: bool}
 * @phpstan-type Group array{title: string, rows: list<Line>, notes?: list<string>, employer?: bool}
 */
final class PayslipDocument
{
    private const array LEGACY_DEDUCTIONS = [
        'late_undertime_deduction' => 'Legacy late / undertime deduction',
        'absence_deduction' => 'Legacy absence deduction',
        'unpaid_leave_deduction' => 'Legacy unpaid leave deduction',
    ];

    /**
     * @param  list<PayslipIncentiveView>  $incentives
     * @param  list<PayslipDeductionView>  $customDeductions
     * @param  Collection<int, DeductionColumnView>  $columns
     * @param  list<PayslipSettlementView>  $settlements  the settlements this payslip made of earlier presumed days
     * @return array<string, mixed>
     */
    public static function issued(PayslipView $p, ?object $employee, bool $yearly, array $incentives, array $customDeductions, Collection $columns, string $payroll = '', bool $own = false, array $settlements = []): array
    {
        $money = static fn (string $label, mixed $value): array => ['label' => $label, 'value' => self::amount($value)];
        $presumed = (float) ($p->presumed_days ?? 0.0);
        $charged = (float) ($p->leave_charged_days ?? 0.0);
        $basis = $yearly ? [] : [
            $money('Daily rate · PHP', $p->daily_rate),
            ['label' => 'Work days', 'value' => PayrollData::dayCount($p->work_days)],
            ['label' => 'Absences · days', 'value' => PayrollData::dayCount($p->absence_days)],
            ['label' => 'Paid leave · days', 'value' => PayrollData::dayCount($p->paid_leave_days)],
            ['label' => 'Unpaid leave · days', 'value' => PayrollData::dayCount($p->unpaid_leave_days)],
            // The advance payroll: the days paid before their attendance was
            // in, settled on the next payslip.
            ...($presumed > 0 ? [['label' => 'Paid in advance · days', 'value' => PayrollData::dayCount($presumed)]] : []),
            // Lateness, undertime and absences charged to leave credits
            // instead of pay, by the employee's leave policy.
            ...($charged > 0 ? [['label' => 'Charged to leave credits · days', 'value' => LeaveChargeText::credits($charged)]] : []),
        ];
        $earnings = $yearly ? [
            $money('13th month pay', $p->thirteenth_month),
            [...$money('Tax-exempt (within PHP 90,000 a year)', $p->exempt_benefits), 'style' => 'detail'],
            [...$money('Taxed (over PHP 90,000 a year)', $p->taxable_benefits_excess), 'style' => 'detail'],
        ] : [
            [...$money('Basic salary', $p->basic_pay), 'quantity' => 'breakdown below', 'style' => 'basic'],
            [...$money('Work days pay', $p->work_days_pay), 'style' => 'detail'],
            [...$money('Holiday pay', $p->holiday_pay), 'quantity' => PayrollData::dayCount($p->holiday_days).' days', 'style' => 'detail'],
            [...$money('Rest day pay', $p->rest_day_pay), 'quantity' => PayrollData::dayCount($p->rest_days).' days', 'style' => 'detail'],
            $money('Allowance', $p->allowances),
        ];
        $incentiveDetails = [];
        if (! $yearly) {
            // Portal omits unapproved OT; Payroll retains its struck-out
            // evidence. Neither path includes it in a new total.
            if ($p->overtime_acknowledged || ! $own) {
                $earnings[] = [...$money($p->overtime_acknowledged ? 'Overtime pay' : 'Overtime pay (not approved)', $p->overtime_pay), 'quantity' => self::hours($p->overtime_hours).' hrs', 'excluded' => ! $p->overtime_acknowledged];
            }
            $earnings[] = $money('NSD', $p->night_differential);
            $earnings[] = $money('Leave', $p->leave_pay);
            $earnings[] = $money('Other earnings', $p->other_earnings);
            // The settlement of presumed days on an earlier payslip (the
            // advance payroll): one line per settled payslip, negative when
            // the days were worth less than presumed; the stored total when
            // the lines are not at hand.
            if ($settlements !== []) {
                foreach ($settlements as $line) {
                    $earnings[] = $money('Settlement for '.self::settledDays($line->from_date, $line->to_date).' · '.$line->settled_code, $line->amount);
                }
            } elseif ((float) ($p->settlement ?? 0.0) !== 0.0) {
                $earnings[] = $money('Attendance settlement', $p->settlement);
            }
            foreach ($incentives as $line) {
                $note = $line->over_ceiling > 0 ? 'PHP '.self::amount($line->over_ceiling).' taxed over the yearly ceiling' : '';
                $category = BenefitText::TAXES[$line->tax ?? ''] ?? ($line->is_taxable ? 'Taxable' : 'Tax-exempt');
                $earnings[] = $money($line->name, $line->amount);
                $incentiveDetails[] = [
                    'label' => $line->name.' ('.strtolower($category).')',
                    'value' => $category.($note !== '' ? ' · '.$note : ''),
                ];
            }
            if ($incentives === [] && $p->bonus !== 0.0) {
                $earnings[] = $money('Bonus', $p->bonus);
            }
        }

        $deductions = $yearly ? [$money('Withholding tax', $p->withholding_tax)] : self::deductions($p, $customDeductions, $columns);
        $supplemental = [];
        $metadata = [['label' => 'Position', 'value' => $employee->position ?? '—']];
        if ($payroll !== '') {
            $metadata[] = ['label' => 'Payroll', 'value' => $payroll];
        }
        if (! $yearly) {
            $metadata[] = $money('Base pay with allowances subtotal', $p->total_base_pay);
        }
        if (! $yearly && $p->attendance_through !== null) {
            $metadata[] = ['label' => 'Attendance counted through', 'value' => $p->attendance_through->format('d M Y')];
        }
        $supplemental[] = ['title' => 'Payroll details', 'rows' => $metadata];
        if ($incentiveDetails !== []) {
            $supplemental[] = ['title' => 'Incentive tax treatment', 'rows' => $incentiveDetails];
        }
        foreach ($settlements as $line) {
            $supplemental[] = ['title' => 'Settlement for '.self::settledDays($line->from_date, $line->to_date).' · '.$line->settled_code, 'rows' => self::settlementRows($line)];
        }
        if (! $yearly && ($p->sss_er !== 0.0 || $p->philhealth_er !== 0.0 || $p->pagibig_er !== 0.0)) {
            $supplemental[] = ['title' => 'Employer contributions (not deducted)', 'employer' => true, 'rows' => [
                $money('SSS', $p->sss_er), $money('PhilHealth', $p->philhealth_er), $money('Pag-IBIG', $p->pagibig_er),
            ]];
        }
        // Historical charges still explain the printed deduction total. Only
        // ancillary evidence belongs in the screen-only details panel.
        foreach (self::LEGACY_DEDUCTIONS as $field => $label) {
            if ($p->{$field} !== 0.0) {
                $deductions[] = $money($label, $p->{$field});
            }
        }

        return [
            'employee' => PayrollData::employeeName($employee), 'employeeId' => EmployeeIdentity::number($employee),
            'payrollCode' => $p->payroll_code ?? '',
            'periodFrom' => $p->period_start->format('d M Y'), 'periodTo' => $p->period_end->format('d M Y'),
            'yearly' => $yearly, 'basis' => $basis, 'earnings' => $earnings, 'deductions' => $deductions,
            'gross' => self::amount($p->gross_pay), 'deductionTotal' => self::amount($p->total_deductions), 'net' => self::amount($p->net_pay),
            'supplemental' => $supplemental,
            'notes' => $yearly
                ? ['One twelfth of basic pay earned in the year. Benefits are tax-exempt up to PHP 90,000 a year; only the excess is taxed. No contributions are deducted.']
                : [
                    'Issued payroll amounts in Philippine pesos. Basic salary includes the work day, holiday and rest day amounts shown beneath it.',
                    ...($presumed > 0 ? ['Days paid in advance were paid as scheduled before their attendance was in; what they turned out to be worth is settled on the next payslip.'] : []),
                    ...($settlements !== [] ? ['A settlement is what days paid in advance on an earlier payslip turned out to be worth, less what that payslip paid for them.'] : []),
                    ...($charged > 0 ? ['Lateness, undertime and absences charged to leave credits are not deducted from pay; the Leave line includes the absences credits paid for.'] : []),
                ],
        ];
    }

    /** "12–15 Oct 2026", or one day. */
    private static function settledDays(DateTimeInterface $from, DateTimeInterface $to): string
    {
        if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
            return $from->format('d M Y');
        }

        return $from->format('Y-m') === $to->format('Y-m')
            ? $from->format('d').'–'.$to->format('d M Y')
            : $from->format('d M Y').' – '.$to->format('d M Y');
    }

    /**
     * One settlement itemised: each compared line as presumed and as the
     * days turned out, then the two totals and the difference.
     *
     * @return list<Line>
     */
    private static function settlementRows(PayslipSettlementView $line): array
    {
        $presumed = $line->details['presumed'] ?? [];
        $actual = $line->details['actual'] ?? [];
        $rows = [];
        foreach ([
            'work_days' => 'Work days', 'paid_leave_days' => 'Paid leave days', 'absence_days' => 'Absences',
            'basic_pay' => 'Basic salary', 'leave_pay' => 'Leave', 'overtime_pay' => 'Overtime pay',
            'night_differential' => 'NSD', 'late_deduction' => 'Late charged', 'undertime_deduction' => 'Undertime charged',
        ] as $key => $label) {
            $before = (float) ($presumed[$key] ?? 0.0);
            $after = (float) ($actual[$key] ?? 0.0);
            if ($before === 0.0 && $after === 0.0) {
                continue;
            }
            $days = str_ends_with($key, '_days');
            $rows[] = ['label' => $label, 'value' => ($days ? PayrollData::dayCount($before) : self::amount($before)).' → '.($days ? PayrollData::dayCount($after) : self::amount($after))];
        }
        $rows[] = ['label' => 'Paid in advance', 'value' => self::amount($line->presumed_amount)];
        $rows[] = ['label' => 'Worth from attendance', 'value' => self::amount($line->actual_amount)];
        $rows[] = ['label' => 'Settlement', 'value' => self::amount($line->amount)];

        return $rows;
    }

    /**
     * Keeps issued deduction names and their query-provided order. Historical
     * deductions remain visible even when a current catalog no longer has them.
     *
     * @param  list<PayslipDeductionView>  $customDeductions
     * @param  Collection<int, DeductionColumnView>  $columns
     * @return list<Line>
     */
    private static function deductions(PayslipView $p, array $customDeductions, Collection $columns): array
    {
        $posted = [];
        foreach ($customDeductions as $line) {
            $posted['row:'.$line->deduction_code] = $line;
        }
        $lines = [];
        foreach ($columns as $column) {
            $field = $column->calculation !== null ? DeductionCalculation::statutoryKey($column->calculation) : null;
            if (! $column->headsColumn()) {
                continue;
            }
            if ($field !== null) {
                $lines[] = ['label' => $column->name, 'value' => self::amount($p->{$field})];
            } elseif (isset($posted[$column->key()])) {
                $lines[] = ['label' => $posted[$column->key()]->name, 'value' => self::amount($posted[$column->key()]->amount)];
            }
        }
        $lines[] = ['label' => 'Other deductions', 'value' => self::amount($p->other_deductions)];
        if ($columns->contains(static fn (DeductionColumnView $c): bool => $c->calculation === DeductionCalculation::LATE_UNDERTIME)) {
            $lines[] = ['label' => 'Undertimes', 'value' => self::amount($p->undertime_deduction), 'quantity' => $p->undertime_minutes.' min'];
            $lines[] = ['label' => 'Late', 'value' => self::amount($p->late_deduction), 'quantity' => $p->late_minutes.' min'];
        }

        return $lines;
    }

    /**
     * Reports already supplies formatted, detached statement groups. Reorder
     * those facts for the shared template and retain its tax and legacy detail.
     *
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    public static function report(array $statement): array
    {
        $identity = array_column($statement['identity'] ?? [], 'value', 'label');
        $totals = array_column($statement['highlights'] ?? [], 'value', 'label');
        $groups = array_column($statement['groups'] ?? [], null, 'title');
        $sourceEarnings = array_column($groups['Earnings']['rows'] ?? [], 1, 0);
        $sourceDeductions = array_column($groups['Deductions']['rows'] ?? [], 1, 0);
        $time = array_column($groups['Time and rate details']['rows'] ?? [], 1, 0);
        $tax = array_column($groups['Tax treatment']['rows'] ?? [], 1, 0);
        $yearly = ($identity['Payroll kind'] ?? '') === PayrollRunKind::LABELS[PayrollRunKind::THIRTEENTH_MONTH];
        $basis = [];
        if (! $yearly) {
            foreach (['Daily rate' => 'Daily rate · PHP', 'Work days' => 'Work days', 'Absence days' => 'Absences · days', 'Paid leave days' => 'Paid leave · days', 'Unpaid leave days' => 'Unpaid leave · days'] as $field => $label) {
                $value = (string) ($time[$field] ?? '—');
                $basis[] = ['label' => $label, 'value' => is_numeric($value) ? ($field === 'Daily rate' ? self::amount($value) : PayrollData::dayCount($value)) : $value];
            }
        }
        $money = static fn (string $label, string $field): array => ['label' => $label, 'value' => self::reportAmount($sourceEarnings[$field] ?? '—')];
        $earnings = $yearly ? [
            $money('13th month pay', '13th month pay'),
            ['label' => 'Tax-exempt (within PHP 90,000 a year)', 'value' => self::reportAmount($tax['Tax treatment: exempt benefits'] ?? '—'), 'style' => 'detail'],
            ['label' => 'Taxed (over PHP 90,000 a year)', 'value' => self::reportAmount($tax['Tax treatment: benefits over exemption ceiling'] ?? '—'), 'style' => 'detail'],
        ] : [
            [...$money('Basic salary', 'Basic pay subtotal'), 'quantity' => 'breakdown below', 'style' => 'basic'],
            [...$money('Work days pay', 'Work days pay'), 'style' => 'detail'],
            [...$money('Holiday pay', 'Holiday pay'), 'quantity' => PayrollData::dayCount($time['Holidays worked'] ?? 0).' days', 'style' => 'detail'],
            [...$money('Rest day pay', 'Rest day pay'), 'quantity' => PayrollData::dayCount($time['Rest days worked'] ?? 0).' days', 'style' => 'detail'],
            $money('Allowance', 'Allowances'),
            [...$money(($tax['Overtime included in gross'] ?? 'Yes') === 'No' ? 'Overtime pay (not approved)' : 'Overtime pay', 'Overtime pay'), 'quantity' => self::hours($time['Overtime hours'] ?? 0).' hrs', 'excluded' => ($tax['Overtime included in gross'] ?? 'Yes') === 'No'],
            $money('NSD', 'Night differential'),
            $money('Leave', 'Leave pay'),
            $money('Other earnings', 'Other earnings'),
        ];
        if (! $yearly) {
            if ((float) str_replace(',', '', (string) ($sourceEarnings['Attendance settlement'] ?? 0)) !== 0.0) {
                $earnings[] = $money('Attendance settlement', 'Attendance settlement');
            }
            $hasIncentives = false;
            foreach ($groups['Earnings']['rows'] ?? [] as $row) {
                if (str_starts_with($row[0], 'Incentive detail: ')) {
                    $hasIncentives = true;
                    $name = self::issuedName($row[0], 'Incentive detail: ');
                    $earnings[] = ['label' => $name, 'value' => self::reportAmount($row[1])];
                }
            }
            if (! $hasIncentives && (float) ($sourceEarnings['Taxable incentives'] ?? 0) !== 0.0) {
                $earnings[] = $money('Bonus', 'Taxable incentives');
            }
            if ((float) ($sourceEarnings['13th month pay'] ?? 0) !== 0.0) {
                $earnings[] = $money('13th month pay', '13th month pay');
            }
        }
        $deductions = [];
        foreach ($yearly ? ['Withholding tax' => 'Withholding tax'] : ['SSS employee' => 'SSS', 'PhilHealth employee' => 'PhilHealth', 'Pag-IBIG employee' => 'Pag-IBIG', 'Withholding tax' => 'Withholding tax'] as $field => $label) {
            $deductions[] = ['label' => $label, 'value' => self::reportAmount($sourceDeductions[$field] ?? '—')];
        }
        if (! $yearly) {
            foreach ($groups['Deductions']['rows'] ?? [] as $row) {
                if (str_starts_with($row[0], 'Deduction detail: ')) {
                    $deductions[] = ['label' => self::issuedName($row[0], 'Deduction detail: '), 'value' => self::reportAmount($row[1])];
                }
            }
            $deductions[] = ['label' => 'Other deductions', 'value' => self::reportAmount($sourceDeductions['Other deductions'] ?? '—')];
            $deductions[] = ['label' => 'Undertimes', 'value' => self::reportAmount($sourceDeductions['Undertime deduction'] ?? '—'), 'quantity' => PayrollData::dayCount($time['Undertime minutes'] ?? 0).' min'];
            $deductions[] = ['label' => 'Late', 'value' => self::reportAmount($sourceDeductions['Late deduction'] ?? '—'), 'quantity' => PayrollData::dayCount($time['Late minutes'] ?? 0).' min'];
        }
        foreach (self::LEGACY_DEDUCTIONS as $label) {
            if ((float) ($sourceDeductions[$label] ?? 0) !== 0.0) {
                $deductions[] = ['label' => $label, 'value' => self::reportAmount($sourceDeductions[$label])];
            }
        }

        // The compact printed slip uses the supplied layout. The report's
        // complete issued evidence remains inspectable in screen-only details.
        $supplemental = [['title' => 'Payroll details', 'rows' => [
            ['label' => 'Payroll', 'value' => $identity['Payroll'] ?? '—'],
            ['label' => 'Payroll kind', 'value' => $identity['Payroll kind'] ?? '—'],
            ['label' => 'Position', 'value' => $identity['Position'] ?? '—'],
        ]]];
        foreach ($groups as $title => $group) {
            if ($yearly && $title === 'Time and rate details') {
                continue;
            }
            $rows = self::reportLines($group['rows']);
            if ($yearly) {
                $rows = array_values(array_filter($rows, static fn (array $line): bool => $line['label'] !== 'Overtime included in gross' && (! in_array($title, ['Earnings', 'Deductions', 'Employer contributions'], true) || $line['value'] !== '0.00')));
            }
            if ($rows !== []) {
                $supplemental[] = ['title' => $title === 'Employer contributions' ? 'Employer contributions (not deducted)' : $title, 'rows' => self::reportValues($rows, time: $title === 'Time and rate details'), 'notes' => $group['notes'] ?? [], 'employer' => $title === 'Employer contributions'];
            }
        }
        $payroll = (string) ($identity['Payroll'] ?? '');

        return [
            'employee' => $identity['Employee'] ?? $statement['title'], 'employeeId' => $identity['Employee ID'] ?? '—',
            'payrollCode' => str_contains($payroll, ' · ') ? explode(' · ', $payroll, 2)[0] : '',
            'periodFrom' => self::reportDate($identity['Period from'] ?? '—'), 'periodTo' => self::reportDate($identity['Period to'] ?? '—'),
            'yearly' => $yearly, 'basis' => $basis, 'earnings' => $earnings, 'deductions' => $deductions,
            'gross' => self::reportAmount($totals['Gross pay'] ?? '—'), 'deductionTotal' => self::reportAmount($totals['Total deductions'] ?? '—'), 'net' => self::reportAmount($totals['Net pay'] ?? '—'),
            'supplemental' => $supplemental, 'notes' => $statement['notes'] ?? [],
        ];
    }

    /** The final parenthesized part is the source code, not part of the issued name. */
    private static function issuedName(string $label, string $prefix): string
    {
        $name = substr($label, strlen($prefix));
        $code = strrpos($name, ' (');

        return $code !== false && str_ends_with($name, ')') ? substr($name, 0, $code) : $name;
    }

    /** @param list<list<string>> $rows
     * @return list<Line>
     */
    private static function reportLines(array $rows): array
    {
        return array_map(static fn (array $row): array => ['label' => $row[0], 'value' => $row[1]], $rows);
    }

    /** @param list<Line> $lines
     * @return list<Line>
     */
    private static function reportValues(array $lines, bool $time = false): array
    {
        foreach ($lines as &$line) {
            if (! $time || $line['label'] === 'Daily rate') {
                $line['value'] = self::reportAmount($line['value']);
            } elseif (is_numeric($line['value']) && in_array($line['label'], ['Work days', 'Holidays worked', 'Rest days worked', 'Absence days', 'Paid leave days', 'Unpaid leave days'], true)) {
                $line['value'] = PayrollData::dayCount($line['value']);
            }
        }
        unset($line);

        return $lines;
    }

    /** Normalize only numeric amounts; preserve worded tax and legacy evidence. */
    private static function reportAmount(string $value): string
    {
        $number = str_replace([',', 'PHP ', '₱'], '', $value);

        return is_numeric($number) ? self::amount($number) : $value;
    }

    /** Match the supplied document: PHP belongs to the heading, not every cell. */
    private static function amount(mixed $value): string
    {
        return str_replace('₱', '', PayrollData::peso($value));
    }

    /** Keep stored hundredths of an hour while omitting empty decimal places. */
    private static function hours(mixed $value): string
    {
        return rtrim(rtrim(self::amount($value), '0'), '.');
    }

    /** Report periods are date-only keys, never instants requiring timezone conversion. */
    private static function reportDate(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false ? $value : $date->format('d M Y');
    }
}
