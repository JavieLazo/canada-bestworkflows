<?php

declare(strict_types=1);

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\ShiftResolver;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Commands\ClearShift;
use App\Modules\Timekeeping\Application\Commands\SaveRosterShifts;
use App\Modules\Timekeeping\Application\Commands\SaveShiftType;
use App\Modules\Timekeeping\Application\RestDayConversion;
use App\Modules\Timekeeping\Application\ShiftTypeDraft;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use Illuminate\Support\Str;

/**
 * EVERY DAY IS A WORKING DAY unless the employee's own schedule rests it: a
 * shift pattern carries hours only. The one-time move (`RestDayConversion`)
 * turns the rest days a pattern's work week gave into explicit Rest day rows
 * on each employee, so EVERY DATE IS SCHEDULED EXACTLY AS BEFORE — working
 * days, rest days and hours — through a later-dated default shift and a
 * later Clear shift too, and a second run changes nothing. A pattern saved
 * from now on works all seven days.
 */
beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-09-21T05:00:00+08:00'));
});

function rrdEmployee(): EmployeeProfile
{
    return EmployeeProfile::query()->create(['employee_code' => 'RD-'.Str::random(10), 'first_name' => 'Rest', 'last_name' => 'Days', 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true]);
}

function rrdActor(): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Rest days '.Str::random(10), 'all_data' => true, 'is_system' => false])->save();
    foreach (['ROSTER', 'RECORDS'] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/** @param list<int> $days */
function rrdPattern(array $days = [1, 2, 3, 4, 5], string $start = '08:00:00'): ShiftType
{
    return ShiftType::query()->create(['code' => Str::random(10), 'name' => 'Day '.Str::random(6), 'mode' => 'FIXED', 'start_time' => $start, 'end_time' => '17:00:00',
        'work_days' => $days, 'grace_minutes' => 15, 'break_minutes' => 0, 'requires_break_punch' => false]);
}

/** @return array<string, string> date => "works start" for every date of the span */
function rrdWeek(string $employeeId, string $from = '2026-09-21', string $to = '2026-10-25'): array
{
    $schedule = app(ShiftRoster::class)->scheduleForEmployee($employeeId, $from, $to);
    $out = [];
    foreach (CalendarDays::each($from, $to) as $date) {
        $rostered = app(ShiftRoster::class)->forDate($employeeId, $date);
        $out[$date] = $rostered === null ? 'none' : (ShiftResolver::worksOnDate($date, $schedule) ? 'works ' : 'rests ').$rostered->startTime;
    }

    return $out;
}

it('moves a pattern work week onto the employee as rest days without changing any date', function () {
    $employee = rrdEmployee();
    $actor = rrdActor();
    app(AssignShift::class)->handle($actor, $employee->id, rrdPattern()->id);
    $before = rrdWeek($employee->id);

    expect(RestDayConversion::run('2026-09-21'))->toBe(1)
        ->and(rrdWeek($employee->id))->toBe($before)
        ->and($before['2026-09-26'])->toStartWith('rests')
        ->and(RosterShift::query()->where('is_rest_day', true)->orderBy('weekday')->pluck('weekday')->all())->toBe([0, 6])
        ->and(RosterScheduleChange::query()->where('source', 'REST_DAYS')->count())->toBe(1)
        ->and(ShiftType::query()->sole()->work_days)->toBe(RestDayConversion::ALL_DAYS)
        // Already moved: a second run changes nothing.
        ->and(RestDayConversion::run('2026-09-21'))->toBe(0);
});

it('keeps a weekday with hours of its own and adds no rest day there', function () {
    $employee = rrdEmployee();
    $actor = rrdActor();
    app(AssignShift::class)->handle($actor, $employee->id, rrdPattern()->id);
    app(SaveRosterShifts::class)->handle($actor, $employee->id, [6 => rrdPattern([6], '09:00:00')->id]);
    $before = rrdWeek($employee->id);

    RestDayConversion::run('2026-09-21');

    expect(rrdWeek($employee->id))->toBe($before)
        ->and($before['2026-09-26'])->toBe('works 09:00:00')
        ->and(RosterShift::query()->where('is_rest_day', true)->pluck('weekday')->all())->toBe([0]);
});

it('ends the moved rest days where a later default shift works them, or the shift is cleared', function (bool $clear) {
    $employee = rrdEmployee();
    $actor = rrdActor();
    app(AssignShift::class)->handle($actor, $employee->id, rrdPattern()->id);
    $clear
        ? app(ClearShift::class)->handle($actor, $employee->id, '2026-10-05')
        : app(AssignShift::class)->handle($actor, $employee->id, rrdPattern(RestDayConversion::ALL_DAYS)->id, '2026-10-05');
    $before = rrdWeek($employee->id);

    RestDayConversion::run('2026-09-21');

    expect(rrdWeek($employee->id))->toBe($before)
        ->and($before['2026-10-10'])->toBe($clear ? 'none' : 'works 08:00:00');
})->with(['a later default shift' => false, 'a later clear' => true]);

it('saves a new pattern working every day, so a new assignment works the whole week', function () {
    $employee = rrdEmployee();
    $actor = rrdActor();
    app(SaveShiftType::class)->handle($actor, null, new ShiftTypeDraft('Every day', 'FIXED', [1, 2, 3, 4, 5], '08:00', '17:00', '15', '8', false, '12:00', '13:00', '0', false));
    $pattern = ShiftType::query()->where('name', 'Every day')->sole();

    app(AssignShift::class)->handle($actor, $employee->id, $pattern->id);

    expect($pattern->work_days)->toBe(RestDayConversion::ALL_DAYS)
        ->and(rrdWeek($employee->id, '2026-09-21', '2026-09-27'))->each->toStartWith('works');
});
