<?php

declare(strict_types=1);

use App\Livewire\Users\Index;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\OvertimeApproval;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskSubmission;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\IdentityAccess\Application\Commands\ManageAccounts;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Support\Access;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * The Users module: /users gates on the USERS grant, every write re-gates on
 * all_data + USERS inside the action, a ticked grant is only staged until the
 * confirm dialog's button writes it, grant edits are visible to the very next
 * Access build (nothing caches grants), and the self-protection rules (own
 * role, own account) hold.
 */
/** @param list<string> $modules ModuleCode values to grant */
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

/** @param list<string> $modules ModuleCode values to grant */
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
        ->assertSee('New Account');
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
        ->set('createEmail', 'blocked@example.com')
        ->set('createPassword', 'Secret-Password#2026')
        ->call('createRoleChanged', (string) $role->getKey())
        ->call('createAccount');

    expect(User::query()->count())->toBe($before);
    expect(User::query()->where('email', 'blocked@example.com')->exists())->toBeFalse();
});

it('refuses grant toggles to a USERS holder without all_data', function () {
    usersActor(['USERS'], false);
    $role = usersRole([], false);

    Livewire::test(Index::class)
        ->call('askGrant', (string) $role->getKey(), 'LEAVES', true, 0)
        ->assertSet('grantConfirmOpen', false)
        // The refused tick still re-keys the box back to the stored state.
        ->assertSet('grantNonce', 1)
        ->call('confirmGrant');

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
    $employee = usersEmployee('COP-00021', 'Liza', 'Domingo');

    Livewire::test(Index::class)
        ->set('adding', true)
        ->set('createEmail', 'liza@example.com')
        ->set('createPassword', 'Temporary-Password#2026')
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
    expect(Hash::check('Temporary-Password#2026', $created->password))->toBeTrue();

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
        ->set('createEmail', 'Admin@Example.com')
        ->set('createPassword', 'Temporary-Password#2026')
        ->call('createRoleChanged', (string) $role->getKey())
        ->call('createAccount');

    expect(User::query()->whereRaw('LOWER(email) = ?', ['admin@example.com'])->count())->toBe(1);
});

it('refuses linking an inactive employee record', function () {
    usersActor(['USERS'], true);
    $role = usersRole(['PORTAL'], false);
    $employee = usersEmployee('COP-00099', 'Gone', 'Person', active: false);

    Livewire::test(Index::class)
        ->set('createEmail', 'newhire@example.com')
        ->set('createPassword', 'Temporary-Password#2026')
        ->call('createRoleChanged', (string) $role->getKey())
        ->call('createEmployeeChanged', (string) $employee->getKey())
        ->call('createAccount');

    expect(User::query()->where('email', 'newhire@example.com')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The create dialog opens fresh — no state from an abandoned attempt
|--------------------------------------------------------------------------
|
| Dismissing a <flux:modal> with its × (or Escape, or the backdrop) closes it
| client-side without writing `adding` back, so updatedAdding() — and the
| resetCreateForm() hanging off it — never runs. openCreate() resets on the way
| IN so the dialog cannot carry a previous attempt's employee link into the
| next account. The one thing the form opens holding is a newly generated
| temporary password.
*/

it('opens the create dialog on a form emptied of the last attempt', function () {
    usersActor(['USERS'], true);
    $role = usersRole(['PORTAL'], false);
    $employee = usersEmployee('COP-00031', 'Abandoned', 'Pick');

    Livewire::test(Index::class)
        ->call('openCreate')
        ->set('createEmail', 'half@example.com')
        ->set('createPassword', 'Temporary-Password#2026')
        ->call('createRoleChanged', (string) $role->getKey())
        ->call('createEmployeeChanged', (string) $employee->getKey())
        // The operator gives up and dismisses the dialog with the × — which
        // never reaches the server — then presses "New account" again.
        ->call('openCreate')
        ->assertSet('adding', true)
        ->assertSet('createEmployeeId', '')
        ->assertSet('createRoleId', '')
        ->assertSet('createEmail', '')
        // The form opens on a newly generated password, never the last attempt's.
        ->assertSet('createPassword', fn (string $password): bool => $password !== '' && $password !== 'Temporary-Password#2026');
});

it('does not link an abandoned attempt’s employee to the next account created', function () {
    usersActor(['USERS'], true);
    $role = usersRole(['PORTAL'], false);
    $stale = usersEmployee('COP-00032', 'Nathaniel', 'Cruz');

    Livewire::test(Index::class)
        ->call('openCreate')
        ->call('createEmployeeChanged', (string) $stale->getKey())
        // Dismissed with the ×: no `adding` round-trip, so no reset here.
        ->call('openCreate')
        ->set('createName', 'Second Sign-in')
        ->set('createEmail', 'second@example.com')
        ->set('createPassword', 'Temporary-Password#2026')
        ->call('createRoleChanged', (string) $role->getKey())
        // The employee picker is never touched for THIS account.
        ->call('createAccount');

    $created = User::query()->where('email', 'second@example.com')->first();

    expect($created)->not->toBeNull();
    expect($created->employee_id)->toBeNull();
});

it('offers a selectable "no linked employee" option on the create picker', function () {
    usersActor(['USERS'], true);
    usersEmployee('COP-00033', 'Someone', 'Else');

    $html = Livewire::test(Index::class)->call('openCreate')->html();

    $open = strpos($html, 'aria-label="Link to employee record (optional)"');

    if ($open === false) {
        throw new RuntimeException('The create dialog did not render the employee picker.');
    }

    $select = substr($html, (int) strrpos(substr($html, 0, $open), '<select'), 4000);
    $select = substr($select, 0, (int) strpos($select, '</select>'));

    preg_match('/<option[^>]*value=""[^>]*>/', $select, $empty);

    // Flux's `placeholder` renders <option value="" disabled>, which can open
    // the select but can never be re-chosen — the link is optional, so the
    // empty option has to stay reachable.
    expect($empty)->not->toBeEmpty();

    // The expectation above already proves the match exists; the throw only
    // states that for static analysis and can never run.
    $emptyOption = $empty[0] ?? throw new RuntimeException('unreachable: the match was asserted above');

    expect($emptyOption)->not->toContain('disabled');
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

it('allows linking a new active account to an employee record another active account already holds', function () {
    usersActor(['USERS'], true);
    $role = usersRole(['PORTAL'], false);
    $employee = usersEmployee('COP-00041', 'Nathaniel', 'Cruz');

    $holder = User::factory()->create([
        'email' => 'holder@example.com',
        'employee_id' => $employee->getKey(),
        'is_active' => true,
    ]);

    Livewire::test(Index::class)
        ->call('openCreate')
        ->set('createEmail', 'secondclaim@example.com')
        ->set('createPassword', 'Temporary-Password#2026')
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
    $employee = usersEmployee('COP-00042', 'Rehired', 'Person');

    // The old login, disabled but still attached to the employee record.
    User::factory()->create([
        'email' => 'old-login@example.com',
        'employee_id' => $employee->getKey(),
        'is_active' => false,
    ]);

    Livewire::test(Index::class)
        ->call('openCreate')
        ->set('createEmail', 'rehired@example.com')
        ->set('createPassword', 'Temporary-Password#2026')
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
    $employee = usersEmployee('COP-00043', 'Taken', 'Record');

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
    $employee = usersEmployee('COP-00044', 'Kept', 'Record');

    $subject = User::factory()->create([
        'email' => 'keeper@example.com',
        'role_id' => usersRole(['PORTAL'], false)->getKey(),
        'employee_id' => $employee->getKey(),
        'is_active' => true,
    ]);

    Livewire::test(Index::class)
        ->call('openAccount', (string) $subject->getKey())
        ->call('saveAccount');

    $subject->refresh();

    expect($subject->name)->toBe('Kept Record');
    expect((string) $subject->employee_id)->toBe((string) $employee->getKey());
});

it('allows reactivating a disabled account whose employee is linked to another active account', function () {
    usersActor(['USERS'], true);
    $employee = usersEmployee('COP-00045', 'Contested', 'Record');

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

        ->call('accountRoleChanged', (string) $newRole->getKey())
        ->set('accountIsActive', false)
        ->call('saveAccount');

    $fresh = $target->fresh();
    expect($fresh->name)->toBe('Account #'.$fresh->id);
    expect((string) $fresh->role_id)->toBe((string) $newRole->getKey());
    expect((bool) $fresh->is_active)->toBeFalse();

    expect(
        AuditLog::query()
            ->where('action', 'USER_UPDATED')
            ->where('entity', 'user_accounts')
            ->where('entity_id', (string) $target->getKey())
            ->where('details', 'Account #'.$target->id.' updated')
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
        ->set('resetPassword', 'Brand-New-Password#2026')
        ->set('resetPasswordConfirm', 'Brand-New-Password#2026')
        ->call('resetAccountPassword')
        ->assertSet('resetPassword', '')
        ->assertSet('resetPasswordConfirm', '');

    expect(Hash::check('Brand-New-Password#2026', $target->fresh()->password))->toBeTrue();

    expect(
        AuditLog::query()
            ->where('action', 'USER_PASSWORD_RESET')
            ->where('entity', 'user_accounts')
            ->where('entity_id', (string) $target->getKey())
            ->where('details', 'Account #'.$target->id.' password reset')
            ->exists()
    )->toBeTrue();
});

it('refuses a password reset when the confirmation does not match', function () {
    usersActor(['USERS'], true);
    $target = User::factory()->create();
    $originalHash = $target->password;

    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->getKey())
        ->set('resetPassword', 'Brand-New-Password#2026')
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
            ->where('details', 'Account #'.$target->id.' account deleted')
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

it('refuses to delete an account that decided a member\'s task response, keeping the attribution', function () {
    usersActor(['USERS'], true);
    $target = User::factory()->create(['is_active' => false]);
    $employee = usersEmployee('EMP-902', 'Ana', 'Garcia');

    $project = new Project;
    $project->forceFill(['code' => 'PRJ-902', 'name' => 'Project 902', 'status' => 'ACTIVE', 'start_date' => '2026-01-01'])->save();
    $task = new ProjectTask;
    $task->forceFill(['code' => 'TSK-902', 'project_id' => $project->getKey(), 'title' => 'Task 902', 'status' => 'IN_PROGRESS', 'priority' => 'NORMAL', 'submission_mode' => 'INDIVIDUAL'])->save();
    $task->syncAssignees([$employee->getKey()], null);

    // The account's only trace anywhere: it approved Ana's response. The
    // reviewed_by FK nulls on delete, which is exactly what must not happen.
    $response = new ProjectTaskSubmission;
    $response->fill(['task_id' => $task->getKey(), 'employee_id' => $employee->getKey(), 'status' => 'APPROVED', 'submitted_at' => '2026-09-24 01:00:00.000', 'reviewed_at' => '2026-09-24 02:00:00.000', 'revision' => 1]);
    // Actor column — set by property, never mass-assigned.
    $response->reviewed_by = (int) $target->getKey();
    $response->save();

    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->getKey())
        ->call('deleteAccount');

    expect(User::query()->whereKey($target->getKey())->exists())->toBeTrue();
    expect($response->fresh()?->reviewed_by)->toBe((int) $target->getKey());
});

it('refuses to delete an account that decided an employee\'s overtime, keeping the attribution', function () {
    usersActor(['USERS'], true);
    $target = User::factory()->create(['is_active' => false]);
    $employee = usersEmployee('EMP-903', 'Ana', 'Garcia');

    // The account's only trace anywhere: it approved Ana's overtime, which
    // authorised pay. overtime_approvals.reviewed_by has NO foreign key, so
    // Timekeeping's AccountReferences is the only thing keeping the account.
    $approval = new OvertimeApproval;
    $approval->fill(['employee_id' => $employee->getKey(), 'date' => '2026-09-08', 'decision' => 'APPROVED',
        'computed_minutes' => 90, 'approved_minutes' => 90]);
    // Actor column — set by property, never mass-assigned.
    $approval->reviewed_by = (int) $target->getKey();
    $approval->save();

    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->getKey())
        ->call('deleteAccount');

    expect(User::query()->whereKey($target->getKey())->exists())->toBeTrue();
    expect($approval->fresh()?->reviewed_by)->toBe((int) $target->getKey());
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
        ->call('askGrant', (string) $role->getKey(), 'LEAVES', true, 0)
        ->call('confirmGrant');

    // The very next build — re-read from roles + role_module_grants — sees it.
    expect(Access::forActor(User::query()->whereKey($member->getKey())->firstOrFail())->hasModule('LEAVES'))->toBeTrue();

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
        ->call('askGrant', (string) $role->getKey(), 'LEAVES', false, 0)
        ->call('confirmGrant');

    expect(Access::forActor(User::query()->whereKey($member->getKey())->firstOrFail())->hasModule('LEAVES'))->toBeFalse();

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
        ->call('askGrant', $ownRoleId, 'USERS', false, 0)
        ->assertSet('grantConfirmOpen', false)
        ->assertSet('grantNonce', 1)
        ->call('confirmGrant');

    expect(
        RoleModuleGrant::query()->where('role_id', $ownRoleId)->where('module', 'USERS')->exists()
    )->toBeTrue();
});

it('asks before a grant changes — a tick alone writes nothing', function () {
    usersActor(['USERS'], true);
    $role = usersRole([], false);
    $audits = AuditLog::query()->count();

    Livewire::test(Index::class)
        ->set('tab', 'roles')
        ->call('askGrant', (string) $role->getKey(), 'LEAVES', true, 0)
        ->assertSet('grantConfirmOpen', true)
        ->assertSet('grantRoleId', (string) $role->getKey())
        // The ticked box re-keys, so it shows the stored state while asked.
        ->assertSet('grantNonce', 1)
        ->assertSee('Give '.$role->name.' access to Leave Management?')
        ->assertSee('Nothing has been saved yet.');

    expect(RoleModuleGrant::query()->where('role_id', $role->getKey())->exists())->toBeFalse();
    expect(AuditLog::query()->count())->toBe($audits);
});

it('names a removal in the confirmation and writes it only once', function () {
    usersActor(['USERS'], true);
    $role = usersRole(['LEAVES'], false);

    $page = Livewire::test(Index::class)
        ->call('askGrant', (string) $role->getKey(), 'LEAVES', false, 0)
        ->assertSee('Remove Leave Management from '.$role->name.'?');

    expect(RoleModuleGrant::query()->where('role_id', $role->getKey())->where('module', 'LEAVES')->exists())->toBeTrue();

    $page->call('confirmGrant')
        ->assertSet('grantConfirmOpen', false)
        ->assertSet('grantRoleId', null)
        ->assertSet('grantNonce', 2);

    expect(RoleModuleGrant::query()->where('role_id', $role->getKey())->where('module', 'LEAVES')->exists())->toBeFalse();

    // A second click on the dialog's button finds nothing staged.
    $page->call('confirmGrant');

    expect(
        AuditLog::query()->where('entity', 'role_module_grants')->where('entity_id', (string) $role->getKey())->count()
    )->toBe(1);
});

it('stages nothing for a role or a module that does not exist', function () {
    usersActor(['USERS'], true);
    $role = usersRole([], false);

    Livewire::test(Index::class)
        ->call('askGrant', 'no-such-role', 'LEAVES', true, 0)
        ->assertSet('grantConfirmOpen', false)
        ->assertSet('grantNonce', 1)
        ->call('askGrant', (string) $role->getKey(), 'NOT_A_MODULE', true, 1)
        ->assertSet('grantConfirmOpen', false)
        ->assertSet('grantRoleId', null)
        ->assertSet('grantNonce', 2);

    expect(RoleModuleGrant::query()->where('role_id', $role->getKey())->exists())->toBeFalse();
});

it('forgets the staged grant when the confirmation is cancelled', function () {
    usersActor(['USERS'], true);
    $role = usersRole([], false);

    Livewire::test(Index::class)
        ->call('askGrant', (string) $role->getKey(), 'LEAVES', true, 0)
        ->set('grantConfirmOpen', false)
        ->assertSet('grantRoleId', null)
        ->call('confirmGrant');

    expect(RoleModuleGrant::query()->where('role_id', $role->getKey())->exists())->toBeFalse();
});

it('re-checks the actor when the confirmation is answered', function () {
    $admin = usersActor(['USERS'], true);
    $role = usersRole([], false);

    $page = Livewire::test(Index::class)
        ->call('askGrant', (string) $role->getKey(), 'LEAVES', true, 0)
        ->assertSet('grantConfirmOpen', true);

    // The organization-wide scope is taken away while the dialog is open.
    // The next request reads the role afresh; here the signed-in model
    // must drop the role it has loaded.
    Role::query()->whereKey($admin->role_id)->update(['all_data' => false]);
    $admin->unsetRelation('role');
    app()->forgetInstance(Access::class);

    // The refused confirm still closes the dialog and forgets the grant.
    $page->call('confirmGrant')
        ->assertSet('grantConfirmOpen', false)
        ->assertSet('grantRoleId', null);

    expect(RoleModuleGrant::query()->where('role_id', $role->getKey())->exists())->toBeFalse();
});

it('keeps the confirmation on the first box when a second is ticked before it arrives', function () {
    usersActor(['USERS'], true);
    $role = usersRole([], false);

    // Both ticks were made on the same drawing of the matrix: the second was
    // clicked while the first request was in flight, so it carries nonce 0.
    $page = Livewire::test(Index::class)
        ->call('askGrant', (string) $role->getKey(), 'LEAVES', true, 0)
        ->call('askGrant', (string) $role->getKey(), 'PAYROLL', true, 0)
        ->assertSet('grantConfirmOpen', true)
        ->assertSet('grantModule', 'LEAVES')
        ->assertSet('grantNonce', 2)
        ->assertSee('Give '.$role->name.' access to Leave Management?');

    $page->call('confirmGrant');

    expect(RoleModuleGrant::query()->where('role_id', $role->getKey())->pluck('module')->all())->toBe(['LEAVES']);
    expect(
        AuditLog::query()->where('entity', 'role_module_grants')->where('entity_id', (string) $role->getKey())->count()
    )->toBe(1);
});

it('asks about the new box when one is ticked after the dialog was dismissed in the browser', function () {
    usersActor(['USERS'], true);
    $role = usersRole([], false);

    // Escape closes the dialog without telling the server; the matrix it
    // returns to was drawn with nonce 1, so its next tick counts.
    $page = Livewire::test(Index::class)
        ->call('askGrant', (string) $role->getKey(), 'LEAVES', true, 0)
        ->call('askGrant', (string) $role->getKey(), 'PAYROLL', true, 1)
        ->assertSet('grantConfirmOpen', true)
        ->assertSet('grantModule', 'PAYROLL')
        ->assertSee('Give '.$role->name.' access to Payroll?');

    $page->call('confirmGrant');

    expect(RoleModuleGrant::query()->where('role_id', $role->getKey())->pluck('module')->all())->toBe(['PAYROLL']);
});

it('writes the state the confirmation named when the grant changed meanwhile', function (bool $asked) {
    usersActor(['USERS'], true);
    $role = usersRole($asked ? [] : ['LEAVES'], false);
    $stored = RoleModuleGrant::query()->where('role_id', $role->getKey())->where('module', 'LEAVES');

    $page = Livewire::test(Index::class)
        ->call('askGrant', (string) $role->getKey(), 'LEAVES', $asked, 0);

    // Someone else makes the same change while the dialog is open.
    if ($asked) {
        (new RoleModuleGrant)->forceFill(['role_id' => $role->getKey(), 'module' => 'LEAVES'])->save();
    } else {
        (clone $stored)->delete();
    }

    $page->call('confirmGrant');

    // The confirm sets the named state; it never toggles what it finds.
    expect((clone $stored)->exists())->toBe($asked);
    expect(
        AuditLog::query()->where('entity', 'role_module_grants')->where('entity_id', (string) $role->getKey())->count()
    )->toBe(0);
})->with(['a grant' => [true], 'a removal' => [false]]);

it('shows a confirmed change on the matrix at once', function () {
    usersActor(['USERS'], true);
    $role = usersRole([], false);
    $id = (string) $role->getKey();

    $html = html_entity_decode(
        Livewire::test(Index::class)
            ->set('tab', 'roles')
            ->call('askGrant', $id, 'LEAVES', true, 0)
            ->call('confirmGrant')
            ->assertNoRedirect()
            ->html(),
        ENT_QUOTES,
    );

    // The box is drawn granted in the same response: its next tick asks to remove.
    expect($html)->toContain("askGrant('{$id}', 'LEAVES', false, 2)");
});

it('redraws the page after a change to your own role, so the sidebar follows', function (bool $asked) {
    $admin = usersActor($asked ? ['USERS'] : ['USERS', 'LEAVES'], true);
    $ownRoleId = (string) $admin->role_id;

    Livewire::test(Index::class)
        ->call('askGrant', $ownRoleId, 'LEAVES', $asked, 0)
        ->call('confirmGrant')
        ->assertRedirect(route('users.index', ['tab' => 'roles']));

    expect(
        RoleModuleGrant::query()->where('role_id', $ownRoleId)->where('module', 'LEAVES')->exists()
    )->toBe($asked);
})->with(['a grant' => [true], 'a removal' => [false]]);

it('opens on the tab the address names, and on Accounts for anything else', function (string $named, string $shown) {
    usersActor(['USERS'], true);

    Livewire::withQueryParams(['tab' => $named]);

    Livewire::test(Index::class)->assertSet('tab', $shown);
})->with([
    'roles' => ['roles', 'roles'],
    'accounts' => ['accounts', 'accounts'],
    'unknown' => ['payroll', 'accounts'],
]);

it('wires each box to the ask and only the dialog button to the write', function () {
    usersActor(['USERS'], true);
    $role = usersRole(['LEAVES'], false);
    $id = (string) $role->getKey();

    // Blade encodes the quotes inside a component attribute.
    $html = html_entity_decode(Livewire::test(Index::class)->set('tab', 'roles')->html(), ENT_QUOTES);

    // Each box asks for the opposite of what is stored, with the nonce it was drawn with.
    expect($html)->toContain("askGrant('{$id}', 'LEAVES', false, 0)");
    expect($html)->toContain("askGrant('{$id}', 'PAYROLL', true, 0)");
    expect($html)->toContain('wire:click="confirmGrant"');
    expect($html)->not->toContain('toggleGrant(');
});

it('leaves no one-click grant action and no client-writable staged grant', function () {
    usersActor(['USERS'], true);
    $role = usersRole([], false);

    // The screen has no one-click grant action…
    expect(method_exists(Index::class, 'toggleGrant'))->toBeFalse();

    // …and the staged grant cannot be planted from the browser.
    expect(fn () => Livewire::test(Index::class)->set('grantRoleId', (string) $role->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => Livewire::test(Index::class)->set('grantModule', 'LEAVES'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => Livewire::test(Index::class)->set('grantOn', true))
        ->toThrow(CannotUpdateLockedPropertyException::class);
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

/*
|--------------------------------------------------------------------------
| The name of an account with no employee record
|--------------------------------------------------------------------------
*/

it('names an account that has no employee record, and requires that name on the New account form', function () {
    usersActor(['USERS'], true);
    $role = usersRole(['PORTAL'], false);

    $form = Livewire::test(Index::class)
        ->call('openCreate')
        ->set('createEmail', 'auditor@example.com')
        ->set('createPassword', 'Temporary-Password#2026')
        ->call('createRoleChanged', (string) $role->getKey())
        ->call('createAccount')
        ->assertSet('adding', true)
        ->assertSet('createNameError', 'Enter a name for the account, or link an employee record.');

    expect(User::query()->where('email', 'auditor@example.com')->exists())->toBeFalse();

    // A refused attempt drops a password typed by hand, so it is typed again.
    $form->set('createName', '  Outside Auditor  ')
        ->set('createPassword', 'Temporary-Password#2026')
        ->call('createAccount')
        ->assertSet('adding', false);

    $created = User::query()->where('email', 'auditor@example.com')->firstOrFail();

    expect($created->employee_id)->toBeNull()
        ->and($created->account_name)->toBe('Outside Auditor')
        ->and($created->name)->toBe('Outside Auditor')
        ->and(Access::forActor($created)->fullName)->toBe('Outside Auditor')
        ->and(AuditLog::query()->where('entity_id', (string) $created->id)->where('details', 'Outside Auditor (auditor@example.com) provisioned')->exists())->toBeTrue();

    // The accounts table and the edit dialog read the same name.
    Livewire::test(Index::class)
        ->assertSee('Outside Auditor')
        ->call('openAccount', (string) $created->id)
        ->assertSet('accountName', 'Outside Auditor');
});

it('fills the name from the employee record when one is linked, and stores no typed name for it', function () {
    usersActor(['USERS'], true);
    $role = usersRole(['PORTAL'], false);
    $employee = usersEmployee('COP-00041', 'Liza', 'Domingo');

    Livewire::test(Index::class)
        ->call('openCreate')
        ->set('createName', 'Typed By Mistake')
        ->call('createEmployeeChanged', (string) $employee->getKey())
        ->assertSeeHtml('value="Liza Domingo"')
        ->set('createEmail', 'liza.linked@example.com')
        ->set('createPassword', 'Temporary-Password#2026')
        ->call('createRoleChanged', (string) $role->getKey())
        ->call('createAccount')
        ->assertSet('adding', false);

    $created = User::query()->where('email', 'liza.linked@example.com')->firstOrFail();

    expect($created->employee_id)->toBe($employee->getKey())
        ->and($created->account_name)->toBeNull()
        ->and($created->name)->toBe('Liza Domingo');
});

it('renames an unlinked account, clears the typed name when an employee is linked, and falls back to the number', function () {
    usersActor(['USERS'], true);
    $employee = usersEmployee('COP-00042', 'Grace', 'Mendoza');
    $target = User::factory()->create(['email' => 'service@example.com', 'role_id' => usersRole(['PORTAL'], false)->getKey()]);

    expect($target->name)->toBe('Account #'.$target->id);

    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->id)
        ->assertSet('accountName', '')
        ->set('accountName', 'Service Desk')
        ->call('saveAccount')
        ->assertSet('accountOriginal.name', 'Service Desk');

    expect($target->fresh()->account_name)->toBe('Service Desk')
        ->and($target->fresh()->name)->toBe('Service Desk');

    // Linking an employee hands the name to the record and drops the typed one.
    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->id)
        ->call('accountEmployeeChanged', (string) $employee->getKey())
        ->assertSeeHtml('value="Grace Mendoza"')
        ->call('saveAccount');

    expect($target->fresh()->account_name)->toBeNull()
        ->and($target->fresh()->name)->toBe('Grace Mendoza');

    // Unlinked again with no name typed, the account reads by its number.
    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->id)
        ->call('accountEmployeeChanged', '')
        ->call('saveAccount');

    expect($target->fresh()->account_name)->toBeNull()
        ->and($target->fresh()->name)->toBe('Account #'.$target->id);
});

it('keeps the account name rules in the command, without the page', function () {
    $actor = Access::forActor(usersActor(['USERS'], true));
    $role = usersRole(['PORTAL'], false);
    $employee = usersEmployee('COP-00043', 'Jose', 'Rivera');
    $accounts = app(ManageAccounts::class);

    expect(fn () => $accounts->create($actor, 'long@example.com', 'Temporary-Password#2026', (string) $role->getKey(), '', str_repeat('x', 151)))
        ->toThrow(RuntimeException::class, 'Name must be at most 150 characters.');
    expect(User::query()->where('email', 'long@example.com')->exists())->toBeFalse();

    // A name sent with a link is dropped; a caller that sends none still works.
    $accounts->create($actor, 'linked@example.com', 'Temporary-Password#2026', (string) $role->getKey(), (string) $employee->getKey(), 'Ignored');
    $accounts->create($actor, 'unnamed@example.com', 'Temporary-Password#2026', (string) $role->getKey(), '');

    $linked = User::query()->where('email', 'linked@example.com')->firstOrFail();
    $unnamed = User::query()->where('email', 'unnamed@example.com')->firstOrFail();

    expect($linked->account_name)->toBeNull()
        ->and($linked->name)->toBe('Jose Rivera')
        ->and($unnamed->name)->toBe('Account #'.$unnamed->id);

    // An update that does not mention the name leaves it as it is.
    $accounts->update($actor, (string) $unnamed->id, 'unnamed@example.com', (string) $role->getKey(), '', true, 'Front Desk');
    $accounts->update($actor, (string) $unnamed->id, 'renamed@example.com', (string) $role->getKey(), '', true);

    expect($unnamed->fresh()->account_name)->toBe('Front Desk')
        ->and(app(AccountNames::class)->list($actor, [$unnamed->id])->first()->name)->toBe('Front Desk');
});

it('adds the account name column only once, so a killed run resumes', function () {
    $migration = require base_path('database/migrations/2026_11_05_0002_add_account_name_to_users_table.php');

    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('users', 'account_name'))->toBeTrue();

    $migration->down();
    $migration->down();
    expect(Schema::hasColumn('users', 'account_name'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('users', 'account_name'))->toBeTrue();
});
