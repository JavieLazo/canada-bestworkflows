<?php

declare(strict_types=1);

use App\Livewire\Roster\RosterPage;
use App\Livewire\Roster\ShiftPolicy;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Roster module: /roster gates on the ROSTER grant, the week table's
 * scoped reads return NOTHING for an employee-less actor and only the own
 * row to a holder without all_data, and assigning a default shift
 * (`AssignShift`) copies the pattern onto a dated shift row behind the
 * all_data + ROSTER gate — a pattern it cannot find, or the one already
 * stored, never reaches a write.
 *
 * A FLEXIBLE shift reads as its daily hours — in the week table's day cells
 * and the audit sentence — never the filler 08:00/17:00 clock the `shifts`
 * row stores by construction.
 */

/**
 * @param  list<string>  $modules  ModuleCode strings, one RoleModuleGrant each
 */
function rosterRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Roster '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/**
 * @param  list<string>  $modules  ModuleCode strings, one RoleModuleGrant each
 */
function rosterActor(array $modules, bool $allData, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => rosterRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function rosterEmployee(string $code, string $first, string $last, ?string $position = null): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => $first,
        'last_name' => $last,
        'position' => $position,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/**
 * @param  array<string, string|int|bool|list<int>|null>  $overrides  ShiftType attributes merged over the FIXED day-shift defaults
 */
function rosterPattern(array $overrides = []): ShiftType
{
    $pattern = new ShiftType;
    $pattern->forceFill(array_merge([
        'code' => 'DAY-'.Str::random(4),
        'name' => 'Day Shift '.Str::random(4),
        'mode' => 'FIXED',
        'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'grace_minutes' => 10,
        'required_hours' => null,
        'break_start' => '12:00:00',
        'break_end' => '13:00:00',
        'break_minutes' => 60,
        'requires_break_punch' => true,
    ], $overrides))->save();

    return $pattern;
}

/** A FLEXIBLE pattern of `$hours` a day, with no clock and no break. */
function rosterFlexible(int $hours): ShiftType
{
    return rosterPattern(['mode' => 'FLEXIBLE', 'start_time' => null, 'end_time' => null, 'required_hours' => $hours, 'grace_minutes' => 0, 'break_start' => null, 'break_end' => null, 'break_minutes' => 0, 'requires_break_punch' => false]);
}

beforeEach(function () {
    // Wednesday 2026-09-30, before any shift here starts: an assignment
    // applies today instead of being moved to tomorrow for review.
    $this->travelTo(new DateTimeImmutable('2026-09-30T06:00:00+08:00'));
});

/*
|--------------------------------------------------------------------------
| Route gating
|--------------------------------------------------------------------------
*/

it('redirects guests to login', function () {
    $this->get('/roster')->assertRedirect(route('login'));
});

it('turns away a signed-in user without the ROSTER grant', function () {
    rosterActor(['LEAVES'], false);

    // EnsureModuleAccess redirects a browser to whatever it may see.
    $this->get('/roster')->assertRedirect('/leaves');
});

it('renders for a ROSTER holder', function () {
    $employee = rosterEmployee('COP-00001', 'Liza', 'Domingo', 'Accountant');
    rosterActor(['ROSTER'], true, (string) $employee->id);

    $this->get('/roster')
        ->assertOk()
        ->assertSee('Shift Roster')
        ->assertSee('Liza Domingo')
        ->assertDontSee('Manage patterns');
});

/*
|--------------------------------------------------------------------------
| Scoping — the employee-less actor sees NOTHING
|--------------------------------------------------------------------------
*/

it('shows zero rows to a ROSTER holder with no employee link and no all_data', function () {
    $employee = rosterEmployee('COP-00042', 'Miguel', 'Reyes', 'Engineer');
    $pattern = rosterPattern();
    Shift::query()->create(['employee_id' => $employee->id] + ShiftPolicy::attributesFromPattern($pattern));

    rosterActor(['ROSTER'], false, null);

    Livewire::test(RosterPage::class)
        ->assertDontSee('Miguel')
        ->assertDontSee('Reyes')
        ->assertSee('No employee matches those filters.');

    // The scoped shift read itself is empty — not IS NULL rows.
    $access = Access::forActor(auth()->user());
    expect(Shift::query()->toEmployee($access, 'employee_id', 'ROSTER', 'EDTR', 'PAYROLL')->get())->toBeEmpty();
});

it('shows a non-all_data ROSTER holder only their own row', function () {
    $own = rosterEmployee('COP-00007', 'Ana', 'Santos', 'Clerk');
    $other = rosterEmployee('COP-00008', 'Ben', 'Torres', 'Clerk');

    rosterActor(['ROSTER'], false, (string) $own->id);

    // The table renders no employee code: the rows are told apart by name.
    Livewire::test(RosterPage::class)
        ->assertSee('Ana Santos')
        ->assertSeeHtml('wire:key="row-'.$own->id.'"')
        ->assertDontSee('Ben Torres')
        ->assertDontSeeHtml('wire:key="row-'.$other->id.'"')
        ->assertDontSee('COP-00007');
});

/*
|--------------------------------------------------------------------------
| Assigning a default shift — the command
|--------------------------------------------------------------------------
*/

it('assigns a pattern by copying its fields onto a dated shift row, and audits it', function () {
    $employee = rosterEmployee('COP-00023', 'Carla', 'Lim', 'Analyst');
    $pattern = rosterPattern();
    rosterActor(['ROSTER'], true);

    expect(app(AssignShift::class)->handle(app(Access::class), (string) $employee->id, (string) $pattern->id))
        ->toBe("{$pattern->name} assigned to Carla Lim.");

    $shift = Shift::query()->where('employee_id', $employee->id)->sole();

    expect($shift->shift_type_id)->toBe($pattern->id)
        ->and($shift->effective_from?->format('Y-m-d'))->toBe('2026-09-30')
        ->and($shift->mode)->toBe('FIXED')
        ->and($shift->start_time)->toBe('09:00:00')
        ->and($shift->end_time)->toBe('18:00:00')
        ->and($shift->grace_minutes)->toBe(10)
        ->and($shift->break_start)->toBe('12:00:00')
        ->and($shift->break_end)->toBe('13:00:00')
        ->and($shift->break_minutes)->toBe(60)
        ->and($shift->requires_break_punch)->toBeTrue()
        ->and($shift->work_days)->toBe([1, 2, 3, 4, 5]);

    // The server-side audit row, actor from the session.
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'SHIFT_ASSIGNED',
        'entity' => 'shifts',
        'entity_id' => (string) $employee->id,
    ]);
});

it('adds a dated row per assignment and reads the latest one as today\'s shift', function () {
    $employee = rosterEmployee('COP-00011', 'Dina', 'Cruz', 'Analyst');
    $first = rosterPattern();
    $second = rosterFlexible(8);
    rosterActor(['ROSTER'], true);
    $access = app(Access::class);

    app(AssignShift::class)->handle($access, (string) $employee->id, (string) $first->id);
    app(AssignShift::class)->handle($access, (string) $employee->id, (string) $second->id);

    // Each assignment is kept as history (both from today, the later one
    // winning); the roster reads the one in effect today.
    $shifts = Shift::query()->where('employee_id', $employee->id)->orderBy('id')->get();
    $today = app(ReferenceData::class)->shift($access, (string) $employee->id);

    expect($shifts)->toHaveCount(2)
        ->and($shifts->first()->shift_type_id)->toBe($first->id)
        ->and($today?->shift_type_id)->toBe($second->id)
        ->and($today?->mode)->toBe('FLEXIBLE')
        // A FLEXIBLE pattern carries no clock times; the default working day
        // stands in (attendance.ts:87-102).
        ->and($today?->start_time)->toBe('08:00:00')
        ->and($today?->end_time)->toBe('17:00:00');
});

it('refuses an unknown pattern id, writing and auditing nothing', function () {
    $employee = rosterEmployee('COP-00018', 'Kiko', 'Salazar', 'Clerk');
    rosterActor(['ROSTER'], true);

    expect(fn () => app(AssignShift::class)->handle(app(Access::class), (string) $employee->id, '01HZZZZZZZZZZZZZZZZZZZZZZZ'))
        ->toThrow(RuntimeException::class, 'That shift pattern no longer exists.');

    expect(Shift::query()->count())->toBe(0);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_ASSIGNED']);
});

it('writes and audits nothing when the pattern the employee already works is assigned again', function () {
    $employee = rosterEmployee('COP-00020', 'Mara', 'Villanueva', 'Clerk');
    $pattern = rosterPattern();
    Shift::query()->create(['employee_id' => $employee->id] + ShiftPolicy::attributesFromPattern($pattern));
    rosterActor(['ROSTER'], true);

    expect(app(AssignShift::class)->handle(app(Access::class), (string) $employee->id, (string) $pattern->id))
        ->toBe("No changes: Mara Villanueva is already on {$pattern->name}.");

    $shifts = Shift::query()->where('employee_id', $employee->id)->get();
    expect($shifts)->toHaveCount(1)
        ->and($shifts->first()->shift_type_id)->toBe($pattern->id)
        ->and($shifts->first()->start_time)->toBe('09:00:00')
        ->and($shifts->first()->end_time)->toBe('18:00:00');
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_ASSIGNED']);
});

it('refuses the write without all_data, even for a ROSTER holder on their own employee', function () {
    $employee = rosterEmployee('COP-00012', 'Elio', 'Vega', 'Clerk');
    $pattern = rosterPattern();
    rosterActor(['ROSTER'], false, (string) $employee->id);
    $before = Shift::query()->count();

    expect(fn () => app(AssignShift::class)->handle(app(Access::class), (string) $employee->id, (string) $pattern->id))
        ->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');

    expect(Shift::query()->count())->toBe($before)
        ->and(Shift::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_ASSIGNED']);
});

/*
|--------------------------------------------------------------------------
| Flexible shifts read as hours, never the filler clock
|--------------------------------------------------------------------------
*/

it('shows a flexible assignment as hours per day in the week table, never the filler clock', function () {
    $employee = rosterEmployee('COP-00026', 'Sela', 'Dizon', 'Clerk');
    $flexible = rosterFlexible(6);
    Shift::query()->create(['employee_id' => $employee->id] + ShiftPolicy::attributesFromPattern($flexible));

    rosterActor(['ROSTER'], true);

    // The stored row carries the filler 08:00/17:00 clock by construction;
    // it may not reach a day cell. Wednesday the 30th works.
    $html = Livewire::test(RosterPage::class)
        ->assertSee('Flexible · 6 h per day')
        ->assertDontSee('8:00 AM–5:00 PM')
        ->html();
    expect(Str::before(Str::after($html, 'data-day="2026-09-30"'), '</td>'))
        ->toContain($flexible->name)
        ->toContain('Flexible · 6 h per day');
});

it('records a flexible assignment by its daily hours and a fixed one by its raw clock in the audit trail', function () {
    $flexibleEmployee = rosterEmployee('COP-00031', 'Dina', 'Cruz', 'Analyst');
    $fixedEmployee = rosterEmployee('COP-00032', 'Dino', 'Cruz', 'Analyst');
    $flexible = rosterFlexible(8);
    $fixed = rosterPattern();
    rosterActor(['ROSTER'], true);

    app(AssignShift::class)->handle(app(Access::class), (string) $flexibleEmployee->id, (string) $flexible->id);
    app(AssignShift::class)->handle(app(Access::class), (string) $fixedEmployee->id, (string) $fixed->id);

    // The sentence roster-page.tsx:147-155 wrote, not the filler 08:00–17:00.
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'SHIFT_ASSIGNED',
        'entity_id' => (string) $flexibleEmployee->id,
        'details' => "Dina Cruz assigned {$flexible->name} (8 h per day)",
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'SHIFT_ASSIGNED',
        'entity_id' => (string) $fixedEmployee->id,
        'details' => "Dino Cruz assigned 09:00:00\u{2013}18:00:00",
    ]);
});
