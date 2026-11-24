<?php

declare(strict_types=1);

use App\Domain\Attendance\DtrCalculator;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterDayOverride;
use App\Models\RosterLocation;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Commands\SaveAssignment;
use App\Modules\Timekeeping\Application\Commands\SaveRosterDate;
use App\Modules\Timekeeping\Application\Commands\SaveRosterLocations;
use App\Modules\Timekeeping\Application\Commands\SaveRosterShifts;
use App\Modules\Timekeeping\Application\Commands\SaveRosterWeek;
use App\Modules\Timekeeping\Application\Commands\SaveShiftType;
use App\Modules\Timekeeping\Application\ScheduleChangeRefused;
use App\Modules\Timekeeping\Application\ScheduleGuard;
use App\Modules\Timekeeping\Application\ScheduleImpactPending;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Modules\Timekeeping\Application\ShiftTypeDraft;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * No entry point can change a schedule backwards. Every roster write — the
 * default shift, weekday hours and places, a date, a shared pattern — is
 * refused for a past date; takes effect today only before today's shift
 * (scheduled and proposed) has started and before its first punch, else
 * from the next date; leaves yesterday's night shift and its morning
 * punches exactly as they were; and refuses a shift left running into the
 * next one. A refusal writes nothing and audits nothing.
 */
const SSG_PAST = 'A schedule change cannot start in the past. Choose today or a later date.';

function ssgActor(): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Guard '.Str::random(8), 'all_data' => true, 'is_system' => false])->save();
    foreach (['ROSTER', 'RECORDS', 'EDTR', 'PAYROLL'] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function ssgEmployee(string $first = 'Gina'): EmployeeProfile
{
    return EmployeeProfile::query()->create(['employee_code' => 'SG-'.Str::random(8), 'first_name' => $first, 'last_name' => 'Guarded',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'monthly_rate' => 30000, 'is_active' => true]);
}

/** @param  array<string, mixed>  $overrides */
function ssgPattern(string $start, string $end, array $overrides = []): ShiftType
{
    return ShiftType::query()->create(array_replace(['code' => Str::random(10), 'name' => 'Guard '.Str::random(6), 'mode' => 'FIXED', 'start_time' => $start, 'end_time' => $end,
        'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false], $overrides));
}

/** The schedule an employee had "since before history was kept": a baseline row, written directly. */
function ssgBaseline(EmployeeProfile $employee, ShiftType $pattern): Shift
{
    return Shift::query()->create(ShiftPolicy::attributesFromPattern($pattern) + ['employee_id' => $employee->id]);
}

function ssgPunch(EmployeeProfile $employee, string $type, string $manila): AttendanceLog
{
    return AttendanceLog::query()->create(['employee_id' => $employee->id, 'log_type' => $type, 'source' => 'MANUAL', 'logged_at' => LocalClock::instant($manila)->utc()]);
}

function ssgAt(string $manila): void
{
    test()->travelTo(new DateTimeImmutable($manila.'+08:00'));
}

/** @return list<array<string, mixed>> */
function ssgDtr(EmployeeProfile $employee, string $start, string $end): array
{
    $inputs = app(AttendanceSource::class)->forDtr(app(Access::class), $employee->id, $start, $end);

    return json_decode((string) json_encode(DtrCalculator::compute($inputs->logs, $inputs->shift, $inputs->leaves, $start, $end)), true);
}

/**
 * Everything a roster write can leave behind.
 *
 * @return list<int>
 */
function ssgRows(): array
{
    return [Shift::query()->count(), RosterShift::query()->count(), RosterLocation::query()->count(), RosterDayOverride::query()->count(),
        RosterScheduleChange::query()->count(), AuditLog::query()->count()];
}

/* |---| Past dates, through every entry point |---| */

it('refuses a past or impossible date through every roster entry point, writing and auditing nothing', function (string $date, string $sentence) {
    ssgAt('2026-10-07T06:00:00');
    $actor = ssgActor();
    $employee = ssgEmployee();
    $pattern = ssgPattern('08:00:00', '17:00:00');
    ssgBaseline($employee, $pattern);
    $other = ssgPattern('09:00:00', '18:00:00');
    $before = ssgRows();
    $draft = new ShiftTypeDraft($pattern->name, 'FIXED', [1, 2, 3, 4, 5], '08:30', '17:30', '0', '', false, '00:00', '01:00', '0', false);

    foreach ([
        'default shift' => fn () => app(AssignShift::class)->handle($actor, $employee->id, $other->id, $date),
        'weekday hours' => fn () => app(SaveRosterShifts::class)->handle($actor, $employee->id, [3 => $other->id], $date),
        'weekday places' => fn () => app(SaveRosterLocations::class)->handle($actor, $employee->id, [3 => 'WFH'], $date),
        'weekly schedule' => fn () => app(SaveRosterWeek::class)->handle($actor, $employee->id, [3 => $other->id], [3 => 'WFH'], $date),
        'one date' => fn () => app(SaveRosterDate::class)->handle($actor, $employee->id, $date, $other->id, 'WFH'),
        'one date removed' => fn () => app(SaveRosterDate::class)->handle($actor, $employee->id, $date, 'DEFAULT', 'DEFAULT'),
        'shared pattern' => fn () => app(SaveShiftType::class)->handle($actor, $pattern->id, $draft, $date),
        'preview' => fn () => app(AssignShift::class)->preview($actor, $employee->id, $other->id, $date),
    ] as $entry => $call) {
        expect($call)->toThrow(ScheduleChangeRefused::class, $sentence, $entry);
    }

    expect(ssgRows())->toBe($before)
        ->and($pattern->fresh()?->start_time)->toBe('08:00:00');
})->with([
    'yesterday' => ['2026-10-06', SSG_PAST],
    'last month' => ['2026-09-07', SSG_PAST],
    'not a date' => ['2026-02-30', 'Choose a valid calendar date.'],
]);

it('accepts today and a later date, dating the rows and the audit sentence', function () {
    ssgAt('2026-10-07T06:00:00');
    $actor = ssgActor();
    $employee = ssgEmployee();
    ssgBaseline($employee, ssgPattern('08:00:00', '17:00:00'));
    $later = ssgPattern('09:00:00', '18:00:00');

    expect(app(AssignShift::class)->handle($actor, $employee->id, $later->id, '2026-10-12'))
        ->toBe("{$later->name} assigned to Gina Guarded. It takes effect on Oct 12, 2026.");

    $roster = app(ShiftRoster::class);
    expect($roster->forDate($employee->id, '2026-10-09')?->startTime)->toBe('08:00:00')
        ->and($roster->forDate($employee->id, '2026-10-12')?->startTime)->toBe('09:00:00')
        ->and(Shift::query()->where('effective_from', '2026-10-12')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'SHIFT_ASSIGNED')->sole()->details)->toBe('Gina Guarded assigned 09:00:00–18:00:00 · from Oct 12, 2026');
});

/* |---| Today's cutoff (D4) |---| */

it('applies a change today before the scheduled shift, the proposed shift and the first punch', function () {
    // Wednesday 2026-10-07, 06:00: the 08:00 shift has not started.
    ssgAt('2026-10-07T06:00:00');
    $actor = ssgActor();
    $employee = ssgEmployee();
    ssgBaseline($employee, ssgPattern('08:00:00', '17:00:00'));
    $late = ssgPattern('13:00:00', '22:00:00');

    $impact = app(AssignShift::class)->preview($actor, $employee->id, $late->id);
    expect($impact->effective)->toBe([$employee->id => '2026-10-07'])
        ->and($impact->deferred)->toBe([])
        ->and($impact->needsConfirmation())->toBeFalse()
        // A preview saves nothing.
        ->and(Shift::query()->count())->toBe(1);

    app(AssignShift::class)->handle($actor, $employee->id, $late->id);
    expect(app(ShiftRoster::class)->forDate($employee->id, '2026-10-07')?->startTime)->toBe('13:00:00');
});

it('moves an undated change to the next date once today is past its cutoff, and saves it only when confirmed', function (string $now, string $existing, string $proposed, ?string $punch, string $reason) {
    ssgAt('2026-10-07T'.$now);
    $actor = ssgActor();
    $employee = ssgEmployee();
    ssgBaseline($employee, ssgPattern(...explode('-', $existing)));
    $wanted = ssgPattern(...explode('-', $proposed));
    if ($punch !== null) {
        ssgPunch($employee, 'TIME_IN', '2026-10-07T'.$punch);
    }
    $today = ssgDtr($employee, '2026-10-05', '2026-10-07');
    $before = ssgRows();

    $impact = app(AssignShift::class)->preview($actor, $employee->id, $wanted->id);
    expect($impact->effective)->toBe([$employee->id => '2026-10-08'])
        ->and($impact->deferred)->toBe([$employee->id => $reason])
        ->and($impact->needsConfirmation())->toBeTrue()
        ->and(fn () => app(AssignShift::class)->handle($actor, $employee->id, $wanted->id))->toThrow(ScheduleImpactPending::class);
    expect(ssgRows())->toBe($before);

    // Asking for today by name is refused, with the earliest date it can use.
    expect(fn () => app(AssignShift::class)->handle($actor, $employee->id, $wanted->id, '2026-10-07'))
        ->toThrow(ScheduleChangeRefused::class, "This change can no longer take effect today: {$reason}. The earliest it can start is Oct 08, 2026.");
    expect(ssgRows())->toBe($before);

    expect(app(AssignShift::class)->handle($actor, $employee->id, $wanted->id, confirmed: $impact->token()))
        ->toBe("{$wanted->name} assigned to Gina Guarded. It takes effect on Oct 08, 2026.");
    expect(Shift::query()->where('effective_from', '2026-10-08')->count())->toBe(1)
        // Today is still judged by the shift it started with.
        ->and(app(ShiftRoster::class)->forDate($employee->id, '2026-10-07')?->startTime)->toBe(explode('-', $existing)[0])
        ->and(ssgDtr($employee, '2026-10-05', '2026-10-07'))->toBe($today);
})->with([
    'the scheduled shift has started' => ['08:00:00', '08:00:00-17:00:00', '13:00:00-22:00:00', null, 'the shift scheduled for today has already started'],
    'the proposed shift would have started' => ['09:30:00', '13:00:00-22:00:00', '09:00:00-18:00:00', null, 'the new shift would already have started today'],
    'an early punch is already in' => ['07:20:00', '08:00:00-17:00:00', '13:00:00-22:00:00', '07:15:00', 'a punch is already recorded for today’s shift'],
]);

it('lets a flexible shift change today until its first punch, and a rest day at any hour', function () {
    $actor = ssgActor();
    $flexible = ssgPattern('08:00:00', '17:00:00', ['mode' => 'FLEXIBLE', 'start_time' => null, 'end_time' => null, 'required_hours' => 8]);
    $late = ssgPattern('18:00:00', '23:00:00');

    // Wednesday 15:00, flexible, nothing punched: still today.
    ssgAt('2026-10-07T15:00:00');
    $free = ssgEmployee('Flora');
    ssgBaseline($free, $flexible);
    expect(app(AssignShift::class)->preview($actor, $free->id, $late->id)->effective)->toBe([$free->id => '2026-10-07']);

    // The same, after a punch: the next date.
    $punched = ssgEmployee('Pia');
    ssgBaseline($punched, $flexible);
    ssgPunch($punched, 'TIME_IN', '2026-10-07T10:00:00');
    expect(app(AssignShift::class)->preview($actor, $punched->id, $late->id)->deferred)->toBe([$punched->id => 'a punch is already recorded for today’s shift']);

    // Sunday 15:00 rests on both schedules: no shift has started.
    ssgAt('2026-10-11T15:00:00');
    $resting = ssgEmployee('Rhea');
    ssgBaseline($resting, ssgPattern('08:00:00', '17:00:00'));
    expect(app(AssignShift::class)->preview($actor, $resting->id, $late->id)->effective)->toBe([$resting->id => '2026-10-11']);
});

it('dates a change today at any hour when it leaves today itself as it was', function () {
    // Wednesday 15:00, mid-shift, punched in: Friday's hours and place change.
    ssgAt('2026-10-07T15:00:00');
    $actor = ssgActor();
    $employee = ssgEmployee();
    ssgBaseline($employee, ssgPattern('08:00:00', '17:00:00'));
    ssgPunch($employee, 'TIME_IN', '2026-10-07T08:00:00');
    $today = ssgDtr($employee, '2026-10-05', '2026-10-07');

    $impact = app(SaveRosterWeek::class)->preview($actor, $employee->id, [5 => ssgPattern('10:00:00', '19:00:00')->id], [5 => 'WFH']);
    expect($impact->effective)->toBe([$employee->id => '2026-10-07'])->and($impact->deferred)->toBe([]);

    // Wednesday's own place is today's: that waits for tomorrow.
    expect(app(SaveRosterLocations::class)->preview($actor, $employee->id, [3 => 'WFH'])->deferred)
        ->toBe([$employee->id => 'a punch is already recorded for today’s shift']);
    expect(ssgDtr($employee, '2026-10-05', '2026-10-07'))->toBe($today);
});

it('refuses a change to today’s own date once today is past its cutoff, including removing it', function () {
    ssgAt('2026-10-07T06:00:00');
    $actor = ssgActor();
    $employee = ssgEmployee();
    ssgBaseline($employee, ssgPattern('08:00:00', '17:00:00'));
    app(SaveRosterDate::class)->handle($actor, $employee->id, '2026-10-07', ssgPattern('10:00:00', '19:00:00')->id, 'WFH');

    ssgAt('2026-10-07T10:30:00');
    $before = ssgRows();
    foreach ([['REST', 'DEFAULT'], ['DEFAULT', 'DEFAULT'], [ssgPattern('12:00:00', '21:00:00')->id, 'WFH']] as [$shift, $place]) {
        expect(fn () => app(SaveRosterDate::class)->handle($actor, $employee->id, '2026-10-07', $shift, $place))
            ->toThrow(ScheduleChangeRefused::class, 'This change can no longer take effect today');
    }
    expect([...array_slice(ssgRows(), 0, 5), AuditLog::query()->count()])->toBe([...array_slice($before, 0, 5), $before[5]])
        ->and(RosterDayOverride::query()->sole()->status)->toBe('ACTIVE');
});

/* |---| Yesterday's night shift (D5) |---| */

it('leaves yesterday’s night shift and its morning punches as they were when today changes', function () {
    $actor = ssgActor();
    $employee = ssgEmployee();
    ssgBaseline($employee, ssgPattern('22:00:00', '06:00:00'));
    $in = ssgPunch($employee, 'TIME_IN', '2026-10-06T21:58:00');
    $out = ssgPunch($employee, 'TIME_OUT', '2026-10-07T06:04:00');

    // Wednesday 07:00: last night is over; tonight's shift has not started.
    ssgAt('2026-10-07T07:00:00');
    $night = ssgDtr($employee, '2026-10-05', '2026-10-06');
    app(AssignShift::class)->handle($actor, $employee->id, ssgPattern('13:00:00', '22:00:00')->id);

    $inputs = app(AttendanceSource::class)->forDtr($actor, $employee->id, '2026-10-06', '2026-10-07');
    $owned = static fn (string $date): array => array_map(static fn ($log): ?string => $log->id, DtrCalculator::rowPunches($inputs->logs, $inputs->shift, $date));
    expect(ssgDtr($employee, '2026-10-05', '2026-10-06'))->toBe($night)
        ->and($night[1]['status'])->toBe('PRESENT')
        ->and($owned('2026-10-06'))->toEqualCanonicalizing([$in->id, $out->id])
        ->and($owned('2026-10-07'))->toBe([])
        ->and(app(ShiftRoster::class)->forDate($employee->id, '2026-10-06')?->startTime)->toBe('22:00:00')
        ->and(app(ShiftRoster::class)->forDate($employee->id, '2026-10-07')?->startTime)->toBe('13:00:00');
});

it('refuses a shift that would start while the night before is still scheduled to be running', function () {
    $actor = ssgActor();
    $employee = ssgEmployee();
    ssgBaseline($employee, ssgPattern('22:00:00', '07:00:00'));
    ssgPunch($employee, 'TIME_IN', '2026-10-06T22:00:00');

    // Wednesday 03:00, mid-shift: a 06:00 start today overlaps the night that ends at 07:00.
    ssgAt('2026-10-07T03:00:00');
    $before = ssgRows();
    $sentence = 'The shift that starts on Oct 06, 2026 ends at 07:00 the next day, after the shift on Oct 07, 2026 starts at 06:00. Adjust one of them so they do not overlap.';
    expect(fn () => app(AssignShift::class)->handle($actor, $employee->id, ssgPattern('06:00:00', '15:00:00')->id))->toThrow(ScheduleChangeRefused::class, $sentence)
        ->and(fn () => app(SaveRosterDate::class)->handle($actor, $employee->id, '2026-10-07', ssgPattern('06:00:00', '15:00:00')->id, 'DEFAULT'))->toThrow(ScheduleChangeRefused::class, $sentence);
    expect(ssgRows())->toBe($before);

    // The same clash on a future date is refused the same way: the last
    // night of the old schedule must finish normally.
    expect(fn () => app(AssignShift::class)->handle($actor, $employee->id, ssgPattern('06:00:00', '15:00:00')->id, '2026-10-20'))
        ->toThrow(ScheduleChangeRefused::class, 'The shift that starts on Oct 19, 2026 ends at 07:00 the next day, after the shift on Oct 20, 2026 starts at 06:00.');
    // A shift that starts when the night ends is fine.
    app(AssignShift::class)->handle($actor, $employee->id, ssgPattern('07:00:00', '16:00:00')->id, '2026-10-20');
    expect(app(ShiftRoster::class)->forDate($employee->id, '2026-10-19')?->endTime)->toBe('07:00:00')
        ->and(app(ShiftRoster::class)->forDate($employee->id, '2026-10-20')?->startTime)->toBe('07:00:00');
});

it('refuses any write that would alter an earlier date’s record, whatever produced it', function () {
    // The backstop under every other rule: the guard compares the earlier
    // dates' records as the DTR computes them, before and after the write.
    $actor = ssgActor();
    $employee = ssgEmployee();
    ssgBaseline($employee, ssgPattern('08:00:00', '17:00:00'));
    ssgPunch($employee, 'TIME_IN', '2026-10-06T08:40:00');
    ssgPunch($employee, 'TIME_OUT', '2026-10-06T17:00:00');
    ssgAt('2026-10-07T06:00:00');

    expect(fn () => DB::transaction(static function () use ($employee): void {
        $guard = app(ScheduleGuard::class);
        $before = $guard->before($employee->id, '2026-10-07');
        // A write that wrongly reaches back: yesterday would no longer be late.
        Shift::query()->create(ShiftPolicy::attributesFromPattern(ssgPattern('09:00:00', '18:00:00')) + ['employee_id' => $employee->id, 'effective_from' => '2026-10-05']);
        $guard->verify($before);
    }))->toThrow(ScheduleChangeRefused::class, 'This change would alter the attendance already recorded up to Oct 06, 2026');
    expect(Shift::query()->count())->toBe(1);
});

/* |---| A day already paid |---| */

it('refuses a change that starts on a day already paid on a posted payslip', function () {
    ssgAt('2026-10-07T06:00:00');
    $actor = ssgActor();
    $employee = ssgEmployee();
    ssgBaseline($employee, ssgPattern('08:00:00', '17:00:00'));
    $payslip = new Payslip;
    $payslip->forceFill(['employee_id' => $employee->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'gross_pay' => 15000, 'total_deductions' => 0, 'net_pay' => 15000])->save();
    $late = ssgPattern('13:00:00', '22:00:00');

    expect(fn () => app(AssignShift::class)->handle($actor, $employee->id, $late->id))
        ->toThrow(ScheduleChangeRefused::class, 'Oct 07, 2026 is already paid on a posted payslip, so its schedule can no longer change. Choose a date after that payroll period.')
        ->and(fn () => app(AssignShift::class)->handle($actor, $employee->id, $late->id, '2026-10-15'))->toThrow(ScheduleChangeRefused::class, 'already paid');
    app(AssignShift::class)->handle($actor, $employee->id, $late->id, '2026-10-16');
    expect(Shift::query()->where('effective_from', '2026-10-16')->count())->toBe(1);

    // A period posted AHEAD of the change (Oct 16–31 finalized early): an
    // open-ended change dated before it, or a temporary schedule reaching
    // into it, would rewrite paid dates; one that ends before it is fine.
    $ahead = new Payslip;
    $ahead->forceFill(['employee_id' => $employee->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-15', 'gross_pay' => 15000, 'total_deductions' => 0, 'net_pay' => 15000])->save();
    expect(fn () => app(AssignShift::class)->handle($actor, $employee->id, ssgPattern('07:00:00', '16:00:00')->id, '2026-10-20'))
        ->toThrow(ScheduleChangeRefused::class, 'Dates after Oct 20, 2026 are already paid on a posted payslip through Nov 15, 2026, so this change would reach them. Choose a date after Nov 15, 2026.')
        ->and(fn () => app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-28', '2026-11-03', $late->id, 'DEFAULT'))->toThrow(ScheduleChangeRefused::class, 'through Nov 15, 2026');
    app(SaveAssignment::class)->handle($actor, $employee->id, '2026-10-28', '2026-10-31', $late->id, 'DEFAULT');
    expect(RosterDayOverride::query()->count())->toBe(1);
});

/* |---| Payroll drafts (D7) |---| */

it('names the open payroll drafts a change can move and saves only when they were shown', function () {
    ssgAt('2026-10-07T06:00:00');
    $actor = ssgActor();
    $employee = ssgEmployee();
    ssgBaseline($employee, ssgPattern('08:00:00', '17:00:00'));
    foreach ([['PR-SG1', '2026-10-01', '2026-10-15', 'DRAFT'], ['PR-SG2', '2026-09-16', '2026-09-30', 'DRAFT'], ['PR-SG3', '2026-10-16', '2026-10-31', 'FINALIZED']] as [$code, $start, $end, $status]) {
        $run = PayrollRun::query()->create(['code' => $code, 'name' => $code.' payroll', 'status' => $status, 'period_start' => $start, 'period_end' => $end]);
        PayrollRunMember::query()->create(['run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0, 'period_start' => $start, 'period_end' => $end]);
    }
    $saturdays = ssgPattern('08:00:00', '12:00:00');

    // October's open draft can move; September's cannot, and the finalized one never does.
    $impact = app(SaveRosterShifts::class)->preview($actor, $employee->id, [6 => $saturdays->id]);
    expect($impact->drafts)->toBe([['employee_id' => $employee->id, 'code' => 'PR-SG1', 'name' => 'PR-SG1 payroll', 'period_start' => '2026-10-01', 'period_end' => '2026-10-15']])
        ->and(fn () => app(SaveRosterShifts::class)->handle($actor, $employee->id, [6 => $saturdays->id]))->toThrow(ScheduleImpactPending::class);
    expect(RosterShift::query()->count())->toBe(0);

    app(SaveRosterShifts::class)->handle($actor, $employee->id, [6 => $saturdays->id], confirmed: $impact->token());
    expect(RosterShift::query()->sole()->effective_from?->format('Y-m-d'))->toBe('2026-10-07');
});

/* |---| A shared pattern, many employees |---| */

it('copies an edited pattern from the date asked for, each employee from their own next eligible date', function () {
    $actor = ssgActor();
    $pattern = ssgPattern('13:00:00', '22:00:00');
    $waiting = ssgEmployee('Wena');
    $working = ssgEmployee('Wilma');
    ssgBaseline($waiting, $pattern);
    ssgBaseline($working, $pattern);
    // Wednesday 11:00: nobody has started, but Wilma came in early.
    ssgAt('2026-10-07T11:00:00');
    ssgPunch($working, 'TIME_IN', '2026-10-07T10:55:00');
    $draft = new ShiftTypeDraft($pattern->name, 'FIXED', [1, 2, 3, 4, 5], '14:00', '23:00', '0', '', false, '00:00', '01:00', '0', false);

    $impact = app(SaveShiftType::class)->preview($actor, $pattern->id, $draft);
    expect($impact->effective)->toBe([$waiting->id => '2026-10-07', $working->id => '2026-10-08'])
        ->and($impact->deferred)->toBe([$working->id => 'a punch is already recorded for today’s shift'])
        ->and($pattern->fresh()?->start_time)->toBe('13:00:00');

    app(SaveShiftType::class)->handle($actor, $pattern->id, $draft, confirmed: $impact->token());
    $roster = app(ShiftRoster::class);
    expect($pattern->fresh()?->start_time)->toBe('14:00:00')
        ->and($roster->forDate($waiting->id, '2026-10-07')?->startTime)->toBe('14:00:00')
        ->and($roster->forDate($working->id, '2026-10-07')?->startTime)->toBe('13:00:00')
        ->and($roster->forDate($working->id, '2026-10-08')?->startTime)->toBe('14:00:00')
        ->and($roster->forDate($waiting->id, '2026-10-06')?->startTime)->toBe('13:00:00');
});

it('rolls a pattern edit back for everyone when one employee’s schedule refuses it', function () {
    $actor = ssgActor();
    $pattern = ssgPattern('08:00:00', '17:00:00');
    $plain = ssgEmployee('Petra');
    $nights = ssgEmployee('Nina');
    ssgBaseline($plain, $pattern);
    // Nina works the pattern on Thursdays only, after Wednesday nights that end at 07:00.
    ssgBaseline($nights, ssgPattern('22:00:00', '07:00:00', ['work_days' => [3]]));
    RosterShift::query()->create(['employee_id' => $nights->id, 'weekday' => 4, 'shift_type_id' => $pattern->id, 'shift_name' => $pattern->name, 'is_rest_day' => false,
        'shift_data' => App\Domain\Attendance\Shift::fromArray(array_replace(ShiftPolicy::attributesFromPattern($pattern), ['work_days' => [4]]))->toArray()]);
    ssgAt('2026-10-05T06:00:00');
    $before = ssgRows();

    // 06:00 starts before Nina's Wednesday night has ended.
    $draft = new ShiftTypeDraft($pattern->name, 'FIXED', [1, 2, 3, 4, 5], '06:00', '15:00', '0', '', false, '00:00', '01:00', '0', false);
    expect(fn () => app(SaveShiftType::class)->handle($actor, $pattern->id, $draft))->toThrow(ScheduleChangeRefused::class, 'so they do not overlap');

    expect(ssgRows())->toBe($before)
        ->and($pattern->fresh()?->start_time)->toBe('08:00:00')
        ->and(app(ShiftRoster::class)->forDate($plain->id, '2026-10-05')?->startTime)->toBe('08:00:00');
});
