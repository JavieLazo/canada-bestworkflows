<?php

declare(strict_types=1);

use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Roster\EmployeeSchedulePage;
use App\Livewire\Roster\RosterPage;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Shift Roster renders only what is on screen: the week table pages its
 * employees in the database and reads the schedules, places and leave of
 * the page's rows alone — never every employee the roster holds — and an
 * employee's month draws the Default schedule dialog's body only while the
 * dialog holds a draft, so the page itself carries none.
 */
function rppActor(): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Roster parity '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => ModuleCode::ROSTER])->save();
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

/** @param array<string, mixed> $attributes */
function rppEmployee(array $attributes): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill($attributes + ['employment_status' => 'Regular', 'is_active' => true, 'date_hired' => '2024-01-01'])->save();

    return $employee;
}

/** @param array<string, mixed> $overrides */
function rppPattern(string $name, array $overrides = []): ShiftType
{
    $pattern = new ShiftType;
    $pattern->forceFill($overrides + [
        'code' => 'P-'.Str::random(5), 'name' => $name, 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '09:00:00', 'end_time' => '18:00:00', 'grace_minutes' => 10, 'required_hours' => null,
        'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60, 'requires_break_punch' => false,
    ])->save();

    return $pattern;
}

function rppAssign(EmployeeProfile $employee, ShiftType $pattern): void
{
    Shift::query()->create(['employee_id' => $employee->id] + ShiftPolicy::attributesFromPattern($pattern));
}

function rppWeekday(EmployeeProfile $employee, int $weekday, ShiftType $pattern): void
{
    RosterShift::query()->create(['employee_id' => $employee->id, 'weekday' => $weekday, 'shift_type_id' => $pattern->id, 'shift_name' => $pattern->name, 'is_rest_day' => false,
        'shift_data' => DomainShift::fromArray(array_replace(ShiftPolicy::attributesFromPattern($pattern), ['work_days' => [$weekday]]))->toArray()]);
}

/*
|--------------------------------------------------------------------------
| Only the page is read
|--------------------------------------------------------------------------
*/

it('reads the employees, schedules, places and leave of the page alone', function () {
    test()->travelTo(new DateTimeImmutable('2026-11-09T09:00:00+08:00'));
    $pattern = rppPattern('Day');
    foreach (range(1, 25) as $n) {
        $employee = rppEmployee(['employee_code' => (string) (100 + $n), 'first_name' => 'Person', 'last_name' => sprintf('Staff %02d', $n)]);
        rppAssign($employee, $pattern);
        rppWeekday($employee, 3, $pattern);
    }
    rppActor();

    DB::enableQueryLog();
    $page = Livewire::test(RosterPage::class);
    $sql = collect(DB::getQueryLog())->map(static fn (array $entry): string => mb_strtolower(str_replace('`', '"', (string) $entry['query'])));
    DB::disableQueryLog();

    expect($page->viewData('rows')->total())->toBe(25)
        ->and($page->viewData('rows')->items())->toHaveCount(20);

    // One LIMITed read of the directory, and no read of every employee.
    expect($sql->contains(static fn (string $q): bool => str_contains($q, 'from "employee_profiles"') && str_contains($q, 'limit 20')))->toBeTrue()
        ->and($sql->contains(static fn (string $q): bool => str_contains($q, 'from "employee_profiles"') && ! str_contains($q, 'limit') && ! str_contains($q, 'count(*)') && ! str_contains($q, 'distinct') && ! str_contains($q, 'in (')))->toBeFalse();

    // Every read of the schedules and the leave names at most the page's 20
    // employees — never the 25 the roster holds.
    $timekeeping = $sql->filter(static fn (string $q): bool => preg_match('/from "(shifts|roster_shifts|roster_locations|roster_day_overrides|roster_schedule_changes|leave_travel_requests)"/', $q) === 1);
    expect($timekeeping)->not->toBeEmpty();
    foreach ($timekeeping as $q) {
        preg_match('/"employee_id" in \(([^)]*)\)/', $q, $list);
        expect($list)->not->toBeEmpty($q)
            ->and(substr_count($list[1] ?? '', '?'))->toBeLessThanOrEqual(20, $q);
    }
});

/*
|--------------------------------------------------------------------------
| A dialog body is drawn only while it holds a draft
|--------------------------------------------------------------------------
*/

it('draws the Default schedule body only while the dialog holds a draft', function () {
    test()->travelTo(new DateTimeImmutable('2026-11-09T05:00:00+08:00'));
    $employee = rppEmployee(['employee_code' => '1', 'first_name' => 'Ana', 'last_name' => 'Garcia']);
    rppAssign($employee, rppPattern('Day'));
    rppActor();
    $body = 'id="default-schedule-heading"';

    // Neither the week table nor the month carries the dialog's body.
    expect(Livewire::test(RosterPage::class)->html())->not->toContain($body);
    $page = Livewire::test(EmployeeSchedulePage::class, ['employee' => $employee->id]);
    expect($page->html())->not->toContain($body)
        ->and($page->html())->not->toContain('id="shift-day-1"');

    $page->call('openDefaultSchedule');
    expect($page->html())->toContain($body)
        ->and($page->html())->toContain('id="shift-day-1"')
        ->and($page->html())->toContain('id="loc-day-1"');
});
