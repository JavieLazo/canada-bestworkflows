<?php

declare(strict_types=1);

use App\Livewire\Users\Index;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Users module: /users gates on the USERS grant, every write re-gates on
 * all_data + USERS inside the action, grant edits are visible to the very
 * next Access build (nothing caches grants), and the self-protection rules
 * (own role, own account) hold.
 */
function usersRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Users '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

function usersActor(array $modules, bool $allData, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => usersRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);

    return $user;
}

function usersEmployee(string $code, string $first, string $last, bool $active = true): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => $first,
        'last_name' => $last,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => $active,
    ])->save();

    return $employee;
}

/*
|--------------------------------------------------------------------------
| Route gating
|--------------------------------------------------------------------------
*/

it('redirects guests to login', function () {
    $this->get('/users')->assertRedirect(route('login'));
});

it('turns away a signed-in user without the USERS grant', function () {
    usersActor(['LEAVES'], false);

    // EnsureModuleAccess redirects a browser to whatever it may see.
    $this->get('/users')->assertRedirect('/leaves');
});

it('renders for a USERS holder — the page gate is the module alone', function () {
    usersActor(['USERS'], false);

    $this->get('/users')
        ->assertOk()
        ->assertSee('User Management')
        ->assertSee('New account');
});

/*
|--------------------------------------------------------------------------
| Scoping — the employee-less actor owns NOTHING
|--------------------------------------------------------------------------
*/

it('resolves ROWS_NONE for an employee-less USERS holder without all_data', function () {
    $user = usersActor(['USERS'], false, null);

    // The module's scoped-read contract: an account with no employee link and
    // no widening matches zero rows — never IS NULL rows.
    expect(Access::forActor($user)->rowVisibility('USERS'))->toBe(Access::ROWS_NONE);
});

/*
|--------------------------------------------------------------------------
| Writes are gated on all_data + USERS, per action
|--------------------------------------------------------------------------
*/

it('refuses account creation to a USERS holder without all_data', function () {
    usersActor(['USERS'], false);
    $role = usersRole(['PORTAL'], false);
    $before = User::query()->count();

    Livewire::test(Index::class)
        ->set('createFullName', 'Blocked Person')
        ->set('createEmail', 'blocked@example.com')
        ->set('createPassword', 'secret-pass-1')
        ->call('createRoleChanged', (string) $role->getKey())
        ->call('createAccount');

    expect(User::query()->count())->toBe($before);
    expect(User::query()->where('email', 'blocked@example.com')->exists())->toBeFalse();
});

it('refuses grant toggles to a USERS holder without all_data', function () {
    usersActor(['USERS'], false);
    $role = usersRole([], false);

    Livewire::test(Index::class)
        ->call('toggleGrant', (string) $role->getKey(), 'LEAVES');

    expect(
        RoleModuleGrant::query()->where('role_id', $role->getKey())->where('module', 'LEAVES')->exists()
    )->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Happy path — provision a login
|--------------------------------------------------------------------------
*/

it('creates an account with a bcrypt password, a role and an audit row', function () {
    usersActor(['USERS'], true);
    $role = usersRole(['PORTAL'], false);
    $employee = usersEmployee('BWF-00021', 'Liza', 'Domingo');

    Livewire::test(Index::class)
        ->set('adding', true)
        ->set('createFullName', 'Liza Domingo')
        ->set('createEmail', 'liza@example.com')
        ->set('createPassword', 'temporary-99')
        ->call('createRoleChanged', (string) $role->getKey())
        ->call('createEmployeeChanged', (string) $employee->getKey())
        ->call('createAccount')
        ->assertSet('adding', false)
        ->assertSet('createEmail', '');

    $created = User::query()->where('email', 'liza@example.com')->first();
    expect($created)->not->toBeNull();
    expect($created->name)->toBe('Liza Domingo');
    expect((string) $created->role_id)->toBe((string) $role->getKey());
    expect((string) $created->employee_id)->toBe((string) $employee->getKey());
    expect((bool) $created->is_active)->toBeTrue();
    // Fortify-compatible: the stored hash verifies through the app hasher.
    expect(Hash::check('temporary-99', $created->password))->toBeTrue();

    expect(
        AuditLog::query()
            ->where('action', 'USER_CREATED')
            ->where('entity', 'user_accounts')
            ->where('entity_id', (string) $created->getKey())
            ->where('details', 'Liza Domingo (liza@example.com) provisioned')
            ->exists()
    )->toBeTrue();
});

it('refuses a case-insensitive duplicate email', function () {
    usersActor(['USERS'], true);
    $role = usersRole(['PORTAL'], false);
    User::factory()->create(['email' => 'admin@example.com']);

    Livewire::test(Index::class)
        ->set('createFullName', 'Copy Cat')
        ->set('createEmail', 'Admin@Example.com')
        ->set('createPassword', 'temporary-99')
        ->call('createRoleChanged', (string) $role->getKey())
        ->call('createAccount');

    expect(User::query()->where('name', 'Copy Cat')->exists())->toBeFalse();
});

it('refuses linking an inactive employee record', function () {
    usersActor(['USERS'], true);
    $role = usersRole(['PORTAL'], false);
    $employee = usersEmployee('BWF-00099', 'Gone', 'Person', active: false);

    Livewire::test(Index::class)
        ->set('createFullName', 'New Hire')
        ->set('createEmail', 'newhire@example.com')
        ->set('createPassword', 'temporary-99')
        ->call('createRoleChanged', (string) $role->getKey())
        ->call('createEmployeeChanged', (string) $employee->getKey())
        ->call('createAccount');

    expect(User::query()->where('email', 'newhire@example.com')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The create dialog opens empty — no state from an abandoned attempt
|--------------------------------------------------------------------------
|
| Dismissing a <flux:modal> with its × (or Escape, or the backdrop) closes it
| client-side without writing `adding` back, so updatedAdding() — and the
| resetCreateForm() hanging off it — never runs. openCreate() resets on the way
| IN so the dialog cannot carry a previous attempt's employee link into the
| next account.
*/

it('opens the create dialog on a form emptied of the last attempt', function () {
    usersActor(['USERS'], true);
    $role = usersRole(['PORTAL'], false);
    $employee = usersEmployee('BWF-00031', 'Abandoned', 'Pick');

    Livewire::test(Index::class)
        ->call('openCreate')
        ->set('createFullName', 'Half Typed')
        ->set('createEmail', 'half@example.com')
        ->set('createPassword', 'temporary-99')
        ->call('createRoleChanged', (string) $role->getKey())
        ->call('createEmployeeChanged', (string) $employee->getKey())
        // The operator gives up and dismisses the dialog with the × — which
        // never reaches the server — then presses "New account" again.
        ->call('openCreate')
        ->assertSet('adding', true)
        ->assertSet('createEmployeeId', '')
        ->assertSet('createRoleId', '')
        ->assertSet('createFullName', '')
        ->assertSet('createEmail', '')
        ->assertSet('createPassword', '');
});

it('does not link an abandoned attempt’s employee to the next account created', function () {
    usersActor(['USERS'], true);
    $role = usersRole(['PORTAL'], false);
    $stale = usersEmployee('BWF-00032', 'Nathaniel', 'Cruz');

    Livewire::test(Index::class)
        ->call('openCreate')
        ->call('createEmployeeChanged', (string) $stale->getKey())
        // Dismissed with the ×: no `adding` round-trip, so no reset here.
        ->call('openCreate')
        ->set('createFullName', 'Second Person')
        ->set('createEmail', 'second@example.com')
        ->set('createPassword', 'temporary-99')
        ->call('createRoleChanged', (string) $role->getKey())
        // The employee picker is never touched for THIS account.
        ->call('createAccount');

    $created = User::query()->where('email', 'second@example.com')->first();

    expect($created)->not->toBeNull();
    expect($created->employee_id)->toBeNull();
});

it('offers a selectable "no linked employee" option on the create picker', function () {
    usersActor(['USERS'], true);
    usersEmployee('BWF-00033', 'Someone', 'Else');

    $html = Livewire::test(Index::class)->call('openCreate')->html();

    $open = strpos($html, 'aria-label="Link to employee record (optional)"');
    $select = substr($html, (int) strrpos(substr($html, 0, $open), '<select'), 4000);
    $select = substr($select, 0, (int) strpos($select, '</select>'));

    preg_match('/<option[^>]*value=""[^>]*>/', $select, $empty);

    // Flux's `placeholder` renders <option value="" disabled>, which can open
    // the select but can never be re-chosen — the link is optional, so the
    // empty option has to stay reachable.
    expect($empty)->not->toBeEmpty();
    expect($empty[0])->not->toContain('disabled');
});

/*
|--------------------------------------------------------------------------
| One employee record, several sign-ins
|--------------------------------------------------------------------------
|
| The employee profile represents the person. User accounts represent ways that
| person signs in, so several active accounts may point at the same employee
| record. Scoping still treats them as the same employee.
*/

it('removes a previously applied employee-link constraint without losing account links', function (string $migrationFile) {
    $employee = usersEmployee('BWF-LINK-UPGRADE', 'Shared', 'Employee');
    $first = User::factory()->create(['employee_id' => $employee->id, 'is_active' => true]);
    $disabled = User::factory()->create(['employee_id' => $employee->id, 'is_active' => false]);

    DB::statement('CREATE UNIQUE INDEX "users_active_employee_unq" ON "users" ("employee_id") WHERE "is_active" = 1 AND "employee_id" IS NOT NULL');
    expect(fn () => $disabled->forceFill(['is_active' => true])->save())->toThrow(QueryException::class);

    $migration = require database_path('migrations/'.$migrationFile);
    $migration->up();

    expect(Schema::hasIndex('users', 'users_active_employee_unq'))->toBeFalse();
    $disabled->refresh()->forceFill(['is_active' => true])->save();
    $second = User::factory()->create(['employee_id' => $employee->id, 'is_active' => true]);

    // A retry and a rollback must preserve the links created under the new rule.
    $migration->up();
    $migration->down();

    expect($employee->accounts()->where('is_active', true)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$first->id, $disabled->id, $second->id])->sort()->values()->all());
    expect(Schema::hasIndex('users', 'users_active_employee_unq'))->toBeFalse();
})->with([
    'pending migration' => '2026_09_08_0001_add_unique_active_employee_link_to_users_table.php',
    'upgrade migration' => '2026_09_10_0003_drop_unique_active_employee_link_from_users_table.php',
]);

it('allows linking a new active account to an employee record another active account already holds', function () {
    usersActor(['USERS'], true);
    $role = usersRole(['PORTAL'], false);
    $employee = usersEmployee('BWF-00041', 'Nathaniel', 'Cruz');

    $holder = User::factory()->create([
        'email' => 'holder@example.com',
        'employee_id' => $employee->getKey(),
        'is_active' => true,
    ]);

    Livewire::test(Index::class)
        ->call('openCreate')
        ->set('createFullName', 'Second Claim')
        ->set('createEmail', 'secondclaim@example.com')
        ->set('createPassword', 'temporary-99')
        ->call('createRoleChanged', (string) $role->getKey())
        ->call('createEmployeeChanged', (string) $employee->getKey())
        ->call('createAccount');

    $created = User::query()->where('email', 'secondclaim@example.com')->first();

    expect($created)->not->toBeNull();
    expect((string) $created->employee_id)->toBe((string) $employee->getKey());
    expect(User::query()->where('employee_id', $employee->getKey())->pluck('id')->map(fn ($id) => (string) $id)->all())
        ->toContain((string) $holder->getKey(), (string) $created->getKey());
});

it('allows a new account on an employee whose other login is deactivated', function () {
    usersActor(['USERS'], true);
    $role = usersRole(['PORTAL'], false);
    $employee = usersEmployee('BWF-00042', 'Rehired', 'Person');

    // The old login, disabled but still attached to the employee record.
    User::factory()->create([
        'email' => 'old-login@example.com',
        'employee_id' => $employee->getKey(),
        'is_active' => false,
    ]);

    Livewire::test(Index::class)
        ->call('openCreate')
        ->set('createFullName', 'Rehired Person')
        ->set('createEmail', 'rehired@example.com')
        ->set('createPassword', 'temporary-99')
        ->call('createRoleChanged', (string) $role->getKey())
        ->call('createEmployeeChanged', (string) $employee->getKey())
        ->call('createAccount');

    $created = User::query()->where('email', 'rehired@example.com')->first();

    expect($created)->not->toBeNull();
    expect((string) $created->employee_id)->toBe((string) $employee->getKey());
    // Both rows keep the link; only one of them is live.
    expect(User::query()->where('employee_id', $employee->getKey())->count())->toBe(2);
});

it('allows moving an account onto an employee another active account holds', function () {
    usersActor(['USERS'], true);
    $employee = usersEmployee('BWF-00043', 'Taken', 'Record');

    $incumbent = User::factory()->create([
        'email' => 'incumbent@example.com',
        'employee_id' => $employee->getKey(),
        'is_active' => true,
    ]);

    $subject = User::factory()->create([
        'email' => 'subject@example.com',
        'role_id' => usersRole(['PORTAL'], false)->getKey(),
        'employee_id' => null,
        'is_active' => true,
    ]);

    Livewire::test(Index::class)
        ->call('openAccount', (string) $subject->getKey())
        ->call('accountEmployeeChanged', (string) $employee->getKey())
        ->call('saveAccount');

    expect((string) $subject->fresh()->employee_id)->toBe((string) $employee->getKey());
    expect(User::query()->where('employee_id', $employee->getKey())->pluck('id')->map(fn ($id) => (string) $id)->all())
        ->toContain((string) $incumbent->getKey(), (string) $subject->getKey());
});

it('lets an account keep the employee record it already holds', function () {
    usersActor(['USERS'], true);
    $employee = usersEmployee('BWF-00044', 'Kept', 'Record');

    $subject = User::factory()->create([
        'email' => 'keeper@example.com',
        'role_id' => usersRole(['PORTAL'], false)->getKey(),
        'employee_id' => $employee->getKey(),
        'is_active' => true,
    ]);

    Livewire::test(Index::class)
        ->call('openAccount', (string) $subject->getKey())
        ->set('accountFullName', 'Keeper Renamed')
        ->call('saveAccount');

    $subject->refresh();

    expect($subject->name)->toBe('Keeper Renamed');
    expect((string) $subject->employee_id)->toBe((string) $employee->getKey());
});

it('allows reactivating a disabled account whose employee is linked to another active account', function () {
    usersActor(['USERS'], true);
    $employee = usersEmployee('BWF-00045', 'Contested', 'Record');

    User::factory()->create([
        'email' => 'current@example.com',
        'employee_id' => $employee->getKey(),
        'is_active' => true,
    ]);

    $disabled = User::factory()->create([
        'email' => 'disabled@example.com',
        'role_id' => usersRole(['PORTAL'], false)->getKey(),
        'employee_id' => $employee->getKey(),
        'is_active' => false,
    ]);

    Livewire::test(Index::class)
        ->call('openAccount', (string) $disabled->getKey())
        ->set('accountIsActive', true)
        ->call('saveAccount');

    expect((bool) $disabled->fresh()->is_active)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Edit, deactivate, reactivate
|--------------------------------------------------------------------------
*/

it('edits another account — role and status included — and audits USER_UPDATED', function () {
    usersActor(['USERS'], true);
    $newRole = usersRole(['PORTAL'], false);
    $target = User::factory()->create(['name' => 'Old Name', 'email' => 'target@example.com']);

    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->getKey())
        ->assertSet('accountDialogOpen', true)
        ->assertSet('accountFullName', 'Old Name')
        ->set('accountFullName', 'New Name')
        ->call('accountRoleChanged', (string) $newRole->getKey())
        ->set('accountIsActive', false)
        ->call('saveAccount');

    $fresh = $target->fresh();
    expect($fresh->name)->toBe('New Name');
    expect((string) $fresh->role_id)->toBe((string) $newRole->getKey());
    expect((bool) $fresh->is_active)->toBeFalse();

    expect(
        AuditLog::query()
            ->where('action', 'USER_UPDATED')
            ->where('entity', 'user_accounts')
            ->where('entity_id', (string) $target->getKey())
            ->where('details', 'New Name updated')
            ->exists()
    )->toBeTrue();
});

it("never applies role or status changes to the actor's own account", function () {
    $admin = usersActor(['USERS'], true);
    $ownRoleId = (string) $admin->role_id;
    $otherRole = usersRole(['PORTAL'], false);

    Livewire::test(Index::class)
        ->call('openAccount', (string) $admin->getKey())
        ->call('accountRoleChanged', (string) $otherRole->getKey())
        ->set('accountIsActive', false)
        ->call('saveAccount');

    $fresh = $admin->fresh();
    // The own-account patch drops role_id and is_active, as the source does.
    expect((string) $fresh->role_id)->toBe($ownRoleId);
    expect((bool) $fresh->is_active)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Password reset
|--------------------------------------------------------------------------
*/

it('resets another account password and audits it', function () {
    usersActor(['USERS'], true);
    $target = User::factory()->create(['name' => 'Reset Me']);

    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->getKey())
        ->set('resetPassword', 'brand-new-pass')
        ->set('resetPasswordConfirm', 'brand-new-pass')
        ->call('resetAccountPassword')
        ->assertSet('resetPassword', '')
        ->assertSet('resetPasswordConfirm', '');

    expect(Hash::check('brand-new-pass', $target->fresh()->password))->toBeTrue();

    expect(
        AuditLog::query()
            ->where('action', 'USER_PASSWORD_RESET')
            ->where('entity', 'user_accounts')
            ->where('entity_id', (string) $target->getKey())
            ->where('details', 'Reset Me password reset')
            ->exists()
    )->toBeTrue();
});

it('refuses a password reset when the confirmation does not match', function () {
    usersActor(['USERS'], true);
    $target = User::factory()->create();
    $originalHash = $target->password;

    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->getKey())
        ->set('resetPassword', 'brand-new-pass')
        ->set('resetPasswordConfirm', 'different-pass')
        ->call('resetAccountPassword');

    expect($target->fresh()->password)->toBe($originalHash);
});

/*
|--------------------------------------------------------------------------
| Delete — only unused, deactivated accounts
|--------------------------------------------------------------------------
*/

it('deletes a clean deactivated account and audits USER_DELETED', function () {
    usersActor(['USERS'], true);
    $target = User::factory()->create(['name' => 'Test Account', 'is_active' => false]);

    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->getKey())
        ->call('deleteAccount')
        ->assertSet('accountDialogOpen', false)
        ->assertSet('deleteConfirmOpen', false);

    expect(User::query()->whereKey($target->getKey())->exists())->toBeFalse();

    expect(
        AuditLog::query()
            ->where('action', 'USER_DELETED')
            ->where('entity', 'user_accounts')
            ->where('details', 'Test Account account deleted')
            ->exists()
    )->toBeTrue();
});

it('refuses to delete an active account', function () {
    usersActor(['USERS'], true);
    $target = User::factory()->create(['is_active' => true]);

    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->getKey())
        ->call('deleteAccount');

    expect(User::query()->whereKey($target->getKey())->exists())->toBeTrue();
});

it('refuses to delete an account with historical records', function () {
    usersActor(['USERS'], true);
    $target = User::factory()->create(['is_active' => false]);

    $notification = new Notification;
    $notification->forceFill([
        'user_id' => $target->getKey(),
        'kind' => 'LEAVE_REVIEWED',
        'title' => 'LR-001 approved',
    ])->save();

    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->getKey())
        ->call('deleteAccount');

    expect(User::query()->whereKey($target->getKey())->exists())->toBeTrue();
});

it('refuses to delete an account that handed out a task assignment, keeping the attribution', function () {
    usersActor(['USERS'], true);
    $target = User::factory()->create(['is_active' => false]);
    $employee = usersEmployee('EMP-901', 'Ana', 'Garcia');

    $project = new Project;
    $project->forceFill(['code' => 'PRJ-901', 'name' => 'Project 901', 'status' => 'ACTIVE', 'start_date' => '2026-01-01'])->save();
    $task = new ProjectTask;
    $task->forceFill(['code' => 'TSK-901', 'project_id' => $project->getKey(), 'title' => 'Task 901', 'status' => 'PENDING', 'priority' => 'NORMAL'])->save();
    // The account's only trace anywhere: it put Ana on the task. The pivot's
    // assigned_by FK nulls on delete, which is exactly what must not happen.
    $task->syncAssignees([$employee->getKey()], (int) $target->getKey());

    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->getKey())
        ->call('deleteAccount');

    expect(User::query()->whereKey($target->getKey())->exists())->toBeTrue();
    expect(ProjectTaskAssignee::query()->where('task_id', $task->getKey())->firstOrFail()->assigned_by)->toBe((int) $target->getKey());
});

it('refuses to delete your own account', function () {
    $admin = usersActor(['USERS'], true);

    Livewire::test(Index::class)
        ->call('openAccount', (string) $admin->getKey())
        ->call('deleteAccount');

    expect(User::query()->whereKey($admin->getKey())->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Roles and the grant matrix
|--------------------------------------------------------------------------
*/

it('creates a role and audits ROLE_CREATED', function () {
    usersActor(['USERS'], true);

    Livewire::test(Index::class)
        ->call('openNewRole')
        ->set('roleName', 'Payroll Clerk')
        ->set('roleAllData', true)
        ->call('saveRole')
        ->assertSet('roleDialogOpen', false);

    $role = Role::query()->where('name', 'Payroll Clerk')->first();
    expect($role)->not->toBeNull();
    expect((bool) $role->all_data)->toBeTrue();

    expect(
        AuditLog::query()
            ->where('action', 'ROLE_CREATED')
            ->where('entity', 'roles')
            ->where('entity_id', (string) $role->getKey())
            ->where('details', 'Payroll Clerk role created')
            ->exists()
    )->toBeTrue();
});

it('grant edits take effect on the NEXT Access build — nothing caches grants', function () {
    usersActor(['USERS'], true);
    $role = usersRole([], false);
    $member = User::factory()->create(['role_id' => $role->getKey()]);

    // Before: a fresh Access for the member holds nothing.
    expect(Access::forActor($member->fresh())->hasModule('LEAVES'))->toBeFalse();

    Livewire::test(Index::class)
        ->call('toggleGrant', (string) $role->getKey(), 'LEAVES');

    // The very next build — re-read from roles + role_module_grants — sees it.
    expect(Access::forActor(User::query()->find($member->getKey()))->hasModule('LEAVES'))->toBeTrue();

    expect(
        AuditLog::query()
            ->where('action', 'GRANT_ADDED')
            ->where('entity', 'role_module_grants')
            ->where('entity_id', (string) $role->getKey())
            ->where('details', $role->name.': LEAVES granted')
            ->exists()
    )->toBeTrue();

    // And a revoke disappears just as immediately.
    Livewire::test(Index::class)
        ->call('toggleGrant', (string) $role->getKey(), 'LEAVES');

    expect(Access::forActor(User::query()->find($member->getKey()))->hasModule('LEAVES'))->toBeFalse();

    expect(
        AuditLog::query()
            ->where('action', 'GRANT_REMOVED')
            ->where('details', $role->name.': LEAVES revoked')
            ->exists()
    )->toBeTrue();
});

it('refuses to remove User Management from your own role', function () {
    $admin = usersActor(['USERS'], true);
    $ownRoleId = (string) $admin->role_id;

    Livewire::test(Index::class)
        ->call('toggleGrant', $ownRoleId, 'USERS');

    expect(
        RoleModuleGrant::query()->where('role_id', $ownRoleId)->where('module', 'USERS')->exists()
    )->toBeTrue();
});

it('refuses to remove organization-wide scope from your own role', function () {
    $admin = usersActor(['USERS'], true);
    $ownRoleId = (string) $admin->role_id;

    Livewire::test(Index::class)
        ->call('openEditRole', $ownRoleId)
        ->set('roleAllData', false)
        ->call('saveRole');

    expect((bool) Role::query()->find($ownRoleId)->all_data)->toBeTrue();
});
