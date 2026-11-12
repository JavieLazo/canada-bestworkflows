<?php

declare(strict_types=1);

use App\Livewire\Payroll\RunWorkspace;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Commands\CancelAssignment;
use App\Modules\Timekeeping\Application\Commands\SaveAssignment;
use App\Modules\Timekeeping\Application\DatedChange;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Modules\Timekeeping\Contracts\ScheduleNotices;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * A payroll draft is told which members are judged by the system default on
 * dates nobody assigned a schedule for — the hold that keeps it from being
 * finalized until their schedule is set (NoShiftHoldTest) — and, without
 * being stopped, whose schedule changed after the draft was last saved: the
 * amounts shown already follow each date's schedule. Both facts stay inside
 * payroll's scope, and neither changes an amount.
 */
/** @param list<string> $modules */
function psnActor(array $modules = ['PAYROLL', 'ROSTER'], bool $allData = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Payroll notices '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function psnPattern(string $name, string $start, string $end): ShiftType
{
    return ShiftType::query()->create(['code' => Str::random(10), 'name' => $name, 'mode' => 'FIXED', 'start_time' => $start, 'end_time' => $end,
        'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);
}

function psnEmployee(string $first, ?ShiftType $default): EmployeeProfile
{
    $employee = EmployeeProfile::query()->create(['employee_code' => 'PN-'.Str::random(6), 'first_name' => $first, 'last_name' => 'Reyes',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true, 'monthly_rate' => 30000]);
    if ($default !== null) {
        Shift::query()->create(ShiftPolicy::attributesFromPattern($default) + ['employee_id' => $employee->id]);
    }

    return $employee;
}

/** @param list<EmployeeProfile> $members */
function psnRun(array $members, string $savedAt = '2026-10-07T02:00:00+08:00'): PayrollRun
{
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-'.Str::random(4), 'name' => 'October first half', 'status' => 'DRAFT',
        'period_start' => '2026-10-01', 'period_end' => '2026-10-15'])->save();
    foreach ($members as $i => $member) {
        PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $member->id, 'position' => $i,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-15']);
    }
    $stamp = CarbonImmutable::parse($savedAt)->utc();
    PayrollRun::query()->whereKey($run->id)->update(['created_at' => $stamp, 'updated_at' => $stamp]);

    return $run->refresh();
}

beforeEach(function () {
    // Wednesday 2026-10-07, before any shift here starts.
    $this->travelTo(new DateTimeImmutable('2026-10-07T05:00:00+08:00'));
});

it('names the members judged by the system default on dates nobody gave them a schedule for, and how many', function () {
    $day = psnPattern('Day', '08:00:00', '17:00:00');
    $ana = psnEmployee('Ana', $day);
    $ben = psnEmployee('Ben', null);
    $cid = psnEmployee('Cid', null);
    $actor = psnActor();
    // Cid was assigned today, before the draft was saved: Oct 1–6 stay
    // unassigned, today and later are not.
    $this->travelTo(new DateTimeImmutable('2026-10-07T01:00:00+08:00'));
    app(AssignShift::class)->handle($actor, $cid->id, $day->id);
    $this->travelTo(new DateTimeImmutable('2026-10-07T05:00:00+08:00'));

    $notices = app(ScheduleNotices::class);
    expect($notices->unscheduledDays($actor, [$ana->id, $ben->id, $cid->id], '2026-10-01', '2026-10-15'))
        ->toBe([$ben->id => 7, $cid->id => 6])
        ->and($notices->unscheduledDays($actor, [$ana->id], '2026-10-01', '2026-10-15'))->toBe([])
        ->and($notices->unscheduledDays($actor, [$ben->id], 'soon', '2026-10-15'))->toBe([]);

    // The workspace holds the whole period, the days still ahead too.
    $run = psnRun([$ana, $ben, $cid]);
    Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertSee('Some dates in this period have no shift for')
        ->assertSee('Ben Reyes (15 days), Cid Reyes (6 days)')
        ->assertSee('before finalizing')
        ->assertDontSee('Schedules changed after this draft');
});

it('names the members whose schedule changed after the draft was last saved, and only those', function () {
    $day = psnPattern('Day', '08:00:00', '17:00:00');
    $late = psnPattern('Late', '13:00:00', '22:00:00');
    $ana = psnEmployee('Ana', $day);
    $ben = psnEmployee('Ben', $day);
    $cid = psnEmployee('Cid', $day);
    $dan = psnEmployee('Dan', $day);
    $actor = psnActor();
    // Before the draft was saved at 02:00: not a change since.
    $this->travelTo(new DateTimeImmutable('2026-10-07T01:00:00+08:00'));
    app(AssignShift::class)->handle($actor, $dan->id, $late->id, '2026-10-08');
    $run = psnRun([$ana, $ben, $cid, $dan]);
    $this->travelTo(new DateTimeImmutable('2026-10-07T05:00:00+08:00'));

    // After it: Ana's default shift from tomorrow, Ben's temporary schedule
    // next week, Cid's change dated in December, which touches no month here.
    app(AssignShift::class)->handle($actor, $ana->id, $late->id, '2026-10-08', DatedChange::ACCEPT);
    app(SaveAssignment::class)->handle($actor, $ben->id, '2026-10-12', '2026-10-13', $late->id, 'DEFAULT', confirmed: DatedChange::ACCEPT);
    app(AssignShift::class)->handle($actor, $cid->id, $late->id, '2026-12-01', DatedChange::ACCEPT);

    $changed = app(ScheduleNotices::class)->changedSince($actor, [$ana->id, $ben->id, $cid->id, $dan->id], '2026-10-01', '2026-10-15', $run->updated_at ?? $run->created_at);
    expect(array_keys($changed))->toBe([$ana->id, $ben->id])
        ->and($changed[$ana->id]->utc()->format('Y-m-d H:i'))->toBe('2026-10-06 21:00');

    Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertSeeInOrder(['Schedules changed after this draft was last saved (Oct 07, 2026, 2:00 AM):', 'Ana Reyes, Ben Reyes.'])
        ->assertSee('The amounts shown already follow the schedule each date has now.')
        ->assertDontSee('have no shift for');

    // Cancelling Ben's temporary schedule later is a change too; a cancelled
    // change no longer in effect still counts as the moment it was cancelled.
    $this->travelTo(new DateTimeImmutable('2026-10-07T06:00:00+08:00'));
    $id = RosterDayOverride::query()->where('employee_id', $ben->id)->sole()->id;
    app(CancelAssignment::class)->handle($actor, $id, DatedChange::ACCEPT);
    $again = app(ScheduleNotices::class)->changedSince($actor, [$ben->id], '2026-10-01', '2026-10-15', CarbonImmutable::parse('2026-10-07T05:30:00+08:00'));
    expect(array_keys($again))->toBe([$ben->id]);
});

it('answers within payroll scope only: everyone with all-data, yourself without it, nothing without the module', function () {
    $ana = psnEmployee('Ana', null);
    $ben = psnEmployee('Ben', null);

    $own = psnActor(['PAYROLL'], allData: false, employeeId: $ana->id);
    expect(app(ScheduleNotices::class)->unscheduledDays($own, [$ana->id, $ben->id], '2026-10-01', '2026-10-15'))->toBe([$ana->id => 7]);

    $unlinked = psnActor(['PAYROLL'], allData: false);
    expect(app(ScheduleNotices::class)->unscheduledDays($unlinked, [$ana->id, $ben->id], '2026-10-01', '2026-10-15'))->toBe([]);

    $elsewhere = psnActor(['EDTR'], allData: true);
    expect(app(ScheduleNotices::class)->unscheduledDays($elsewhere, [$ana->id], '2026-10-01', '2026-10-15'))->toBe([])
        ->and(app(ScheduleNotices::class)->changedSince($elsewhere, [$ana->id], '2026-10-01', '2026-10-15', CarbonImmutable::parse('2026-01-01')))->toBe([]);
});
