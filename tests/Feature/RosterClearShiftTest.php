<?php

declare(strict_types=1);

use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Livewire\Edtr\PunchStation;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterLocation;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Commands\CancelScheduleChange;
use App\Modules\Timekeeping\Application\Commands\ClearShift;
use App\Modules\Timekeeping\Application\Commands\RecordPunch;
use App\Modules\Timekeeping\Application\Commands\SaveShiftType;
use App\Modules\Timekeeping\Application\PunchInput;
use App\Modules\Timekeeping\Application\Queries\DayCorrections;
use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Modules\Timekeeping\Application\Queries\RosterHistory;
use App\Modules\Timekeeping\Application\Queries\RosterLocations;
use App\Modules\Timekeeping\Application\Queries\RosterShifts;
use App\Modules\Timekeeping\Application\Queries\ScheduleDays;
use App\Modules\Timekeeping\Application\ScheduleImpactPending;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Modules\Timekeeping\Application\ShiftTypeDraft;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Domain\Roster\ScheduleBasis;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * An employee's shift can be taken away, and only as dated history: from
 * the effective date they have no default shift and no weekday hours — read
 * exactly like someone never rostered (the engine default, counted as
 * unscheduled) — while every earlier date keeps the shift it was judged by.
 * Clearing runs under the same safeguards and review as any roster
 * change — moved past today's cutoff, it is saved only with the token of
 * the impact shown — can be cancelled before it takes effect, is undone by
 * assigning a shift again, never reaches a shift through a pattern edit,
 * needs all-data ROSTER, and writes nothing when there is nothing to clear.
 * With no shift on a date — cleared or never assigned — there is no Time
 * In: the punch station refuses it and says so beforehand, while a Time
 * Out still closes a Time In already open.
 */
function rcsActor(bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Clear shift '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach (['ROSTER', 'RECORDS', 'EDTR', 'PAYROLL'] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/** @param list<int> $days */
function rcsPattern(string $name, string $start, string $end, array $days = [1, 2, 3, 4, 5]): ShiftType
{
    return ShiftType::query()->create(['code' => Str::random(10), 'name' => $name, 'mode' => 'FIXED', 'start_time' => $start, 'end_time' => $end,
        'work_days' => $days, 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);
}

/** An employee on a baseline default shift, or on none. */
function rcsEmployee(?ShiftType $default, string $first = 'Sol'): EmployeeProfile
{
    $employee = EmployeeProfile::query()->create(['employee_code' => 'RR-'.Str::random(8), 'first_name' => $first, 'last_name' => 'Santos',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'monthly_rate' => 30000, 'is_active' => true]);
    if ($default !== null) {
        Shift::query()->create(ShiftPolicy::attributesFromPattern($default) + ['employee_id' => $employee->id]);
    }

    return $employee;
}

/** A baseline weekday pick of a pattern. */
function rcsWeekday(EmployeeProfile $employee, int $weekday, ShiftType $pattern): void
{
    RosterShift::query()->create(['employee_id' => $employee->id, 'weekday' => $weekday, 'shift_type_id' => $pattern->id, 'shift_name' => $pattern->name, 'is_rest_day' => false,
        'shift_data' => DomainShift::fromArray(array_replace(ShiftPolicy::attributesFromPattern($pattern), ['work_days' => [$weekday]]))->toArray()]);
}

/** @return list<array<string, mixed>> */
function rcsDtr(EmployeeProfile $employee, string $start, string $end): array
{
    $inputs = app(AttendanceSource::class)->forDtr(app(Access::class), $employee->id, $start, $end);

    return json_decode((string) json_encode(DtrCalculator::compute($inputs->logs, $inputs->shift, $inputs->leaves, $start, $end)), true);
}

beforeEach(function () {
    // Wednesday 2026-10-07, before any shift here starts.
    $this->travelTo(new DateTimeImmutable('2026-10-07T05:00:00+08:00'));
});

/* |---| The command |---| */

it('clears the default shift and weekday hours from today, keeping every earlier date on the shift it had', function () {
    $actor = rcsActor();
    $day = rcsPattern('Day', '09:00:00', '18:00:00');
    $sol = rcsEmployee($day);
    rcsWeekday($sol, 6, rcsPattern('Saturday half', '08:00:00', '12:00:00', [6]));
    foreach (['TIME_IN' => '2026-10-05T09:20:00', 'TIME_OUT' => '2026-10-05T18:00:00'] as $type => $at) {
        AttendanceLog::query()->create(['employee_id' => $sol->id, 'log_type' => $type, 'source' => 'MANUAL', 'logged_at' => ManilaClock::instant($at)->utc()]);
    }
    $before = rcsDtr($sol, '2026-10-01', '2026-10-06');

    $message = app(ClearShift::class)->handle($actor, $sol->id);

    $roster = app(ShiftRoster::class);
    $month = $roster->scheduleForEmployee($sol->id, '2026-10-01', '2026-10-31');
    $sources = $roster->explainedForEmployees([$sol->id], '2026-10-05', '2026-10-09')[$sol->id]['sources'];
    $days = app(ScheduleDays::class)->forEmployee($actor, $sol->id, '2026-10-06', '2026-10-07');
    expect($message)->toBe("Sol Santos's shift cleared.")
        // Earlier dates: the shift, and the DTR, exactly as they were.
        ->and(rcsDtr($sol, '2026-10-01', '2026-10-06'))->toBe($before)
        ->and($roster->forDate($sol->id, '2026-10-06')?->startTime)->toBe('09:00:00')
        ->and($month !== null && ShiftResolver::worksOnDate('2026-10-03', $month))->toBeTrue()
        // From today: nothing assigned, so the engine default stands in.
        ->and($roster->forDate($sol->id, '2026-10-07'))->toBeNull()
        ->and($roster->forDate($sol->id, '2026-10-12'))->toBeNull()
        ->and($month?->forDate('2026-10-12')->startTime)->toBe('08:00:00')
        ->and($month !== null && ShiftResolver::worksOnDate('2026-10-10', $month))->toBeFalse()
        ->and($sources)->toBe(['2026-10-05' => 'DEFAULT', '2026-10-06' => 'DEFAULT', '2026-10-07' => 'NONE', '2026-10-08' => 'NONE', '2026-10-09' => 'NONE'])
        ->and($days['2026-10-06']->basis)->not->toBe(ScheduleBasis::NONE)
        ->and($days['2026-10-07']->basis)->toBe(ScheduleBasis::NONE)
        // The roster's own reads: no default shift, no weekday hours today.
        ->and(app(ReferenceData::class)->shift($actor, $sol->id))->toBeNull()
        ->and(app(ReferenceData::class)->shift($actor, $sol->id, '2026-10-06')?->shift_type_id)->toBe($day->id)
        ->and(app(ReferenceData::class)->shifts($actor)->pluck('employee_id')->all())->not->toContain($sol->id)
        ->and(app(RosterShifts::class)->weekly($actor, $sol->id))->toBe([]);

    // History rows were added, never deleted: the baseline rows stay.
    $change = RosterScheduleChange::query()->sole();
    expect($change->effective_from?->format('Y-m-d'))->toBe('2026-10-07')
        ->and($change->source)->toBe('MANUAL')
        ->and(Shift::query()->count())->toBe(2)
        ->and(Shift::query()->where('change_id', $change->id)->sole()->is_unassigned)->toBeTrue()
        ->and(Shift::query()->where('change_id', $change->id)->sole()->shift_type_id)->toBeNull()
        ->and(RosterShift::query()->where('change_id', $change->id)->sole()->shift_data)->toBeNull()
        ->and(app(RosterHistory::class)->forEmployee($actor, $sol->id)['changes'][0]->sets)->toBe(['Default shift: none (cleared)', 'Sat: default shift']);

    $audit = AuditLog::query()->sole();
    expect($audit->action)->toBe('SHIFT_CLEARED')
        ->and($audit->entity)->toBe('shifts')
        ->and($audit->entity_id)->toBe($sol->id)
        ->and($audit->details)->toBe('Sol Santos shift cleared · was default 9:00 AM–6:00 PM · Sat Saturday half');
});

it('clears from a later date, which reads in the message and the audit sentence', function () {
    $actor = rcsActor();
    $sol = rcsEmployee(rcsPattern('Day', '08:00:00', '17:00:00'));

    expect(app(ClearShift::class)->handle($actor, $sol->id, '2026-10-12'))->toBe("Sol Santos's shift cleared. It takes effect on Oct 12, 2026.");

    $roster = app(ShiftRoster::class);
    expect($roster->forDate($sol->id, '2026-10-09')?->startTime)->toBe('08:00:00')
        ->and($roster->forDate($sol->id, '2026-10-12'))->toBeNull()
        ->and(AuditLog::query()->sole()->details)->toBe('Sol Santos shift cleared · was default 8:00 AM–5:00 PM · from Oct 12, 2026');
});

it('writes and audits nothing when there is no shift to clear', function () {
    $actor = rcsActor();
    $none = rcsEmployee(null, 'Luz');
    $sol = rcsEmployee(rcsPattern('Day', '08:00:00', '17:00:00'));
    app(ClearShift::class)->handle($actor, $sol->id);
    $rows = [Shift::query()->count(), RosterShift::query()->count(), RosterScheduleChange::query()->count(), AuditLog::query()->count()];

    expect(app(ClearShift::class)->handle($actor, $none->id))->toBe('No changes: Luz Santos has no shift to clear.')
        ->and(app(ClearShift::class)->handle($actor, $sol->id))->toBe('No changes: Sol Santos has no shift to clear.')
        ->and([Shift::query()->count(), RosterShift::query()->count(), RosterScheduleChange::query()->count(), AuditLog::query()->count()])->toBe($rows);
});

it('refuses a past date and an actor without all-data ROSTER, writing and auditing nothing', function () {
    $sol = rcsEmployee(rcsPattern('Day', '08:00:00', '17:00:00'));
    $viewer = rcsActor(allData: false, employeeId: $sol->id);

    expect(fn () => app(ClearShift::class)->handle($viewer, $sol->id))->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    expect(fn () => app(ClearShift::class)->handle(rcsActor(), $sol->id, '2026-10-06'))->toThrow(RuntimeException::class);
    expect(fn () => app(ClearShift::class)->handle(rcsActor(), 'no-such-employee'))->toThrow(RuntimeException::class, 'That employee no longer exists.');

    expect(Shift::query()->count())->toBe(1)
        ->and(RosterScheduleChange::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('lets a clearing still ahead be cancelled, and the shift simply continues', function () {
    $actor = rcsActor();
    $sol = rcsEmployee(rcsPattern('Day', '08:00:00', '17:00:00'));
    app(ClearShift::class)->handle($actor, $sol->id, '2026-10-12');

    app(CancelScheduleChange::class)->handle($actor, RosterScheduleChange::query()->sole()->id);

    expect(app(ShiftRoster::class)->forDate($sol->id, '2026-10-12')?->startTime)->toBe('08:00:00')
        ->and(app(ReferenceData::class)->shift($actor, $sol->id, '2026-10-12'))->not->toBeNull();
});

it('takes a shift assigned again after it was cleared, from that date only', function () {
    $actor = rcsActor();
    $sol = rcsEmployee(rcsPattern('Day', '08:00:00', '17:00:00'));
    $late = rcsPattern('Late', '13:00:00', '22:00:00');
    app(ClearShift::class)->handle($actor, $sol->id);

    app(AssignShift::class)->handle($actor, $sol->id, $late->id, '2026-10-12');

    $roster = app(ShiftRoster::class);
    expect($roster->forDate($sol->id, '2026-10-08'))->toBeNull()
        ->and($roster->forDate($sol->id, '2026-10-12')?->startTime)->toBe('13:00:00')
        ->and($roster->explainedForEmployees([$sol->id], '2026-10-09', '2026-10-12')[$sol->id]['sources'])
        ->toBe(['2026-10-09' => 'NONE', '2026-10-10' => 'NONE', '2026-10-11' => 'NONE', '2026-10-12' => 'DEFAULT']);
});

it('never puts a cleared shift back through an edit of its old pattern', function () {
    $actor = rcsActor();
    $day = rcsPattern('Day', '08:00:00', '17:00:00');
    $sol = rcsEmployee($day);
    $luz = rcsEmployee($day, 'Luz');
    app(ClearShift::class)->handle($actor, $sol->id);
    app(ClearShift::class)->handle($actor, $luz->id, '2026-10-12');

    app(SaveShiftType::class)->handle($actor, $day->id, new ShiftTypeDraft('Day', 'FIXED', [1, 2, 3, 4, 5], '08:30', '17:30', '0', '', false, '00:00', '01:00', '0', false));

    $roster = app(ShiftRoster::class);
    expect($roster->forDate($sol->id, '2026-10-07'))->toBeNull()
        ->and($roster->forDate($sol->id, '2026-10-12'))->toBeNull()
        // Luz is on the edited hours until her clearing takes effect.
        ->and($roster->forDate($luz->id, '2026-10-08')?->startTime)->toBe('08:30:00')
        ->and($roster->forDate($luz->id, '2026-10-12'))->toBeNull()
        ->and(app(ReferenceData::class)->shiftTypeUsage($actor)[$day->id] ?? 0)->toBe(1);
});

/* |---| No shift, no Time In |---| */

/** Every weekday from home, so a punch is judged by its shift alone. */
function rcsFromHome(EmployeeProfile $employee): void
{
    foreach (range(0, 6) as $weekday) {
        RosterLocation::query()->create(['employee_id' => $employee->id, 'weekday' => $weekday, 'kind' => 'WFH', 'site_id' => null]);
    }
}

function rcsPunch(Access $actor, EmployeeProfile $employee, string $type): ?string
{
    return app(RecordPunch::class)->handle($actor, new PunchInput($employee->id, '', 'WEB_GPS', false, '', ''), $type);
}

it('refuses a Time In on a date with no shift, cleared or never assigned, while a rostered colleague keeps the usual window', function () {
    $actor = rcsActor();
    $day = rcsPattern('Day', '08:00:00', '17:00:00');
    $sol = rcsEmployee($day);
    $luz = rcsEmployee($day, 'Luz');
    $ana = rcsEmployee(null, 'Ana');
    foreach ([$sol, $luz, $ana] as $employee) {
        rcsFromHome($employee);
    }
    app(ClearShift::class)->handle($actor, $sol->id, '2026-10-08');
    // 06:30 on Sol's first day without a shift: the three-day read that
    // decides the work date still holds Oct 6–7 on the Day shift.
    $this->travelTo(new DateTimeImmutable('2026-10-08T06:30:00+08:00'));

    // Still rostered: the window opens at 7:00 AM.
    expect(fn () => rcsPunch($actor, $luz, 'TIME_IN'))->toThrow(RuntimeException::class, 'too early');
    expect(fn () => rcsPunch($actor, $sol, 'TIME_IN'))->toThrow(RuntimeException::class, 'Time In is blocked: Sol Santos has no shift on Oct 08, 2026. Assign one in Shift Roster first.');
    expect(fn () => rcsPunch($actor, $ana, 'TIME_IN'))->toThrow(RuntimeException::class, 'Time In is blocked: Ana Santos has no shift on Oct 08, 2026.');

    $stations = app(RosterLocations::class);
    expect(AttendanceLog::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'like', 'PUNCH_%')->count())->toBe(0)
        // The station says so before anyone presses Time In.
        ->and($stations->forStation($actor, $sol->id)?->has_shift)->toBeFalse()
        ->and($stations->forStation($actor, $sol->id)?->open_time_in)->toBeFalse()
        ->and($stations->forStation($actor, $ana->id)?->has_shift)->toBeFalse()
        ->and($stations->forStation($actor, $luz->id)?->has_shift)->toBeTrue()
        // The correction dialog reads the cleared date as unrostered too.
        ->and(app(DayCorrections::class)->view($actor, $sol->id, '2026-10-08')?->shift->rostered)->toBeFalse()
        ->and(app(DayCorrections::class)->view($actor, $sol->id, '2026-10-07')?->shift->rostered)->toBeTrue();
});

it('still takes a Time Out that closes a Time In already open on a date with no shift', function () {
    $actor = rcsActor();
    $ana = rcsEmployee(null, 'Ana');
    rcsFromHome($ana);
    // Entered by a DTR correction, which a date with no shift still allows.
    AttendanceLog::query()->create(['employee_id' => $ana->id, 'log_type' => 'TIME_IN', 'source' => 'MANUAL', 'logged_at' => ManilaClock::instant('2026-10-07T04:00:00')->utc()]);

    rcsPunch($actor, $ana, 'TIME_OUT');

    expect(AttendanceLog::query()->where('employee_id', $ana->id)->orderBy('logged_at')->pluck('log_type')->all())->toBe(['TIME_IN', 'TIME_OUT'])
        // With nothing open, a Time Out is refused as before.
        ->and(fn () => rcsPunch($actor, $ana, 'TIME_OUT'))->toThrow(RuntimeException::class, 'TIME_OUT with no open TIME_IN.');
});

/**
 * The portal's own punch station for `$employee`, as that employee.
 *
 * @return Testable<PunchStation>
 */
function rcsStation(EmployeeProfile $employee): Testable
{
    return Livewire::test(PunchStation::class, ['lockedEmployeeId' => $employee->id, 'selfService' => true, 'showClock' => false]);
}

it('turns both punch buttons off for an employee with no shift and nothing open, and says so', function () {
    $ana = rcsEmployee(null, 'Ana');
    $luz = rcsEmployee(rcsPattern('Day', '08:00:00', '17:00:00'), 'Luz');
    rcsFromHome($ana);
    rcsFromHome($luz);
    rcsActor(employeeId: $ana->id);

    $page = rcsStation($ana);
    // No card: the disabled buttons carry the reason as their tooltip.
    $page->assertSeeHtml('title="No shift on Oct 07, 2026. A shift has to be assigned in Shift Roster first."')
        ->assertDontSee('Your location is checked against')
        ->assertViewHas('noShift', true)
        ->assertViewHas('noShiftCanClose', false);
    expect(substr_count($page->html(), 'title="No shift on Oct 07, 2026.'))->toBe(2);
    // The buttons are off, and the actions refuse all the same.
    $page->call('punch', 'TIME_IN', null, null, null);
    $page->call('punch', 'TIME_OUT', null, null, null);
    expect(AttendanceLog::query()->count())->toBe(0);

    rcsActor(employeeId: $luz->id);
    rcsStation($luz)->assertDontSeeHtml('title="No shift on')->assertViewHas('noShift', false)->assertViewHas('noShiftCanClose', false);
});

it('lets a night begun before the shift was cleared end with a Time Out, and offers nothing after it', function () {
    $sol = rcsEmployee(rcsPattern('Night', '22:00:00', '06:00:00', [0, 1, 2, 3, 4, 5, 6]));
    rcsFromHome($sol);
    $actor = rcsActor(employeeId: $sol->id);
    // Cleared from tomorrow; tonight's shift still starts at 10:00 PM.
    app(ClearShift::class)->handle($actor, $sol->id, '2026-10-08');
    $this->travelTo(new DateTimeImmutable('2026-10-07T22:00:00+08:00'));
    rcsPunch($actor, $sol, 'TIME_IN');

    // 7:00 AM: the night is over and the new day has no shift, but the
    // night's Time In is still open, so only Time Out is offered.
    $this->travelTo(new DateTimeImmutable('2026-10-08T07:00:00+08:00'));
    rcsStation($sol)
        ->assertSeeHtml('title="Closes the Time In still open."')
        ->assertSeeHtml('title="No shift on Oct 08, 2026. A shift has to be assigned in Shift Roster first."')
        ->assertViewHas('noShift', true)
        ->assertViewHas('noShiftCanClose', true);
    expect(fn () => rcsPunch($actor, $sol, 'TIME_IN'))->toThrow(RuntimeException::class, 'has no shift on Oct 08, 2026');

    rcsPunch($actor, $sol, 'TIME_OUT');

    expect(AttendanceLog::query()->orderBy('logged_at')->pluck('log_type')->all())->toBe(['TIME_IN', 'TIME_OUT']);
    rcsStation($sol)
        ->assertDontSeeHtml('title="Closes the Time In still open."')
        ->assertViewHas('noShiftCanClose', false);
});

/* |---| Review |---| */

it('holds a clearing that moved to the next date for review, and saves it only when confirmed', function () {
    $actor = rcsActor();
    $sol = rcsEmployee(rcsPattern('Day', '08:00:00', '17:00:00'));
    // 10:00: today's 08:00 shift has started.
    $this->travelTo(new DateTimeImmutable('2026-10-07T10:00:00+08:00'));

    $impact = app(ClearShift::class)->preview($actor, $sol->id);
    expect($impact->effective)->toBe([$sol->id => '2026-10-08'])
        ->and($impact->needsConfirmation())->toBeTrue()
        ->and(fn () => app(ClearShift::class)->handle($actor, $sol->id))->toThrow(ScheduleImpactPending::class);
    expect(RosterScheduleChange::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);

    expect(app(ClearShift::class)->handle($actor, $sol->id, confirmed: $impact->token()))
        ->toBe("Sol Santos's shift cleared. It takes effect on Oct 08, 2026.");
    expect(RosterScheduleChange::query()->sole()->effective_from?->format('Y-m-d'))->toBe('2026-10-08')
        ->and(app(ShiftRoster::class)->forDate($sol->id, '2026-10-07')?->startTime)->toBe('08:00:00')
        ->and(app(ShiftRoster::class)->forDate($sol->id, '2026-10-08'))->toBeNull()
        ->and(AuditLog::query()->where('action', 'SHIFT_CLEARED')->count())->toBe(1);
});

/* |---| The migration |---| */

/**
 * Run `$run` in the window before the migration: the column dropped FIRST,
 * before anything is seeded, then restored through the migration's own
 * up() — twice, the retry — the MariaDB-safe shape of the testing guide.
 */
function rcsWithoutColumn(Closure $run): void
{
    $migration = require base_path('database/migrations/2026_11_09_0004_add_is_unassigned_to_shifts_table.php');
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $migration->down();
    $migration->down();
    Once::flush();
    if ($engine) {
        DB::connection()->getPdo()->beginTransaction();
    }

    try {
        $run();
    } finally {
        if ($engine) {
            DB::connection()->getPdo()->rollBack();
        }
        $migration->up();
        $migration->up();
        Once::flush();
        if ($engine) {
            DB::connection()->getPdo()->beginTransaction();
        }
    }
}

it('refuses to drop the column while a cleared shift exists', function () {
    $migration = require base_path('database/migrations/2026_11_09_0004_add_is_unassigned_to_shifts_table.php');
    $actor = rcsActor();
    $sol = rcsEmployee(rcsPattern('Day', '08:00:00', '17:00:00'));
    app(ClearShift::class)->handle($actor, $sol->id, '2026-10-12');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'cleared default shifts');
    expect(Schema::hasColumn('shifts', 'is_unassigned'))->toBeTrue()
        ->and(app(ShiftRoster::class)->forDate($sol->id, '2026-10-12'))->toBeNull();
});

it('reads every row as before and refuses to clear a shift until the migration has run', function () {
    rcsWithoutColumn(function (): void {
        $actor = rcsActor();
        $sol = rcsEmployee(rcsPattern('Day', '08:00:00', '17:00:00'));

        expect(Schema::hasColumn('shifts', 'is_unassigned'))->toBeFalse()
            ->and(app(ShiftRoster::class)->forDate($sol->id, '2026-10-12')?->startTime)->toBe('08:00:00')
            ->and(app(ReferenceData::class)->shift($actor, $sol->id))->not->toBeNull()
            ->and(app(AssignShift::class)->handle($actor, $sol->id, rcsPattern('Late', '13:00:00', '22:00:00')->id, '2026-10-19'))->toContain('Late assigned');
        expect(fn () => app(ClearShift::class)->handle($actor, $sol->id))->toThrow(RuntimeException::class, 'database update');
        expect(RosterScheduleChange::query()->count())->toBe(1);
    });

    // Seeded after the restore: sqlite keeps what the window seeded, MariaDB
    // rolled it back, so nothing here may collide with it.
    $actor = rcsActor();
    $luz = rcsEmployee(rcsPattern('Day after', '08:00:00', '17:00:00'), 'Luz');
    expect(Schema::hasColumn('shifts', 'is_unassigned'))->toBeTrue()
        ->and(app(ClearShift::class)->handle($actor, $luz->id))->toBe("Luz Santos's shift cleared.");
});
