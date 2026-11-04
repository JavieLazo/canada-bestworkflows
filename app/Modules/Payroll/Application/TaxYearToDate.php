<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Enums\PayrollRunKind;
use App\Models\PayrollPaySchedule;
use App\Models\PayrollRun;
use App\Models\PayrollTaxBalance;
use App\Models\Payslip;
use App\Modules\Payroll\Domain\Schedules\PayCalendar;
use App\Modules\Payroll\Domain\Schedules\PaySchedule;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * What a TAX YEAR already holds for an employee (DIVERGENCE 20): the
 * taxable pay and the withholding of the year's FINALIZED payslips —
 * regular and 13th month, each in the year of its pay date (its period's
 * last day on a run made without one; the posted `payslips.tax_year` once
 * written) — plus the APPROVED opening balances and previous-employer
 * figures recorded for the year (`payroll_tax_balances`). A draft never
 * counts. The whole-year withholding estimate takes the year's remaining
 * tax from these; they are captured in the evidence, so a payslip posted
 * meanwhile stales the preview and a replay never reads the live tables.
 */
final class TaxYearToDate
{
    private function __construct() {}

    /** Whether the payslip columns exist yet (the window before the cron migrates 2026_11_02_0011). */
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('payslips', 'tax_year'));
    }

    /** Whether the balances table exists yet (the window before the cron migrates 2026_11_02_0012). */
    public static function balancesReady(): bool
    {
        return once(static fn (): bool => Schema::hasTable('payroll_tax_balances'));
    }

    /** The tax year a payslip falls in: the year of its pay date, else of its period's last day. */
    public static function yearOf(?string $payDate, string $periodEnd): int
    {
        return (int) substr($payDate ?? $periodEnd, 0, 4);
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, array{taxable: float, withheld: float}> employee id => the year's posted figures; employees with nothing posted are absent
     */
    public static function posted(array $employeeIds, int $year): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $out = [];
        $ready = self::ready();
        $query = Payslip::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('period_end', [sprintf('%04d-01-01', $year - 1), sprintf('%04d-12-31', $year)])
            ->where(static fn (Builder $q) => $q
                ->whereNull('run_id')
                ->orWhereHas('run', static fn (Builder $run) => $run->whereIn('kind', [PayrollRunKind::REGULAR, PayrollRunKind::THIRTEENTH_MONTH])->where('status', 'FINALIZED')))
            ->with('run:id,pay_date,period_end');
        foreach ($query->get() as $payslip) {
            $taxYear = $ready && $payslip->tax_year !== null
                ? (int) $payslip->tax_year
                : self::yearOf($payslip->run?->pay_date?->format('Y-m-d'), $payslip->period_end->format('Y-m-d'));
            if ($taxYear !== $year) {
                continue;
            }
            $sums = $out[$payslip->employee_id] ?? ['taxable' => 0.0, 'withheld' => 0.0];
            // A payslip posted before the base was recorded counts its gross less the shares, as the engine taxed it.
            $taxable = $payslip->taxable_compensation ?? max(0.0, $payslip->gross_pay - $payslip->sss - $payslip->philhealth - $payslip->pagibig - $payslip->late_deduction - $payslip->undertime_deduction);
            $sums['taxable'] = Money::round($sums['taxable'] + $taxable);
            $sums['withheld'] = Money::round($sums['withheld'] + $payslip->withholding_tax - (float) ($ready ? ($payslip->tax_refund ?? 0.0) : 0.0));
            $out[$payslip->employee_id] = $sums;
        }
        if (self::balancesReady()) {
            $balances = PayrollTaxBalance::query()->whereIn('employee_id', $employeeIds)->where('tax_year', $year)->where('status', PayrollTaxBalance::APPROVED)->get();
            foreach ($balances as $balance) {
                $sums = $out[$balance->employee_id] ?? ['taxable' => 0.0, 'withheld' => 0.0];
                $sums['taxable'] = Money::round($sums['taxable'] + $balance->taxable_income);
                $sums['withheld'] = Money::round($sums['withheld'] + $balance->tax_withheld);
                $out[$balance->employee_id] = $sums;
            }
        }

        return $out;
    }

    /**
     * The regular payrolls still to come in the tax year after the one
     * ending on `$periodEnd`: the run's pay schedule's periods — the
     * company's default, else the standard 1st–15th / 16th–end — whose
     * start is after the period and whose pay date falls in the year.
     * Nobody types the number.
     */
    public static function payrollsLeft(?PayrollRun $run, string $periodEnd, int $year): int
    {
        $row = $run?->schedule_id === null ? PaySchedules::default() : PayrollPaySchedule::query()->whereKey($run->schedule_id)->first();
        $schedule = $row === null ? PaySchedule::standard() : PaySchedules::schedule($row);
        $holidays = PaySchedules::ready() ? PaySchedules::holidayDates([$year, $year + 1]) : [];
        $left = 0;
        $y = (int) substr($periodEnd, 0, 4);
        $m = (int) substr($periodEnd, 5, 2);
        for ($i = 0; $i < 14 && $y <= $year; $i++) {
            foreach (PayCalendar::month($schedule, $y, $m, $holidays) as $period) {
                if ($period['start'] > $periodEnd && (int) substr($period['pay_date'], 0, 4) === $year) {
                    $left++;
                }
            }
            if (++$m > 12) {
                $m = 1;
                $y++;
            }
        }

        return $left;
    }

    /**
     * The evidence entry for a payroll whose members include a whole-year
     * employee: the year, the payrolls left after this one and each such
     * member's posted figures.
     *
     * @param  list<string>  $employeeIds  the members priced by the whole-year estimate
     * @return array{year: int, payrolls_left: int, posted: array<string, array{taxable: float, withheld: float}>}
     */
    public static function entry(array $employeeIds, ?PayrollRun $run, ?string $payDate, string $periodEnd): array
    {
        $year = self::yearOf($payDate, $periodEnd);

        return ['year' => $year, 'payrolls_left' => self::payrollsLeft($run, $periodEnd, $year), 'posted' => self::posted($employeeIds, $year)];
    }
}
