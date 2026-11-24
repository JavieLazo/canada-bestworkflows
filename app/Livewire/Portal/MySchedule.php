<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Domain\Payroll\PayrollPeriod;
use App\Livewire\Roster\Support\RosterDayText;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Timekeeping\Application\Queries\RosterCalendar;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * My Portal's My schedule: the employee's own month as the Shift Roster
 * draws it — each date's shift and hours, where it is worked, holidays and
 * their own approved leave — so they see a change before the punch station
 * refuses a Time In on a date with no shift. It reads only; HR changes
 * schedules on the Shift Roster, and each change reaches the employee's
 * inbox too (`ScheduleChangeNotice`).
 *
 * Pinned to the signed-in employee even for an all-data holder
 * (`RosterCalendar::own`); an account with no employee link reads nothing.
 */
class MySchedule extends Component
{
    /** The month shown, 'Y-m'; blank for this month. Plain state: render() reads anything that is not a month as this month. */
    public string $month = '';

    public function previousMonth(): void
    {
        $this->month = substr(PayrollPeriod::addCalendarDays(RosterDayText::monthStart($this->month), -1), 0, 7);
    }

    public function nextMonth(): void
    {
        $first = RosterDayText::monthStart($this->month);
        $this->month = substr(PayrollPeriod::addCalendarDays(PayrollPeriod::calendarMonthRange($first)['to'], 1), 0, 7);
    }

    public function thisMonth(): void
    {
        $this->month = '';
    }

    public function render(): View
    {
        $access = app(Access::class);
        $calendar = app(RosterCalendar::class);
        $today = LocalClock::todayKey();
        $first = RosterDayText::monthStart($this->month);
        $last = PayrollPeriod::calendarMonthRange($first)['to'];
        $days = $calendar->own($access, $first, $last);
        $thisWeek = RosterDayText::week(RosterDayText::monday($today));
        $usualWeek = array_intersect_key($days, array_flip($thisWeek));
        if (count($usualWeek) !== 7) {
            $usualWeek = $calendar->own($access, $thisWeek[0], $thisWeek[6]);
        }

        return view('livewire.portal.my-schedule', [
            'linked' => $access->hasEmployeeLink(),
            'days' => $days,
            'first' => $first,
            'last' => $last,
            'today' => $today,
            'usualWeek' => count($usualWeek) === 7 ? RosterDayText::usualWeek($usualWeek) : '',
            'sites' => $days === [] ? collect() : app(OrganizationDirectory::class)->sites($access)->keyBy('id'),
        ]);
    }
}
