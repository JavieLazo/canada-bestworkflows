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
use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Roster module: /roster gates on the ROSTER grant, scoped reads return
 * NOTHING for an employee-less actor, and the assignment flow copies the
 * pattern onto the employee's one shift row behind the all_data + ROSTER gate.
 *
 * The row menu only stages a pick (`pickPattern()`, after re-resolving both
 * ids); `confirmAssign()` is the one row-level write, and a pick the actor may
 * not see, cannot find, or that matches the stored pattern never reaches it.
 * A FLEXIBLE row reads as its daily hours — in the Hours cell, both dialogs
 * and the audit sentence — never the filler 08:00/17:00 clock the `shifts`
 * row stores by construction, and its Grace cell reads "—", not "0 min".
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
        ->assertDontSee('COP-00042')
        ->assertDontSee('Miguel');

    // The scoped shift read itself is empty — not IS NULL rows.
    $access = Access::forActor(auth()->user());
    expect(Shift::query()->toEmployee($access, 'employee_id', 'ROSTER', 'EDTR', 'PAYROLL')->get())->toBeEmpty();
});

it('shows a non-all_data ROSTER holder only their own row', function () {
    $own = rosterEmployee('COP-00007', 'Ana', 'Santos', 'Clerk');
    rosterEmployee('COP-00008', 'Ben', 'Torres', 'Clerk');

    rosterActor(['ROSTER'], false, (string) $own->id);

    Livewire::test(RosterPage::class)
        ->assertSee('COP-00007')
        ->assertDontSee('COP-00008');
});

/*
|--------------------------------------------------------------------------
| Row menu — a pick stages, the confirm dialog writes
|--------------------------------------------------------------------------
*/

it('stages a row pick for a visible employee and a real pattern, then asks', function () {
    $employee = rosterEmployee('COP-00010', 'Carla', 'Lim', 'Analyst');
    $pattern = rosterPattern();

    rosterActor(['ROSTER'], true);

    Livewire::test(RosterPage::class)
        ->call('pickPattern', (string) $employee->id, (string) $pattern->id)
        ->assertSet('confirmEmployeeId', (string) $employee->id)
        ->assertSet('confirmPatternId', (string) $pattern->id)
        ->assertDispatched('modal-show', name: 'confirm-assign');

    // Staging writes nothing.
    expect(Shift::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_ASSIGNED']);
});

it('drops a pick for an employee outside the actor visible directory', function () {
    $own = rosterEmployee('COP-00016', 'Ivan', 'Bautista', 'Clerk');
    $other = rosterEmployee('COP-00017', 'Jena', 'Ocampo', 'Clerk');
    $pattern = rosterPattern();

    // Non-all_data: only the own row is visible, so the other id is dropped
    // on the floor — and a confirm afterwards has nothing to write.
    rosterActor(['ROSTER'], false, (string) $own->id);

    Livewire::test(RosterPage::class)
        ->call('pickPattern', (string) $other->id, (string) $pattern->id)
        ->assertSet('confirmEmployeeId', '')
        ->assertSet('confirmPatternId', '')
        ->assertNotDispatched('modal-show')
        ->call('confirmAssign');

    expect(Shift::query()->count())->toBe(0);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_ASSIGNED']);
});

it('drops a pick of an unknown pattern id', function () {
    $employee = rosterEmployee('COP-00018', 'Kiko', 'Salazar', 'Clerk');

    rosterActor(['ROSTER'], true);

    Livewire::test(RosterPage::class)
        ->call('pickPattern', (string) $employee->id, '01HZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertSet('confirmEmployeeId', '')
        ->assertSet('confirmPatternId', '')
        ->assertNotDispatched('modal-show')
        ->call('confirmAssign');

    expect(Shift::query()->count())->toBe(0);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_ASSIGNED']);
});

it('drops a pick from an actor with no employee link', function () {
    $employee = rosterEmployee('COP-00019', 'Lara', 'Mendoza', 'Clerk');
    $pattern = rosterPattern();

    // No all_data and no employee link: the directory lookup finds NOTHING,
    // never every row.
    rosterActor(['ROSTER'], false, null);

    Livewire::test(RosterPage::class)
        ->call('pickPattern', (string) $employee->id, (string) $pattern->id)
        ->assertSet('confirmEmployeeId', '')
        ->assertSet('confirmPatternId', '')
        ->assertNotDispatched('modal-show')
        ->call('confirmAssign');

    expect(Shift::query()->count())->toBe(0);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_ASSIGNED']);
});

it('ignores a pick of the pattern the row already stores', function () {
    $employee = rosterEmployee('COP-00020', 'Mara', 'Villanueva', 'Clerk');
    $pattern = rosterPattern();
    Shift::query()->create(['employee_id' => $employee->id] + ShiftPolicy::attributesFromPattern($pattern));

    rosterActor(['ROSTER'], true);

    Livewire::test(RosterPage::class)
        ->call('pickPattern', (string) $employee->id, (string) $pattern->id)
        ->assertSet('confirmEmployeeId', '')
        ->assertSet('confirmPatternId', '')
        ->assertNotDispatched('modal-show')
        ->call('confirmAssign');

    $shifts = Shift::query()->where('employee_id', $employee->id)->get();

    expect($shifts)->toHaveCount(1)
        ->and($shifts->first()->shift_type_id)->toBe($pattern->id)
        ->and($shifts->first()->start_time)->toBe('09:00:00')
        ->and($shifts->first()->end_time)->toBe('18:00:00');
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_ASSIGNED']);
});

it('marks the stored pattern in the row menu and gives it no click', function () {
    $employee = rosterEmployee('COP-00021', 'Nilo', 'Quinto', 'Clerk');
    $current = rosterPattern(['name' => 'Day Shift Current']);
    $other = rosterPattern(['name' => 'Day Shift Other']);
    Shift::query()->create(['employee_id' => $employee->id] + ShiftPolicy::attributesFromPattern($current));

    rosterActor(['ROSTER'], true);

    Livewire::test(RosterPage::class)
        ->assertSeeHtml("pickPattern('{$employee->id}', '{$other->id}')")
        ->assertDontSeeHtml("pickPattern('{$employee->id}', '{$current->id}')")
        ->assertSeeHtml('aria-label="Nilo Quinto shift pattern: Day Shift Current"');
});

it('does nothing on confirm without a staged pick', function () {
    rosterEmployee('COP-00022', 'Ofel', 'Reyes', 'Clerk');
    rosterPattern();

    rosterActor(['ROSTER'], true);

    Livewire::test(RosterPage::class)
        ->call('confirmAssign')
        ->assertNotDispatched('modal-close');

    expect(Shift::query()->count())->toBe(0);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_ASSIGNED']);
});

it('assigns a pattern from the row menu once confirmed, copying its fields onto the one shift row', function () {
    $employee = rosterEmployee('COP-00023', 'Carla', 'Lim', 'Analyst');
    $pattern = rosterPattern();

    rosterActor(['ROSTER'], true);

    Livewire::test(RosterPage::class)
        ->call('pickPattern', (string) $employee->id, (string) $pattern->id)
        ->call('confirmAssign')
        ->assertSet('confirmEmployeeId', '')
        ->assertSet('confirmPatternId', '')
        ->assertDispatched('modal-close', name: 'confirm-assign');

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

it('adds a dated row per assignment and reads the latest one as today\'s shift', function () {
    $employee = rosterEmployee('COP-00011', 'Dina', 'Cruz', 'Analyst');
    $first = rosterPattern();
    $second = rosterPattern(['mode' => 'FLEXIBLE', 'start_time' => null, 'end_time' => null, 'required_hours' => 8, 'grace_minutes' => 0, 'break_start' => null, 'break_end' => null, 'break_minutes' => 0, 'requires_break_punch' => false]);

    rosterActor(['ROSTER'], true);

    Livewire::test(RosterPage::class)
        ->call('pickPattern', (string) $employee->id, (string) $first->id)
        ->call('confirmAssign')
        ->call('pickPattern', (string) $employee->id, (string) $second->id)
        ->call('confirmAssign');

    // Each assignment is kept as history (both from today, the later one
    // winning); the roster reads the one in effect today.
    $shifts = Shift::query()->where('employee_id', $employee->id)->orderBy('id')->get();
    $today = app(ReferenceData::class)->shift(Access::forActor(auth()->user()), (string) $employee->id);

    expect($shifts)->toHaveCount(2)
        ->and($shifts->first()->shift_type_id)->toBe($first->id)
        ->and($today?->shift_type_id)->toBe($second->id)
        ->and($today?->mode)->toBe('FLEXIBLE')
        // A FLEXIBLE pattern carries no clock times; the default working day
        // stands in (attendance.ts:87-102).
        ->and($today?->start_time)->toBe('08:00:00')
        ->and($today?->end_time)->toBe('17:00:00');
});

it('refuses the write without all_data, even for a ROSTER holder', function () {
    $employee = rosterEmployee('COP-00012', 'Elio', 'Vega', 'Clerk');
    $pattern = rosterPattern();

    rosterActor(['ROSTER'], false, (string) $employee->id);

    $before = Shift::query()->count();

    // The own row is visible, so the pick stages; the command refuses the write.
    Livewire::test(RosterPage::class)
        ->call('pickPattern', (string) $employee->id, (string) $pattern->id)
        ->assertSet('confirmEmployeeId', (string) $employee->id)
        ->call('confirmAssign')
        ->assertSet('confirmEmployeeId', '')
        ->assertSet('confirmPatternId', '');

    expect(Shift::query()->count())->toBe($before)
        ->and(Shift::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_ASSIGNED']);
});

it('shows the replace warning in the confirm dialog', function () {
    $employee = rosterEmployee('COP-00024', 'Pia', 'Aquino', 'Clerk');
    $current = rosterPattern();
    $other = rosterPattern();
    Shift::query()->create(['employee_id' => $employee->id] + ShiftPolicy::attributesFromPattern($current));

    rosterActor(['ROSTER'], true);

    // The modal renders after the table, so the order proves the dialog and
    // not the Hours cell, which prints the same clock.
    Livewire::test(RosterPage::class)
        ->call('pickPattern', (string) $employee->id, (string) $other->id)
        ->assertSeeHtmlInOrder(['They currently work', '9:00 AM–6:00 PM', 'Saving changes it from the effective date.']);
});

it('shows a flexible stored row as hours per day in the confirm dialog', function () {
    $employee = rosterEmployee('COP-00025', 'Rina', 'Castro', 'Clerk');
    $current = rosterPattern(['mode' => 'FLEXIBLE', 'start_time' => null, 'end_time' => null, 'required_hours' => 8, 'grace_minutes' => 0, 'break_start' => null, 'break_end' => null, 'break_minutes' => 0, 'requires_break_punch' => false]);
    $other = rosterPattern();
    Shift::query()->create(['employee_id' => $employee->id] + ShiftPolicy::attributesFromPattern($current));

    rosterActor(['ROSTER'], true);

    Livewire::test(RosterPage::class)
        ->call('pickPattern', (string) $employee->id, (string) $other->id)
        ->assertSeeHtmlInOrder(['They currently work', 'Flexible · 8 h per day', 'Saving changes it from the effective date.']);
});

/*
|--------------------------------------------------------------------------
| The Assign shift dialog
|--------------------------------------------------------------------------
*/

it('assigns through the dialog and resets it afterwards', function () {
    $employee = rosterEmployee('COP-00013', 'Fara', 'Uy', 'Clerk');
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

it('takes the dialog employee from the searchable picker event, for this picker only and for active employees only', function () {
    $employee = rosterEmployee('COP-00031', 'Ines', 'Salazar', 'Clerk');
    $gone = rosterEmployee('COP-00032', 'Jon', 'Tan', 'Clerk');
    $gone->forceFill(['is_active' => false])->save();

    rosterActor(['ROSTER'], true);

    // The dialog renders the kit picker, not a plain select, limited to
    // active employees.
    $page = Livewire::test(RosterPage::class)
        ->call('openAssign')
        ->assertSeeHtml('wire:name="kit.employee-picker"')
        ->assertDontSeeHtml('placeholder="Select employee"');

    // The event is client-dispatchable: another picker's name is ignored, an
    // inactive employee is dropped, a visible active one is taken — and the
    // line under the field says they have no shift yet.
    $page->call('employeePicked', 'dtr-employee', (string) $employee->id)
        ->assertSet('dialogEmployeeId', '')
        ->call('employeePicked', 'assign-employee', (string) $gone->id)
        ->assertSet('dialogEmployeeId', '')
        ->call('employeePicked', 'assign-employee', (string) $employee->id)
        ->assertSet('dialogEmployeeId', (string) $employee->id)
        ->assertSee('Ines Salazar has no shift yet.');
});

it('drops a dialog employee pick outside the actor visible directory', function () {
    $own = rosterEmployee('COP-00014', 'Gina', 'Ramos', 'Clerk');
    $other = rosterEmployee('COP-00015', 'Hugo', 'Perez', 'Clerk');

    // Non-all_data: only the own row is visible, so picking the other id is
    // dropped on the floor, not selected.
    rosterActor(['ROSTER'], false, (string) $own->id);

    Livewire::test(RosterPage::class)
        ->call('dialogEmployeeChanged', (string) $other->id)
        ->assertSet('dialogEmployeeId', '');
});

/*
|--------------------------------------------------------------------------
| Flexible rows read as hours, never the filler clock
|--------------------------------------------------------------------------
*/

it('shows a flexible assignment as hours per day, never the filler clock', function () {
    $employee = rosterEmployee('COP-00026', 'Sela', 'Dizon', 'Clerk');
    // break_minutes 0 so a "60 min" break cannot mask a leaked "0 min" grace.
    $flexible = rosterPattern(['mode' => 'FLEXIBLE', 'start_time' => null, 'end_time' => null, 'required_hours' => 6, 'grace_minutes' => 0, 'break_start' => null, 'break_end' => null, 'break_minutes' => 0, 'requires_break_punch' => false]);
    Shift::query()->create(['employee_id' => $employee->id] + ShiftPolicy::attributesFromPattern($flexible));

    rosterActor(['ROSTER'], true);

    // The stored row carries the filler 08:00/17:00 clock and grace 0 by
    // construction; neither may reach the Hours or Grace cell.
    Livewire::test(RosterPage::class)
        ->assertSee('Flexible · 6 h per day')
        ->assertDontSee('8:00 AM–5:00 PM')
        ->assertDontSee('0 min')
        // The Assign dialog's "already works …" line reads the same way.
        ->call('dialogEmployeeChanged', (string) $employee->id)
        ->assertSeeHtmlInOrder(['already works', 'Flexible · 6 h per day', 'Saving changes it from the effective date']);
});

it('sorts flexible rows after fixed rows on the Hours column', function () {
    $fixedLate = rosterEmployee('COP-00027', 'Tomas', 'Ilagan', 'Clerk');
    $flexible = rosterEmployee('COP-00028', 'Uma', 'Fajardo', 'Clerk');
    $fixedEarly = rosterEmployee('COP-00029', 'Vero', 'Galang', 'Clerk');
    $none = rosterEmployee('COP-00030', 'Wena', 'Hidalgo', 'Clerk');

    // The flexible row stores the filler 08:00 clock, which a start_time
    // compare would slot BETWEEN the 7:00 and 9:00 rows.
    Shift::query()->create(['employee_id' => $fixedLate->id] + ShiftPolicy::attributesFromPattern(rosterPattern()));
    Shift::query()->create(['employee_id' => $flexible->id] + ShiftPolicy::attributesFromPattern(rosterPattern(['mode' => 'FLEXIBLE', 'start_time' => null, 'end_time' => null, 'required_hours' => 8, 'grace_minutes' => 0, 'break_start' => null, 'break_end' => null, 'break_minutes' => 0, 'requires_break_punch' => false])));
    Shift::query()->create(['employee_id' => $fixedEarly->id] + ShiftPolicy::attributesFromPattern(rosterPattern(['start_time' => '07:00:00', 'end_time' => '16:00:00'])));

    rosterActor(['ROSTER'], true);

    // The row keys, not the names: the Assign dialog lists every name again
    // after the table, in directory order, which could mask a wrong sort.
    $rowKeys = static fn (EmployeeProfile ...$employees): array => array_map(
        static fn (EmployeeProfile $e): string => "row-{$e->id}",
        $employees,
    );

    Livewire::test(RosterPage::class)
        ->call('sortBy', 'shift')
        ->assertSet('sortKey', 'shift')
        ->assertSet('sortDir', 'asc')
        ->assertSeeHtmlInOrder($rowKeys($fixedEarly, $fixedLate, $flexible, $none))
        // Descending is still the whole list reversed (roster-page.tsx:127).
        ->call('sortBy', 'shift')
        ->assertSet('sortDir', 'desc')
        ->assertSeeHtmlInOrder($rowKeys($none, $flexible, $fixedLate, $fixedEarly));
});

it('records a flexible assignment by its daily hours in the audit trail', function () {
    $employee = rosterEmployee('COP-00031', 'Dina', 'Cruz', 'Analyst');
    $flexible = rosterPattern(['mode' => 'FLEXIBLE', 'start_time' => null, 'end_time' => null, 'required_hours' => 8, 'grace_minutes' => 0, 'break_start' => null, 'break_end' => null, 'break_minutes' => 0, 'requires_break_punch' => false]);

    rosterActor(['ROSTER'], true);

    Livewire::test(RosterPage::class)
        ->call('pickPattern', (string) $employee->id, (string) $flexible->id)
        ->call('confirmAssign');

    // The sentence roster-page.tsx:147-155 wrote, not the filler 08:00–17:00.
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'SHIFT_ASSIGNED',
        'entity_id' => (string) $employee->id,
        'details' => "Dina Cruz assigned {$flexible->name} (8 h per day)",
    ]);
});

it('records a fixed assignment by its raw clock in the audit trail', function () {
    $employee = rosterEmployee('COP-00032', 'Dina', 'Cruz', 'Analyst');
    $fixed = rosterPattern();

    rosterActor(['ROSTER'], true);

    Livewire::test(RosterPage::class)
        ->call('pickPattern', (string) $employee->id, (string) $fixed->id)
        ->call('confirmAssign');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'SHIFT_ASSIGNED',
        'entity_id' => (string) $employee->id,
        'details' => "Dina Cruz assigned 09:00:00\u{2013}18:00:00",
    ]);
});
