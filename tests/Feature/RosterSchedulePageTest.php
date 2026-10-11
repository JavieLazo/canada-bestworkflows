<?php

declare(strict_types=1);

use App\Livewire\Records\ShiftTypesPage;
use App\Livewire\Roster\RosterPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\RosterScheduleChange;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Commands\SaveAssignment;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Shift Roster shows the schedule safeguards, it does not make them:
 * every dialog carries an Effective from date, a change that needs
 * reviewing is held — nothing saved — until it is confirmed with the
 * token of exactly what was shown, a temporary schedule covers a date or a
 * period and is changed or cancelled, never overwritten, and each employee's
 * history names who did what. Every id the client sends is re-resolved.
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

beforeEach(function () {
    // Wednesday 2026-10-07, before any shift here starts.
    $this->travelTo(new DateTimeImmutable('2026-10-07T05:00:00+08:00'));
});

/* |---| Effective from |---| */

it('assigns a shift from the date typed in either dialog, and from today when the box is blank', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    $late = rspPattern('Late', '13:00:00', '22:00:00');
    $sol = rspEmployee($day);
    $luz = rspEmployee($day, 'Luz');
    rspActor();

    Livewire::test(RosterPage::class)
        ->call('openAssign')
        ->call('dialogEmployeeChanged', $sol->id)->call('dialogPatternChanged', $late->id)
        ->set('assignFrom', '2026-10-12')
        ->call('dialogSave')
        ->assertSet('dialogEmployeeId', '')
        ->assertSet('assignFrom', '')
        ->assertSet('reviewAction', '');
    Livewire::test(RosterPage::class)
        ->call('pickPattern', $luz->id, $late->id)
        ->assertSee('Effective from')
        ->call('confirmAssign')
        ->assertSet('confirmEmployeeId', '');

    expect(Shift::query()->where('employee_id', $sol->id)->whereNotNull('effective_from')->sole()->effective_from?->format('Y-m-d'))->toBe('2026-10-12')
        ->and(Shift::query()->where('employee_id', $luz->id)->whereNotNull('effective_from')->sole()->effective_from?->format('Y-m-d'))->toBe('2026-10-07')
        ->and(app(ShiftRoster::class)->forDate($sol->id, '2026-10-09')?->startTime)->toBe('08:00:00');
});

it('toasts a past date as refused and keeps the dialog and its picks', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    $sol = rspEmployee($day);
    rspActor();

    Livewire::test(RosterPage::class)
        ->call('openLocations', $sol->id)
        ->set('locationDays.5', 'WFH')
        ->set('weekFrom', '2026-10-06')
        ->call('saveLocations')
        ->assertSet('locationsEmployeeId', $sol->id)
        ->assertSet('locationDays.5', 'WFH')
        ->assertSet('reviewAction', '')
        ->assertNotDispatched('modal-close', name: 'weekly-locations');

    expect(RosterScheduleChange::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
});

/* |---| Review |---| */

it('holds a change that moved to the next date, saves nothing, and saves it when confirmed', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    $late = rspPattern('Late', '13:00:00', '22:00:00');
    $sol = rspEmployee($day);
    rspActor();
    // 10:00: today's 08:00 shift has started.
    $this->travelTo(new DateTimeImmutable('2026-10-07T10:00:00+08:00'));

    $page = Livewire::test(RosterPage::class)
        ->call('openAssign')
        ->call('dialogEmployeeChanged', $sol->id)->call('dialogPatternChanged', $late->id)
        ->call('dialogSave')
        ->assertSet('reviewAction', 'assign')
        ->assertSet('review.takes_effect', 'Oct 08, 2026')
        ->assertSet('review.moved', 'Today is no longer possible: the shift scheduled for today has already started.')
        // The draft is kept while the change is held.
        ->assertSet('dialogEmployeeId', $sol->id)
        ->assertDispatched('modal-show', name: 'schedule-review')
        ->assertSee('Nothing has been saved yet.');
    expect(Shift::query()->count())->toBe(1)->and(AuditLog::query()->count())->toBe(0);

    $page->call('confirmReview')
        ->assertSet('reviewAction', '')
        ->assertSet('reviewToken', '')
        ->assertSet('dialogEmployeeId', '');
    expect(Shift::query()->whereNotNull('effective_from')->sole()->effective_from?->format('Y-m-d'))->toBe('2026-10-08')
        // A second click carries nothing.
        ->and(Livewire::test(RosterPage::class)->call('confirmReview')->get('reviewAction'))->toBe('');
    expect(Shift::query()->count())->toBe(2);
});

it('shows a changed impact again instead of saving what was confirmed before it changed', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    $sol = rspEmployee($day);
    rspActor();
    $this->travelTo(new DateTimeImmutable('2026-10-07T10:00:00+08:00'));

    $page = Livewire::test(RosterPage::class)
        ->call('pickPattern', $sol->id, rspPattern('Late', '13:00:00', '22:00:00')->id)
        ->call('confirmAssign')
        ->assertSet('reviewAction', 'row')
        ->assertSet('review.drafts', []);

    // A payroll draft for October appears before the confirmation.
    $run = PayrollRun::query()->create(['code' => 'PR-RSP', 'name' => 'October first half', 'status' => 'DRAFT', 'period_start' => '2026-10-01', 'period_end' => '2026-10-15']);
    PayrollRunMember::query()->create(['run_id' => $run->id, 'employee_id' => $sol->id, 'position' => 0, 'period_start' => '2026-10-01', 'period_end' => '2026-10-15']);

    $page->call('confirmReview')
        ->assertSet('reviewAction', 'row')
        ->assertSet('review.drafts', ['PR-RSP · October first half · Oct 01, 2026 to Oct 15, 2026'])
        ->assertSee('Payroll drafts that need a new preview');
    expect(Shift::query()->count())->toBe(1);

    $page->call('confirmReview')->assertSet('reviewAction', '')->assertSet('confirmEmployeeId', '');
    expect(Shift::query()->count())->toBe(2);
});

/* |---| Temporary schedules |---| */

it('saves a period from the dialog, loads it to change it, and keeps the original in the history', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    $night = rspPattern('Night', '22:00:00', '06:00:00');
    $sol = rspEmployee($day);
    rspActor();

    Livewire::test(RosterPage::class)
        ->call('openDateSchedule', $sol->id)
        ->assertSet('scheduleDate', '2026-10-08')
        ->assertSet('editingAssignmentId', '')
        ->set('scheduleDate', '2026-10-12')->set('scheduleEnd', '2026-10-23')
        ->assertSee('Schedule work on rest days')
        ->set('dateShiftChoice', $night->id)->set('dateLocationChoice', 'WFH')
        ->call('saveDateSchedule')
        ->assertSet('dateEmployeeId', '');
    $saved = RosterDayOverride::query()->sole();
    expect($saved->end_date?->format('Y-m-d'))->toBe('2026-10-23')->and($saved->include_rest_days)->toBeFalse();

    // Its first date loads it; so does its Change button.
    Livewire::test(RosterPage::class)
        ->call('openDateSchedule', $sol->id)
        ->assertSee('Saved temporary schedules')
        ->call('pickScheduleDate', '2026-10-12')
        ->assertSet('editingAssignmentId', $saved->id)
        ->assertSet('scheduleEnd', '2026-10-23')
        ->assertSet('dateShiftChoice', $night->id)
        ->assertSet('dateLocationChoice', 'WFH')
        ->call('newTemporary')
        ->assertSet('editingAssignmentId', '')
        ->call('editTemporary', $saved->id)
        ->assertSet('editingAssignmentId', $saved->id)
        ->assertSee('Change temporary schedule')
        ->set('scheduleEnd', '2026-10-16')
        ->call('saveDateSchedule')
        ->assertSet('dateEmployeeId', '')
        ->assertSet('editingAssignmentId', '');

    $saved->refresh();
    expect($saved->status)->toBe('CANCELLED')
        ->and(RosterDayOverride::query()->where('revision_of', $saved->id)->sole()->end_date?->format('Y-m-d'))->toBe('2026-10-16')
        ->and(app(ShiftRoster::class)->forDate($sol->id, '2026-10-19')?->startTime)->toBe('08:00:00');
});

it('lists an overlap in the review dialog and replaces it only when confirmed', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    $late = rspPattern('Late', '13:00:00', '22:00:00');
    $night = rspPattern('Night', '22:00:00', '06:00:00');
    $sol = rspEmployee($day);
    $access = Access::forActor(rspActor());
    app(SaveAssignment::class)->handle($access, $sol->id, '2026-10-12', '2026-10-16', $late->id, 'DEFAULT');
    $existing = RosterDayOverride::query()->sole();

    $page = Livewire::test(RosterPage::class)
        ->call('openDateSchedule', $sol->id)
        ->set('scheduleDate', '2026-10-15')->set('scheduleEnd', '2026-10-21')
        ->set('dateShiftChoice', $night->id)
        ->call('saveDateSchedule')
        ->assertSet('reviewAction', 'dates')
        ->assertSet('reviewReplaceIds', [$existing->id])
        ->assertSet('review.conflicts', ['Oct 12, 2026 to Oct 16, 2026 · Late · Weekly location — shares Oct 15, 2026 to Oct 16, 2026'])
        ->assertSee('Confirming replaces these on the shared dates only.');
    expect(RosterDayOverride::query()->count())->toBe(1)->and($existing->fresh()?->status)->toBe('ACTIVE');

    $page->call('confirmReview')->assertSet('reviewAction', '')->assertSet('reviewReplaceIds', []);
    $roster = app(ShiftRoster::class);
    expect($existing->fresh()?->cut_after?->format('Y-m-d'))->toBe('2026-10-14')
        ->and($roster->forDate($sol->id, '2026-10-14')?->startTime)->toBe('13:00:00')
        ->and($roster->forDate($sol->id, '2026-10-15')?->startTime)->toBe('22:00:00');
});

it('cancels a temporary schedule from its list and drops an id the employee’s history does not hold', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    $late = rspPattern('Late', '13:00:00', '22:00:00');
    $sol = rspEmployee($day);
    $luz = rspEmployee($day, 'Luz');
    $access = Access::forActor(rspActor());
    app(SaveAssignment::class)->handle($access, $sol->id, '2026-10-12', '2026-10-16', $late->id, 'DEFAULT');
    app(SaveAssignment::class)->handle($access, $luz->id, '2026-10-12', '2026-10-16', $late->id, 'DEFAULT');
    $own = RosterDayOverride::query()->where('employee_id', $sol->id)->sole();
    $other = RosterDayOverride::query()->where('employee_id', $luz->id)->sole();

    Livewire::test(RosterPage::class)
        ->call('openDateSchedule', $sol->id)
        // Another employee's schedule is not loaded into this dialog.
        ->call('editTemporary', $other->id)
        ->assertSet('editingAssignmentId', '')
        ->call('cancelTemporary', $own->id)
        ->assertSet('endingAssignmentId', '')
        ->assertDontSee('Saved temporary schedules');

    expect($own->fresh()?->status)->toBe('CANCELLED')
        ->and($own->fresh()?->cancelled_by)->toBe((int) $access->userId)
        ->and($other->fresh()?->status)->toBe('ACTIVE')
        ->and(AuditLog::query()->where('action', 'ROSTER_PERIOD_CANCELLED')->count())->toBe(1);
});

/* |---| History and the as-of view |---| */

it('shows the history with who saved and cancelled each change, and cancels one still ahead', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    $late = rspPattern('Late', '13:00:00', '22:00:00');
    $sol = rspEmployee($day);
    $access = Access::forActor(rspActor());
    RosterScheduleChange::query()->create(['employee_id' => $sol->id, 'source' => 'BASELINE', 'assumed_before' => '2026-09-30', 'may_differ_through' => '2026-09-20']);
    app(AssignShift::class)->handle($access, $sol->id, $late->id, '2026-10-12');
    app(SaveAssignment::class)->handle($access, $sol->id, '2026-10-19', '2026-10-23', 'REST', 'WFH');
    $change = RosterScheduleChange::query()->where('source', 'MANUAL')->sole();

    $page = Livewire::test(RosterPage::class)
        ->assertSee('2 upcoming changes')
        ->call('openHistory', $sol->id)
        ->assertSet('historyEmployeeId', $sol->id)
        ->assertSeeHtmlInOrder(['Schedule history', 'From Oct 12, 2026', 'Scheduled', 'Default shift: 1:00 PM–10:00 PM', 'Saved by '.$access->fullName])
        ->assertSeeHtmlInOrder(['Before Sep 30, 2026: assumed.', 'may have differed up to that date'])
        ->assertSeeHtmlInOrder(['Temporary schedules', 'Oct 19, 2026 to Oct 23, 2026', 'Rest day', 'Work from home']);

    $page->call('cancelChange', $change->id)
        ->assertSet('droppingChangeId', '')
        ->assertSeeHtmlInOrder(['From Oct 12, 2026', 'Cancelled', 'cancelled by '.$access->fullName])
        ->assertSee('1 upcoming change');
    expect($change->fresh()?->status)->toBe('CANCELLED')
        ->and(app(ShiftRoster::class)->forDate($sol->id, '2026-10-12')?->startTime)->toBe('08:00:00');
});

it('lets a viewer without all_data read their own history and cancel nothing', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    $sol = rspEmployee($day);
    $luz = rspEmployee($day, 'Luz');
    $manager = Access::forActor(rspActor());
    app(AssignShift::class)->handle($manager, $sol->id, rspPattern('Late', '13:00:00', '22:00:00')->id, '2026-10-12');
    app(SaveAssignment::class)->handle($manager, $sol->id, '2026-10-19', '2026-10-23', 'REST', 'DEFAULT');
    $change = RosterScheduleChange::query()->sole();
    $period = RosterDayOverride::query()->sole();
    $audits = AuditLog::query()->count();

    rspActor(allData: false, employeeId: $sol->id);
    Livewire::test(RosterPage::class)
        ->call('openHistory', $luz->id)
        ->assertSet('historyEmployeeId', '')
        ->call('openHistory', $sol->id)
        ->assertSet('historyEmployeeId', $sol->id)
        ->assertSee('From Oct 12, 2026')
        ->assertDontSeeHtml("cancelChange('{$change->id}')")
        ->assertDontSeeHtml("cancelTemporary('{$period->id}')")
        ->call('cancelChange', $change->id)
        ->call('cancelTemporary', $period->id)
        ->call('openDateSchedule', $sol->id)
        ->assertSet('dateEmployeeId', '');

    expect($change->fresh()?->status)->toBe('ACTIVE')
        ->and($period->fresh()?->status)->toBe('ACTIVE')
        ->and(AuditLog::query()->count())->toBe($audits);
});

/* |---| Shifting Settings |---| */

it('applies a pattern edit from the date typed in Shifting Settings and refuses a past one', function () {
    $day = rspPattern('Day', '08:00:00', '17:00:00');
    $sol = rspEmployee($day);
    rspActor();

    Livewire::test(ShiftTypesPage::class)
        ->call('startEdit', $day->id)
        ->assertSee('Effective from')
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
