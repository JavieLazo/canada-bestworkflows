<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Models\PayrollPaySchedule;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Modules\Payroll\Domain\Canada\DeductionRates;
use App\Modules\Payroll\Domain\Schedules\PayCalendar;
use App\Modules\Payroll\Domain\Schedules\PaySchedule;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * What a payroll's source deductions are worked out from, read once and
 * captured with the payroll so a replay never reads the live tables:
 *
 *  - the pay date and the tax year it falls in (a period that ends in
 *    December and is paid in January counts in the new year);
 *  - how many pay dates that year has on the payroll's pay schedule, which
 *    the tax for one pay is annualized by;
 *  - the published rates in force on the pay date;
 *  - each member's tax details, and what the year's posted payslips have
 *    already taken from them, which is what stops a contribution at its
 *    yearly maximum.
 */
final class SourceDeductionFacts
{
    private function __construct() {}

    /** Whether the payslip columns exist yet: files reach a host before its migration runs. */
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('payslips', 'cpp') && Schema::hasColumn('payslips', 'tax_year'));
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array{pay_date: string, tax_year: int, periods: int, rates_from: string, rates: array<string, mixed>, members: array<string, array{details: array<string, mixed>, to_date: array{pensionable: float, pension: float, second_pension: float, insurance: float}}>}|null
     *                                                                                                                                                                                                                                                                               null before the columns exist
     */
    public static function entry(array $employeeIds, ?PayrollRun $run, ?string $payDate, string $periodEnd): ?array
    {
        if (! self::ready()) {
            return null;
        }

        $paidOn = $payDate ?? $periodEnd;
        $year = (int) substr($paidOn, 0, 4);
        try {
            $rates = DeductionRates::inForceOn($paidOn);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException('This payroll\'s pay date is before the earliest deduction figures the system holds. '.$e->getMessage());
        }

        $details = EmployeeTaxDetails::forEmployees($employeeIds);
        $posted = self::posted($employeeIds, $year);
        $members = [];
        foreach ($employeeIds as $id) {
            $members[$id] = [
                'details' => $details[$id]->toArray(),
                'to_date' => $posted[$id] ?? ['pensionable' => 0.0, 'pension' => 0.0, 'second_pension' => 0.0, 'insurance' => 0.0],
            ];
        }

        return [
            'pay_date' => $paidOn,
            'tax_year' => $year,
            'periods' => self::periods($run, $year),
            'rates_from' => $rates->effectiveFrom,
            'rates' => $rates->toArray(),
            'members' => $members,
        ];
    }

    /**
     * What the year's posted payslips already took from each employee. A
     * payslip exists only once its payroll is finalized, so a draft never
     * counts; a payslip with no tax year (posted before the column) counts
     * in the year its period ended.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array{pensionable: float, pension: float, second_pension: float, insurance: float}>
     */
    public static function posted(array $employeeIds, int $year): array
    {
        if (! self::ready() || $employeeIds === []) {
            return [];
        }

        $rows = self::inYear(Payslip::query()->whereIn('employee_id', $employeeIds), $year)
            ->get(['employee_id', 'pensionable_earnings', 'cpp', 'cpp2', 'ei']);

        $out = [];
        foreach ($rows as $row) {
            $sums = $out[$row->employee_id] ?? ['pensionable' => 0.0, 'pension' => 0.0, 'second_pension' => 0.0, 'insurance' => 0.0];
            $sums['pensionable'] += (float) $row->pensionable_earnings;
            $sums['pension'] += (float) $row->cpp;
            $sums['second_pension'] += (float) $row->cpp2;
            $sums['insurance'] += (float) $row->ei;
            $out[$row->employee_id] = $sums;
        }

        return array_map(static fn (array $sums): array => array_map(static fn (float $sum): float => Money::round($sum), $sums), $out);
    }

    /**
     * A payslip query narrowed to the payslips that count in a tax year:
     * those posted with that year, and those posted before the column whose
     * period ended in it.
     *
     * @param  Builder<Payslip>  $query
     * @return Builder<Payslip>
     */
    public static function inYear(Builder $query, int $year): Builder
    {
        return $query->where(static function ($outer) use ($year): void {
            $outer->where('tax_year', $year)
                ->orWhere(static fn ($inner) => $inner->whereNull('tax_year')->whereBetween('period_end', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]));
        });
    }

    /**
     * How many pay dates the year has on the payroll's schedule: the run's
     * own, else the company's default, else two a month.
     */
    public static function periods(?PayrollRun $run, int $year): int
    {
        $row = null;
        if (PaySchedules::ready()) {
            $row = $run?->schedule_id === null ? PaySchedules::default() : PayrollPaySchedule::query()->whereKey($run->schedule_id)->first();
            $row ??= PaySchedules::default();
        }
        $schedule = $row === null ? PaySchedule::standard() : PaySchedules::schedule($row);
        $count = PayCalendar::payDatesInYear($schedule, $year, PaySchedules::ready() ? PaySchedules::holidayDates([$year - 1, $year, $year + 1]) : []);

        return $count > 0 ? $count : $schedule->usualPaysPerYear();
    }
}
