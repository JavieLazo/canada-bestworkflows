<?php

declare(strict_types=1);

use App\Livewire\Portal\MySchedule;
use App\Livewire\Roster\EmployeeSchedulePage;
use App\Livewire\Roster\RosterPage;
use App\Livewire\Roster\Support\RosterDayText;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterLocation;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\SaveRosterLocations;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Support\Access;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * THE WORK WEEK RUNS SUNDAY TO SATURDAY wherever the roster shows or pages
 * one: the week table's columns and its `?week=` value (any date of a week
 * becomes that week's Sunday, and paging moves Sunday to Sunday), the month
 * grids of the Shift Roster and of My Portal's My Schedule (Sunday is the
 * first column, so the 1st sits under its own weekday), the Default Schedule
 * dialog's rows, and the order a week is named in an audit sentence. The
 * stored weekday numbers stay 0 Sunday … 6 Saturday: a row saved for
 * weekday 0 is still Sunday's.
 */

/** @param list<string> $modules */
function sunwkActor(array $modules = ['ROSTER'], bool $allData = true, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'SunWeek '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

/** An employee on a Monday-to-Friday day shift, so a weekend reads "Off". */
function sunwkEmployee(string $first, string $last): EmployeeProfile
{
    $employee = EmployeeProfile::query()->create(['employee_code' => 'SW-'.Str::random(8), 'first_name' => $first, 'last_name' => $last,
        'position' => 'Cashier', 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true]);
    Shift::query()->create(['employee_id' => $employee->id, 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);

    return $employee;
}

/**
 * The week table's seven day columns, as the dates of one employee's cells
 * in the order they are drawn.
 *
 * @return list<string>
 */
function sunwkColumns(string $html): array
{
    preg_match_all('~data-day="(\d{4}-\d{2}-\d{2})"~', $html, $found);

    return array_slice($found[1], 0, 7);
}

/**
 * A month grid's weekday headings in order, and how many empty boxes it
 * draws before the 1st.
 *
 * @return array{headings: list<string>, lead: int}
 */
function sunwkGrid(string $html, string $firstDateMarker): array
{
    expect($html)->toContain($firstDateMarker);
    $grid = Str::before(Str::after($html, 'grid grid-cols-7'), $firstDateMarker);
    preg_match_all('~uppercase tracking-wide text-muted-foreground">\s*(\w{3})\s*</span>~', $grid, $headings);

    return ['headings' => $headings[1], 'lead' => substr_count($grid, '<span aria-hidden="true"></span>')];
}

beforeEach(function () {
    // Monday 16 November 2026, before any shift here starts.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 05:00:00', LocalClock::zone()));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/*
|--------------------------------------------------------------------------
| The week table
|--------------------------------------------------------------------------
*/

it('draws the week table Sunday to Saturday, through a week that straddles a month', function () {
    sunwkEmployee('Liza', 'Domingo');
    sunwkActor();

    // Tuesday 1 December 2026 belongs to the week of Sunday 29 November.
    $html = Livewire::test(RosterPage::class)->set('week', '2026-12-01')->html();

    expect($html)->toContain('Nov 29 – Dec 5, 2026')
        ->and(sunwkColumns($html))->toBe(['2026-11-29', '2026-11-30', '2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04', '2026-12-05']);

    // The headings read in that order too, Sunday first and Saturday last.
    $order = array_map(static fn (string $heading): int => (int) strpos($html, $heading), ['Sun 29', 'Mon 30', 'Tue 1', 'Wed 2', 'Thu 3', 'Fri 4', 'Sat 5']);
    $sorted = $order;
    sort($sorted);
    expect($order)->toBe($sorted)->and(min($order))->toBeGreaterThan(0);
});

it('turns the ?week= value into the Sunday of its week and pages Sunday to Sunday', function (string $typed, string $sunday) {
    sunwkActor();

    Livewire::withQueryParams(['week' => $typed]);
    $page = Livewire::test(RosterPage::class);

    expect($page->get('week'))->toBe($sunday);

    $page->call('nextWeek');
    expect($page->get('week'))->toBe(CarbonImmutable::parse($sunday, 'UTC')->addDays(7)->format('Y-m-d'));

    $page->call('previousWeek')->call('previousWeek');
    expect($page->get('week'))->toBe(CarbonImmutable::parse($sunday, 'UTC')->subDays(7)->format('Y-m-d'));
})->with([
    'a Sunday stays' => ['2026-11-15', '2026-11-15'],
    'a Monday goes back a day' => ['2026-11-16', '2026-11-15'],
    'a Saturday goes back six' => ['2026-11-21', '2026-11-15'],
    'the 1st of a month goes back into the month before' => ['2026-12-01', '2026-11-29'],
    'New Year’s Day goes back into the year before' => ['2027-01-01', '2026-12-27'],
]);

it('shows this week, Sunday to Saturday, for no week or for anything that is not a date', function (string $typed) {
    sunwkActor();

    if ($typed !== '') {
        Livewire::withQueryParams(['week' => $typed]);
    }
    $page = Livewire::test(RosterPage::class);

    // Today is Monday the 16th: its week began on Sunday the 15th.
    expect($page->html())->toContain('Nov 15 – Nov 21, 2026');
})->with(['no week' => [''], 'junk' => ['next-week'], 'an impossible date' => ['2026-02-31']]);

it('says a week by its Sunday and Saturday, and starts it on the day WorkWeek names', function () {
    expect(RosterDayText::weekStart('2026-11-18'))->toBe('2026-11-15')
        ->and(RosterDayText::week('2026-11-29'))->toBe(['2026-11-29', '2026-11-30', '2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04', '2026-12-05'])
        ->and(RosterDayText::weekLabel('2026-11-29'))->toBe('Nov 29 – Dec 5, 2026')
        ->and(RosterDayText::weekLabel('2026-12-27'))->toBe('Dec 27 – Jan 2, 2027')
        ->and(RosterDayText::weekdayHeadings())->toBe(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'])
        // A month that starts on a Sunday needs no empty box; one that
        // starts on a Tuesday needs two, and on a Saturday six.
        ->and(RosterDayText::monthLead('2026-11-01'))->toBe(0)
        ->and(RosterDayText::monthLead('2026-12-01'))->toBe(2)
        ->and(RosterDayText::monthLead('2026-08-01'))->toBe(6);
});

/*
|--------------------------------------------------------------------------
| The month grids
|--------------------------------------------------------------------------
*/

it('puts Sunday in the first column of an employee’s month, with the 1st under its own weekday', function () {
    $employee = sunwkEmployee('Liza', 'Domingo');
    sunwkActor();

    // December 2026 starts on a Tuesday: two empty boxes, under Sun and Mon.
    Livewire::withQueryParams(['month' => '2026-12']);
    $december = sunwkGrid(Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])->html(), 'data-date="2026-12-01"');
    expect($december['headings'])->toBe(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'])
        ->and($december['lead'])->toBe(2);

    // November 2026 starts on a Sunday: none.
    Livewire::withQueryParams(['month' => '2026-11']);
    $november = sunwkGrid(Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])->html(), 'data-date="2026-11-01"');
    expect($november['headings'])->toBe(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'])
        ->and($november['lead'])->toBe(0);
});

it('reads the usual week from this Sunday-to-Saturday week, and still says a weekend as Sat and Sun', function () {
    $employee = sunwkEmployee('Liza', 'Domingo');
    sunwkActor();

    Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])
        ->assertSee('Usual week:')
        ->assertSee('8:00 AM–5:00 PM, off Sat and Sun');
});

it('lists the Default Schedule’s days Sunday first and Saturday last', function () {
    $employee = sunwkEmployee('Liza', 'Domingo');
    sunwkActor();

    $html = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])->call('openDefaultSchedule')->html();
    preg_match_all('~wire:key="week-row-(\d)"~', $html, $rows);

    expect($rows[1])->toBe(['0', '1', '2', '3', '4', '5', '6']);
});

it('draws My Schedule Sunday first for the employee’s own month', function () {
    $employee = sunwkEmployee('Liza', 'Domingo');
    sunwkActor(['PORTAL'], allData: false, employeeId: $employee->id);

    $page = Livewire::test(MySchedule::class)->call('nextMonth');
    $december = sunwkGrid($page->html(), 'data-date="2026-12-01"');

    expect($december['headings'])->toBe(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'])
        ->and($december['lead'])->toBe(2)
        ->and($page->html())->toContain('8:00 AM–5:00 PM, off Sat and Sun');
});

/*
|--------------------------------------------------------------------------
| Stored weekdays and the audit sentence
|--------------------------------------------------------------------------
*/

it('names a saved week Sunday first in the audit sentence, and keeps the stored weekday numbers', function () {
    $employee = sunwkEmployee('Liza', 'Domingo');
    $hr = sunwkActor();

    // Sent Saturday first, on purpose: the sentence follows the week, not the input.
    app(SaveRosterLocations::class)->handle(Access::forActor($hr), $employee->id, [6 => LocationKind::WFH, 1 => LocationKind::WFH, 0 => LocationKind::WFH]);

    $sentence = (string) AuditLog::query()->where('action', 'ROSTER_LOCATIONS_SET')->sole()->details;
    expect($sentence)->toContain('Sun WFH · Mon WFH · Sat WFH');

    // 0 is still Sunday and 6 still Saturday in the table.
    expect(RosterLocation::query()->where('employee_id', $employee->id)->orderBy('weekday')->pluck('weekday')->all())->toBe([0, 1, 6]);
});
