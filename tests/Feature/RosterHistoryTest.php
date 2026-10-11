<?php

declare(strict_types=1);

use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\Shift as DomainShift;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\RosterLocation;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Commands\SaveRosterDate;
use App\Modules\Timekeeping\Application\Commands\SaveRosterWeek;
use App\Modules\Timekeeping\Application\Commands\SaveShiftType;
use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Modules\Timekeeping\Application\Queries\RosterDates;
use App\Modules\Timekeeping\Application\RosterChanges;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Modules\Timekeeping\Application\ShiftTypeDraft;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LegacyShiftRoster;

/**
 * Each DTR date keeps the schedule that applied to it: a change to the
 * default shift, the weekly schedule or a shared pattern takes effect from
 * today and never recalculates an earlier date, a cancelled change never
 * applies, and a schedule nobody changed resolves exactly as the
 * single-version roster (LegacyShiftRoster) does — so earlier DTR rows,
 * and payroll inputs for months the change does not reach, stay identical.
 */
function rhistActor(): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'History '.Str::random(8), 'all_data' => true, 'is_system' => false])->save();
    foreach (['ROSTER', 'RECORDS', 'EDTR', 'PAYROLL'] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function rhistEmployee(): EmployeeProfile
{
    return EmployeeProfile::query()->create(['employee_code' => 'RH-'.Str::random(8), 'first_name' => 'History', 'last_name' => 'Employee',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'monthly_rate' => 30000, 'is_active' => true]);
}

/** @param list<int> $days */
function rhistPattern(string $start, string $end, array $days = [1, 2, 3, 4, 5]): ShiftType
{
    return ShiftType::query()->create(['code' => Str::random(10), 'name' => 'History '.Str::random(6), 'mode' => 'FIXED', 'start_time' => $start, 'end_time' => $end,
        'work_days' => $days, 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);
}

/** A Time In and Time Out in Manila wall-clock time; an earlier Time Out lands on the next day. */
function rhistPunchDay(EmployeeProfile $employee, string $date, string $in, string $out): void
{
    $outDate = $out <= $in ? CarbonImmutable::parse($date, 'UTC')->addDay()->format('Y-m-d') : $date;
    foreach (['TIME_IN' => "{$date}T{$in}:00", 'TIME_OUT' => "{$outDate}T{$out}:00"] as $type => $at) {
        AttendanceLog::query()->create(['employee_id' => $employee->id, 'log_type' => $type, 'source' => 'MANUAL', 'logged_at' => ManilaClock::instant($at)->utc()]);
    }
}

/**
 * The DTR as the eDTR screen computes it, as plain arrays so two readings
 * compare field by field.
 *
 * @return list<array<string, mixed>>
 */
function rhistDtr(EmployeeProfile $employee, string $start, string $end): array
{
    $inputs = app(AttendanceSource::class)->forDtr(app(Access::class), $employee->id, $start, $end);

    return json_decode((string) json_encode(DtrCalculator::compute($inputs->logs, $inputs->shift, $inputs->leaves, $start, $end)), true);
}

function rhistAt(string $manila): void
{
    test()->travelTo(new DateTimeImmutable($manila));
}

/* |---| A schedule nobody changed |---| */

it('resolves a schedule nobody changed exactly as the single-version roster did', function () {
    rhistActor();
    $employee = rhistEmployee();
    $night = rhistPattern('22:00:00', '06:00:00');
    Shift::query()->create(ShiftPolicy::attributesFromPattern(rhistPattern('08:00:00', '17:00:00')) + ['employee_id' => $employee->id]);
    RosterShift::query()->create(['employee_id' => $employee->id, 'weekday' => 6, 'shift_type_id' => null, 'shift_name' => 'Rest day', 'is_rest_day' => true,
        'shift_data' => array_replace(DomainShift::restDay()->toArray(), ['shift_type_id' => null])]);
    RosterShift::query()->create(['employee_id' => $employee->id, 'weekday' => 3, 'shift_type_id' => $night->id, 'shift_name' => $night->name, 'is_rest_day' => false,
        'shift_data' => DomainShift::fromArray(array_replace(ShiftPolicy::attributesFromPattern($night), ['work_days' => [3]]))->toArray()]);
    RosterDayOverride::query()->create(['employee_id' => $employee->id, 'date' => '2026-09-19', 'shift_type_id' => $night->id, 'shift_name' => $night->name,
        'shift_data' => DomainShift::fromArray(ShiftPolicy::attributesFromPattern($night))->toArray()]);
    RosterDayOverride::query()->create(['employee_id' => $employee->id, 'date' => '2026-09-21', 'location_kind' => 'WFH']);
    RosterLocation::query()->create(['employee_id' => $employee->id, 'weekday' => 1, 'kind' => 'WFH', 'site_id' => null]);

    $legacy = new LegacyShiftRoster;
    $roster = app(ShiftRoster::class);
    foreach ([['2026-09-01', '2026-09-30'], ['2026-08-31', '2026-10-01'], ['2026-09-20', '2026-09-20'], [null, null]] as [$from, $to]) {
        expect($roster->scheduleForEmployee($employee->id, $from, $to)?->toArray())->toBe($legacy->scheduleForEmployee($employee->id, $from, $to)?->toArray());
    }
    foreach (['2026-09-14', '2026-09-19', '2026-09-21', '2026-09-22'] as $date) {
        expect($roster->locationForDate($employee->id, $date))->toEqual($legacy->locationForDate($employee->id, $date));
    }
    expect($roster->scheduleForEmployee(rhistEmployee()->id, '2026-09-01', '2026-09-30'))->toBeNull();
});

/* |---| Changes from today never reach an earlier date |---| */

it('keeps every earlier date on its shift when the default shift is reassigned today', function () {
    $actor = rhistActor();
    $employee = rhistEmployee();
    $day = rhistPattern('08:00:00', '17:00:00');
    $early = rhistPattern('07:00:00', '16:00:00');
    rhistAt('2026-09-01T06:00:00+08:00');
    app(AssignShift::class)->handle($actor, $employee->id, $day->id);
    foreach (['2026-09-14', '2026-09-15'] as $date) {
        rhistPunchDay($employee, $date, '08:00', '17:00');
    }

    // Before today's shift has started, so the change can still be for today.
    rhistAt('2026-09-16T05:00:00+08:00');
    $before = rhistDtr($employee, '2026-09-01', '2026-09-15');
    app(AssignShift::class)->handle($actor, $employee->id, $early->id);
    rhistAt('2026-09-17T20:00:00+08:00');
    foreach (['2026-09-16', '2026-09-17'] as $date) {
        rhistPunchDay($employee, $date, '08:00', '17:00');
    }

    expect(rhistDtr($employee, '2026-09-01', '2026-09-15'))->toBe($before)
        ->and(rhistDtr($employee, '2026-09-16', '2026-09-17')[0]['lateMinutes'])->toEqual(60)
        ->and(Shift::query()->where('employee_id', $employee->id)->count())->toBe(2);
    $change = RosterScheduleChange::query()->where('employee_id', $employee->id)->orderByDesc('id')->first();
    expect($change?->effective_from?->format('Y-m-d'))->toBe('2026-09-16')
        ->and($change?->source)->toBe('MANUAL')
        ->and($change?->created_by)->toBe((int) $actor->userId);
});

it('keeps earlier dates on their weekday hours and place when the weekly schedule is saved today', function () {
    $actor = rhistActor();
    $employee = rhistEmployee();
    $night = rhistPattern('22:00:00', '06:00:00', [3]);
    rhistAt('2026-09-01T06:00:00+08:00');
    app(AssignShift::class)->handle($actor, $employee->id, rhistPattern('08:00:00', '17:00:00')->id);
    rhistPunchDay($employee, '2026-09-09', '08:00', '17:00');
    rhistPunchDay($employee, '2026-09-23', '22:00', '06:00');

    rhistAt('2026-09-16T05:00:00+08:00');
    $before = rhistDtr($employee, '2026-09-01', '2026-09-15');
    app(SaveRosterWeek::class)->handle($actor, $employee->id, [3 => $night->id], [3 => 'WFH']);

    $roster = app(ShiftRoster::class);
    expect(rhistDtr($employee, '2026-09-01', '2026-09-15'))->toBe($before)
        ->and($roster->forDate($employee->id, '2026-09-09')?->startTime)->toBe('08:00:00')
        ->and($roster->locationForDate($employee->id, '2026-09-09'))->toBeNull()
        ->and($roster->forDate($employee->id, '2026-09-23')?->startTime)->toBe('22:00:00')
        ->and($roster->locationForDate($employee->id, '2026-09-23')?->kind)->toBe('WFH')
        ->and(rhistDtr($employee, '2026-09-23', '2026-09-23')[0]['lateMinutes'])->toEqual(0);

    // Going back to the default from a later date keeps the Wednesday night
    // for the dates it applied to.
    rhistAt('2026-09-30T05:00:00+08:00');
    app(SaveRosterWeek::class)->handle($actor, $employee->id, [3 => 'DEFAULT'], [3 => 'DEFAULT']);
    expect($roster->forDate($employee->id, '2026-09-23')?->startTime)->toBe('22:00:00')
        ->and($roster->locationForDate($employee->id, '2026-09-23')?->kind)->toBe('WFH')
        ->and($roster->forDate($employee->id, '2026-09-30')?->startTime)->toBe('08:00:00')
        ->and($roster->locationForDate($employee->id, '2026-09-30'))->toBeNull();
});

it('copies an edited pattern from today only: past copies stay, a running assignment splits, and what has not begun takes it in place', function () {
    $actor = rhistActor();
    $employee = rhistEmployee();
    $pattern = rhistPattern('08:00:00', '17:00:00');
    rhistAt('2026-09-01T06:00:00+08:00');
    app(AssignShift::class)->handle($actor, $employee->id, $pattern->id);
    // A period running across the edit (the period screen arrives with the
    // dated roster commands, so the row is written directly).
    $running = new RosterDayOverride(['employee_id' => $employee->id, 'date' => '2026-09-10', 'end_date' => '2026-09-20', 'include_rest_days' => false,
        'shift_type_id' => $pattern->id, 'shift_name' => $pattern->name, 'shift_data' => DomainShift::fromArray(ShiftPolicy::attributesFromPattern($pattern))->toArray()]);
    $running->save();
    app(SaveRosterDate::class)->handle($actor, $employee->id, '2026-09-25', $pattern->id, 'DEFAULT');
    $pending = RosterChanges::open($actor, $employee->id, '2026-09-28');
    Shift::query()->create(ShiftPolicy::attributesFromPattern($pattern) + ['employee_id' => $employee->id, 'effective_from' => '2026-09-28', 'change_id' => $pending->id]);
    foreach (['2026-09-11', '2026-09-15', '2026-09-17', '2026-09-25'] as $date) {
        rhistPunchDay($employee, $date, '08:00', '17:00');
    }

    rhistAt('2026-09-16T05:00:00+08:00');
    $before = rhistDtr($employee, '2026-09-01', '2026-09-15');
    app(SaveShiftType::class)->handle($actor, $pattern->id, new ShiftTypeDraft($pattern->name, 'FIXED', [1, 2, 3, 4, 5], '08:30', '17:30', '0', '', false, '00:00', '01:00', '0', false));

    $roster = app(ShiftRoster::class);
    expect(rhistDtr($employee, '2026-09-01', '2026-09-15'))->toBe($before)
        ->and($roster->forDate($employee->id, '2026-09-15')?->startTime)->toBe('08:00:00')
        ->and($roster->forDate($employee->id, '2026-09-17')?->startTime)->toBe('08:30:00')
        ->and($roster->forDate($employee->id, '2026-09-22')?->startTime)->toBe('08:30:00')
        ->and($roster->forDate($employee->id, '2026-09-25')?->startTime)->toBe('08:30:00')
        ->and($roster->forDate($employee->id, '2026-09-29')?->startTime)->toBe('08:30:00');
    $running->refresh();
    $revision = RosterDayOverride::query()->where('revision_of', $running->id)->sole();
    expect($running->cut_after?->format('Y-m-d'))->toBe('2026-09-15')
        ->and($running->status)->toBe('ACTIVE')
        ->and($running->shift_data['start_time'] ?? null)->toBe('08:00:00')
        ->and($revision->date->format('Y-m-d'))->toBe('2026-09-16')
        ->and($revision->end_date?->format('Y-m-d'))->toBe('2026-09-20')
        ->and($revision->include_rest_days)->toBeFalse()
        ->and($revision->created_by)->toBe((int) $actor->userId)
        ->and(RosterDayOverride::query()->where('date', '2026-09-25')->count())->toBe(1)
        ->and(Shift::query()->where('change_id', $pending->id)->sole()->start_time)->toBe('08:30:00')
        ->and(AuditLog::query()->where('action', 'SHIFT_TYPE_UPDATED')->sole()->details)->toContain('re-copied onto 1 rostered employee');
});

it('never resolves the rows of a cancelled change', function () {
    $actor = rhistActor();
    $employee = rhistEmployee();
    Shift::query()->create(ShiftPolicy::attributesFromPattern(rhistPattern('08:00:00', '17:00:00')) + ['employee_id' => $employee->id]);
    $change = RosterChanges::open($actor, $employee->id, '2026-09-20');
    Shift::query()->create(ShiftPolicy::attributesFromPattern(rhistPattern('22:00:00', '06:00:00')) + ['employee_id' => $employee->id, 'effective_from' => '2026-09-20', 'change_id' => $change->id]);
    expect(app(ShiftRoster::class)->forDate($employee->id, '2026-09-21')?->startTime)->toBe('22:00:00');

    $change->status = RosterScheduleChange::STATUS_CANCELLED;
    $change->cancelled_at = CarbonImmutable::now('UTC');
    $change->cancelled_by = (int) $actor->userId;
    $change->save();

    rhistAt('2026-09-22T09:00:00+08:00');
    expect(app(ShiftRoster::class)->forDate($employee->id, '2026-09-21')?->startTime)->toBe('08:00:00')
        ->and(app(ReferenceData::class)->shift($actor, $employee->id)?->start_time)->toBe('08:00:00');
});

/* |---| Payroll |---| */

it('leaves an earlier month\'s payroll inputs untouched by a change today, and changes those of the month it falls in', function () {
    $actor = rhistActor();
    $employee = rhistEmployee();
    $day = rhistPattern('08:00:00', '17:00:00');
    rhistAt('2026-09-01T06:00:00+08:00');
    app(AssignShift::class)->handle($actor, $employee->id, $day->id);
    foreach (['2026-09-01', '2026-09-02', '2026-10-01', '2026-10-02'] as $date) {
        rhistPunchDay($employee, $date, '08:00', '17:00');
    }

    rhistAt('2026-10-14T06:00:00+08:00');
    $september = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $october = PayrollData::calculateDrafts([$employee->id], '2026-10-01', '2026-10-15', actor: $actor);
    // Saturdays become working days from today: October's scheduled days —
    // the whole-month divisor (D7) — change, September's cannot.
    app(SaveRosterWeek::class)->handle($actor, $employee->id, [6 => $day->id], []);
    $septemberAfter = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $octoberAfter = PayrollData::calculateDrafts([$employee->id], '2026-10-01', '2026-10-15', actor: $actor);

    expect($septemberAfter['inputs']?->fingerprint())->toBe($september['inputs']?->fingerprint())
        ->and($septemberAfter['drafts'][0]->gross_pay)->toBe($september['drafts'][0]->gross_pay)
        ->and($octoberAfter['inputs']?->fingerprint())->not->toBe($october['inputs']?->fingerprint());
});

/* |---| Saves that change nothing |---| */

it('writes no dated row and moves no payroll input when the same hours are saved again', function () {
    $actor = rhistActor();
    $employee = rhistEmployee();
    $pattern = rhistPattern('08:00:00', '17:00:00');
    rhistAt('2026-10-01T06:00:00+08:00');
    app(AssignShift::class)->handle($actor, $employee->id, $pattern->id);
    app(SaveRosterWeek::class)->handle($actor, $employee->id, [6 => $pattern->id], []);
    $running = new RosterDayOverride(['employee_id' => $employee->id, 'date' => '2026-10-05', 'end_date' => '2026-10-20', 'include_rest_days' => false,
        'shift_type_id' => $pattern->id, 'shift_name' => $pattern->name, 'shift_data' => DomainShift::fromArray(ShiftPolicy::attributesFromPattern($pattern))->toArray()]);
    $running->save();
    rhistPunchDay($employee, '2026-10-02', '08:00', '17:00');

    rhistAt('2026-10-14T06:00:00+08:00');
    $draft = PayrollData::calculateDrafts([$employee->id], '2026-10-01', '2026-10-15', actor: $actor)['inputs']?->fingerprint();
    $rows = [Shift::query()->count(), RosterShift::query()->count(), RosterDayOverride::query()->count(), RosterScheduleChange::query()->count()];

    // The dialog re-picks the current pattern; the pattern is saved as it is;
    // then renamed. None of them changes an hour.
    app(AssignShift::class)->handle($actor, $employee->id, $pattern->id);
    $draftOf = static fn (ShiftType $pattern): ShiftTypeDraft => new ShiftTypeDraft($pattern->name, 'FIXED', [1, 2, 3, 4, 5], '08:00', '17:00', '0', '', false, '00:00', '01:00', '0', false);
    app(SaveShiftType::class)->handle($actor, $pattern->id, $draftOf($pattern));
    $renamed = $pattern->fresh();
    assert($renamed instanceof ShiftType);
    $renamed->name = 'Renamed day';
    app(SaveShiftType::class)->handle($actor, $pattern->id, $draftOf($renamed));

    expect([Shift::query()->count(), RosterShift::query()->count(), RosterDayOverride::query()->count(), RosterScheduleChange::query()->count()])->toBe($rows)
        ->and(PayrollData::calculateDrafts([$employee->id], '2026-10-01', '2026-10-15', actor: $actor)['inputs']?->fingerprint())->toBe($draft)
        // The name is display only, so it follows the pattern in place.
        ->and(RosterShift::query()->sole()->shift_name)->toBe('Renamed day')
        ->and($running->fresh()?->shift_name)->toBe('Renamed day')
        ->and($running->fresh()?->cut_after)->toBeNull();
});

it('resolves a dated row that repeats the hours in effect exactly as if it were not there', function () {
    rhistActor();
    $employee = rhistEmployee();
    $attributes = ShiftPolicy::attributesFromPattern(rhistPattern('08:00:00', '17:00:00'));
    Shift::query()->create($attributes + ['employee_id' => $employee->id]);
    $before = app(ShiftRoster::class)->scheduleForEmployee($employee->id, '2026-10-01', '2026-10-31')?->toArray();
    Shift::query()->create($attributes + ['employee_id' => $employee->id, 'effective_from' => '2026-10-10']);

    expect(app(ShiftRoster::class)->scheduleForEmployee($employee->id, '2026-10-01', '2026-10-31')?->toArray())->toBe($before)
        ->and($before['date_shifts'] ?? null)->toBe([]);
});

/* |---| Periods and the one-date change |---| */

it('refuses a one-date change inside a longer assignment, and reads that assignment for any date it covers', function () {
    $actor = rhistActor();
    $employee = rhistEmployee();
    $pattern = rhistPattern('22:00:00', '06:00:00');
    rhistAt('2026-10-01T06:00:00+08:00');
    $period = new RosterDayOverride(['employee_id' => $employee->id, 'date' => '2026-10-05', 'end_date' => '2026-10-16', 'include_rest_days' => false, 'location_kind' => 'WFH',
        'shift_type_id' => $pattern->id, 'shift_name' => $pattern->name, 'shift_data' => DomainShift::fromArray(ShiftPolicy::attributesFromPattern($pattern))->toArray()]);
    $period->save();

    expect(fn () => app(SaveRosterDate::class)->handle($actor, $employee->id, '2026-10-08', 'REST', 'DEFAULT'))
        ->toThrow(RuntimeException::class, 'That date is part of a temporary schedule for several dates. Change that schedule instead.');
    expect($period->fresh()?->status)->toBe('ACTIVE')
        ->and($period->fresh()?->cut_after)->toBeNull()
        ->and(RosterDayOverride::query()->count())->toBe(1)
        ->and(app(RosterDates::class)->forDate($actor, $employee->id, '2026-10-08')?->id)->toBe($period->id);
});

it('stores the last date a cancelled assignment applied as a bare calendar day', function () {
    $actor = rhistActor();
    $employee = rhistEmployee();
    rhistAt('2026-10-01T06:00:00+08:00');
    app(SaveRosterDate::class)->handle($actor, $employee->id, '2026-10-09', 'REST', 'WFH');
    app(SaveRosterDate::class)->handle($actor, $employee->id, '2026-10-09', 'DEFAULT', 'DEFAULT');

    $cancelled = RosterDayOverride::query()->sole();
    expect(DB::table('roster_day_overrides')->where('id', $cancelled->id)->value('cut_after'))->toBe('2026-10-08')
        ->and($cancelled->status)->toBe('CANCELLED')
        ->and($cancelled->cancelled_by)->toBe((int) $actor->userId)
        ->and($cancelled->cancelled_at)->not->toBeNull();
});
