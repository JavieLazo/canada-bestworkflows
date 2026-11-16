<?php

declare(strict_types=1);

use App\Livewire\Records\ShiftTypesPage;
use App\Livewire\Roster\EmployeeSchedulePage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The Shift Roster shows the schedule safeguards, it does not make them:
 * the employee month's Default schedule dialog and Shifting Settings carry
 * an Effective from date, a past one is refused with the draft kept, and a
 * change that needs reviewing is held — nothing saved — until it is
 * confirmed with the token of exactly what was shown; an impact that
 * changed before the confirmation is shown again instead of saved. The
 * confirmation is never an action argument. Temporary schedules, their
 * overlaps and cancellations are pinned at the command level
 * (RosterPeriodsTest).
 */
function rspActor(bool $allData = true, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Roster page '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach (['ROSTER', 'RECORDS', 'PAYROLL'] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function rspPattern(string $name, string $start, string $end): ShiftType
{
    return ShiftType::query()->create(['code' => Str::random(10), 'name' => $name, 'mode' => 'FIXED', 'start_time' => $start, 'end_time' => $end,
        'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);
}

/** An employee on a baseline default shift. */
function rspEmployee(ShiftType $default, string $first = 'Sol'): EmployeeProfile
{
    $employee = EmployeeProfile::query()->create(['employee_code' => 'RP-'.Str::random(8), 'first_name' => $first, 'last_name' => 'Santos',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true]);
    Shift::query()->create(ShiftPolicy::attributesFromPattern($default) + ['employee_id' => $employee->id]);

    return $employee;
}

/**
 * The employee's month with the Default schedule dialog open.
 *
 * @return Testable<EmployeeSchedulePage>
 */
function rspDefaultSchedule(EmployeeProfile $employee): Testable
{
    return Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id])->call('openDefaultSchedule');
}

beforeEach(function () {
    // Wednesday 2026-10-07, before any shift here starts.
    $this->travelTo(new DateTimeImmutable('2026-10-07T05:00:00+08:00'));
});

/* |---| Effective from |---| */

it('toasts a past date as refused and keeps the dialog and its picks', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    $sol = rspEmployee($day);
    rspActor();

    rspDefaultSchedule($sol)
        ->assertSee('Effective From')
        ->set('locationDays.5', 'WFH')
        ->set('weekFrom', '2026-10-06')
        ->call('saveDefaultSchedule')
        ->assertSet('locationDays.5', 'WFH')
        ->assertSet('weekFrom', '2026-10-06')
        ->assertSet('reviewAction', '')
        ->assertNotDispatched('modal-close', name: 'default-schedule');

    expect(RosterScheduleChange::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
});

/* |---| Review |---| */

it('holds a change that moved to the next date, saves nothing, and saves it when confirmed', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    $late = rspPattern('Late', '13:00:00', '22:00:00');
    $sol = rspEmployee($day);
    rspActor();
    // 10:00: today's 08:00 shift has started, and the change is to today's weekday.
    $this->travelTo(new DateTimeImmutable('2026-10-07T10:00:00+08:00'));

    $page = rspDefaultSchedule($sol)
        ->set('shiftDays.3', $late->id)
        ->call('saveDefaultSchedule')
        ->assertSet('reviewAction', 'week')
        ->assertSet('review.takes_effect', 'Oct 08, 2026')
        ->assertSet('review.moved', 'Today is no longer possible: the shift scheduled for today has already started.')
        // The draft is kept while the change is held.
        ->assertSet('shiftDays.3', $late->id)
        ->assertDispatched('modal-show', name: 'schedule-review')
        ->assertSee('Nothing has been saved yet.');
    expect(RosterShift::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);

    // The held token cannot be written from the client, and the confirmation
    // takes no argument.
    expect(fn () => $page->set('reviewToken', '*'))->toThrow(Exception::class);
    expect((new ReflectionMethod(EmployeeSchedulePage::class, 'confirmReview'))->getNumberOfParameters())->toBe(0);

    $page->call('confirmReview')
        ->assertSet('reviewAction', '')
        ->assertSet('reviewToken', '')
        ->assertSet('shiftDays', []);
    expect(RosterShift::query()->where('weekday', 3)->sole()->effective_from?->format('Y-m-d'))->toBe('2026-10-08')
        ->and(app(ShiftRoster::class)->forDate($sol->id, '2026-10-07')?->startTime)->toBe('08:00:00')
        ->and(app(ShiftRoster::class)->forDate($sol->id, '2026-10-14')?->startTime)->toBe('13:00:00')
        // A second click carries nothing.
        ->and(Livewire::test(EmployeeSchedulePage::class, ['employee' => $sol->id])->call('confirmReview')->get('reviewAction'))->toBe('');
    expect(RosterShift::query()->count())->toBe(1);
});

it('shows a changed impact again instead of saving what was confirmed before it changed', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    $sol = rspEmployee($day);
    rspActor();
    $this->travelTo(new DateTimeImmutable('2026-10-07T10:00:00+08:00'));

    $page = rspDefaultSchedule($sol)
        ->set('shiftDays.3', rspPattern('Late', '13:00:00', '22:00:00')->id)
        ->call('saveDefaultSchedule')
        ->assertSet('reviewAction', 'week')
        ->assertSet('review.drafts', []);

    // A payroll draft for October appears before the confirmation.
    $run = PayrollRun::query()->create(['code' => 'PR-RSP', 'name' => 'October first half', 'status' => 'DRAFT', 'period_start' => '2026-10-01', 'period_end' => '2026-10-15']);
    PayrollRunMember::query()->create(['run_id' => $run->id, 'employee_id' => $sol->id, 'position' => 0, 'period_start' => '2026-10-01', 'period_end' => '2026-10-15']);

    $page->call('confirmReview')
        ->assertSet('reviewAction', 'week')
        ->assertSet('review.drafts', ['PR-RSP · October first half · Oct 01, 2026 to Oct 15, 2026'])
        ->assertSee('Payroll drafts that need a new preview');
    expect(RosterShift::query()->count())->toBe(0);

    $page->call('confirmReview')->assertSet('reviewAction', '')->assertSet('shiftDays', []);
    expect(RosterShift::query()->count())->toBe(1);
});

/* |---| Shifting Settings |---| */

it('applies a pattern edit from the date typed in Shifting Settings and refuses a past one', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    $sol = rspEmployee($day);
    rspActor();

    Livewire::test(ShiftTypesPage::class)
        ->call('startEdit', $day->id)
        ->assertSee('Effective From')
        ->set('formStartTime', '09:00')->set('formEndTime', '18:00')
        ->set('formEffectiveFrom', '2026-10-06')
        ->call('save')
        ->assertSet('dialogOpen', true)
        ->assertSet('editingId', $day->id)
        ->assertSet('reviewToken', '');
    expect($day->refresh()->start_time)->toBe('08:00:00')
        ->and(Shift::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);

    Livewire::test(ShiftTypesPage::class)
        ->call('startEdit', $day->id)
        ->set('formStartTime', '09:00')->set('formEndTime', '18:00')
        ->set('formEffectiveFrom', '2026-10-12')
        ->call('save')
        ->assertSet('dialogOpen', false)
        ->assertSet('editingId', null)
        ->assertSet('formEffectiveFrom', '');

    expect(Shift::query()->where('employee_id', $sol->id)->whereNotNull('effective_from')->sole()->effective_from?->format('Y-m-d'))->toBe('2026-10-12')
        ->and(app(ShiftRoster::class)->forDate($sol->id, '2026-10-09')?->startTime)->toBe('08:00:00')
        ->and(app(ShiftRoster::class)->forDate($sol->id, '2026-10-12')?->startTime)->toBe('09:00:00');
});

it('holds a pattern edit for review, returns to the editor as it was left, and saves only when confirmed', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    $sol = rspEmployee($day);
    rspActor();
    // 10:00: today's 08:00 shift has started.
    $this->travelTo(new DateTimeImmutable('2026-10-07T10:00:00+08:00'));

    $page = Livewire::test(ShiftTypesPage::class)
        ->call('startEdit', $day->id)
        ->set('formStartTime', '09:00')->set('formEndTime', '18:00')
        ->call('save')
        ->assertSet('dialogOpen', false)
        ->assertSet('review.takes_effect', 'Oct 08, 2026')
        ->assertSet('review.moved', 'Today is no longer possible: the shift scheduled for today has already started.')
        ->assertDispatched('modal-show', name: 'schedule-review')
        ->assertSee('Nothing has been saved yet.');
    expect($day->refresh()->start_time)->toBe('08:00:00')
        ->and(Shift::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);

    // Go back: the editor again, with what was typed, and nothing to confirm.
    $page->call('reviewBack')
        ->assertSet('dialogOpen', true)
        ->assertSet('formStartTime', '09:00')
        ->assertSet('reviewToken', '')
        ->call('confirmReview');
    expect(Shift::query()->count())->toBe(1);

    $page->call('save')->call('confirmReview')
        ->assertSet('dialogOpen', false)
        ->assertSet('editingId', null)
        ->assertSet('reviewToken', '');
    expect($day->refresh()->start_time)->toBe('09:00:00')
        ->and(Shift::query()->where('employee_id', $sol->id)->whereNotNull('effective_from')->sole()->effective_from?->format('Y-m-d'))->toBe('2026-10-08')
        ->and(app(ShiftRoster::class)->forDate($sol->id, '2026-10-07')?->startTime)->toBe('08:00:00');
});

it('gives the Shifting Settings save no way to carry a confirmation from the client', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    rspEmployee($day);
    rspActor();
    $this->travelTo(new DateTimeImmutable('2026-10-07T10:00:00+08:00'));

    // An argument is ignored, and the held token cannot be written.
    $page = Livewire::test(ShiftTypesPage::class)
        ->call('startEdit', $day->id)
        ->set('formStartTime', '09:00')->set('formEndTime', '18:00')
        ->call('save', '*')
        ->assertDispatched('modal-show', name: 'schedule-review');
    expect(Shift::query()->count())->toBe(1)->and($day->refresh()->start_time)->toBe('08:00:00');

    expect(fn () => $page->set('reviewToken', '*'))->toThrow(Exception::class);
    expect((new ReflectionMethod(ShiftTypesPage::class, 'save'))->getNumberOfParameters())->toBe(0)
        ->and((new ReflectionMethod(ShiftTypesPage::class, 'confirmReview'))->getNumberOfParameters())->toBe(0);
});
