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
