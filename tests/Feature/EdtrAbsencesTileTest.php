<?php

declare(strict_types=1);

use App\Livewire\Edtr\EdtrPage;
use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollFormula;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Daily Time Record's Absences tile counts a day only once it is over:
 * the period is computed through today, whose row reads ABSENT before its
 * shift starts and INCOMPLETE while the employee is still at work. Neither
 * is an absence until the shift's end plus the Formulas OT max has passed —
 * the next day for a night shift. Once over, a day with a Time In and no
 * Time Out counts, as it does on the payslip.
 */
function absTileActor(): void
{
    $role = new Role;
    $role->forceFill(['name' => 'AbsTile '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => 'EDTR'])->save();

    test()->actingAs(User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => null]));
    app()->forgetInstance(Access::class);
}

/**
 * An employee, unrostered (08:00–17:00, Monday–Friday) unless a roster row
 * is given.
 *
 * @param  array<string, mixed>  $shift
 */
function absTileEmployee(array $shift = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'ABS-'.random_int(10000, 99999), 'first_name' => 'Liza', 'last_name' => 'Domingo',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ])->save();

    if ($shift !== []) {
        $row = new Shift;
        $row->forceFill(array_merge([
            'employee_id' => (string) $employee->getKey(), 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
            'grace_minutes' => 5, 'break_start' => null, 'break_end' => null, 'break_minutes' => 0,
            'requires_break_punch' => false,
        ], $shift))->save();
    }

    return $employee;
}

function absTilePunch(EmployeeProfile $employee, string $type, string $manila): void
{
    $punch = new AttendanceLog;
    $punch->forceFill([
        'employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'MANUAL',
        'is_geofenced' => true, 'is_anomaly' => false,
        'logged_at' => LocalClock::instant($manila)->utc(),
    ])->save();
}

/** The Absences tile for the period, as the page renders it at the Manila instant `$now`. */
function absTileCount(EmployeeProfile $employee, string $start, string $end, string $now): string
{
    test()->travelTo(new DateTimeImmutable($now.'+08:00'));

    $html = Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->set('start', $start)
        ->set('end', $end)
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->html();

    preg_match('/wire:key="stat-Absences".*?<p class="num mt-1 text-2xl font-bold[^"]*">([^<]*)<\/p>/s', $html, $tile);

    return $tile[1] ?? 'no tile';
}

it('does not count today as an absence before its shift has started', function () {
    // Tuesday the 1st and Wednesday the 2nd, no punches on either.
    $employee = absTileEmployee();
    absTileActor();

    expect(absTileCount($employee, '2026-09-01', '2026-09-02', '2026-09-02T07:00:00'))->toBe('1')
        // The shift is over, the overtime it could still run to is not.
        ->and(absTileCount($employee, '2026-09-01', '2026-09-02', '2026-09-02T17:30:00'))->toBe('1')
        ->and(absTileCount($employee, '2026-09-01', '2026-09-02', '2026-09-03T01:00:00'))->toBe('2');
});

it('counts a Time In with no Time Out once the shift and its overtime are over', function () {
    $employee = absTileEmployee();
    absTilePunch($employee, 'TIME_IN', '2026-09-02T08:00:00');
    absTileActor();

    expect(absTileCount($employee, '2026-09-02', '2026-09-02', '2026-09-02T20:00:00'))->toBe('0')
        ->and(absTileCount($employee, '2026-09-02', '2026-09-02', '2026-09-03T01:00:00'))->toBe('1');
});

it('closes the day by the Formulas OT maximum', function () {
    PayrollFormula::create(['code' => 'F-OT', 'key' => 'OT', 'name' => 'Overtime', 'expression' => 'Display only',
        'params' => [['key' => 'working_hours', 'value' => 8], ['key' => 'percentage', 'value' => 1.25], ['key' => 'max_ot', 'value' => 2]]]);
    $employee = absTileEmployee();
    absTilePunch($employee, 'TIME_IN', '2026-09-02T08:00:00');
    absTileActor();

    expect(absTileCount($employee, '2026-09-02', '2026-09-02', '2026-09-02T18:59:00'))->toBe('0')
        ->and(absTileCount($employee, '2026-09-02', '2026-09-02', '2026-09-02T19:00:00'))->toBe('1');
});

it('waits for a night shift to end, and its overtime, before counting it', function () {
    $employee = absTileEmployee(['start_time' => '22:00:00', 'end_time' => '06:00:00']);
    // In at 22:00 on Tuesday the 1st, still no Time Out the next morning.
    absTilePunch($employee, 'TIME_IN', '2026-09-01T22:00:00');
    absTileActor();

    expect(absTileCount($employee, '2026-09-01', '2026-09-01', '2026-09-02T00:30:00'))->toBe('0')
        ->and(absTileCount($employee, '2026-09-01', '2026-09-01', '2026-09-02T09:00:00'))->toBe('0')
        ->and(absTileCount($employee, '2026-09-01', '2026-09-01', '2026-09-02T14:00:00'))->toBe('1');
});
