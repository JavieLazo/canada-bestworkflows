<?php

declare(strict_types=1);

namespace App\Livewire\Roster\Support;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Payroll\PayrollPeriod;
use App\Modules\Organization\Contracts\Views\WorkSiteView;
use App\Modules\Timekeeping\Contracts\Views\RosterDayView;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Timekeeping\Domain\Roster\WorkWeek;
use App\Support\HrisFormat;
use App\Support\LocalClock;
use Illuminate\Support\Collection;

/**
 * The Shift Roster's words for its dates: the week and month it pages
 * through, a set of picked dates said briefly, an employee's usual week in
 * one line, where a date is worked, and the note behind the short-rest
 * warning. Presentation only — every fact comes from Timekeeping's
 * `RosterCalendar`.
 */
final class RosterDayText
{
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    private function __construct() {}

    /**
     * The first day of the week holding `$date` — the day `WorkWeek` starts
     * a week on — or of this week for anything that is not a date.
     */
    public static function weekStart(string $date): string
    {
        return WorkWeek::startOf(PayrollPeriod::isPayrollDate($date) ? $date : LocalClock::todayKey());
    }

    /**
     * The seven dates of the week starting `$weekStart`.
     *
     * @return list<string>
     */
    public static function week(string $weekStart): array
    {
        return CalendarDays::each($weekStart, PayrollPeriod::addCalendarDays($weekStart, 6));
    }

    /**
     * The weekday headings over a month grid, "Sun" … "Sat" in the week's
     * order.
     *
     * @return list<string>
     */
    public static function weekdayHeadings(): array
    {
        return array_map(static fn (int $weekday): string => HrisFormat::weekday($weekday), WorkWeek::weekdays());
    }

    /** How many empty boxes a month grid draws before the month's first day `$first`. */
    public static function monthLead(string $first): int
    {
        return WorkWeek::position(CalendarDays::dayOfWeek($first));
    }

    /** The first day of the month `$month` ('Y-m') names, or of this month for anything else. */
    public static function monthStart(string $month): string
    {
        $first = $month.'-01';

        return preg_match('/^\d{4}-\d{2}$/', $month) === 1 && PayrollPeriod::isPayrollDate($first)
            ? $first
            : substr(LocalClock::todayKey(), 0, 8).'01';
    }

    /** "Nov 16", the short date the roster's headings use. */
    public static function short(string $date): string
    {
        return self::MONTHS[(int) substr($date, 5, 2) - 1].' '.(int) substr($date, 8, 2);
    }

    /** "Nov 15 – Nov 21, 2026", the week starting `$weekStart`. */
    public static function weekLabel(string $weekStart): string
    {
        $weekEnd = PayrollPeriod::addCalendarDays($weekStart, 6);

        return self::short($weekStart).' – '.self::short($weekEnd).', '.substr($weekEnd, 0, 4);
    }

    /** "November 2026". */
    public static function monthLabel(string $first): string
    {
        return date('F Y', (int) strtotime($first.' 00:00:00 UTC'));
    }

    /** "Monday, Nov 16", a date with its weekday in full. */
    public static function longDay(string $date): string
    {
        return HrisFormat::weekday(CalendarDays::dayOfWeek($date), short: false).', '.self::short($date);
    }

    /** "Wed Nov 18". */
    public static function dayAndDate(string $date): string
    {
        return HrisFormat::weekday(CalendarDays::dayOfWeek($date)).' '.self::short($date);
    }

    /**
     * Picked dates said briefly — the month once, a run of days as a range:
     * "Nov 18, 20, 22, 28–29".
     *
     * @param  list<string>  $dates  sorted
     */
    public static function compact(array $dates): string
    {
        $runs = [];
        foreach ($dates as $date) {
            $last = count($runs) - 1;
            if ($last >= 0 && CalendarDays::next($runs[$last][1]) === $date) {
                $runs[$last][1] = $date;
            } else {
                $runs[] = [$date, $date];
            }
        }
        $parts = [];
        $month = '';
        foreach ($runs as [$from, $to]) {
            $label = (substr($from, 0, 7) !== $month ? self::MONTHS[(int) substr($from, 5, 2) - 1].' ' : '').(int) substr($from, 8, 2);
            if ($from !== $to) {
                $label .= '–'.(substr($to, 0, 7) !== substr($from, 0, 7) ? self::MONTHS[(int) substr($to, 5, 2) - 1].' ' : '').(int) substr($to, 8, 2);
            }
            $month = substr($to, 0, 7);
            $parts[] = $label;
        }

        return implode(', ', $parts);
    }

    /**
     * The employee's usual week in one line, from the weekly schedule of each
     * of its seven dates: "AM 6:00 AM–2:00 PM, off Wed and Thu", each
     * different shift in full ("AM 6:00 AM–2:00 PM / PM 2:00 PM–10:00 PM").
     * Rest days are named in the week's order, a weekend across its edge as
     * "Sat and Sun".
     *
     * @param  array<string, RosterDayView>  $week  the seven dates of one week, in the week's order
     */
    public static function usualWeek(array $week): string
    {
        $shifts = [];
        $off = [];
        $none = 0;
        $position = 0;
        foreach ($week as $date => $day) {
            if ($day->usual === 'Rest day') {
                $off[$position] = HrisFormat::weekday(CalendarDays::dayOfWeek($date));
            } elseif ($day->usual === 'No shift') {
                $none++;
            } else {
                $shifts[$day->usual] = true;
            }
            $position++;
        }
        if ($shifts === []) {
            return $none > 0 ? 'no shift' : 'rest days only';
        }
        // Rest days that run across the week's edge are said as they fall on
        // the calendar — "Sat and Sun", not "Sun and Sat": the days resting
        // at the start of the week follow the ones resting at its end.
        if (isset($off[0], $off[6]) && count($off) < 7) {
            for ($first = 0; isset($off[$first]); $first++) {
                $label = $off[$first];
                unset($off[$first]);
                $off[$first + 7] = $label;
            }
        }
        $off = array_values($off);
        $line = implode(' / ', array_keys($shifts));
        if ($off === []) {
            return $line.', no rest day';
        }
        $last = array_pop($off);

        return $line.', off '.($off === [] ? $last : implode(', ', $off).' and '.$last);
    }

    /**
     * The one word a date's approved leave reads as, on the week table and the
     * employee's month alike: Travel for official business, Half-day for a
     * half-day leave, Leave otherwise. The type itself goes in the title.
     */
    public static function leaveWord(RosterDayView $day): string
    {
        return match (true) {
            $day->official_business => 'Travel',
            $day->half_day => 'Half-day',
            default => 'Leave',
        };
    }

    /**
     * Where the date is worked: Work from home, its site, or the Assigned
     * Worksite when the roster names none.
     *
     * @param  Collection<string, WorkSiteView>  $sites  keyed by id
     */
    public static function place(RosterDayView $day, Collection $sites, ?string $assignedSiteId): string
    {
        if ($day->place_kind === LocationKind::WFH) {
            return 'Work from home';
        }
        $siteId = $day->place_kind === LocationKind::ONSITE ? $day->place_site_id : $assignedSiteId;

        return $siteId === null ? 'No worksite' : ($sites->get($siteId)->name ?? 'No worksite');
    }

    /**
     * Why a date carries the short-rest warning, from it and the date before.
     *
     * @return array{previous: string, previous_date: string, ends: string, label: string, date: string, starts: string, rest: string}|null
     */
    public static function restNote(?RosterDayView $previous, RosterDayView $day): ?array
    {
        if ($previous === null || $day->short_rest_minutes === null) {
            return null;
        }

        return [
            'previous' => $previous->custom ? (string) $previous->hours : $previous->label,
            'previous_date' => self::dayAndDate($previous->date),
            'ends' => (string) $previous->ends,
            'label' => $day->custom ? (string) $day->hours : $day->label,
            'date' => self::dayAndDate($day->date),
            'starts' => (string) $day->starts,
            'rest' => $day->short_rest_minutes === 0 ? '0 hours' : HrisFormat::duration((float) $day->short_rest_minutes),
        ];
    }
}
