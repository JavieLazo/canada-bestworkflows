<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Attendance\HolidaySetting;
use App\Models\PayrollPaySchedule;
use App\Modules\Organization\Contracts\HolidayCalendar;
use App\Modules\Payroll\Contracts\Views\PayScheduleView;
use App\Modules\Payroll\Domain\Schedules\PayCalendar;
use App\Modules\Payroll\Domain\Schedules\PaySchedule;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * The pay schedules as payroll reads them: whether the table exists yet
 * (the window before the cron migrates 2026_11_02_0004), the company's
 * default, a row's figures read strictly, and the holiday dates the pay
 * calendar moves a pay date off — the Holidays list expanded onto the
 * years a calendar asks about, through Organization's contract.
 */
final class PaySchedules
{
    private function __construct() {}

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('payroll_pay_schedules') && Schema::hasColumn('payroll_runs', 'pay_date'));
    }

    /** The company's default schedule, or null before one exists. */
    public static function default(): ?PayrollPaySchedule
    {
        if (! self::ready()) {
            return null;
        }

        return PayrollPaySchedule::query()->where('is_default', true)->orderBy('id')->first();
    }

    /** The schedule a row holds, as the pure rules read it, strictly. */
    public static function schedule(PayrollPaySchedule $row): PaySchedule
    {
        try {
            return PaySchedule::fromArray($row->params);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException('A pay schedule holds figures payroll cannot read: '.$e->getMessage());
        }
    }

    /**
     * The holiday dates of these years, as the pay calendar moves a pay
     * date off them: every one-time holiday in the years and every annual
     * holiday on each of them.
     *
     * @param  list<int>  $years
     * @return list<string>
     */
    public static function holidayDates(array $years): array
    {
        $dates = [];
        foreach (app(HolidayCalendar::class)->all() as $holiday) {
            if ($holiday->schedule === HolidaySetting::SCHEDULE_ONE_TIME) {
                if (in_array((int) substr($holiday->date, 0, 4), $years, true)) {
                    $dates[$holiday->date] = true;
                }

                continue;
            }
            foreach ($years as $year) {
                $dates[sprintf('%04d-%s', $year, substr($holiday->date, 5))] = true;
            }
        }
        $out = array_keys($dates);
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * The same dates with the holiday each one is, for a screen that says
     * what a pay date was moved off: date => name, in date order.
     *
     * @param  list<int>  $years
     * @return array<string, string>
     */
    public static function holidayNames(array $years): array
    {
        $names = [];
        foreach (app(HolidayCalendar::class)->all() as $holiday) {
            if ($holiday->schedule === HolidaySetting::SCHEDULE_ONE_TIME) {
                if (in_array((int) substr($holiday->date, 0, 4), $years, true)) {
                    $names[$holiday->date] ??= $holiday->name;
                }

                continue;
            }
            foreach ($years as $year) {
                $names[sprintf('%04d-%s', $year, substr($holiday->date, 5))] ??= $holiday->name;
            }
        }
        ksort($names, SORT_STRING);

        return $names;
    }

    /**
     * The schedule's period with exactly these dates, with the pay date and
     * contribution month a payroll on them takes, or null when the dates
     * are not one of its periods.
     *
     * @return array{start: string, end: string, pay_date: string, position: string, contribution_month: string, deadlines: list<array{label: string, date: string}>}|null
     */
    public static function periodFor(PayrollPaySchedule $row, string $start, string $end): ?array
    {
        return PayCalendar::periodFor(self::schedule($row), $start, $end, self::holidayDates(self::yearsAround($start)));
    }

    /**
     * The same, from a detached view (a screen's read).
     *
     * @return array{start: string, end: string, pay_date: string, position: string, contribution_month: string, deadlines: list<array{label: string, date: string}>}|null
     */
    public static function periodForView(PayScheduleView $view, string $start, string $end): ?array
    {
        try {
            $schedule = PaySchedule::fromArray($view->params);
        } catch (InvalidArgumentException) {
            return null;
        }

        return PayCalendar::periodFor($schedule, $start, $end, self::holidayDates(self::yearsAround($start)));
    }

    /**
     * The period whose pay day comes next on or after a day: what a new
     * payroll starts on. On a schedule paid some days after a period ends
     * that is the period that just ended; on one paid on a period's last
     * day it is the period the day falls in. Null for figures that do not
     * read.
     *
     * @return array{start: string, end: string, pay_date: string, position: string, contribution_month: string, deadlines: list<array{label: string, date: string}>}|null
     */
    public static function nextToPayForView(PayScheduleView $view, string $today): ?array
    {
        try {
            $schedule = PaySchedule::fromArray($view->params);
        } catch (InvalidArgumentException) {
            return null;
        }
        $holidays = self::holidayDates(self::yearsAround(sprintf('%04d-01-01', (int) substr($today, 0, 4) - 1)));
        $period = PayCalendar::containing($schedule, $today, $holidays);
        // A pay day is at most a month and a few days after its period
        // ends, so a handful of steps back always reaches a paid one.
        for ($i = 0; $i < 8; $i++) {
            $dayBefore = gmdate('Y-m-d', (int) strtotime($period['start'].' 00:00:00 UTC') - 86400);
            $before = PayCalendar::containing($schedule, $dayBefore, $holidays);
            if ($before['pay_date'] < $today) {
                break;
            }
            $period = $before;
        }

        return $period;
    }

    /**
     * Which pay of its year a pay date is on a schedule, and how many pay
     * dates that year has; null for figures that do not read.
     *
     * @return array{number: int, of: int, days: int|null, per_month: int|null}|null
     */
    public static function payOfYearForView(PayScheduleView $view, string $payDate): ?array
    {
        try {
            return self::payPlace(PaySchedule::fromArray($view->params), $payDate);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The same, from a schedule row (a system read).
     *
     * @return array{number: int, of: int, days: int|null, per_month: int|null}|null
     */
    public static function payOfYear(PayrollPaySchedule $row, string $payDate): ?array
    {
        try {
            return self::payPlace(self::schedule($row), $payDate);
        } catch (InvalidArgumentException|RuntimeException) {
            return null;
        }
    }

    /** @return array{number: int, of: int, days: int|null, per_month: int|null} */
    private static function payPlace(PaySchedule $schedule, string $payDate): array
    {
        $year = (int) substr($payDate, 0, 4);
        $holidays = self::holidayDates([$year - 1, $year, $year + 1]);

        return [
            'number' => PayCalendar::payNumber($schedule, $payDate, $holidays),
            'of' => PayCalendar::payDatesInYear($schedule, $year, $holidays),
            'days' => $schedule->isByWeeks() ? $schedule->periodDays() : null,
            'per_month' => $schedule->isByWeeks() ? null : count($schedule->periods),
        ];
    }

    /**
     * The schedule's next period after a day, with its pay date.
     *
     * @return array{start: string, end: string, pay_date: string, position: string, contribution_month: string, deadlines: list<array{label: string, date: string}>}
     */
    public static function nextAfter(PayrollPaySchedule $row, string $day): array
    {
        return PayCalendar::nextAfter(self::schedule($row), $day, self::holidayDates(self::yearsAround($day)));
    }

    /**
     * This year and the next, so a pay date rolling into January is moved
     * off January's holidays too.
     *
     * @return list<int>
     */
    public static function yearsAround(string $day): array
    {
        $year = (int) substr($day, 0, 4);

        return [$year, $year + 1];
    }
}
