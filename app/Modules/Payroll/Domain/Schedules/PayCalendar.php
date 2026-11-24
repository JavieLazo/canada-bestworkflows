<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Schedules;

/**
 * The pay calendar a schedule makes: for a month, each salary period with
 * its pay date — the schedule's pay day, moved off a Saturday, Sunday or
 * holiday as the schedule says — its deadlines counted back from the pay
 * date and its place in the month (FIRST, LAST or ONLY, which decides
 * which payroll carries a month's whole-month deductions). A period's
 * dates are 'Y-m-d' strings compared as the rest of payroll compares them.
 *
 * A schedule that runs by weeks has no months to cut: its periods follow
 * one another from the schedule's anchor day, each is the ONLY one of its
 * kind (no first or last of a month), and "the month's periods" means the
 * periods that START in that month, so walking the months meets each period
 * once.
 *
 * Pure: the holidays are handed in as dates, the clock never read. Day
 * arithmetic is integer epoch days, as PayrollPeriod does it.
 */
final class PayCalendar
{
    public const string FIRST = 'FIRST';

    public const string LAST = 'LAST';

    public const string MIDDLE = 'MIDDLE';

    public const string ONLY = 'ONLY';

    private function __construct() {}

    /**
     * The month's periods, in order.
     *
     * @param  list<string>  $holidays  'Y-m-d' dates a pay date moves off
     * @return list<array{start: string, end: string, pay_date: string, position: string, deadlines: list<array{label: string, date: string}>}>
     */
    public static function month(PaySchedule $schedule, int $year, int $month, array $holidays = []): array
    {
        if ($schedule->isByWeeks()) {
            return self::weeksStartingIn($schedule, $year, $month, $holidays);
        }
        $out = [];
        $count = count($schedule->periods);
        $lastDay = self::daysInMonth($year, $month);
        foreach ($schedule->periods as $index => $period) {
            $start = sprintf('%04d-%02d-%02d', $year, $month, $period['start']);
            $end = sprintf('%04d-%02d-%02d', $year, $month, $period['end'] ?? $lastDay);
            $payDate = self::movedOff(self::payDay($period['pay_day'], $year, $month, $end), $schedule->holidayRule, $holidays);
            $position = $count === 1 ? self::ONLY : ($index === 0 ? self::FIRST : ($index === $count - 1 ? self::LAST : self::MIDDLE));
            $deadlines = [];
            foreach ($schedule->deadlines as $deadline) {
                $deadlines[] = ['label' => $deadline['label'], 'date' => self::addDays($payDate, -$deadline['days_before'])];
            }
            $out[] = [
                'start' => $start,
                'end' => $end,
                'pay_date' => $payDate,
                'position' => $position,
                'deadlines' => $deadlines,
            ];
        }

        return $out;
    }

    /**
     * The schedule's period with exactly these dates, or null when the
     * dates are not one of its periods.
     *
     * @param  list<string>  $holidays
     * @return array{start: string, end: string, pay_date: string, position: string, deadlines: list<array{label: string, date: string}>}|null
     */
    public static function periodFor(PaySchedule $schedule, string $start, string $end, array $holidays = []): ?array
    {
        if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $start, $m) !== 1) {
            return null;
        }
        if ($schedule->isByWeeks()) {
            $period = self::weekPeriodAt($schedule, self::toEpochDay($start), $holidays);

            return $period['start'] === $start && $period['end'] === $end ? $period : null;
        }
        foreach (self::month($schedule, (int) $m[1], (int) $m[2], $holidays) as $period) {
            if ($period['start'] === $start && $period['end'] === $end) {
                return $period;
            }
        }

        return null;
    }

    /**
     * The first period of the schedule starting after a day — the next
     * payroll after the last one paid.
     *
     * @param  list<string>  $holidays
     * @return array{start: string, end: string, pay_date: string, position: string, deadlines: list<array{label: string, date: string}>}
     */
    public static function nextAfter(PaySchedule $schedule, string $day, array $holidays = []): array
    {
        if ($schedule->isByWeeks()) {
            $containing = self::weekPeriodAt($schedule, self::toEpochDay($day), $holidays);

            return self::weekPeriodAt($schedule, self::toEpochDay($containing['start']) + $schedule->periodDays(), $holidays);
        }
        $year = (int) substr($day, 0, 4);
        $month = (int) substr($day, 5, 2);
        for ($i = 0; $i < 3; $i++) {
            foreach (self::month($schedule, $year, $month, $holidays) as $period) {
                if ($period['start'] > $day) {
                    return $period;
                }
            }
            if (++$month > 12) {
                $month = 1;
                $year++;
            }
        }

        // Unreachable: a schedule always has a period starting in the month after any day.
        return self::month($schedule, $year, $month, $holidays)[0];
    }

    /**
     * The periods whose pay date, or any deadline, falls within `$days` of
     * today and on or after it — what a reminder is about — across this
     * month and the next.
     *
     * @param  list<string>  $holidays
     * @return list<array{start: string, end: string, pay_date: string, position: string, deadlines: list<array{label: string, date: string}>}>
     */
    public static function upcoming(PaySchedule $schedule, string $today, int $days, array $holidays = []): array
    {
        $year = (int) substr($today, 0, 4);
        $month = (int) substr($today, 5, 2);
        $limit = self::addDays($today, $days);
        $out = [];
        if ($schedule->isByWeeks()) {
            // A pay date follows its period and a deadline precedes its pay
            // date, so the periods to look at reach a little before today
            // and a little past the limit.
            $reach = PaySchedule::MAX_PAY_AFTER_DAYS + PaySchedule::MAX_DEADLINE_DAYS + $schedule->periodDays();
            $day = self::toEpochDay(self::weekPeriodAt($schedule, self::toEpochDay($today) - $reach, $holidays)['start']);
            $last = self::toEpochDay($limit) + $reach;
            for (; $day <= $last; $day += $schedule->periodDays()) {
                $period = self::weekPeriodAt($schedule, $day, $holidays);
                foreach ([$period['pay_date'], ...array_column($period['deadlines'], 'date')] as $date) {
                    if ($date >= $today && $date <= $limit) {
                        $out[] = $period;
                        break;
                    }
                }
            }

            return $out;
        }
        for ($i = 0; $i < 2; $i++) {
            foreach (self::month($schedule, $year, $month, $holidays) as $period) {
                $dates = [$period['pay_date'], ...array_column($period['deadlines'], 'date')];
                foreach ($dates as $date) {
                    if ($date >= $today && $date <= $limit) {
                        $out[] = $period;
                        break;
                    }
                }
            }
            if (++$month > 12) {
                $month = 1;
                $year++;
            }
        }

        return $out;
    }

    /**
     * The period a day falls in.
     *
     * @param  list<string>  $holidays
     * @return array{start: string, end: string, pay_date: string, position: string, deadlines: list<array{label: string, date: string}>}
     */
    public static function containing(PaySchedule $schedule, string $day, array $holidays = []): array
    {
        if ($schedule->isByWeeks()) {
            return self::weekPeriodAt($schedule, self::toEpochDay($day), $holidays);
        }
        foreach (self::month($schedule, (int) substr($day, 0, 4), (int) substr($day, 5, 2), $holidays) as $period) {
            if ($period['start'] <= $day && $day <= $period['end']) {
                return $period;
            }
        }

        // Unreachable: a month-cut schedule covers every day of every month.
        return self::month($schedule, (int) substr($day, 0, 4), (int) substr($day, 5, 2), $holidays)[0];
    }

    /**
     * The period a day falls in and the ones after it, `$count` in all.
     *
     * @param  list<string>  $holidays
     * @return list<array{start: string, end: string, pay_date: string, position: string, deadlines: list<array{label: string, date: string}>}>
     */
    public static function following(PaySchedule $schedule, string $day, int $count, array $holidays = []): array
    {
        $out = [];
        $period = self::containing($schedule, $day, $holidays);
        for ($i = 0; $i < $count; $i++) {
            $out[] = $period;
            $period = self::nextAfter($schedule, $period['start'], $holidays);
        }

        return $out;
    }

    /**
     * How many pay dates fall in a calendar year: what a pay's tax is
     * annualized by. A two-week schedule gives 26 in most years and 27 in
     * a year its pay dates fit one more time.
     *
     * @param  list<string>  $holidays
     */
    public static function payDatesInYear(PaySchedule $schedule, int $year, array $holidays = []): int
    {
        $count = 0;
        // From the month before the year (a period can be paid in the month
        // after it starts) through the year's last month.
        $period = self::containing($schedule, sprintf('%04d-11-01', $year - 1), $holidays);
        $end = sprintf('%04d-12-31', $year);
        for ($i = 0; $i < 80 && $period['start'] <= $end; $i++) {
            if ((int) substr($period['pay_date'], 0, 4) === $year) {
                $count++;
            }
            $period = self::nextAfter($schedule, $period['start'], $holidays);
        }

        return $count;
    }

    /**
     * Which pay of its year a pay date is: 1 for the year's first.
     *
     * @param  list<string>  $holidays
     */
    public static function payNumber(PaySchedule $schedule, string $payDate, array $holidays = []): int
    {
        $year = (int) substr($payDate, 0, 4);
        $number = 0;
        $period = self::containing($schedule, sprintf('%04d-11-01', $year - 1), $holidays);
        for ($i = 0; $i < 80 && $period['pay_date'] <= $payDate; $i++) {
            if ((int) substr($period['pay_date'], 0, 4) === $year) {
                $number++;
            }
            $period = self::nextAfter($schedule, $period['start'], $holidays);
        }

        return max(1, $number);
    }

    /**
     * Whether a day is a Saturday, a Sunday or one of the holidays.
     *
     * @param  list<string>  $holidays
     */
    public static function isOff(string $day, array $holidays): bool
    {
        $weekday = (int) gmdate('N', self::toEpochDay($day) * 86400);

        return $weekday >= 6 || in_array($day, $holidays, true);
    }

    /**
     * The by-weeks period a day falls in: the anchor's period counted
     * forward or back in whole periods.
     *
     * @param  list<string>  $holidays
     * @return array{start: string, end: string, pay_date: string, position: string, deadlines: list<array{label: string, date: string}>}
     */
    private static function weekPeriodAt(PaySchedule $schedule, int $epochDay, array $holidays): array
    {
        $length = $schedule->periodDays();
        $anchor = self::toEpochDay((string) ($schedule->cycle['anchor'] ?? '1970-01-04'));
        $index = (int) floor(($epochDay - $anchor) / $length);
        $start = $anchor + $index * $length;
        $end = self::fromEpochDay($start + $length - 1);
        $payDate = self::movedOff(self::addDays($end, (int) ($schedule->cycle['pay_after_days'] ?? 0)), $schedule->holidayRule, $holidays);
        $deadlines = [];
        foreach ($schedule->deadlines as $deadline) {
            $deadlines[] = ['label' => $deadline['label'], 'date' => self::addDays($payDate, -$deadline['days_before'])];
        }

        return [
            'start' => self::fromEpochDay($start),
            'end' => $end,
            'pay_date' => $payDate,
            'position' => self::ONLY,
            'deadlines' => $deadlines,
        ];
    }

    /**
     * The by-weeks periods that start in a month.
     *
     * @param  list<string>  $holidays
     * @return list<array{start: string, end: string, pay_date: string, position: string, deadlines: list<array{label: string, date: string}>}>
     */
    private static function weeksStartingIn(PaySchedule $schedule, int $year, int $month, array $holidays): array
    {
        $first = sprintf('%04d-%02d-01', $year, $month);
        $last = sprintf('%04d-%02d-%02d', $year, $month, self::daysInMonth($year, $month));
        $out = [];
        $period = self::weekPeriodAt($schedule, self::toEpochDay($first), $holidays);
        for ($i = 0; $i < 8 && $period['start'] <= $last; $i++) {
            if ($period['start'] >= $first) {
                $out[] = $period;
            }
            $period = self::weekPeriodAt($schedule, self::toEpochDay($period['start']) + $schedule->periodDays(), $holidays);
        }

        return $out;
    }

    /** @param array{kind: string, day: ?int, next_month: bool} $payDay */
    private static function payDay(array $payDay, int $year, int $month, string $periodEnd): string
    {
        if ($payDay['kind'] === PaySchedule::PAY_PERIOD_END) {
            return $periodEnd;
        }
        if ($payDay['next_month']) {
            if (++$month > 12) {
                $month = 1;
                $year++;
            }
        }
        $day = min((int) $payDay['day'], self::daysInMonth($year, $month));

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** @param list<string> $holidays */
    private static function movedOff(string $day, string $rule, array $holidays): string
    {
        if ($rule === PaySchedule::MOVE_KEEP) {
            return $day;
        }
        $step = $rule === PaySchedule::MOVE_BEFORE ? -1 : 1;
        for ($i = 0; $i < 14 && self::isOff($day, $holidays); $i++) {
            $day = self::addDays($day, $step);
        }

        return $day;
    }

    private static function daysInMonth(int $year, int $month): int
    {
        return (int) gmdate('t', self::timestamp($year, $month, 1));
    }

    private static function addDays(string $day, int $days): string
    {
        return self::fromEpochDay(self::toEpochDay($day) + $days);
    }

    private static function toEpochDay(string $day): int
    {
        return intdiv(self::timestamp((int) substr($day, 0, 4), (int) substr($day, 5, 2), (int) substr($day, 8, 2)), 86400);
    }

    /** UTC midnight of a calendar day as a timestamp; every real year, month and day has one. */
    private static function timestamp(int $year, int $month, int $day): int
    {
        $timestamp = gmmktime(0, 0, 0, $month, $day, $year);
        if ($timestamp === false) {
            throw new \InvalidArgumentException("No such day: {$year}-{$month}-{$day}.");
        }

        return $timestamp;
    }

    private static function fromEpochDay(int $epochDay): string
    {
        return gmdate('Y-m-d', $epochDay * 86400);
    }
}
