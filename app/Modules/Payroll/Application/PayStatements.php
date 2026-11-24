<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Models\PayrollPaySchedule;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipDeduction;
use App\Models\PayslipIncentive;
use App\Modules\Payroll\Domain\Canada\TaxDetails;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * A posted payslip as a pay statement: each kind of earning with its
 * quantity and rate, the statutory deductions, the other deductions, each
 * with its total for the year up to and including that pay, and the net.
 *
 * NOTHING IS RECALCULATED: every amount is a posted column or a posted line,
 * and every year-to-date figure is a footing of posted ones over the
 * employee's payslips of the same tax year that end on or before this one.
 *
 * A system read over payslips the caller has ALREADY scoped; a screen reaches
 * it only through a query that checked its actor (`PayslipRecords`,
 * `PayrollReports`). The year's earlier payslips it adds up belong to the
 * same employee, whom the caller was allowed to read. The statement carries
 * no name: whoever draws it names the employee from their own scoped read.
 *
 * @phpstan-type Row array{label: string, note: string, quantity: ?float, unit: string, rate: ?float, amount: float, ytd: float}
 * @phpstan-type Statement array{payslip_id: string, employee_id: string, payroll_code: string, period_start: string, period_end: string, pay_date: string, year: int, pay_number: ?int, pays_in_year: ?int, province: string, earnings: list<Row>, gross: array{amount: float, ytd: float}, statutory: list<Row>, statutory_total: array{amount: float, ytd: float}, other: list<Row>, other_total: array{amount: float, ytd: float}, deductions: float, refund: float, net: float, employer: array{pension: float, insurance: float}|null}
 */
final class PayStatements
{
    /** The earnings a payslip carries as columns: column => [label, quantity column, unit, whether the daily rate is its rate]. */
    private const array EARNINGS = [
        'work_days_pay' => ['Regular Pay', 'work_days', 'days', true],
        'holiday_pay' => ['Holiday Pay', 'holiday_days', 'days', false],
        'rest_day_pay' => ['Rest Day Pay', 'rest_days', 'days', false],
        'allowances' => ['Allowance', null, '', false],
        'overtime_pay' => ['Overtime Pay', 'overtime_hours', 'hrs', false],
        'night_differential' => ['Night Shift Pay', null, '', false],
        'leave_pay' => ['Leave Pay', 'paid_leave_days', 'days', false],
        'other_earnings' => ['Other Earnings', null, '', false],
        'settlement' => ['Earlier Period Adjustment', null, '', false],
        'thirteenth_month' => ['13th Month Pay', null, '', false],
    ];

    /** The statutory deductions: label => the columns it adds up. The last four are amounts posted before the Canadian deductions. */
    private const array STATUTORY = [
        'Canada Pension Plan (CPP)' => ['cpp', 'cpp2'],
        'Employment Insurance (EI)' => ['ei'],
        'Federal Income Tax' => ['federal_tax'],
        'Provincial Income Tax' => ['provincial_tax'],
        'SSS' => ['sss'],
        'PhilHealth' => ['philhealth'],
        'Pag-IBIG' => ['pagibig'],
        'Withholding Tax' => ['withholding_tax'],
    ];

    /** The other deductions a payslip carries as columns. */
    private const array OTHER = [
        'other_deductions' => 'Other Deductions',
        'late_deduction' => 'Late',
        'undertime_deduction' => 'Undertime',
        'late_undertime_deduction' => 'Late and Undertime',
        'absence_deduction' => 'Absences',
        'unpaid_leave_deduction' => 'Leave Without Pay',
    ];

    private function __construct() {}

    /**
     * @param  Collection<int, Payslip>  $payslips  already scoped by the caller
     * @param  bool  $own  the employee's own copy: without what the employer pays
     * @return array<string, Statement> keyed by payslip id
     */
    public static function build(Collection $payslips, bool $own): array
    {
        if ($payslips->isEmpty()) {
            return [];
        }

        // Every payslip of the same employees and tax years: what the
        // year-to-date figures are footed from.
        $years = [];
        foreach ($payslips as $payslip) {
            $years[self::yearOf($payslip)][$payslip->employee_id] = true;
        }
        $history = new Collection;
        foreach ($years as $year => $employees) {
            $history = $history->concat(
                SourceDeductionFacts::inYear(Payslip::query()->whereIn('employee_id', array_keys($employees)), (int) $year)
                    ->orderBy('period_end')->orderBy('id')->get()
            );
        }
        $history = $history->unique('id')->values();
        $historyIds = $history->pluck('id')->all();
        $incentives = self::lines(PayslipIncentive::query()->whereIn('payslip_id', $historyIds)->orderBy('position')->get(['payslip_id', 'name', 'amount']));
        $deductions = self::lines(PayslipDeduction::query()->whereIn('payslip_id', $historyIds)->orderBy('position')->get(['payslip_id', 'name', 'amount']));
        $runs = PayrollRun::query()->whereIn('id', $payslips->pluck('run_id')->unique()->all())->get()->keyBy('id');
        $schedules = PaySchedules::ready()
            ? PayrollPaySchedule::query()->whereIn('id', $runs->pluck('schedule_id')->filter()->unique()->all())->get()->keyBy('id')
            : new Collection;

        $out = [];
        foreach ($payslips as $payslip) {
            $year = self::yearOf($payslip);
            $end = $payslip->period_end->format('Y-m-d');
            $toDate = $history->filter(static fn (Payslip $row): bool => $row->employee_id === $payslip->employee_id
                && self::yearOf($row) === $year
                && ($row->period_end->format('Y-m-d') < $end || ($row->period_end->format('Y-m-d') === $end && $row->id <= $payslip->id)));
            $sum = static fn (string ...$columns): float => Money::round($toDate->sum(static fn (Payslip $row): float => array_sum(array_map(static fn (string $column): float => (float) $row->{$column}, $columns))));
            $own_ = static fn (string ...$columns): float => Money::round(array_sum(array_map(static fn (string $column): float => (float) $payslip->{$column}, $columns)));

            $run = $runs->get($payslip->run_id);
            $payDate = $run?->pay_date?->format('Y-m-d') ?? $end;
            $schedule = $run?->schedule_id === null ? null : $schedules->get($run->schedule_id);
            $place = $schedule !== null && PaySchedules::periodFor($schedule, $payslip->period_start->format('Y-m-d'), $end) !== null
                ? PaySchedules::payOfYear($schedule, $payDate)
                : null;

            // Earnings: the columns, then each named line.
            $earnings = [];
            foreach (self::EARNINGS as $column => [$label, $quantity, $unit, $daily]) {
                // Overtime counts only where it was approved: an undecided day paid
                // nothing, on this payslip and on every earlier one of the year.
                $amount = $column === 'overtime_pay' && ! $payslip->overtime_acknowledged ? 0.0 : (float) $payslip->{$column};
                $ytd = $column === 'overtime_pay'
                    ? Money::round($toDate->sum(static fn (Payslip $row): float => $row->overtime_acknowledged ? (float) $row->overtime_pay : 0.0))
                    : $sum($column);
                if ($amount === 0.0 && $ytd === 0.0) {
                    continue;
                }
                $earnings[] = [
                    'label' => $label, 'note' => '',
                    'quantity' => $quantity === null || $amount === 0.0 ? null : (float) $payslip->{$quantity},
                    'unit' => $unit,
                    'rate' => $daily && (float) $payslip->daily_rate > 0.0 ? (float) $payslip->daily_rate : null,
                    'amount' => $amount, 'ytd' => $ytd,
                ];
            }
            $named = self::named($incentives, $toDate, $payslip->id);
            foreach ($named as $name => [$amount, $ytd]) {
                $earnings[] = ['label' => $name, 'note' => '', 'quantity' => null, 'unit' => '', 'rate' => null, 'amount' => $amount, 'ytd' => $ytd];
            }
            // A bonus posted with no lines of its own (payslips from before the lines were kept).
            if ($named === [] && ((float) $payslip->bonus !== 0.0)) {
                $earnings[] = ['label' => 'Bonus', 'note' => '', 'quantity' => null, 'unit' => '', 'rate' => null, 'amount' => (float) $payslip->bonus, 'ytd' => $sum('bonus')];
            }

            $province = TaxDetails::DEFAULT_PROVINCE;
            $statutory = [];
            foreach (self::STATUTORY as $label => $columns) {
                $amount = $own_(...$columns);
                $ytd = $sum(...$columns);
                // The four Canadian lines always show; an earlier system's only where it posted something.
                if ($amount === 0.0 && $ytd === 0.0 && ! in_array($columns[0], ['cpp', 'ei', 'federal_tax', 'provincial_tax'], true)) {
                    continue;
                }
                $statutory[] = [
                    'label' => $label === 'Provincial Income Tax' ? "Provincial Income Tax ({$province})" : $label,
                    'note' => $columns === ['cpp', 'cpp2'] && (float) $payslip->cpp2 > 0.0 ? 'includes the second CPP contribution of '.number_format((float) $payslip->cpp2, 2) : '',
                    'quantity' => null, 'unit' => '', 'rate' => null, 'amount' => $amount, 'ytd' => $ytd,
                ];
            }

            $other = [];
            foreach (self::named($deductions, $toDate, $payslip->id) as $name => [$amount, $ytd]) {
                $other[] = ['label' => $name, 'note' => '', 'quantity' => null, 'unit' => '', 'rate' => null, 'amount' => $amount, 'ytd' => $ytd];
            }
            foreach (self::OTHER as $column => $label) {
                $amount = (float) $payslip->{$column};
                $ytd = $sum($column);
                if ($amount === 0.0 && $ytd === 0.0) {
                    continue;
                }
                $minutes = match ($column) {
                    'late_deduction' => (float) $payslip->late_minutes,
                    'undertime_deduction' => (float) $payslip->undertime_minutes,
                    default => null,
                };
                $other[] = ['label' => $label, 'note' => '', 'quantity' => $minutes === null || $amount === 0.0 ? null : $minutes, 'unit' => 'min', 'rate' => null, 'amount' => $amount, 'ytd' => $ytd];
            }

            $out[$payslip->id] = [
                'payslip_id' => $payslip->id,
                'employee_id' => $payslip->employee_id,
                'payroll_code' => (string) ($run->code ?? ''),
                'period_start' => $payslip->period_start->format('Y-m-d'),
                'period_end' => $end,
                'pay_date' => $payDate,
                'year' => $year,
                'pay_number' => $place['number'] ?? null,
                'pays_in_year' => $place['of'] ?? null,
                'province' => $province,
                'earnings' => $earnings,
                'gross' => ['amount' => (float) $payslip->gross_pay, 'ytd' => $sum('gross_pay')],
                'statutory' => $statutory,
                'statutory_total' => self::footing($statutory),
                'other' => $other,
                'other_total' => self::footing($other),
                'deductions' => (float) $payslip->total_deductions,
                'refund' => (float) ($payslip->tax_refund ?? 0.0),
                'net' => (float) $payslip->net_pay,
                'employer' => $own ? null : [
                    'pension' => Money::round((float) $payslip->cpp_er + (float) $payslip->cpp2_er),
                    'insurance' => (float) $payslip->ei_er,
                ],
            ];
        }

        return $out;
    }

    /** The tax year a payslip counts in: its own, or the year its period ended for one posted before the column. */
    private static function yearOf(Payslip $payslip): int
    {
        return (int) ($payslip->tax_year ?? $payslip->period_end->format('Y'));
    }

    /**
     * Named lines by name: what this payslip posted and the year's total,
     * the payslip's own names first in their posted order, then the names
     * only earlier payslips carry.
     *
     * @param  array<string, list<array{name: string, amount: float}>>  $lines  by payslip id
     * @param  Collection<int, Payslip>  $toDate
     * @return array<string, array{float, float}>
     */
    private static function named(array $lines, Collection $toDate, string $payslipId): array
    {
        $out = [];
        foreach ($lines[$payslipId] ?? [] as $line) {
            $out[$line['name']] = [Money::round(($out[$line['name']][0] ?? 0.0) + $line['amount']), 0.0];
        }
        foreach ($toDate as $row) {
            foreach ($lines[$row->id] ?? [] as $line) {
                $out[$line['name']] ??= [0.0, 0.0];
                $out[$line['name']][1] = Money::round($out[$line['name']][1] + $line['amount']);
            }
        }

        return $out;
    }

    /**
     * Posted lines as plain rows by payslip id, in their posted order.
     *
     * @param  iterable<PayslipIncentive|PayslipDeduction>  $rows
     * @return array<string, list<array{name: string, amount: float}>>
     */
    private static function lines(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->payslip_id][] = ['name' => (string) $row->name, 'amount' => (float) $row->amount];
        }

        return $out;
    }

    /**
     * @param  list<Row>  $rows
     * @return array{amount: float, ytd: float}
     */
    private static function footing(array $rows): array
    {
        return [
            'amount' => Money::round(array_sum(array_column($rows, 'amount'))),
            'ytd' => Money::round(array_sum(array_column($rows, 'ytd'))),
        ];
    }
}
