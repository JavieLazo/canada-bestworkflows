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
use App\Support\Money;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Formats issued payroll facts for the same document in Payroll, Portal and
 * Reports. Presentation only — NEVER recalculate a posted amount or total. The
 * month's totals on a sheet are a footing of the posted figures, nothing more.
 *
 * @phpstan-type Line array{label: string, value: string, quantity?: string, note?: string, style?: string, excluded?: bool, group?: string}
 * @phpstan-type Group array{title: string, rows: list<Line>, notes?: list<string>, employer?: bool}
 */
final class PayslipDocument
{
    private const array LEGACY_DEDUCTIONS = [
        'late_undertime_deduction' => 'Legacy late / undertime deduction',
        'absence_deduction' => 'Legacy absence deduction',
        'unpaid_leave_deduction' => 'Legacy unpaid leave deduction',
    ];

    private const string BASIC_AND_ALLOWANCES = 'Basic Pay & Allowances';

    private const string OTHER_EARNINGS = 'Other Earnings';

    private const string REFUNDS = 'Refunds';

    private const string TAX_AND_PREMIUMS = 'Tax & Premiums';

    private const string FUND_AND_RECEIVABLES = 'Provident Fund & Receivables';

    private const string LOANS = 'Loans';

    private const string OTHER_DEDUCTIONS = 'Other Deductions';

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
            ['label' => 'Leave without pay (LWOP) · days', 'value' => PayrollData::dayCount($p->unpaid_leave_days)],
            // The advance payroll: the days paid before their attendance was
            // in, settled on the next payslip.
            ...($presumed > 0 ? [['label' => 'Paid as scheduled · days', 'value' => PayrollData::dayCount($presumed)]] : []),
            // Lateness, undertime and absences charged to leave credits
            // instead of pay, by the employee's leave policy.
            ...($charged > 0 ? [['label' => 'Leave credits deducted', 'value' => LeaveChargeText::credits($charged)]] : []),
        ];
        $earnings = $yearly ? [
            $money('13th month pay', $p->thirteenth_month),
            [...$money('Tax-exempt (within PHP 90,000 a year)', $p->exempt_benefits), 'style' => 'detail'],
            [...$money('Taxed (over PHP 90,000 a year)', $p->taxable_benefits_excess), 'style' => 'detail'],
        ] : [
            [...$money('Basic pay', $p->basic_pay), 'quantity' => 'breakdown below', 'style' => 'basic'],
            [...$money('Work days pay', $p->work_days_pay), 'style' => 'detail'],
            [...$money('Holiday pay', $p->holiday_pay), 'quantity' => PayrollData::dayCount($p->holiday_days).' days', 'style' => 'detail'],
            [...$money('Rest day pay', $p->rest_day_pay), 'quantity' => PayrollData::dayCount($p->rest_days).' days', 'style' => 'detail'],
            $money('Allowance', $p->allowances),
        ];
        $incentiveDetails = [];
        if (! $yearly) {
            // Overtime appears only once approved: an undecided day pays
            // nothing and shows no amount, on the employee's copy and the
            // officer's alike. The Daily Time Record is where it is decided.
            if ($p->overtime_acknowledged) {
                $earnings[] = [...$money('Overtime pay', $p->overtime_pay), 'quantity' => self::hours($p->overtime_hours).' hrs'];
            }
            $earnings[] = $money('NSD', $p->night_differential);
            $earnings[] = $money('Leave pay', $p->leave_pay);
            $earnings[] = $money('Other earnings', $p->other_earnings);
            // The settlement of presumed days on an earlier payslip (the
            // advance payroll): one line per settled payslip, negative when
            // the days were worth less than presumed; the stored total when
            // the lines are not at hand.
            if ($settlements !== []) {
                foreach ($settlements as $line) {
                    $earnings[] = $money('Prior cut-off adjustment for '.self::settledDays($line->from_date, $line->to_date).' · '.$line->settled_code, $line->amount);
                }
            } elseif ((float) ($p->settlement ?? 0.0) !== 0.0) {
                $earnings[] = $money('Prior cut-off adjustment', $p->settlement);
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

        $deductions = $yearly ? [[...$money('Withholding tax', $p->withholding_tax), 'group' => self::TAX_AND_PREMIUMS]] : self::deductions($p, $customDeductions, $columns);
        $supplemental = [];
        $metadata = [['label' => 'Position', 'value' => $employee->position ?? '—']];
        if ($payroll !== '') {
            $metadata[] = ['label' => 'Payroll', 'value' => $payroll];
        }
        if (! $yearly) {
            $metadata[] = $money('Basic pay and allowance subtotal', $p->total_base_pay);
        }
        if (! $yearly && $p->attendance_through !== null) {
            $metadata[] = ['label' => 'Attendance counted through', 'value' => $p->attendance_through->format('d M Y')];
        }
        $supplemental[] = ['title' => 'Payroll details', 'rows' => $metadata];
        if ($incentiveDetails !== []) {
            $supplemental[] = ['title' => 'Incentive tax treatment', 'rows' => $incentiveDetails];
        }
        foreach ($settlements as $line) {
            $supplemental[] = ['title' => 'Prior cut-off adjustment for '.self::settledDays($line->from_date, $line->to_date).' · '.$line->settled_code, 'rows' => self::settlementRows($line)];
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

        $refund = (float) ($p->tax_refund ?? 0.0);

        return [
            ...self::slipFacts($p->period_start, $p->period_end, $yearly, $earnings, $deductions),
            'employee' => PayrollData::employeeName($employee), 'employeeId' => EmployeeIdentity::number($employee),
            'position' => $employee->position ?? '—',
            'payrollCode' => $p->payroll_code ?? '',
            'amounts' => ['gross' => (float) $p->gross_pay, 'deductions' => (float) $p->total_deductions, 'net' => (float) $p->net_pay],
            'refund' => $refund > 0 ? self::amount($refund) : '',
            'periodFrom' => $p->period_start->format('d M Y'), 'periodTo' => $p->period_end->format('d M Y'),
            'yearly' => $yearly, 'basis' => $basis, 'earnings' => $earnings, 'deductions' => $deductions,
            'gross' => self::amount($p->gross_pay), 'deductionTotal' => self::amount($p->total_deductions), 'net' => self::amount($p->net_pay),
            'supplemental' => $supplemental,
            'notes' => $yearly
                ? ['One twelfth of basic pay earned in the year. Benefits are tax-exempt up to PHP 90,000 a year; only the excess is taxed. No contributions are deducted.']
                : [
                    'Issued payroll amounts in Philippine pesos. Basic pay includes the work day, holiday and rest day amounts shown beneath it.',
                    ...($presumed > 0 ? ['Days paid as scheduled were paid before their attendance was in; what they turned out to be worth is adjusted on the next payslip.'] : []),
                    ...($settlements !== [] ? ['A prior cut-off adjustment is what days paid as scheduled on an earlier payslip turned out to be worth, less what that payslip paid for them.'] : []),
                    ...($charged > 0 ? ['Lateness, undertime and absences deducted from leave credits are not deducted from pay; the Leave pay line includes the absences the credits paid for.'] : []),
                ],
        ];
    }

    /**
     * One sheet from the slips of one employee's month (or one 13th month
     * slip): the shared header facts, the slips oldest first and the month's
     * footing. A regular sheet always carries the summary, even with one slip.
     *
     * @param  list<array<string, mixed>>  $slips
     * @return array<string, mixed>
     */
    public static function sheet(array $slips, string $office): array
    {
        if ($slips === []) {
            throw new InvalidArgumentException('A payslip sheet needs at least one slip.');
        }
        $first = $slips[0];
        $cell = static fn (float $amount): string => $amount !== 0.0 ? self::amount($amount) : '–';
        $total = static fn (string $key): float => Money::round(array_sum(array_map(static fn (array $slip): float => $slip['amounts'][$key], $slips)));

        return [
            'yearly' => $first['yearly'],
            'title' => $first['title'],
            'employee' => $first['employee'], 'employeeId' => $first['employeeId'], 'position' => $first['position'],
            'office' => $office !== '' ? $office : '—',
            'slips' => $slips,
            'summary' => $first['yearly'] ? null : [
                'rows' => array_map(static fn (array $slip): array => [
                    'period' => $slip['period'], 'gross' => $cell($slip['amounts']['gross']), 'deductions' => $cell($slip['amounts']['deductions']), 'net' => $cell($slip['amounts']['net']),
                ], $slips),
                'total' => ['gross' => $cell($total('gross')), 'deductions' => $cell($total('deductions')), 'net' => $cell($total('net'))],
            ],
        ];
    }

    /**
     * What a report's statements print as: one sheet per employee and month,
     * from the statements the report selected and no others. A 13th month
     * statement stands alone.
     *
     * @param  list<array<string, mixed>>  $statements
     * @return list<array<string, mixed>>
     */
    public static function reportSheets(array $statements): array
    {
        $months = [];
        foreach ($statements as $index => $statement) {
            $slip = self::report($statement);
            $key = $slip['yearly'] ? 'yearly:'.$index : ($statement['employee_key'] ?? '').'|'.$slip['title'];
            $months[$key]['office'] = (string) ($statement['office'] ?? '');
            $months[$key]['slips'][] = $slip;
        }

        return array_values(array_map(static function (array $month): array {
            usort($month['slips'], static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

            return self::sheet($month['slips'], $month['office']);
        }, $months));
    }

    /**
     * The facts a slip's band and two columns print, the same for issued
     * payslips and report statements.
     *
     * @param  list<Line>  $earnings
     * @param  list<Line>  $deductions
     * @return array<string, mixed>
     */
    private static function slipFacts(DateTimeInterface $from, DateTimeInterface $to, bool $yearly, array $earnings, array $deductions): array
    {
        return [
            'from' => $from->format('Y-m-d'),
            'title' => $yearly ? 'For the year '.$from->format('Y') : 'For the month of '.strtoupper($from->format('F Y')),
            'period' => self::periodLabel($from, $to),
            'half' => $yearly ? '13th month' : self::half($from, $to),
            'earningGroups' => $yearly
                ? self::grouped($earnings, [''], static fn (array $line): string => '')
                : self::grouped($earnings, [self::BASIC_AND_ALLOWANCES, self::OTHER_EARNINGS, self::REFUNDS], self::earningGroup(...)),
            'deductionGroups' => self::grouped($deductions, [self::TAX_AND_PREMIUMS, self::FUND_AND_RECEIVABLES, self::LOANS, self::OTHER_DEDUCTIONS], self::deductionGroup(...)),
        ];
    }

    /**
     * The lines under their group headings, empty groups left out. An item
     * with no amount reads "–".
     *
     * @param  list<Line>  $lines
     * @param  list<string>  $titles
     * @param  Closure(Line): string  $groupOf
     * @return list<array{title: string, lines: list<array<string, mixed>>}>
     */
    private static function grouped(array $lines, array $titles, Closure $groupOf): array
    {
        $groups = array_fill_keys($titles, []);
        foreach ($lines as $line) {
            $zero = in_array($line['value'], ['0.00', '—'], true);
            $groups[$groupOf($line)][] = [...$line, 'shown' => $zero ? '–' : $line['value'], 'zero' => $zero];
        }

        $sections = [];
        foreach ($groups as $title => $rows) {
            if ($rows !== []) {
                $sections[] = ['title' => (string) $title, 'lines' => $rows];
            }
        }

        return $sections;
    }

    /** @param Line $line */
    private static function earningGroup(array $line): string
    {
        return match (true) {
            in_array($line['style'] ?? '', ['basic', 'detail'], true),
            preg_match('/\b(?:pera|rata|allowances?)\b/i', $line['label']) === 1 => self::BASIC_AND_ALLOWANCES,
            preg_match('/\brefunds?\b/i', $line['label']) === 1 => self::REFUNDS,
            default => self::OTHER_EARNINGS,
        };
    }

    /**
     * A computed line is tagged where it is built. A typed row's name is free
     * text, so this reads it: provident first, because a Provident Fund Loan
     * belongs with the fund, then loans, because an A/R on a loan is a loan.
     *
     * @param  Line  $line
     */
    private static function deductionGroup(array $line): string
    {
        return $line['group'] ?? match (true) {
            preg_match('/\bprovident\b/i', $line['label']) === 1 => self::FUND_AND_RECEIVABLES,
            preg_match('/\b(?:loans?|slf|mpl)\b|\bpag-?\s?ibig\s+(?:ii|2)\b/i', $line['label']) === 1 => self::LOANS,
            preg_match('/\breceivables?\b/i', $line['label']) === 1 => self::FUND_AND_RECEIVABLES,
            default => self::OTHER_DEDUCTIONS,
        };
    }

    /** "SEPTEMBER 1–15, 2026", or as much of the dates as differ. */
    private static function periodLabel(DateTimeInterface $from, DateTimeInterface $to): string
    {
        $label = match (true) {
            $from->format('Y-m-d') === $to->format('Y-m-d') => $from->format('F j, Y'),
            $from->format('Y-m') === $to->format('Y-m') => $from->format('F j').'–'.$to->format('j, Y'),
            $from->format('Y') === $to->format('Y') => $from->format('F j').' – '.$to->format('F j, Y'),
            default => $from->format('F j, Y').' – '.$to->format('F j, Y'),
        };

        return strtoupper($label);
    }

    /** Names the standard cut-offs only; any other period reads by its dates alone. */
    private static function half(DateTimeInterface $from, DateTimeInterface $to): string
    {
        $endsMonth = $to->format('j') === $to->format('t');

        return match (true) {
            $from->format('Y-m') !== $to->format('Y-m') => '',
            $from->format('j') === '1' && $endsMonth => 'Whole month',
            $from->format('j') === '1' && $to->format('j') === '15' => '1st half',
            $from->format('j') === '16' && $endsMonth => '2nd half',
            default => '',
        };
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
            'basic_pay' => 'Basic pay', 'leave_pay' => 'Leave pay', 'overtime_pay' => 'Overtime pay',
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
        $rows[] = ['label' => 'Paid as scheduled', 'value' => self::amount($line->presumed_amount)];
        $rows[] = ['label' => 'Worth from attendance', 'value' => self::amount($line->actual_amount)];
        $rows[] = ['label' => 'Prior cut-off adjustment', 'value' => self::amount($line->amount)];

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
                $lines[] = ['label' => $column->name, 'value' => self::amount($p->{$field}), 'group' => self::TAX_AND_PREMIUMS];
            } elseif (isset($posted[$column->key()])) {
                $lines[] = ['label' => $posted[$column->key()]->name, 'value' => self::amount($posted[$column->key()]->amount)];
            }
        }
        $lines[] = ['label' => 'Other deductions', 'value' => self::amount($p->other_deductions)];
        if ($columns->contains(static fn (DeductionColumnView $c): bool => $c->calculation === DeductionCalculation::LATE_UNDERTIME)) {
            $lines[] = ['label' => 'Undertime', 'value' => self::amount($p->undertime_deduction), 'quantity' => $p->undertime_minutes.' min'];
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
            foreach (['Daily rate' => 'Daily rate · PHP', 'Work days' => 'Work days', 'Absence days' => 'Absences · days', 'Paid leave days' => 'Paid leave · days', 'Unpaid leave days' => 'Leave without pay (LWOP) · days'] as $field => $label) {
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
            [...$money('Basic pay', 'Basic pay subtotal'), 'quantity' => 'breakdown below', 'style' => 'basic'],
            [...$money('Work days pay', 'Work days pay'), 'style' => 'detail'],
            [...$money('Holiday pay', 'Holiday pay'), 'quantity' => PayrollData::dayCount($time['Holidays worked'] ?? 0).' days', 'style' => 'detail'],
            [...$money('Rest day pay', 'Rest day pay'), 'quantity' => PayrollData::dayCount($time['Rest days worked'] ?? 0).' days', 'style' => 'detail'],
            $money('Allowance', 'Allowances'),
            // Overtime only once approved; the statement rows carry no hours
            // or amount for an unapproved day, only the flag.
            ...(($tax['Overtime included in gross'] ?? 'Yes') === 'No' ? [] : [[...$money('Overtime pay', 'Overtime pay'), 'quantity' => self::hours($time['Overtime hours'] ?? 0).' hrs']]),
            $money('NSD', 'Night differential'),
            $money('Leave pay', 'Leave pay'),
            $money('Other earnings', 'Other earnings'),
        ];
        if (! $yearly) {
            if ((float) str_replace(',', '', (string) ($sourceEarnings['Attendance settlement'] ?? 0)) !== 0.0) {
                $earnings[] = $money('Prior cut-off adjustment', 'Attendance settlement');
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
            $deductions[] = ['label' => $label, 'value' => self::reportAmount($sourceDeductions[$field] ?? '—'), 'group' => self::TAX_AND_PREMIUMS];
        }
        if (! $yearly) {
            foreach ($groups['Deductions']['rows'] ?? [] as $row) {
                if (str_starts_with($row[0], 'Deduction detail: ')) {
                    $deductions[] = ['label' => self::issuedName($row[0], 'Deduction detail: '), 'value' => self::reportAmount($row[1])];
                }
            }
            $deductions[] = ['label' => 'Other deductions', 'value' => self::reportAmount($sourceDeductions['Other deductions'] ?? '—')];
            $deductions[] = ['label' => 'Undertime', 'value' => self::reportAmount($sourceDeductions['Undertime deduction'] ?? '—'), 'quantity' => PayrollData::dayCount($time['Undertime minutes'] ?? 0).' min'];
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
        $refund = self::reportNumber((string) ($sourceEarnings['Tax refund'] ?? '0'));

        return [
            ...self::slipFacts(self::reportDay($identity['Period from'] ?? ''), self::reportDay($identity['Period to'] ?? ''), $yearly, $earnings, $deductions),
            'employee' => $identity['Employee'] ?? $statement['title'], 'employeeId' => $identity['Employee ID'] ?? '—',
            'position' => $identity['Position'] ?? '—',
            'amounts' => ['gross' => self::reportNumber($totals['Gross pay'] ?? '0'), 'deductions' => self::reportNumber($totals['Total deductions'] ?? '0'), 'net' => self::reportNumber($totals['Net pay'] ?? '0')],
            'refund' => $refund > 0 ? self::amount($refund) : '',
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

    /** A statement's formatted amount as a number, for the month's footing. */
    private static function reportNumber(string $value): float
    {
        $number = str_replace([',', 'PHP ', '₱'], '', $value);

        return is_numeric($number) ? (float) $number : 0.0;
    }

    /** A statement's period key as a date; the owner query always writes one. */
    private static function reportDay(string $value): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: throw new InvalidArgumentException('A payslip statement has no period date.');
    }

    /** Amounts print bare: the peso sign belongs to the net pay line and the notes, not every cell. */
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
