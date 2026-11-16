<?php

declare(strict_types=1);

use App\Livewire\Roster\EmployeeSchedulePage;
use App\Livewire\Roster\RosterPage;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\SaveRosterDays;
use App\Modules\Timekeeping\Application\Queries\RosterCalendar;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Shift Roster's two screens. The WEEK TABLE lists the active employees
 * the actor's directory holds, a column per day naming its shift and hours,
 * pages by week, narrows by department after the row scope, marks a shift
 * that starts too soon after the one before, and opens an employee's month
 * from the row. The EMPLOYEE'S MONTH is reachable only for someone in that
 * directory; it marks today, keeps past dates and whole-day leave out of
 * reach, changes any picked dates together, edits the usual week in Default
 * schedule, holds a change the safeguards move for review, and is read-only
 * without all-data ROSTER.
 */

/** @param list<string> $modules */
function rwkActor(array $modules = ['ROSTER'], bool $allData = true, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Week '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function rwkPattern(string $name, string $start, string $end): ShiftType
{
    return ShiftType::query()->create(['code' => Str::random(10), 'name' => $name, 'mode' => 'FIXED', 'start_time' => $start, 'end_time' => $end,
        'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);
}

function rwkEmployee(string $first, string $last, ?ShiftType $default, bool $active = true, ?Position $position = null): EmployeeProfile
{
    $employee = EmployeeProfile::query()->create(['employee_code' => 'WK-'.Str::random(8), 'first_name' => $first, 'last_name' => $last,
        'position' => $position->name ?? 'Staff nurse', 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => $active]);
    if ($position !== null) {
        $employee->forceFill(['position_id' => $position->id])->save();
    }
    if ($default !== null) {
        Shift::query()->create(ShiftPolicy::attributesFromPattern($default) + ['employee_id' => $employee->id]);
    }

    return $employee;
}

function rwkNode(string $code, string $kind, string $name, ?string $parent = null): OrgNode
{
    $node = new OrgNode;
    $node->forceFill(['code' => $code, 'kind' => $kind, 'name' => $name, 'parent_id' => $parent, 'sort_order' => 1])->save();

    return $node;
}

beforeEach(function () {
    // Monday 2026-11-16, before any shift here starts.
    $this->travelTo(new DateTimeImmutable('2026-11-16T05:00:00+08:00'));
});

/*
|--------------------------------------------------------------------------
| The week table
|--------------------------------------------------------------------------
*/

it('draws each active employee’s week, a column a day, and leaves out those who have left', function () {
    $day = rwkPattern('Day', '08:00:00', '17:00:00');
    rwkEmployee('Liza', 'Domingo', $day);
    rwkEmployee('Lito', 'Former', $day, active: false);
    rwkEmployee('Noel', 'Unrostered', null);
    rwkActor();

    $this->get('/roster')->assertOk()->assertSee('Shift Roster');
    $page = Livewire::test(RosterPage::class);
    $html = $page->html();

    expect($html)->toContain('Nov 16 – Nov 22, 2026')
        ->toContain('Mon 16')->toContain('Sun 22')
        ->toContain('Liza Domingo')->toContain('8:00 AM–5:00 PM')->toContain('Off')
        ->toContain('Noel Unrostered')->toContain('No shift')
        ->not->toContain('Lito Former');
});

it('pages by week and reads any date as the Monday of its week', function () {
    rwkActor();
    $page = Livewire::test(RosterPage::class);

    $page->set('week', '2026-11-19');
    expect($page->html())->toContain('Nov 16 – Nov 22, 2026');
    $page->call('nextWeek');
    expect($page->get('week'))->toBe('2026-11-23')->and($page->html())->toContain('Nov 23 – Nov 29, 2026');
    $page->call('previousWeek')->call('previousWeek');
    expect($page->html())->toContain('Nov 9 – Nov 15, 2026');
    $page->set('week', 'not a date');
    expect($page->html())->toContain('Nov 16 – Nov 22, 2026');
});

it('narrows to a department after the row scope, and names each person’s department under All departments', function () {
    $nursing = rwkNode('1', OrgNode::KIND_DEPARTMENT, 'Nursing');
    $ward = rwkNode('2', OrgNode::KIND_UNIT, 'Ward 2', $nursing->id);
    $position = new Position;
    $position->forceFill(['code' => '10', 'name' => 'Staff nurse', 'org_node_id' => $ward->id, 'sort_order' => 1])->save();
    $day = rwkPattern('Day', '08:00:00', '17:00:00');
    rwkEmployee('Ana', 'Placed', $day, position: $position);
    rwkEmployee('Ben', 'Elsewhere', $day);
    rwkActor();

    $page = Livewire::test(RosterPage::class);
    expect($page->html())->toContain('Ana Placed')->toContain('Ben Elsewhere')->toContain('Nursing · Ward 2');

    $page->set('department', $nursing->id);
    expect($page->html())->toContain('Ana Placed')->not->toContain('Ben Elsewhere');

    // A value the select does not offer never hides rows.
    $page->set('department', 'no-such-node');
    expect($page->get('department'))->toBe('ALL')->and($page->html())->toContain('Ben Elsewhere');
});

it('shows a non-all-data ROSTER holder only their own row, and an unlinked one nobody', function () {
    $day = rwkPattern('Day', '08:00:00', '17:00:00');
    $own = rwkEmployee('Ana', 'Own', $day);
    rwkEmployee('Ben', 'Other', $day);

    rwkActor(['ROSTER'], allData: false, employeeId: $own->id);
    expect(Livewire::test(RosterPage::class)->html())->toContain('Ana Own')->not->toContain('Ben Other');

    rwkActor(['ROSTER'], allData: false);
    $html = Livewire::test(RosterPage::class)->html();
    expect($html)->not->toContain('Ana Own')->not->toContain('Ben Other')->toContain('No employee matches those filters.');
});

it('opens the employee’s month for the week shown, from the whole row and from the name', function () {
    $employee = rwkEmployee('Liza', 'Domingo', rwkPattern('Day', '08:00:00', '17:00:00'));
    rwkActor();
    $url = route('roster.employee', ['employee' => $employee->id, 'month' => '2026-11']);

    $html = Livewire::test(RosterPage::class)->html();

    expect($html)->toContain('href="'.$url.'"')
        ->toContain('Livewire.navigate(')
        ->toContain('aria-label="Open Liza Domingo’s month"');
});

it('marks a shift that starts too soon after the one before, with a note that says why', function () {
    $employee = rwkEmployee('Ramon', 'Aquino', rwkPattern('Day', '08:00:00', '17:00:00'));
    $user = rwkActor();
    $access = Access::forActor($user);
    app(SaveRosterDays::class)->handle($access, $employee->id, ['2026-11-18'], rwkPattern('Night', '22:00:00', '06:00:00')->id, 'DEFAULT');
    app(SaveRosterDays::class)->handle($access, $employee->id, ['2026-11-19'], rwkPattern('AM', '06:00:00', '14:00:00')->id, 'DEFAULT');

    $html = Livewire::test(RosterPage::class)->html();

    expect(substr_count($html, 'data-rest-alert'))->toBe(1)
        ->and($html)->toContain('Less than 8 hours of rest')
        ->toContain('works <b>Night</b> on Wed Nov 18, which ends at 6:00 AM')
        ->toContain('then <b>AM</b> on Thu Nov 19, which starts at 6:00 AM')
        ->toContain('<b>0 hours</b> of rest between them');
});

it('shows approved leave on the week only to a viewer of that employee’s leave', function () {
    $employee = rwkEmployee('Grace', 'Leave', rwkPattern('Day', '08:00:00', '17:00:00'));
    LeaveTravelRequest::query()->create(['code' => 'WK-LV', 'employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE', 'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-11-18', 'end_date' => '2026-11-18', 'status' => 'APPROVED', 'is_paid' => true, 'reason' => 'Private reason']);

    rwkActor(['ROSTER', 'LEAVES']);
    expect(Livewire::test(RosterPage::class)->html())->toContain('>Leave</span>')->toContain('title="Vacation Leave"')->not->toContain('Private reason');

    rwkActor(['ROSTER']);
    expect(Livewire::test(RosterPage::class)->html())->not->toContain('>Leave</span>');
});

it('names leave in one word, the same on the week and the month: Leave, Half-day or Travel', function () {
    $employee = rwkEmployee('Grace', 'Leave', rwkPattern('Day', '08:00:00', '17:00:00'));
    foreach ([['WK-LV', 'VACATION_LEAVE', 'WHOLE_DAY', '2026-11-18'], ['WK-HD', 'SICK_LEAVE', 'HALF_DAY', '2026-11-19'], ['WK-OB', 'BUSINESS_TRAVEL', 'WHOLE_DAY', '2026-11-20']] as [$code, $type, $dayType, $date]) {
        LeaveTravelRequest::query()->create(['code' => $code, 'employee_id' => $employee->id, 'request_type' => $type, 'day_type' => $dayType,
            'start_date' => $date, 'end_date' => $date, 'status' => 'APPROVED', 'is_paid' => $type === 'BUSINESS_TRAVEL' ? null : true, 'reason' => 'Private reason']);
    }
    rwkActor(['ROSTER', 'LEAVES']);

    $week = Livewire::test(RosterPage::class)->html();
    $month = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])->html();

    foreach (['Leave', 'Half-day', 'Travel'] as $word) {
        expect($week)->toContain('>'.$word.'</span>')
            ->and($month)->toMatch('/>\s*'.$word.'\s*</');
    }
    foreach (['On leave', 'Half-day leave', 'Official business', '>OB<'] as $old) {
        expect($week)->not->toContain($old)->and($month)->not->toContain($old);
    }
    expect($week)->toContain('title="Business Travel / OB"')->toContain('title="Sick Leave · half day"');
});

/*
|--------------------------------------------------------------------------
| The employee's month
|--------------------------------------------------------------------------
*/

it('opens an employee’s month only for someone in the actor’s directory', function () {
    $day = rwkPattern('Day', '08:00:00', '17:00:00');
    $own = rwkEmployee('Ana', 'Own', $day);
    $other = rwkEmployee('Ben', 'Other', $day);
    rwkActor(['ROSTER'], allData: false, employeeId: $own->id);

    $this->get(route('roster.employee', ['employee' => $own->id]))->assertOk()
        ->assertSee('Ana Own')
        ->assertSee('Usual week: Day 8:00 AM–5:00 PM, off Sat and Sun')
        ->assertSee('November 2026');
    $this->get(route('roster.employee', ['employee' => $other->id]))->assertNotFound();
    $this->get(route('roster.employee', ['employee' => 'no-such-id']))->assertNotFound();
});

it('marks today, keeps past dates, other months and whole-day leave out of reach, and picks any other dates', function () {
    $employee = rwkEmployee('Liza', 'Domingo', rwkPattern('Day', '08:00:00', '17:00:00'));
    LeaveTravelRequest::query()->create(['code' => 'WK-LV', 'employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE', 'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-11-19', 'end_date' => '2026-11-19', 'status' => 'APPROVED', 'is_paid' => true, 'reason' => 'Private']);
    rwkActor(['ROSTER', 'LEAVES']);
    $page = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id]);

    expect($page->html())->toContain('16 · Today');
    $page->call('pick', '2026-11-15')->call('pick', '2026-11-19')->call('pick', '2026-12-01')->call('pick', 'junk');
    expect($page->get('picked'))->toBe([]);

    $page->call('pick', '2026-11-20')->call('pick', '2026-11-16')->call('pick', '2026-11-18');
    expect($page->get('picked'))->toBe(['2026-11-16', '2026-11-18', '2026-11-20'])
        ->and($page->html())->toContain('3 days')->toContain('Nov 16, 18, 20');

    // Picking a picked date again unpicks it; Cancel unpicks them all.
    $page->call('pick', '2026-11-18');
    expect($page->get('picked'))->toBe(['2026-11-16', '2026-11-20']);
    $page->call('clearPicks');
    // The card stays on the page for the browser to show, hidden with nothing picked.
    expect($page->get('picked'))->toBe([])->and($page->html())->toMatch('/data-picked-card\s+style="display: none;?"/');
});

it('starts the side card on what one date has now, and saves every picked date together', function () {
    $employee = rwkEmployee('Liza', 'Domingo', rwkPattern('Day', '08:00:00', '17:00:00'));
    $am = rwkPattern('AM', '06:00:00', '14:00:00');
    $user = rwkActor();
    app(SaveRosterDays::class)->handle(Access::forActor($user), $employee->id, ['2026-11-18'], $am->id, 'WFH');
    $page = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id]);

    $page->call('pick', '2026-11-18');
    expect([$page->get('dayShift'), $page->get('dayLocation')])->toBe([$am->id, 'WFH']);
    $page->call('pick', '2026-11-18')->call('pick', '2026-11-17');
    // A date with no temporary schedule starts on its usual schedule and place.
    expect([$page->get('dayShift'), $page->get('dayLocation')])->toBe(['DEFAULT', 'DEFAULT'])
        // What the browser fills the card from when that date is picked.
        ->and($page->viewData('card')['2026-11-17'])->toMatchArray(['heading' => 'Tuesday, Nov 17', 'shift' => 'DEFAULT', 'location' => 'DEFAULT', 'usual' => 'Day 8:00 AM–5:00 PM'])
        ->and($page->viewData('card')['2026-11-18'])->toMatchArray(['shift' => $am->id, 'location' => 'WFH'])
        // Past dates are not on it: they cannot be picked.
        ->and($page->viewData('card'))->not->toHaveKey('2026-11-15');

    $page->call('pick', '2026-11-21')->set('dayShift', $am->id)->call('saveDays');

    expect($page->get('picked'))->toBe([]);
    $days = app(RosterCalendar::class)->forEmployees(Access::forActor($user), [$employee->id], '2026-11-17', '2026-11-21')[$employee->id];
    expect($days['2026-11-17']->label)->toBe('AM')
        ->and($days['2026-11-21']->label)->toBe('AM')
        ->and($days['2026-11-21']->works)->toBeTrue()
        ->and($days['2026-11-19']->label)->toBe('Day');
});

it('gives a viewer without all-data ROSTER a read-only month', function () {
    $employee = rwkEmployee('Ana', 'Own', rwkPattern('Day', '08:00:00', '17:00:00'));
    rwkActor(['ROSTER'], allData: false, employeeId: $employee->id);
    $page = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id]);

    expect($page->html())->not->toContain('Default Schedule');
    $page->call('pick', '2026-11-18')->call('openDefaultSchedule');
    expect($page->get('picked'))->toBe([])->and($page->get('shiftDays'))->toBe([]);

    // A client that sets the draft itself saves nothing either.
    $page->set('picked', ['2026-11-18'])->set('dayShift', 'REST')->call('saveDays');
    expect(RosterDayOverride::query()->count())->toBe(0);
});

it('edits the usual week in Default schedule, and holds a change past today’s cutoff for review', function () {
    $employee = rwkEmployee('Liza', 'Domingo', rwkPattern('Day', '08:00:00', '17:00:00'));
    $am = rwkPattern('AM', '06:00:00', '14:00:00');
    rwkActor();
    // 10:00 on Monday: today's Day shift has started.
    $this->travelTo(new DateTimeImmutable('2026-11-16T10:00:00+08:00'));
    $page = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id]);

    $page->call('openDefaultSchedule');
    expect($page->get('shiftDays'))->toBe(array_fill(0, 7, 'DEFAULT'))
        ->and($page->html())->toContain('id="default-schedule-heading"');

    // Monday is today and its shift has begun, so the change moves to tomorrow.
    $page->set('shiftDays.1', $am->id)->set('shiftDays.3', 'REST')->call('saveDefaultSchedule');
    expect($page->get('reviewAction'))->toBe('week')
        ->and(RosterShift::query()->count())->toBe(0);

    $page->call('confirmReview');
    expect(RosterShift::query()->where('employee_id', $employee->id)->whereNotNull('shift_data')->count())->toBe(2)
        ->and(RosterShift::query()->where('employee_id', $employee->id)->pluck('effective_from')->map->format('Y-m-d')->unique()->values()->all())->toBe(['2026-11-17'])
        ->and($page->get('shiftDays'))->toBe([]);
});

it('refuses a save of dates the calendar would not offer, as the browser picks them', function () {
    $employee = rwkEmployee('Liza', 'Domingo', rwkPattern('Day', '08:00:00', '17:00:00'));
    LeaveTravelRequest::query()->create(['code' => 'WK-LV', 'employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE', 'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-11-19', 'end_date' => '2026-11-19', 'status' => 'APPROVED', 'is_paid' => true, 'reason' => 'Private']);
    rwkActor(['ROSTER', 'LEAVES']);
    $page = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id]);

    // On leave, in another month, in the past: each refused, nothing saved.
    foreach ([['2026-11-18', '2026-11-19'], ['2026-12-02'], ['2026-11-13']] as $dates) {
        $page->set('picked', $dates)->set('dayShift', 'REST')->call('saveDays');
        expect(RosterDayOverride::query()->count())->toBe(0);
    }
    $page->set('picked', ['2026-11-18'])->set('dayShift', 'REST')->call('saveDays');
    expect(RosterDayOverride::query()->count())->toBe(1);
});

it('confirms exactly the week the review showed, though closing the dialog dropped its draft', function () {
    $employee = rwkEmployee('Liza', 'Domingo', rwkPattern('Day', '08:00:00', '17:00:00'));
    $am = rwkPattern('AM', '06:00:00', '14:00:00');
    rwkActor();
    $this->travelTo(new DateTimeImmutable('2026-11-16T10:00:00+08:00'));
    $page = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id]);

    $page->call('openDefaultSchedule')->set('shiftDays.1', $am->id)->call('saveDefaultSchedule');
    expect($page->get('reviewAction'))->toBe('week');

    // What closing the dialog does in the browser.
    $page->set('shiftDays', [])->set('locationDays', [])->set('customHours', [])->set('customSlots', []);
    $page->call('confirmReview');

    $monday = RosterShift::query()->where('employee_id', $employee->id)->where('weekday', 1)->sole();
    expect($monday->shift_type_id)->toBe($am->id)
        ->and($monday->effective_from?->format('Y-m-d'))->toBe('2026-11-17')
        ->and($page->get('reviewInput'))->toBe([]);
});
