<?php

declare(strict_types=1);

use App\Livewire\Edtr\EdtrPage;
use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterScheduleChange;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Commands\SaveAssignment;
use App\Modules\Timekeeping\Application\Queries\ScheduleDays;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The schedule EACH DATE was measured against is a read of its own, never
 * the employee's current one, and says how far it can be trusted: recorded,
 * assumed, may have differed — or NO ASSIGNED SCHEDULE, whose figures come
 * from the system default and are counted apart so they never pass for the
 * employee's record. The Daily Time Record's Date cell shows ONLY THE DATE:
 * the shift a day was measured against is read in its dialog, and the
 * unscheduled days are counted in the card above the table. Punches are
 * shown as recorded whatever the basis. The read is scoped like the record
 * beside it.
 */
/** @param list<string> $modules */
function dscActor(array $modules = ['EDTR', 'ROSTER'], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'DTR schedule '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function dscPattern(string $name, string $start, string $end): ShiftType
{
    return ShiftType::query()->create(['code' => Str::random(10), 'name' => $name, 'mode' => 'FIXED', 'start_time' => $start, 'end_time' => $end,
        'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);
}

function dscEmployee(string $first = 'Sol'): EmployeeProfile
{
    return EmployeeProfile::query()->create(['employee_code' => 'DS-'.Str::random(8), 'first_name' => $first, 'last_name' => 'Santos',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true]);
}

function dscPunch(EmployeeProfile $employee, string $type, string $manilaStamp): void
{
    $log = new AttendanceLog;
    $log->forceFill(['employee_id' => $employee->id, 'log_type' => $type, 'source' => 'WEB',
        'logged_at' => ManilaClock::instant($manilaStamp)->utc()])->save();
}

/** One DTR row's markup. */
function dscRow(string $html, string $date): string
{
    return Str::before(Str::after($html, 'wire:key="dtr-'.$date.'"'), '</tr>');
}

/** The Date cell of one DTR row, tags stripped: the date and nothing else. */
function dscDateCell(string $html, string $date): string
{
    $cell = Str::before(Str::after(dscRow($html, $date), '<td'), '</td>');

    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags('<td'.$cell))));
}

beforeEach(function () {
    // Wednesday 2026-10-07, before any shift here starts.
    $this->travelTo(new DateTimeImmutable('2026-10-07T05:00:00+08:00'));
});

it('reads the schedule each date was measured against, and shows only the date in the Date cell', function () {
    $day = dscPattern('Day', '08:00:00', '17:00:00');
    $late = dscPattern('Late', '13:00:00', '22:00:00');
    $sol = dscEmployee();
    // The schedule on record when history began on Oct 5; the audit trail
    // records a change on Oct 1.
    Shift::query()->create(ShiftPolicy::attributesFromPattern($day) + ['employee_id' => $sol->id]);
    RosterScheduleChange::query()->create(['employee_id' => $sol->id, 'effective_from' => null, 'source' => 'BASELINE', 'status' => 'ACTIVE',
        'assumed_before' => '2026-10-05', 'may_differ_through' => '2026-10-01']);
    $actor = dscActor();
    app(AssignShift::class)->handle($actor, $sol->id, $late->id, '2026-10-08');
    app(SaveAssignment::class)->handle($actor, $sol->id, '2026-10-07', null, $late->id, 'DEFAULT');

    $days = app(ScheduleDays::class)->forEmployee($actor, $sol->id, '2026-09-30', '2026-10-09');
    expect(array_map(static fn ($d): array => [$d->source, $d->basis, $d->works, $d->hours], $days))->toBe([
        '2026-09-30' => ['DEFAULT', 'MAY_DIFFER', true, '8:00 AM–5:00 PM'],
        '2026-10-01' => ['DEFAULT', 'MAY_DIFFER', true, '8:00 AM–5:00 PM'],
        '2026-10-02' => ['DEFAULT', 'ASSUMED', true, '8:00 AM–5:00 PM'],
        '2026-10-03' => ['DEFAULT', 'ASSUMED', false, '8:00 AM–5:00 PM'],
        '2026-10-04' => ['DEFAULT', 'ASSUMED', false, '8:00 AM–5:00 PM'],
        '2026-10-05' => ['DEFAULT', 'RECORDED', true, '8:00 AM–5:00 PM'],
        '2026-10-06' => ['DEFAULT', 'RECORDED', true, '8:00 AM–5:00 PM'],
        '2026-10-07' => ['TEMPORARY', 'RECORDED', true, '1:00 PM–10:00 PM'],
        '2026-10-08' => ['DEFAULT', 'RECORDED', true, '1:00 PM–10:00 PM'],
        '2026-10-09' => ['DEFAULT', 'RECORDED', true, '1:00 PM–10:00 PM'],
    ]);

    $html = Livewire::test(EdtrPage::class)->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', $sol->id)
        ->set('start', '2026-09-30')->set('end', '2026-10-09')
        // The table says nothing of the basis: no marker under any date, no legend.
        ->assertDontSee('May have differed')
        ->assertDontSee('no assigned schedule')
        ->html();

    expect($html)->not->toContain('data-schedule-legend')->not->toContain('data-shift');
    // Whatever the basis — may have differed, assumed, recorded, temporary, a rest
    // day, or a date ahead of today — the Date cell is the date alone.
    foreach (['2026-10-01' => 'Oct 01, 2026', '2026-10-02' => 'Oct 02, 2026', '2026-10-03' => 'Oct 03, 2026', '2026-10-06' => 'Oct 06, 2026', '2026-10-07' => 'Oct 07, 2026', '2026-10-08' => 'Oct 08, 2026'] as $date => $label) {
        expect(dscDateCell($html, $date))->toBe($label)
            // (The Remarks cell may still say "Rest day": that is the day's remark, not its schedule.)
            ->and(dscRow($html, $date))->not->toContain($days[$date]->hours)->not->toContain('Assumed')->not->toContain('Temporary');
    }
});

it('counts the dates nobody assigned a schedule for apart, and keeps their punches', function () {
    $late = dscPattern('Late', '13:00:00', '22:00:00');
    $sol = dscEmployee();
    // Worked 13:00 to 22:00 on Monday, before any schedule was given.
    dscPunch($sol, 'TIME_IN', '2026-10-05T13:00:00');
    dscPunch($sol, 'TIME_OUT', '2026-10-05T22:00:00');
    $actor = dscActor();
    // Assigned today: today onward only, so earlier dates stay unassigned.
    app(AssignShift::class)->handle($actor, $sol->id, $late->id);

    $days = app(ScheduleDays::class)->forEmployee($actor, $sol->id, '2026-10-05', '2026-10-07');
    expect(array_map(static fn ($d): array => [$d->source, $d->basis], $days))->toBe([
        '2026-10-05' => ['NONE', 'NONE'],
        '2026-10-06' => ['NONE', 'NONE'],
        '2026-10-07' => ['DEFAULT', 'RECORDED'],
    ]);

    $page = Livewire::test(EdtrPage::class)->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', $sol->id)
        ->set('start', '2026-10-05')->set('end', '2026-10-07')
        ->assertSee('days have no assigned schedule and need review.')
        ->assertSee('Punches, captured locations and remarks are kept exactly as recorded.')
        // The default's 08:00 start makes a 13:00 Time In five hours late.
        ->assertSee('5h on unscheduled days')
        ->assertSee('1 on unscheduled days');
    $html = $page->html();

    expect($html)->toContain('data-unscheduled-days="2"')
        // The row wears no marker: the card above counts the days, the dialog names the basis.
        ->and(dscDateCell($html, '2026-10-05'))->toBe('Oct 05, 2026')
        ->and(dscDateCell($html, '2026-10-07'))->toBe('Oct 07, 2026')
        ->and($html)->not->toContain('System default')->not->toContain('data-schedule-legend')
        // The punches are the record, whatever they are measured against.
        ->and(dscRow($html, '2026-10-05'))->toContain('1:00 PM')->toContain('10:00 PM')
        ->and(AttendanceLog::query()->where('employee_id', $sol->id)->count())->toBe(2);
});

it('shows no review notice once every date of the period has an assigned schedule', function () {
    $sol = dscEmployee();
    Shift::query()->create(ShiftPolicy::attributesFromPattern(dscPattern('Day', '08:00:00', '17:00:00')) + ['employee_id' => $sol->id]);
    dscActor();

    $html = Livewire::test(EdtrPage::class)->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', $sol->id)
        ->set('start', '2026-10-05')->set('end', '2026-10-07')
        ->assertDontSee('no assigned schedule')
        ->assertDontSee('on unscheduled days')
        ->html();

    expect($html)->not->toContain('data-unscheduled-days')
        ->and(dscDateCell($html, '2026-10-05'))->toBe('Oct 05, 2026');
});

it('answers only the actor’s own schedule without all-data, and nothing without the module', function () {
    $day = dscPattern('Day', '08:00:00', '17:00:00');
    $sol = dscEmployee();
    $luz = dscEmployee('Luz');
    Shift::query()->create(ShiftPolicy::attributesFromPattern($day) + ['employee_id' => $sol->id]);
    Shift::query()->create(ShiftPolicy::attributesFromPattern($day) + ['employee_id' => $luz->id]);

    $own = dscActor(['EDTR'], allData: false, employeeId: $sol->id);
    expect(app(ScheduleDays::class)->forEmployee($own, $sol->id, '2026-10-05', '2026-10-06'))->toHaveCount(2)
        ->and(app(ScheduleDays::class)->forEmployee($own, $luz->id, '2026-10-05', '2026-10-06'))->toBe([]);

    $unlinked = dscActor(['EDTR'], allData: false);
    expect(app(ScheduleDays::class)->forEmployee($unlinked, $sol->id, '2026-10-05', '2026-10-06'))->toBe([])
        ->and(app(ScheduleDays::class)->forEmployee($unlinked, '', '2026-10-05', '2026-10-06'))->toBe([]);

    $elsewhere = dscActor(['TASKS'], allData: true, employeeId: $sol->id);
    expect(app(ScheduleDays::class)->forEmployee($elsewhere, $sol->id, '2026-10-05', '2026-10-06'))->toBe([]);

    $all = dscActor(['PAYROLL'], allData: true);
    expect(app(ScheduleDays::class)->forEmployee($all, $luz->id, '2026-10-05', '2026-10-06'))->toHaveCount(2)
        // Not a range: nothing, never a guess.
        ->and(app(ScheduleDays::class)->forEmployee($all, $luz->id, '2026-10-06', '2026-10-05'))->toBe([])
        ->and(app(ScheduleDays::class)->forEmployee($all, $luz->id, 'soon', '2026-10-05'))->toBe([])
        // Bounded to a year of days however long the range asked for.
        ->and(app(ScheduleDays::class)->forEmployee($all, $luz->id, '2026-01-01', '2030-12-31'))->toHaveCount(366);
});

it('labels the day dialog’s place as assigned and each punch’s as captured', function () {
    $sol = dscEmployee();
    Shift::query()->create(ShiftPolicy::attributesFromPattern(dscPattern('Day', '08:00:00', '17:00:00')) + ['employee_id' => $sol->id]);
    $log = new AttendanceLog;
    $log->forceFill(['employee_id' => $sol->id, 'log_type' => 'TIME_IN', 'source' => 'WEB', 'location_kind' => 'WFH',
        'latitude' => 14.5995, 'longitude' => 120.9842, 'logged_at' => ManilaClock::instant('2026-10-06T08:00:00')->utc()])->save();
    dscActor();

    Livewire::test(EdtrPage::class)->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', $sol->id)
        ->set('start', '2026-10-05')->set('end', '2026-10-07')
        ->call('openCorrection', '2026-10-06')
        ->assertSeeInOrder(['Assigned location', 'Captured location', 'Web GPS']);
});
