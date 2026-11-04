<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Payroll\Contracts\Views\PayScheduleView;
use App\Modules\Payroll\Domain\Schedules\PaySchedule;
use InvalidArgumentException;

/**
 * A pay schedule as the Pay Schedules page and its CSV word it: the
 * periods and their pay days in a phrase, the weekend and holiday rule,
 * the deadlines, the contribution-month rule. Read straight off the
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

    public static function contributionMonth(PaySchedule $schedule): string
    {
        return $schedule->contributionMonth === PaySchedule::MONTH_PAY_DATE ? 'The pay date\'s month' : 'The period\'s month';
    }
}
