<?php

declare(strict_types=1);

use App\Livewire\Portal\PortalPage;
use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Timekeeping\Application\Commands\FileAbsence;
use App\Support\Access;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PayrollEvidenceFixture;

/**
 * DELIBERATE DIVERGENCE 21 through the application: a half-day request
 * may say which half is off (AM or PM) — filed from My Portal, stored on
 * the request, read into the attendance facts and the payroll evidence
 * (schema 23; older evidence carries no half and reads as either), and
 * forgiven by the DTR only on that half. A half day that says neither is
 * the reference's.
 */

/** @param list<string> $modules */
function hdlActor(array $modules, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Half '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function hdlEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'HDL-'.Str::upper(Str::random(5)), 'first_name' => 'Hali', 'last_name' => 'Day',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'monthly_rate' => 22000, 'is_active' => true])->save();
    $shift = new Shift;
    $shift->forceFill(['employee_id' => (string) $employee->getKey(), 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 0,
        'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60, 'requires_break_punch' => false])->save();
    $type = new LeaveType;
    $type->forceFill(['code' => 'HDL-'.Str::random(6), 'name' => 'Vacation Leave'])->save();
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->id, 'year' => 2026, 'request_type' => 'VACATION_LEAVE', 'credits' => 5])->save();

    return $employee->refresh();
}

function hdlPunch(EmployeeProfile $employee, string $date, string $in, string $out): void
{
    foreach (['TIME_IN' => $in, 'TIME_OUT' => $out] as $type => $at) {
        $punch = new AttendanceLog;
        $punch->forceFill(['employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'MANUAL',
            'is_geofenced' => true, 'is_anomaly' => false, 'logged_at' => ManilaClock::instant("{$date}T{$at}:00")->utc()])->save();
    }
}

it('files a half day with its half from My Portal, stores it only on a half day, and shows it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 02:00:00', 'UTC'));
    $employee = hdlEmployee();
    hdlActor(['PORTAL'], $employee->id);

    Livewire::test(PortalPage::class)
        ->set('tab', 'requests')
        ->set('type', 'VACATION_LEAVE')->set('dayType', 'HALF_DAY')->assertSee('Which half')
        ->set('half', 'PM')->set('start', '2026-09-28')->set('end', '2026-09-28')->set('reason', 'Afternoon errand')
        ->call('file')
        ->assertSet('half', '')
        ->assertSee('Half day · Afternoon off');
    $request = LeaveTravelRequest::query()->sole();
    expect($request->half)->toBe('PM')->and($request->day_type)->toBe('HALF_DAY');

    // A whole day never carries a half, and an unknown half reads as neither.
    $actor = app(Access::class);
    app(FileAbsence::class)->handle($actor, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-29', '2026-09-29', 'Whole', 'AM');
    app(FileAbsence::class)->handle($actor, 'VACATION_LEAVE', 'HALF_DAY', '2026-09-30', '2026-09-30', 'Either', 'NOON');
    expect(LeaveTravelRequest::query()->where('start_date', '2026-09-29')->sole()->half)->toBeNull()
        ->and(LeaveTravelRequest::query()->where('start_date', '2026-09-30')->sole()->half)->toBeNull();
});

it('forgives only the half that is off in the attendance facts, captures it in the evidence and restores older evidence as either', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    // Monday 7 September: a PM half day, with the employee leaving at noon — and on Tuesday the 8th an AM half day, arriving at one.
    foreach ([['2026-09-07', 'PM', '08:00', '12:00'], ['2026-09-08', 'AM', '13:00', '17:00']] as [$date, $half, $in, $out]) {
        $request = new LeaveTravelRequest;
        $request->forceFill(['code' => 'HDL-'.$half, 'employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE', 'day_type' => 'HALF_DAY',
            'half' => $half, 'start_date' => $date, 'end_date' => $date, 'status' => 'APPROVED', 'is_paid' => true])->save();
        foreach (['TIME_IN' => $in, 'TIME_OUT' => $out] as $type => $at) {
            AttendanceLog::create(['employee_id' => $employee->id, 'source' => 'CORRECTION', 'log_type' => $type,
                'logged_at' => ManilaClock::instant("{$date}T{$at}:00")->utc(), 'latitude' => 14.5, 'longitude' => 121.0]);
        }
    }
    // The reference reading first: both halves forgiven on each day.
    LeaveTravelRequest::query()->update(['half' => null]);
    $either = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor)['drafts'][0];
    LeaveTravelRequest::query()->where('code', 'HDL-PM')->update(['half' => 'PM']);
    LeaveTravelRequest::query()->where('code', 'HDL-AM')->update(['half' => 'AM']);
    $calc = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor);
    $leaves = $calc['inputs']->attendance[$employee->id]->leaves;
    $byDate = [];
    foreach ($leaves as $leave) {
        $byDate[$leave->startDate] = $leave->half;
    }
    expect($byDate['2026-09-07'])->toBe('PM')->and($byDate['2026-09-08'])->toBe('AM')
        // The right halves forgive what the reference forgave.
        ->and($calc['drafts'][0]->late_minutes)->toBe($either->late_minutes)
        ->and($calc['drafts'][0]->undertime_minutes)->toBe($either->undertime_minutes)
        // A half day worked is a work day to the engine (the reference): the leave half is not paid twice.
        ->and($calc['drafts'][0]->paid_leave_days)->toBe($either->paid_leave_days)
        ->and($calc['inputs']->toArray()['schema_version'])->toBe(23);

    // The evidence restores with the halves; shaped as schema 22 it carries none and the DTR forgives both halves.
    $restored = PayrollInputs::restore(PayrollInputs::json($calc['inputs']->toArray()));
    expect($restored->fingerprint())->toBe($calc['inputs']->fingerprint());
    $older = PayrollInputs::restore(PayrollInputs::json(PayrollEvidenceFixture::asSchema(['schema_version' => 22] + $calc['inputs']->toArray(), 22)));
    foreach ($older->attendance[$employee->id]->leaves as $leave) {
        expect($leave->half)->toBeNull();
    }
    expect(PayrollData::calculateInputs($older, strict: true)['drafts'][0]->late_minutes)->toBe($either->late_minutes);

    // The wrong half worked is not forgiven: swap the halves and the noon departure is undertime, the one o'clock arrival late.
    LeaveTravelRequest::query()->where('code', 'HDL-PM')->update(['half' => 'AM']);
    LeaveTravelRequest::query()->where('code', 'HDL-AM')->update(['half' => 'PM']);
    $swapped = PayrollData::calculateDrafts([$employee->id], '2026-09-01', '2026-09-15', actor: $actor)['drafts'][0];
    // Both days now read as plain days: the noon departure and the missed morning (which the break-window path files as undertime) are short by half a day each.
    expect($swapped->undertime_minutes)->toBeGreaterThan($either->undertime_minutes + 400.0)
        ->and($swapped->late_minutes)->toBeGreaterThanOrEqual($either->late_minutes);
});
