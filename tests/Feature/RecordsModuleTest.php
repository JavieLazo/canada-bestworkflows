<?php

declare(strict_types=1);

use App\Livewire\Records\HolidaysPage;
use App\Livewire\Records\PositionsPage;
use App\Livewire\Records\ReferenceListPage;
use App\Livewire\Records\ShiftTypesPage;
use App\Livewire\Records\SitesPage;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterScheduleChange;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Timekeeping\Application\Commands\SaveShiftType;
use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Modules\Timekeeping\Application\ShiftTypeDraft;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The Records module: /records/* gates on the RECORDS grant, the usage counts'
 * scoped reads return NOTHING for an employee-less actor, and the CRUD flows
 * sit behind the all_data + RECORDS gate exactly as requireAllDataModule did.
 *
 * Shift patterns are the exception on both counts: their "in use by N" is an
 * UNSCOPED count of linked roster rows, and editing an in-use pattern
 * re-copies it onto those rows inside one transaction, which additionally
 * needs all_data + ROSTER (the gate AssignShift applies to the same table).
 */

/**
 * @param  list<string>  $modules  ModuleCode strings granted to the role
 */
function recordsRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Records '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/**
 * @param  list<string>  $modules  ModuleCode strings granted to the actor's role
 */
function recordsActor(array $modules, bool $allData, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => recordsRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);

    return $user;
}

function recordsEmployee(string $code, string $first, string $last): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => $first,
        'last_name' => $last,
        'position' => 'Clerk',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/*
|--------------------------------------------------------------------------
| Route gating
|--------------------------------------------------------------------------
*/

it('redirects guests to login', function () {
    $this->get('/records/positions')->assertRedirect(route('login'));
});

it('turns away a signed-in user without the RECORDS grant', function () {
    recordsActor(['LEAVES'], false);

    // EnsureModuleAccess redirects a browser to whatever it may see.
    $this->get('/records/positions')->assertRedirect('/leaves');
    $this->get('/records/holidays')->assertRedirect('/leaves');
    $this->get('/records/sites')->assertRedirect('/leaves');
});

it('sends /records to the first list, exactly as records/page.tsx does', function () {
    recordsActor(['RECORDS'], true);

    $this->get('/records')->assertRedirect('/records/positions');
});

it('renders every screen for a RECORDS holder', function () {
    recordsActor(['RECORDS'], true);

    $this->get('/records/positions')->assertOk()->assertSee('Position');
    $this->get('/records/departments')->assertOk()->assertSee('Departments &amp; Units', false);
    $this->get('/records/leave-types')->assertOk()->assertSee('Leave Settings');
    $this->get('/records/holidays')->assertOk()->assertSee('Holiday Settings');
    $this->get('/records/shifts')->assertOk()->assertSee('Shifting Settings');
    $this->get('/records/sites')->assertOk()->assertSee('Site Settings');
    $this->get('/records/pay-rates')->assertOk()->assertSee('Pay Basis &amp; Rates', false);
});

it('404s a slug that is not a reference list', function () {
    recordsActor(['RECORDS'], true);

    $this->get('/records/not-a-list')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Scoping — the employee-less actor's scoped reads see NOTHING
|--------------------------------------------------------------------------
*/

it('counts zero usage for an employee-less actor without all_data', function () {
    $employee = recordsEmployee('COP-00021', 'Liza', 'Domingo');

    $request = new LeaveTravelRequest;
    $request->forceFill([
        'code' => 'REQ-001',
        'employee_id' => $employee->id,
        'request_type' => 'SICK',
        'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-04',
        'status' => 'PENDING',
    ])->save();

    recordsActor(['RECORDS'], false, null);

    // The scoped leave read behind the leave-types usage column is empty —
    // not IS NULL rows.
    $access = Access::forActor(auth()->user());
    expect(LeaveTravelRequest::query()->toEmployee($access, 'employee_id', 'LEAVES')->get())->toBeEmpty();

    // And the page renders without leaking a count.
    Livewire::test(ReferenceListPage::class, ['slug' => 'leave-types'])->assertStatus(200);
});

/*
|--------------------------------------------------------------------------
| Reference list CRUD — the happy path and its gate
|--------------------------------------------------------------------------
*/

it('adds, renames and deletes a position behind the all_data + RECORDS gate', function () {
    recordsActor(['RECORDS'], true);

    // Add — the next id is minted from the rows.
    Livewire::test(PositionsPage::class)
        ->call('openAdd')
        ->set('formName', 'Driver')
        ->set('formDescription', 'Company drivers')
        ->call('save')
        ->assertSet('dialogOpen', false);

    $row = Position::query()->where('name', 'Driver')->first();

    expect($row)->not->toBeNull()
        ->and($row->code)->toBe('1')
        ->and($row->description)->toBe('Company drivers');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'REFERENCE_CREATED',
        'entity' => 'positions',
        'entity_id' => (string) $row->getKey(),
    ]);

    // Rename.
    Livewire::test(PositionsPage::class)
        ->call('startEdit', (string) $row->getKey())
        ->set('formName', 'Company Driver')
        ->call('save');

    expect($row->fresh()->name)->toBe('Company Driver');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'REFERENCE_UPDATED',
        'entity' => 'positions',
        'entity_id' => (string) $row->getKey(),
    ]);

    // Delete.
    Livewire::test(PositionsPage::class)
        ->call('confirmDelete', (string) $row->getKey())
        ->call('deleteConfirmed');

    expect(Position::query()->whereKey($row->getKey())->exists())->toBeFalse();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'REFERENCE_DELETED',
        'entity' => 'positions',
        'entity_id' => (string) $row->getKey(),
    ]);
});

it('requires a name, inline', function () {
    recordsActor(['RECORDS'], true);

    Livewire::test(PositionsPage::class)
        ->call('openAdd')
        ->set('formName', '   ')
        ->call('save')
        ->assertSet('formError', 'Name is required.')
        ->assertSet('dialogOpen', true);

    expect(Position::query()->count())->toBe(0);
});

it('refuses a write from a RECORDS holder without all_data', function () {
    recordsActor(['RECORDS'], false);

    Livewire::test(PositionsPage::class)
        ->call('openAdd')
        ->set('formName', 'Driver')
        ->call('save');

    expect(Position::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Holidays, shift types, sites — one flow each
|--------------------------------------------------------------------------
*/

it('adds a holiday with a minted id and the dialog defaults', function () {
    recordsActor(['RECORDS'], true);

    Livewire::test(HolidaysPage::class)
        ->call('openAdd')
        ->set('formName', 'Rizal Day')
        ->set('formDate', '2026-12-30')
        ->call('save')
        ->assertSet('dialogOpen', false);

    $this->assertDatabaseHas('holidays', [
        'code' => '1',
        'name' => 'Rizal Day',
        'schedule' => 'ANNUAL',
        'type' => 'REGULAR',
    ]);

    $this->assertDatabaseHas('audit_logs', ['action' => 'HOLIDAY_CREATED', 'entity' => 'holidays']);
});

it('adds a fixed shift pattern and refuses a broken one', function () {
    recordsActor(['RECORDS'], true);

    // The dialog's defaults are a complete 08:00–17:00 pattern.
    Livewire::test(ShiftTypesPage::class)
        ->call('openAdd')
        ->set('formName', 'Day Shift')
        ->call('save')
        ->assertSet('dialogOpen', false);

    $this->assertDatabaseHas('shift_types', [
        'code' => '1',
        'name' => 'Day Shift',
        'mode' => 'FIXED',
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'grace_minutes' => 15,
        'break_start' => '12:00:00',
        'break_end' => '13:00:00',
        'break_minutes' => 60,
        'requires_break_punch' => 1,
    ]);

    // A flexible pattern owing zero hours trips the shape policy and stays
    // in the dialog.
    Livewire::test(ShiftTypesPage::class)
        ->call('openAdd')
        ->set('formName', 'Broken Flex')
        ->set('formMode', 'FLEXIBLE')
        ->set('formRequiredHours', '0')
        ->call('save')
        ->assertSet('dialogOpen', true);

    expect(ShiftType::query()->where('name', 'Broken Flex')->exists())->toBeFalse();
});

it('adds a site, validates its geofence, and blocks deleting a used one', function () {
    recordsActor(['RECORDS'], true);

    // 0, 0 is not a place.
    Livewire::test(SitesPage::class)
        ->call('openAdd')
        ->set('formName', 'Nowhere')
        ->set('formLatitude', '0')
        ->set('formLongitude', '0')
        ->call('save')
        ->assertSet('dialogOpen', true);

    expect(WorkSite::query()->count())->toBe(0);

    // A real site saves.
    Livewire::test(SitesPage::class)
        ->call('openAdd')
        ->set('formName', 'Makati Head Office')
        ->set('formLatitude', '14.5547')
        ->set('formLongitude', '121.0244')
        ->set('formRadius', '100')
        ->call('save')
        ->assertSet('dialogOpen', false);

    $site = WorkSite::query()->where('name', 'Makati Head Office')->first();

    expect($site)->not->toBeNull()
        ->and($site->radius_meters)->toBe(100)
        ->and($site->geofence_exempt)->toBeFalse();

    // A site with an assigned employee must remain.
    $employee = recordsEmployee('COP-00031', 'Miguel', 'Reyes');
    $employee->forceFill(['work_site_id' => $site->id])->save();

    Livewire::test(SitesPage::class)
        ->call('confirmDelete', (string) $site->id)
        ->call('deleteConfirmed');

    expect(WorkSite::query()->whereKey($site->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Shift pattern edits reach the roster
|--------------------------------------------------------------------------
*/

/**
 * @param  array<string, string|int|float|bool|list<int>|null>  $overrides  ShiftType attributes merged over the FIXED 09:00–18:00 defaults
 */
function recordsShiftPattern(array $overrides = []): ShiftType
{
    $pattern = new ShiftType;
    $pattern->forceFill(array_merge([
        'code' => 'SFT-'.Str::random(4),
        'name' => 'Pattern '.Str::random(4),
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

/**
 * The employee's one roster row, copied from `$pattern` exactly as AssignShift
 * copies it (null: only `$overrides`, for an orphaned or hand-built row).
 *
 * @param  array<string, string|int|float|bool|list<int>|null>  $overrides
 */
function recordsRosteredShift(EmployeeProfile $employee, ?ShiftType $pattern, array $overrides = []): Shift
{
    return Shift::query()->create(array_merge(
        ['employee_id' => $employee->id],
        $pattern !== null ? ShiftPolicy::attributesFromPattern($pattern) : [],
        $overrides,
    ));
}

/** The default-shift row in effect today (an edit adds a dated row). */
function recordsShiftToday(string $employeeId): Shift
{
    $row = app(RosterVersions::class)->standingAsOf([$employeeId], ManilaClock::todayKey())[$employeeId] ?? null;
    expect($row)->not->toBeNull();
    assert($row instanceof Shift);

    return $row;
}

it('re-copies an edited pattern onto every rostered employee still linked to it', function () {
    // Before the helper's 09:00 shift starts, so the edit applies today.
    $this->travelTo(new DateTimeImmutable('2026-09-30T06:00:00+08:00'));
    $p = recordsShiftPattern();
    $q = recordsShiftPattern(['start_time' => '07:00:00', 'end_time' => '16:00:00', 'break_start' => '11:00:00', 'break_end' => '12:00:00']);
    $a = recordsRosteredShift(recordsEmployee('COP-00101', 'Ana', 'Reyes'), $p);
    $b = recordsRosteredShift(recordsEmployee('COP-00102', 'Ben', 'Santos'), $p);
    $c = recordsRosteredShift(recordsEmployee('COP-00103', 'Cely', 'Ramos'), $q);
    // D was rostered on P's hours but its pattern link is gone (nullOnDelete):
    // it keeps its last copy and is out of reach.
    $d = recordsRosteredShift(recordsEmployee('COP-00104', 'Dan', 'Cruz'), $p, ['shift_type_id' => null]);
    $e = recordsEmployee('COP-00105', 'Eva', 'Lim');

    recordsActor(['RECORDS', 'ROSTER'], true);

    Livewire::test(ShiftTypesPage::class)
        ->call('startEdit', $p->id)
        ->set('formStartTime', '08:30')
        ->set('formEndTime', '17:30')
        ->set('formGrace', '5')
        ->set('formBreakStart', '12:30')
        ->set('formBreakEnd', '13:30')
        ->set('formBreakMinutes', '60')
        ->call('toggleDay', 6)
        ->call('save')
        ->assertSet('dialogOpen', false);

    foreach ([$a, $b] as $row) {
        // The row earlier dates were judged by keeps its copy; the edit is a
        // new row from today (2026-09-30).
        expect($row->fresh()->start_time)->toBe('09:00:00');
        $fresh = recordsShiftToday($row->employee_id);
        expect($fresh->shift_type_id)->toBe($p->id)
            ->and($fresh->start_time)->toBe('08:30:00')
            ->and($fresh->end_time)->toBe('17:30:00')
            ->and($fresh->grace_minutes)->toBe(5)
            ->and($fresh->break_start)->toBe('12:30:00')
            ->and($fresh->break_end)->toBe('13:30:00')
            ->and($fresh->break_minutes)->toBe(60)
            ->and($fresh->work_days)->toBe([1, 2, 3, 4, 5, 6]);
    }

    expect($c->fresh()->start_time)->toBe('07:00:00')
        ->and($c->fresh()->shift_type_id)->toBe($q->id)
        ->and($d->fresh()->start_time)->toBe('09:00:00')
        ->and($d->fresh()->work_days)->toBe([1, 2, 3, 4, 5])
        ->and($d->fresh()->shift_type_id)->toBeNull()
        ->and(Shift::query()->where('employee_id', $e->id)->exists())->toBeFalse()
        ->and(Shift::query()->count())->toBe(6)
        ->and(RosterScheduleChange::query()->where('source', 'PATTERN_EDIT')->count())->toBe(2);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'SHIFT_TYPE_UPDATED',
        'entity' => 'shift_types',
        'entity_id' => $p->id,
        'details' => "Shift pattern {$p->code} updated — {$p->name} · re-copied onto 2 rostered employees",
    ]);
    // One action, one row: no per-employee assignment rows.
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_ASSIGNED']);
});

it('switches rostered rows to FLEXIBLE with the filler clock when the pattern changes mode', function () {
    // Before the helper's 09:00 shift starts, so the edit applies today.
    $this->travelTo(new DateTimeImmutable('2026-09-30T06:00:00+08:00'));
    $p = recordsShiftPattern();
    $a = recordsRosteredShift(recordsEmployee('COP-00111', 'Ana', 'Reyes'), $p);

    recordsActor(['RECORDS', 'ROSTER'], true);

    Livewire::test(ShiftTypesPage::class)
        ->call('startEdit', $p->id)
        ->set('formMode', 'FLEXIBLE')
        ->set('formRequiredHours', '6')
        ->set('formHasBreak', false)
        ->call('save')
        ->assertSet('dialogOpen', false);

    expect($a->fresh()->mode)->toBe('FIXED');
    $fresh = recordsShiftToday($a->employee_id);
    expect($fresh->shift_type_id)->toBe($p->id)
        ->and($fresh->mode)->toBe('FLEXIBLE')
        ->and($fresh->required_hours)->toBe(6.0)
        // A FLEXIBLE row keeps the default working day as its clock (ShiftPolicy).
        ->and($fresh->start_time)->toBe('08:00:00')
        ->and($fresh->end_time)->toBe('17:00:00')
        ->and($fresh->grace_minutes)->toBe(0)
        ->and($fresh->break_start)->toBeNull()
        ->and($fresh->break_end)->toBeNull()
        ->and($fresh->break_minutes)->toBe(0)
        ->and($fresh->requires_break_punch)->toBeFalse();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'SHIFT_TYPE_UPDATED',
        'entity_id' => $p->id,
        'details' => "Shift pattern {$p->code} updated — {$p->name} · re-copied onto 1 rostered employee",
    ]);
});

it('refuses an in-use pattern edit from a RECORDS all-data actor without ROSTER', function () {
    // Before the helper's 09:00 shift starts, so the edit applies today.
    $this->travelTo(new DateTimeImmutable('2026-09-30T06:00:00+08:00'));
    $p = recordsShiftPattern();
    $q = recordsShiftPattern(['name' => 'Spare pattern']);
    $a = recordsRosteredShift(recordsEmployee('COP-00121', 'Ana', 'Reyes'), $p);

    recordsActor(['RECORDS'], true);

    // The fan-out writes `shifts`, which AssignShift gates on ROSTER — so the
    // same gate refuses this edit before anything is written.
    Livewire::test(ShiftTypesPage::class)
        ->call('startEdit', $p->id)
        ->set('formStartTime', '08:30')
        ->set('formEndTime', '17:30')
        ->set('formBreakStart', '12:30')
        ->set('formBreakEnd', '13:30')
        ->call('save')
        ->assertSet('dialogOpen', true);

    expect($p->fresh()->start_time)->toBe('09:00:00')
        ->and($a->fresh()->start_time)->toBe('09:00:00')
        ->and(Shift::query()->count())->toBe(1);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_TYPE_UPDATED']);

    // An unassigned pattern still needs RECORDS alone.
    Livewire::test(ShiftTypesPage::class)
        ->call('startEdit', $q->id)
        ->set('formName', 'Renamed spare')
        ->call('save')
        ->assertSet('dialogOpen', false);

    expect($q->fresh()->name)->toBe('Renamed spare');
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'SHIFT_TYPE_UPDATED',
        'entity_id' => $q->id,
        'details' => "Shift pattern {$q->code} updated — Renamed spare",
    ]);
});

it('refuses a pattern edit from a RECORDS holder without all_data and touches no rostered row', function () {
    // Before the helper's 09:00 shift starts, so the edit applies today.
    $this->travelTo(new DateTimeImmutable('2026-09-30T06:00:00+08:00'));
    $p = recordsShiftPattern();
    $a = recordsRosteredShift(recordsEmployee('COP-00131', 'Ana', 'Reyes'), $p);

    recordsActor(['RECORDS', 'ROSTER'], false);
    $patterns = ShiftType::query()->count();
    $rows = Shift::query()->count();

    Livewire::test(ShiftTypesPage::class)
        ->call('startEdit', $p->id)
        ->set('formStartTime', '08:30')
        ->set('formEndTime', '17:30')
        ->set('formBreakStart', '12:30')
        ->set('formBreakEnd', '13:30')
        ->call('save')
        ->assertSet('dialogOpen', true);

    expect($p->fresh()->start_time)->toBe('09:00:00')
        ->and($a->fresh()->start_time)->toBe('09:00:00')
        ->and(ShiftType::query()->count())->toBe($patterns)
        ->and(Shift::query()->count())->toBe($rows);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_TYPE_UPDATED']);
});

it('rolls back the pattern edit when copying onto the roster fails', function () {
    // Before the helper's 09:00 shift starts, so the edit applies today.
    $this->travelTo(new DateTimeImmutable('2026-09-30T06:00:00+08:00'));
    $p = recordsShiftPattern();
    $a = recordsRosteredShift(recordsEmployee('COP-00141', 'Ana', 'Reyes'), $p);

    recordsActor(['RECORDS', 'ROSTER'], true);

    // The pattern UPDATE succeeds and the roster write — an INSERT of the
    // new dated row — fails: both must be gone, and the screen must take
    // the generic "nothing was changed" path. sqlite quotes the table as
    // "shifts" and MySQL/MariaDB as `shifts`; the hook matches both, so the
    // suite fails the roster write on either engine.
    DB::connection()->beforeExecuting(static function (string $sql): void {
        if (str_starts_with(strtolower($sql), 'insert') && (str_contains($sql, '"shifts"') || str_contains($sql, '`shifts`'))) {
            throw new QueryException('sqlite', $sql, [], new RuntimeException('Synthetic roster update failure'));
        }
    });

    Livewire::test(ShiftTypesPage::class)
        ->call('startEdit', $p->id)
        ->set('formStartTime', '08:30')
        ->set('formEndTime', '17:30')
        ->set('formBreakStart', '12:30')
        ->set('formBreakEnd', '13:30')
        ->call('save')
        ->assertSet('dialogOpen', true);

    expect($p->fresh()->start_time)->toBe('09:00:00')
        ->and($p->fresh()->break_start)->toBe('12:00:00')
        ->and($a->fresh()->start_time)->toBe('09:00:00')
        ->and($a->fresh()->break_start)->toBe('12:00:00')
        ->and(Shift::query()->count())->toBe(1)
        ->and(RosterScheduleChange::query()->count())->toBe(0);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_TYPE_UPDATED']);
});

it('gates an in-use pattern edit on ROSTER when the command is called directly, without Livewire', function () {
    // Before the helper's 09:00 shift starts, so the edit applies today.
    $this->travelTo(new DateTimeImmutable('2026-09-30T06:00:00+08:00'));
    $p = recordsShiftPattern();
    $a = recordsRosteredShift(recordsEmployee('COP-00161', 'Ana', 'Reyes'), $p);
    // The helper's own shape (09:00–18:00, grace 10, a punched 12:00–13:00
    // break of 60) with one field moved, so the re-copy is observable.
    $draft = new ShiftTypeDraft($p->name, 'FIXED', [1, 2, 3, 4, 5], '08:30', '18:00', '10', '', true, '12:00', '13:00', '60', true);

    // RECORDS all-data without ROSTER: refused inside the command, before any
    // write — the gate must not depend on the screen that usually calls it.
    $denied = Access::forActor(recordsActor(['RECORDS'], true));
    expect(fn () => app(SaveShiftType::class)->handle($denied, $p->id, $draft))
        ->toThrow(RuntimeException::class, 'Editing a pattern that employees are rostered on needs Shift Roster access.');
    expect($p->fresh()->start_time)->toBe('09:00:00')
        ->and($a->fresh()->start_time)->toBe('09:00:00');
    $this->assertDatabaseMissing('audit_logs', ['action' => 'SHIFT_TYPE_UPDATED']);

    // RECORDS + ROSTER all-data: the same call re-copies the pattern.
    $allowed = Access::forActor(recordsActor(['RECORDS', 'ROSTER'], true));
    expect(app(SaveShiftType::class)->handle($allowed, $p->id, $draft))
        ->toBe("{$p->name} updated. 1 rostered employee now follows this pattern.");
    expect($p->fresh()->start_time)->toBe('08:30:00')
        ->and($a->fresh()->start_time)->toBe('09:00:00')
        ->and(recordsShiftToday($a->employee_id)->start_time)->toBe('08:30:00');

    // The count behind the badge answers by pattern id, and a guest is refused.
    expect(app(ReferenceData::class)->shiftTypeUsage($allowed))->toBe([(string) $p->id => 1]);
    expect(fn () => app(ReferenceData::class)->shiftTypeUsage(Access::forGuest()))->toThrow(HttpException::class);
});

it('shows the linked count on the Shifting Settings row and the edit dialog for a FLEXIBLE pattern', function () {
    $p = recordsShiftPattern([
        'name' => 'Flexi Eight',
        'mode' => 'FLEXIBLE',
        'required_hours' => 8.0,
        'start_time' => null,
        'end_time' => null,
        'grace_minutes' => 0,
        'break_start' => null,
        'break_end' => null,
        'break_minutes' => 0,
        'requires_break_punch' => false,
    ]);
    recordsRosteredShift(recordsEmployee('COP-00151', 'Ana', 'Reyes'), $p);
    recordsRosteredShift(recordsEmployee('COP-00152', 'Ben', 'Santos'), $p);

    // No employee link and no all_data: the scoped shift list would be empty
    // (and the reference read 0 for every FLEXIBLE pattern anyway), yet the
    // count is what the roster actually links.
    recordsActor(['RECORDS'], false, null);

    Livewire::test(ShiftTypesPage::class)
        ->assertSee('in use by 2')
        ->call('startEdit', $p->id)
        ->assertSeeHtmlInOrder(['Saving changes the hours of the', '2', 'employees', 'rostered on this pattern from the effective date', 'Effective from']);
});
