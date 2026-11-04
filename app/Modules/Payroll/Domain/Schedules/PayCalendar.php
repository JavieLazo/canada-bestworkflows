<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Schedules;

/**
 * The pay calendar a schedule makes: for a month, each salary period with
 * its pay date — the schedule's pay day, moved off a Saturday, Sunday or
 * holiday as the schedule says — its deadlines counted back from the pay
 * date, its place in the month (FIRST, LAST or ONLY, which decides which
 * payroll carries a month's whole-month deductions) and the month whose
 * contributions it carries. A period's dates are 'Y-m-d' strings compared
 * as the rest of payroll compares them.
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
     * @return list<array{start: string, end: string, pay_date: string, position: string, contribution_month: string, deadlines: list<array{label: string, date: string}>}>
     */
    public static function month(PaySchedule $schedule, int $year, int $month, array $holidays = []): array
    {
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
                'contribution_month' => $schedule->contributionMonth === PaySchedule::MONTH_PAY_DATE ? substr($payDate, 0, 7) : sprintf('%04d-%02d', $year, $month),
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
     * @return array{start: string, end: string, pay_date: string, position: string, contribution_month: string, deadlines: list<array{label: string, date: string}>}|null
     */
    public static function periodFor(PaySchedule $schedule, string $start, string $end, array $holidays = []): ?array
    {
        if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $start, $m) !== 1) {
            return null;
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
     * @return array{start: string, end: string, pay_date: string, position: string, contribution_month: string, deadlines: list<array{label: string, date: string}>}
     */
    public static function nextAfter(PaySchedule $schedule, string $day, array $holidays = []): array
    {
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
     * @return list<array{start: string, end: string, pay_date: string, position: string, contribution_month: string, deadlines: list<array{label: string, date: string}>}>
     */
    public static function upcoming(PaySchedule $schedule, string $today, int $days, array $holidays = []): array
    {
        $year = (int) substr($today, 0, 4);
        $month = (int) substr($today, 5, 2);
        $limit = self::addDays($today, $days);
        $out = [];
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
     * Whether a day is a Saturday, a Sunday or one of the holidays.
     *
     * @param  list<string>  $holidays
     */
    public static function isOff(string $day, array $holidays): bool
    {
        $weekday = (int) gmdate('N', self::toEpochDay($day) * 86400);

        return $weekday >= 6 || in_array($day, $holidays, true);
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
