<?php

declare(strict_types=1);

namespace App\Livewire\Payroll\Support;

use App\Domain\Payroll\PayslipDraft;
use App\Livewire\Records\Support\Hris;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Contracts\Views\PayslipSettlementView;
use App\Modules\Payroll\Contracts\Views\PayslipView;
use App\Modules\Payroll\Domain\PayrollAdvance;

/**
 * How the payroll workspace reads an ADVANCE PAYROLL (PayrollAdvance): the
 * days a draft pays ahead of their attendance, and the settlement each line
 * carries for earlier ones — the "Why this amount?" explanation of a
 * Settlement cell. Presentation only: every figure comes from the draft's
 * calculation (`settlementLines`) or the posted settlement rows.
 */
final class AdvanceText
{
    private function __construct() {}

    /**
     * The panel above the grid: the counted date, the presumed days and who
     * is settled on this payroll; null when nothing is advanced or settled.
     *
     * @param  iterable<PayslipDraft|PayslipView>  $lines  the drafts or posted payslips
     * @param  array<string, ?string>  $countedThrough  each member's own counted date (PayrollAdvance::countedThrough); `earlier` counts those pulled back before the run's
     * @return array{through: ?string, from: ?string, to: ?string, days: int, members: int, settled: int, total: float, earlier: int}|null
     */
    public static function summary(?string $through, string $end, iterable $lines, array $countedThrough = []): ?array
    {
        $range = PayrollAdvance::presumedRange($through, $end);
        $days = 0;
        $members = 0;
        $settled = 0;
        $total = 0.0;
        $earlier = 0;
        foreach ($countedThrough as $date) {
            if ($through !== null && $date !== null && $date < $through) {
                $earlier++;
            }
        }
        foreach ($lines as $line) {
            $presumed = (float) ($line->presumed_days ?? 0.0);
            if ($presumed > 0) {
                $days += (int) $presumed;
                $members++;
            }
            $settlement = (float) ($line->settlement ?? 0.0);
            if ($settlement !== 0.0) {
                $settled++;
                $total += $settlement;
            }
        }
        if ($range === null && $settled === 0) {
            return null;
        }

        return ['through' => $through, 'from' => $range['from'] ?? null, 'to' => $range['to'] ?? null, 'days' => $days, 'members' => $members, 'settled' => $settled, 'total' => $total, 'earlier' => $earlier];
    }

    /**
     * "Why this amount?" for one member's Settlement cell: one step per
     * settled payslip — the days, what was paid in advance, what they turned
     * out to be worth — each used.
     *
     * @param  list<array{payslip_id: string, run_code: string, from: string, to: string, presumed: array<string, float>, actual: array<string, float>, presumed_amount: float, actual_amount: float, amount: float}>  $lines
     * @return array{title: string, amount: float, steps: list<array{label: string, detail: string, state: string}>}
     */
    public static function explain(string $employeeName, float $amount, array $lines): array
    {
        $steps = [];
        foreach ($lines as $line) {
            $steps[] = [
                'label' => 'Prior cut-off adjustment for '.self::days($line['from'], $line['to']).' · payroll '.$line['run_code'],
                'detail' => 'Paid as scheduled '.Hris::peso($line['presumed_amount']).' for '.self::dayCount($line['presumed']['work_days'] ?? 0.0).' scheduled '.(($line['presumed']['work_days'] ?? 0.0) == 1.0 ? 'day' : 'days')
                    .'; from attendance the days are worth '.Hris::peso($line['actual_amount']).self::changes($line['actual']).'. Difference '.Hris::peso($line['amount']).'.',
                'state' => 'used',
            ];
        }
        if ($steps === []) {
            $steps[] = ['label' => 'Nothing to adjust', 'detail' => 'No earlier payslip of theirs has days paid as scheduled that are not yet adjusted.', 'state' => 'unused'];
        }

        return ['title' => 'Prior cut-off adjustment · '.$employeeName, 'amount' => $amount, 'steps' => $steps];
    }

    /**
     * A finalized run's settlements in the shape the draft's calculation
     * returns them, keyed by employee, so the view reads one shape.
     *
     * @param  array<string, list<PayslipSettlementView>>  $settlements  payslip id => rows
     * @param  iterable<PayslipView>  $payslips
     * @return array<string, list<array{payslip_id: string, run_code: string, from: string, to: string, presumed: array<string, float>, actual: array<string, float>, presumed_amount: float, actual_amount: float, amount: float}>>
     */
    public static function postedLines(array $settlements, iterable $payslips): array
    {
        $lines = [];
        foreach ($payslips as $payslip) {
            foreach ($settlements[$payslip->id] ?? [] as $row) {
                $lines[$payslip->employee_id][] = [
                    'payslip_id' => $row->settled_payslip_id, 'run_code' => $row->settled_code,
                    'from' => $row->from_date->format('Y-m-d'), 'to' => $row->to_date->format('Y-m-d'),
                    'presumed' => $row->details['presumed'] ?? [], 'actual' => $row->details['actual'] ?? [],
                    'presumed_amount' => $row->presumed_amount, 'actual_amount' => $row->actual_amount, 'amount' => $row->amount,
                ];
            }
        }

        return $lines;
    }

    /** "12 Oct 2026 to 15 Oct 2026", or one day. */
    public static function days(string $from, string $to): string
    {
        return $from === $to ? PayrollPeriodText::day($from) : PayrollPeriodText::day($from).' to '.PayrollPeriodText::day($to);
    }

    /**
     * What the days turned out to hold, for the explanation: ", 1 absence,
     * 45 min late" — '' when they were worked as scheduled.
     *
     * @param  array<string, float>  $actual
     */
    private static function changes(array $actual): string
    {
        $parts = [];
        $absences = (float) ($actual['absence_days'] ?? 0.0);
        if ($absences > 0) {
            $parts[] = self::dayCount($absences).($absences == 1.0 ? ' absence' : ' absences');
        }
        $leave = (float) ($actual['paid_leave_days'] ?? 0.0);
        if ($leave > 0) {
            $parts[] = self::dayCount($leave).($leave == 1.0 ? ' day' : ' days').' of paid leave';
        }
        if ((float) ($actual['late_deduction'] ?? 0.0) > 0 || (float) ($actual['undertime_deduction'] ?? 0.0) > 0) {
            $parts[] = Hris::peso((float) ($actual['late_deduction'] ?? 0.0) + (float) ($actual['undertime_deduction'] ?? 0.0)).' of lateness and undertime';
        }
        if ((float) ($actual['overtime_pay'] ?? 0.0) > 0) {
            $parts[] = Hris::peso((float) $actual['overtime_pay']).' of approved overtime';
        }
        if ((float) ($actual['night_differential'] ?? 0.0) > 0) {
            $parts[] = Hris::peso((float) $actual['night_differential']).' of night pay';
        }

        return $parts === [] ? ' (worked as scheduled)' : ' ('.implode(', ', $parts).')';
    }

    private static function dayCount(mixed $value): string
    {
        return PayrollData::dayCount($value);
    }
}
