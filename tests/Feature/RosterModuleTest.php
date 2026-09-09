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
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Roster module: /roster gates on the ROSTER grant, scoped reads return
 * NOTHING for an employee-less actor, and the assignment flow copies the
 * pattern onto the employee's one shift row behind the all_data + ROSTER gate.
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

function rosterActor(array $modules, bool $allData, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => rosterRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);

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
    $employee = rosterEmployee('BWF-00001', 'Liza', 'Domingo', 'Accountant');
    rosterActor(['ROSTER'], true, (string) $employee->id);

    $this->get('/roster')
        ->assertOk()
        ->assertSee('Shift Roster')
        ->assertSee('Manage patterns');
});

/*
|--------------------------------------------------------------------------
| Scoping — the employee-less actor sees NOTHING
|--------------------------------------------------------------------------
*/

it('shows zero rows to a ROSTER holder with no employee link and no all_data', function () {
    $employee = rosterEmployee('BWF-00042', 'Miguel', 'Reyes', 'Engineer');
    $pattern = rosterPattern();
    Shift::query()->create(['employee_id' => $employee->id] + ShiftPolicy::attributesFromPattern($pattern));

    rosterActor(['ROSTER'], false, null);

    Livewire::test(RosterPage::class)
        ->assertDontSee('BWF-00042')
        ->assertDontSee('Miguel');

    // The scoped shift read itself is empty — not IS NULL rows.
    $access = Access::forActor(auth()->user());
    expect(Shift::query()->toEmployee($access, 'employee_id', 'ROSTER', 'EDTR', 'PAYROLL')->get())->toBeEmpty();
});

it('shows a non-all_data ROSTER holder only their own row', function () {
    $own = rosterEmployee('BWF-00007', 'Ana', 'Santos', 'Clerk');
    rosterEmployee('BWF-00008', 'Ben', 'Torres', 'Clerk');

    rosterActor(['ROSTER'], false, (string) $own->id);

    Livewire::test(RosterPage::class)
        ->assertSee('BWF-00007')
        ->assertDontSee('BWF-00008');
});

/*
|--------------------------------------------------------------------------
| Assignment — the happy path and its gate
|--------------------------------------------------------------------------
*/

it('assigns a pattern inline, copying its fields onto the one shift row', function () {
    $employee = rosterEmployee('BWF-00010', 'Carla', 'Lim', 'Analyst');
    $pattern = rosterPattern();

    rosterActor(['ROSTER'], true);

    Livewire::test(RosterPage::class)
        ->call('assign', (string) $employee->id, (string) $pattern->id);

    $shift = Shift::query()->where('employee_id', $employee->id)->first();

    expect($shift)->not->toBeNull()
        ->and($shift->shift_type_id)->toBe($pattern->id)
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

it('replaces rather than stacks: one shift row per employee', function () {
    $employee = rosterEmployee('BWF-00011', 'Dina', 'Cruz', 'Analyst');
    $first = rosterPattern();
    $second = rosterPattern(['mode' => 'FLEXIBLE', 'start_time' => null, 'end_time' => null, 'required_hours' => 8, 'grace_minutes' => 0, 'break_start' => null, 'break_end' => null, 'break_minutes' => 0, 'requires_break_punch' => false]);

    rosterActor(['ROSTER'], true);

    Livewire::test(RosterPage::class)
        ->call('assign', (string) $employee->id, (string) $first->id)
        ->call('assign', (string) $employee->id, (string) $second->id);

    $shifts = Shift::query()->where('employee_id', $employee->id)->get();

    expect($shifts)->toHaveCount(1)
        ->and($shifts->first()->shift_type_id)->toBe($second->id)
        ->and($shifts->first()->mode)->toBe('FLEXIBLE')
        // A FLEXIBLE pattern carries no clock times; the default working day
        // stands in (attendance.ts:87-102).
        ->and($shifts->first()->start_time)->toBe('08:00:00')
        ->and($shifts->first()->end_time)->toBe('17:00:00');
});

it('refuses the write without all_data, even for a ROSTER holder', function () {
    $employee = rosterEmployee('BWF-00012', 'Elio', 'Vega', 'Clerk');
    $pattern = rosterPattern();

    rosterActor(['ROSTER'], false, (string) $employee->id);

    Livewire::test(RosterPage::class)
        ->call('assign', (string) $employee->id, (string) $pattern->id);

    expect(Shift::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_ASSIGNED']);
});

it('assigns through the dialog and resets it afterwards', function () {
    $employee = rosterEmployee('BWF-00013', 'Fara', 'Uy', 'Clerk');
    $pattern = rosterPattern();

    rosterActor(['ROSTER'], true);

    Livewire::test(RosterPage::class)
        ->call('openAssign')
        ->assertSet('dialogEmployeeId', '')
        ->assertSet('dialogPatternId', '')
        ->call('dialogEmployeeChanged', (string) $employee->id)
        ->assertSet('dialogEmployeeId', (string) $employee->id)
        ->call('dialogPatternChanged', (string) $pattern->id)
        ->assertSet('dialogPatternId', (string) $pattern->id)
        ->call('dialogSave')
        ->assertSet('dialogEmployeeId', '')
        ->assertSet('dialogPatternId', '');

    expect(Shift::query()->where('employee_id', $employee->id)->exists())->toBeTrue();
});

it('drops a dialog employee pick outside the actor visible directory', function () {
    $own = rosterEmployee('BWF-00014', 'Gina', 'Ramos', 'Clerk');
    $other = rosterEmployee('BWF-00015', 'Hugo', 'Perez', 'Clerk');

    // Non-all_data: only the own row is visible, so picking the other id is
    // dropped on the floor, not selected.
    rosterActor(['ROSTER'], false, (string) $own->id);

    Livewire::test(RosterPage::class)
        ->call('dialogEmployeeChanged', (string) $other->id)
        ->assertSet('dialogEmployeeId', '');
});
