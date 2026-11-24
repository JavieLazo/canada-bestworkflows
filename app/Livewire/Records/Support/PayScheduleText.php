<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Payroll\Contracts\Views\PayScheduleView;
use App\Modules\Payroll\Domain\Schedules\PaySchedule;
use InvalidArgumentException;

/**
 * A pay schedule as the Pay Schedules page and its CSV word it: the
 * periods and their pay days in a phrase, the weekend and holiday rule
 * and the deadlines. Read straight off the
 * view's figures; a row payroll cannot read is named as such.
 */
final class PayScheduleText
{
    private function __construct() {}

    public static function schedule(PayScheduleView $view): ?PaySchedule
    {
        try {
            return PaySchedule::fromArray($view->params);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** How often the schedule pays, as a title: "Every Two Weeks". */
    public static function frequency(PaySchedule $schedule): string
    {
        return match (true) {
            $schedule->isByWeeks() && $schedule->periodDays() === 7 => 'Every Week',
            $schedule->isByWeeks() => 'Every Two Weeks',
            count($schedule->periods) === 1 => 'Once a Month',
            default => 'Twice a Month',
        };
    }

    /** The schedule in a few words, as it is named on record: "Every two weeks, paid 6 days after". */
    public static function name(PaySchedule $schedule): string
    {
        $every = match (self::frequency($schedule)) {
            'Every Week' => 'Every week',
            'Every Two Weeks' => 'Every two weeks',
            'Once a Month' => 'Once a month',
            default => 'Twice a month',
        };
        if ($schedule->isByWeeks()) {
            $after = (int) $schedule->cycle['pay_after_days'];

            return $every.', '.($after === 0 ? 'paid on the last day' : 'paid '.($after === 1 ? '1 day' : $after.' days').' after');
        }

        return $every.', '.lcfirst(self::payDays($schedule));
    }

    /** "Sunday to Saturday, Two Weeks", or the month's cut: "1st–15th and 16th–end". */
    public static function runs(PaySchedule $schedule): string
    {
        if (! $schedule->isByWeeks()) {
            return self::periods($schedule);
        }
        $start = (int) strtotime($schedule->cycle['anchor'].' 00:00:00 UTC');

        return gmdate('l', $start).' to '.gmdate('l', $start + 6 * 86400).', '.($schedule->periodDays() === 7 ? 'One Week' : 'Two Weeks');
    }

    /** "The Friday After the Period Ends", for a schedule that runs by weeks; else the month's pay days. */
    public static function payDay(PaySchedule $schedule): string
    {
        if (! $schedule->isByWeeks()) {
            return self::payDays($schedule);
        }
        $after = (int) $schedule->cycle['pay_after_days'];
        if ($after === 0) {
            return 'The Last Day of the Period';
        }
        $end = (int) strtotime($schedule->cycle['anchor'].' 00:00:00 UTC') + ($schedule->periodDays() - 1) * 86400;

        return 'The '.gmdate('l', $end + $after * 86400).' After the Period Ends';
    }

    /** "It Moves to the Working Day Before". */
    public static function holidayMove(PaySchedule $schedule): string
    {
        return match ($schedule->holidayRule) {
            PaySchedule::MOVE_BEFORE => 'It Moves to the Working Day Before',
            PaySchedule::MOVE_AFTER => 'It Moves to the Working Day After',
            default => 'It Stays on That Day',
        };
    }

    /** "Sun, Dec 21, 2025". */
    public static function longDay(string $date): string
    {
        return gmdate('D, M j, Y', (int) strtotime($date.' 00:00:00 UTC'));
    }

    /** "Fri, Nov 27", with the year when it is not the year given. */
    public static function payDate(string $date, int $year): string
    {
        return gmdate((int) substr($date, 0, 4) === $year ? 'D, M j' : 'D, M j, Y', (int) strtotime($date.' 00:00:00 UTC'));
    }

    /** "Nov 8 to Nov 21, 2026", or "Dec 20, 2026 to Jan 2, 2027" across a year's end. */
    public static function range(string $start, string $end): string
    {
        $from = (int) strtotime($start.' 00:00:00 UTC');
        $to = (int) strtotime($end.' 00:00:00 UTC');

        return gmdate(substr($start, 0, 4) === substr($end, 0, 4) ? 'M j' : 'M j, Y', $from).' to '.gmdate('M j, Y', $to);
    }

    /** "1st–15th and 16th–end" or "1st–end". */
    public static function periods(PaySchedule $schedule): string
    {
        $parts = [];
        foreach ($schedule->periods as $period) {
            $parts[] = PaySchedule::ordinal($period['start']).'–'.($period['end'] === null ? 'end' : PaySchedule::ordinal($period['end']));
        }

        return implode(' and ', $parts);
    }

    /** "Paid on the last day of each period" or "Paid on the 15th and the 30th". */
    public static function payDays(PaySchedule $schedule): string
    {
        $days = [];
        $allEnd = true;
        foreach ($schedule->periods as $period) {
            $pay = $period['pay_day'];
            if ($pay['kind'] === PaySchedule::PAY_PERIOD_END) {
                $days[] = 'the period\'s last day';

                continue;
            }
            $allEnd = false;
            $days[] = 'the '.PaySchedule::ordinal((int) $pay['day']).($pay['next_month'] ? ' of the next month' : '');
        }
        if ($allEnd) {
            return count($days) === 1 ? 'Paid on the period\'s last day' : 'Paid on the last day of each period';
        }

        return 'Paid on '.implode(' and ', $days);
    }

    public static function holidayRule(PaySchedule $schedule): string
    {
        return match ($schedule->holidayRule) {
            PaySchedule::MOVE_BEFORE => 'Working day before',
            PaySchedule::MOVE_AFTER => 'Working day after',
            default => 'Kept as is',
        };
    }

    /** "Draft complete 5 days before · Approved 2 days before", or "None". */
    public static function deadlines(PaySchedule $schedule): string
    {
        if ($schedule->deadlines === []) {
            return 'None';
        }

        return implode(' · ', array_map(static fn (array $d): string => $d['label'].' '.($d['days_before'] === 1 ? '1 day' : $d['days_before'].' days').' before', $schedule->deadlines));
    }
}
