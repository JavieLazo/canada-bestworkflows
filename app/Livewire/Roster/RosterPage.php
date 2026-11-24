<?php

declare(strict_types=1);

namespace App\Livewire\Roster;

use App\Domain\Payroll\PayrollPeriod;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Kit\Support\DepartmentFilter;
use App\Livewire\Roster\Support\RosterDayText;
use App\Modules\Timekeeping\Application\Queries\RosterCalendar;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Shift Roster — one week of everyone's schedule: a row per employee, a
 * column per day, each day showing the shift that applies on it and its
 * hours, approved leave, and a warning where a shift starts less than eight
 * hours after the one before ended (`RosterCalendar`). A row opens that
 * employee's month (`EmployeeSchedulePage`), where dates and the default
 * schedule are changed; this table only reads.
 *
 * The table is one page from the database: the directory searches, filters
 * by department and pages the ACTIVE employees — and those made inactive
 * whose date resigned is in or after the week, marked so — and Timekeeping
 * answers the seven days for that page's employees alone, in one batch.
 * A holiday is named under its day's heading.
 *
 * Permissions: the PAGE needs ROSTER (route middleware); the EMPLOYEE LIST
 * is the operational directory's tier (all-data on an operational module
 * reads everyone, otherwise your own row); the DAYS are scoped like every
 * roster read (ROSTER, EDTR, PAYROLL), and leave on them by Leave
 * Management's own scope. Nothing here writes.
 */
#[Layout('layouts.app')]
#[Title('Shift Roster')]
class RosterPage extends Component
{
    use WithTableFilters;

    /**
     * Department filter — a `DepartmentFilter` value. Plain filter state:
     * the directory applies it after the row scope, so it only narrows, and
     * render() turns a value the select does not offer back into ALL.
     */
    public string $department = DepartmentFilter::ALL;

    /**
     * The first day of the week shown, 'Y-m-d'; blank for this week. Plain
     * state from the URL — render() turns any date into the first day of
     * its week (`RosterDayText::weekStart`), and anything else into this
     * week's.
     */
    #[Url(as: 'week', except: '')]
    public string $week = '';

    public function updatedDepartment(): void
    {
        $this->resetPage();
    }

    public function previousWeek(): void
    {
        $this->week = PayrollPeriod::addCalendarDays(RosterDayText::weekStart($this->week), -7);
    }

    public function nextWeek(): void
    {
        $this->week = PayrollPeriod::addCalendarDays(RosterDayText::weekStart($this->week), 7);
    }

    public function render(): View
    {
        $access = app(Access::class);
        $directory = app(OperationalDirectory::class);
        $weekStart = RosterDayText::weekStart($this->week);
        // The address names the week by its first day, whatever date of it
        // was typed or bookmarked.
        if ($this->week !== '') {
            $this->week = $weekStart;
        }
        $days = RosterDayText::week($weekStart);

        $departments = DepartmentFilter::options($access);
        $this->department = DepartmentFilter::choice($this->department, $departments);

        $rows = $this->acceptPage($directory->tablePage(
            $access,
            // Active employees, and those who left during or after this
            // week: their schedule for it still stands.
            ['search' => $this->search, 'status' => 'ALL', 'position' => 'ALL', 'place' => $this->department, 'active' => 'ACTIVE', 'left_from' => $weekStart],
            null,
            'name',
            'asc',
            (int) $this->getPage(),
            $this->perPage,
        ));
        $ids = array_values(array_map(static fn (DirectoryEmployee $e): string => (string) $e->id, $rows->items()));

        return view('livewire.roster.roster-page', [
            'rows' => $rows,
            'days' => $days,
            'weekStart' => $weekStart,
            // The day before the week too: the note behind the first day's
            // short-rest warning names the shift it follows.
            'calendar' => app(RosterCalendar::class)->forEmployees($access, $ids, PayrollPeriod::addCalendarDays($weekStart, -1), $days[6]),
            'departments' => $departments,
            // Under each name with every department listed; a filtered
            // table already says which department it is.
            'places' => $this->department === DepartmentFilter::ALL ? $directory->placeLabels($access, $ids) : [],
            'holidays' => app(RosterCalendar::class)->holidays($days[0], $days[6]),
            'today' => LocalClock::todayKey(),
        ]);
    }
}
