<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Users\Index as UsersIndex;
use App\Livewire\Users\Support\RolesExport;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\IdentityAccess\Application\Commands\ManageRoles;
use App\Platform\Notifications\NotificationRecipients;
use App\Support\Access;
use App\Support\ModuleRegistry;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * A MODULE CAN BE TURNED OFF FOR THE WHOLE INSTALLATION by naming its code in
 * `copems.modules_off` (BESTWORKFLOWS_MODULES_OFF). `ModuleRegistry::off()`
 * is the one reader, and `Access` drops those codes from every actor's
 * grants, so an off module: is in nobody's sidebar and is never a landing
 * page; its routes turn a holder away exactly as they turn away a role
 * without the grant; every action's own check fails; and User Management's
 * roles table has no column for it and cannot change its grant. The stored
 * grants are never touched — taking the code off the list gives every role
 * back what it had. An unknown code is ignored, and USERS and PROFILE can
 * never be turned off. `config/modules.php` and `ModuleCode::all()` are the
 * same whatever is off.
 */

/** @param list<string> $modules */
function modoffRole(array $modules, bool $allData = false): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'ModOff '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }

    return $role;
}

/** @param list<string> $modules */
function modoffActor(array $modules, bool $allData = false): User
{
    $user = User::factory()->create(['role_id' => modoffRole($modules, $allData)->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

/** Turns modules off (or, with '', all of them back on) as the setting would, for the next request. */
function modoffSet(string $codes): void
{
    config(['copems.modules_off' => $codes]);
    app()->forgetInstance(Access::class);
}

/**
 * User Management opened on its Roles tab, as the signed-in actor.
 *
 * @return Testable<UsersIndex>
 */
function modoffRolesTab(): Testable
{
    Livewire::withQueryParams(['tab' => 'roles']);

    return Livewire::test(UsersIndex::class);
}

/** @return list<string> */
function modoffStoredGrants(User $user): array
{
    $modules = [];
    foreach (RoleModuleGrant::query()->where('role_id', $user->role_id)->orderBy('module')->get() as $grant) {
        $modules[] = (string) $grant->module;
    }

    return $modules;
}

/*
|--------------------------------------------------------------------------
| The setting
|--------------------------------------------------------------------------
*/

it('reads the list of modules turned off: known codes only, in module order, never USERS or PROFILE', function (string $setting, array $off) {
    config(['copems.modules_off' => $setting]);

    expect(app(ModuleRegistry::class)->off())->toBe($off);
})->with([
    'nothing set' => ['', []],
    'one module' => ['PROJECTS', ['PROJECTS']],
    'several, any case, spaced, out of order' => [' tasks , Projects,INVENTORY ', ['PROJECTS', 'INVENTORY', 'TASKS']],
    'a code that is not a module is ignored' => ['PROJECTS,TIMESHEETS,,', ['PROJECTS']],
    'USERS and PROFILE cannot be turned off' => ['USERS,PROFILE,CHAT', ['CHAT']],
    'only the two protected ones' => ['USERS, PROFILE', []],
]);

it('keeps the registry and the module list whole, and lists the running modules without the ones off', function () {
    modoffSet('PROJECTS,INVENTORY');
    $registry = app(ModuleRegistry::class);

    expect(array_column($registry->all(), 'code'))->toBe(ModuleCode::all())
        ->and(array_column($registry->enabled(), 'code'))->toBe(array_values(array_diff(ModuleCode::all(), ['PROJECTS', 'INVENTORY'])))
        ->and($registry->isOff('PROJECTS'))->toBeTrue()
        ->and($registry->isOff('EMPLOYEES'))->toBeFalse()
        ->and($registry->withoutOff(['EMPLOYEES', 'PROJECTS', 'PROFILE']))->toBe(['EMPLOYEES', 'PROFILE']);
});

/*
|--------------------------------------------------------------------------
| Navigation and the landing page
|--------------------------------------------------------------------------
*/

it('takes an off module out of the sidebar of a role that holds it', function () {
    modoffActor(['EMPLOYEES', 'PROJECTS', 'PROFILE'], allData: true);

    $this->get('/employees')->assertOk()->assertSee('title="Project Management"', false);

    modoffSet('PROJECTS');

    $this->get('/employees')->assertOk()->assertDontSee('title="Project Management"', false)->assertDontSee('/projects?section=overview', false);
    expect(array_column(app(ModuleRegistry::class)->navFor(app(Access::class)->modules), 'code'))->toBe(['EMPLOYEES']);
});

it('never lands a role on an off module: the next module it holds, or the no-modules page', function () {
    modoffActor(['PROJECTS', 'PAYROLL', 'PROFILE'], allData: true);
    $this->get('/dashboard')->assertRedirect('/projects');

    modoffSet('PROJECTS');
    $this->get('/dashboard')->assertRedirect('/payroll');

    modoffSet('PROJECTS,PAYROLL');
    $this->get('/dashboard')->assertRedirect('/profile');

    // A role whose only module is off is a role granted nothing.
    modoffActor(['PROJECTS']);
    modoffSet('PROJECTS');
    $this->get('/dashboard')->assertOk()->assertSee('No modules assigned');
});

it('draws nothing for an off module even from raw grants, nor a tree entry that needs its grant', function () {
    modoffSet('PAYROLL');
    $registry = app(ModuleRegistry::class);

    // Payroll Settings sits in the Records tree for a PAYROLL holder.
    expect($registry->navFor(['PAYROLL', 'PROFILE']))->toBe([])
        ->and($registry->landingPath(['PAYROLL', 'PROFILE']))->toBe('/profile')
        ->and(array_column($registry->grantedFor(['PAYROLL', 'PROFILE']), 'code'))->toBe(['PROFILE']);

    modoffSet('');
    expect(array_column($registry->navFor(['PAYROLL', 'PROFILE']), 'code'))->toBe(['PAYROLL', 'RECORDS']);
});

/*
|--------------------------------------------------------------------------
| Routes and checks
|--------------------------------------------------------------------------
*/

it('turns a holder away from an off module’s pages exactly as it turns away a role without the grant', function () {
    // The role without the grant, with everything on.
    modoffActor(['EMPLOYEES', 'PROFILE'], allData: true);
    $withoutGrant = $this->get('/projects');
    $withoutGrant->assertRedirect('/employees');
    $this->getJson('/projects')->assertForbidden();

    // The role that holds it, with it off: the same answers.
    modoffActor(['EMPLOYEES', 'PROJECTS', 'PROFILE'], allData: true);
    $this->get('/projects')->assertOk();
    modoffSet('PROJECTS');
    $this->get('/projects')->assertRedirect('/employees');
    $this->get('/projects/clients')->assertRedirect('/employees');
    $this->getJson('/projects')->assertForbidden()->assertJsonPath('message', 'You do not have access to that.');

    // Its other modules are untouched.
    $this->get('/employees')->assertOk();
});

it('fails every check on an off module, for an all-data holder too', function () {
    $user = modoffActor(['PROJECTS', 'TASKS', 'USERS'], allData: true);
    modoffSet('PROJECTS');
    $access = Access::forActor($user);

    expect($access->modules)->toEqualCanonicalizing(['TASKS', 'USERS'])
        ->and($access->hasModule(ModuleCode::PROJECTS))->toBeFalse()
        ->and($access->mayReadAllData(ModuleCode::PROJECTS))->toBeFalse()
        ->and($access->rowVisibility(ModuleCode::PROJECTS))->toBe(Access::ROWS_NONE)
        // Held with another module, the other still passes.
        ->and($access->hasModule(ModuleCode::PROJECTS, ModuleCode::TASKS))->toBeTrue();
});

it('tells no manager of an off module about its work', function () {
    $manager = modoffActor(['PROJECTS', 'LEAVES'], allData: true);
    $recipients = app(NotificationRecipients::class);

    expect($recipients->moduleHolders(ModuleCode::PROJECTS, null))->toBe([(int) $manager->id]);

    modoffSet('PROJECTS');
    expect($recipients->moduleHolders(ModuleCode::PROJECTS, null))->toBe([])
        ->and($recipients->moduleHolders(ModuleCode::LEAVES, null))->toBe([(int) $manager->id]);
    foreach ($recipients->accounts([(int) $manager->id]) as $account) {
        expect($account->modules)->toBe(['LEAVES']);
    }
});

/*
|--------------------------------------------------------------------------
| User Management's roles table
|--------------------------------------------------------------------------
*/

it('leaves an off module out of the roles table and its CSV, and keeps every stored grant', function () {
    $admin = modoffActor(['USERS', 'PROJECTS', 'INVENTORY', 'PROFILE'], allData: true);
    $before = modoffStoredGrants($admin);

    $page = modoffRolesTab();
    // Every module has a column, and each box names its module in its action.
    expect(array_column($page->viewData('modules'), 'code'))->toBe(ModuleCode::all())
        ->and($page->html())->toContain('Project Management')
        ->and(preg_match('/askGrant\([^)]*PROJECTS/', $page->html()))->toBe(1);

    modoffSet('PROJECTS');
    $page = modoffRolesTab();
    $codes = array_column($page->viewData('modules'), 'code');
    expect($codes)->not->toContain('PROJECTS')
        ->and($codes)->toContain('INVENTORY')
        ->and($page->html())->not->toContain('Project Management')
        ->and(preg_match('/askGrant\([^)]*PROJECTS/', $page->html()))->toBe(0)
        ->and(preg_match('/askGrant\([^)]*INVENTORY/', $page->html()))->toBe(1);

    $export = app(RolesExport::class)->export(Access::forActor($admin), []);
    expect($export->headers)->not->toContain('Project Management')
        ->and($export->headers)->toContain('Inventory')
        ->and(count(iterator_to_array(($export->rows)(), false)[0]))->toBe(count($export->headers));

    expect(modoffStoredGrants($admin))->toBe($before);
});

it('refuses to give or remove an off module’s grant, on the screen and in the command', function () {
    $admin = modoffActor(['USERS', 'PROJECTS', 'PROFILE'], allData: true);
    $other = modoffRole(['EMPLOYEES']);
    modoffSet('PROJECTS');
    $grants = RoleModuleGrant::query()->count();
    $audits = AuditLog::query()->count();

    // A forged tick: the box is not on the matrix, so nothing is staged.
    $page = modoffRolesTab();
    $page->call('askGrant', (string) $admin->role_id, 'PROJECTS', false, $page->get('grantNonce'))
        ->assertSet('grantConfirmOpen', false);
    $page->call('askGrant', (string) $other->id, 'PROJECTS', true, $page->get('grantNonce'))
        ->assertSet('grantConfirmOpen', false);

    // The command says why, whichever way the grant would go.
    $access = Access::forActor($admin);
    expect(fn () => app(ManageRoles::class)->toggleGrant($access, (string) $admin->role_id, 'PROJECTS', false))
        ->toThrow(RuntimeException::class, 'Project Management is turned off for this installation')
        ->and(fn () => app(ManageRoles::class)->toggleGrant($access, (string) $other->id, 'PROJECTS', true))
        ->toThrow(RuntimeException::class, 'Project Management is turned off for this installation');

    expect(RoleModuleGrant::query()->count())->toBe($grants)
        ->and(AuditLog::query()->count())->toBe($audits)
        ->and(modoffStoredGrants($admin))->toBe(['PROFILE', 'PROJECTS', 'USERS']);

    // A module that is on is still granted as before.
    app(ManageRoles::class)->toggleGrant($access, (string) $other->id, 'INVENTORY', true);
    expect(RoleModuleGrant::query()->where('role_id', $other->id)->where('module', 'INVENTORY')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Turning it back on, and what cannot be turned off
|--------------------------------------------------------------------------
*/

it('gives every role its access back when the module is turned on again', function () {
    $user = modoffActor(['EMPLOYEES', 'PROJECTS', 'PROFILE'], allData: true);

    modoffSet('PROJECTS');
    $this->get('/projects')->assertRedirect('/employees');
    expect(modoffStoredGrants($user))->toBe(['EMPLOYEES', 'PROFILE', 'PROJECTS']);

    modoffSet('');
    $this->get('/projects')->assertOk();
    expect(Access::forActor($user)->mayReadAllData(ModuleCode::PROJECTS))->toBeTrue()
        ->and(array_column(app(ModuleRegistry::class)->navFor(Access::forActor($user)->modules), 'code'))->toBe(['EMPLOYEES', 'PROJECTS']);
});

it('never turns User Management or My Profile off, whatever the setting names', function () {
    $admin = modoffActor(['USERS', 'PROFILE'], allData: true);
    modoffSet('USERS,PROFILE');

    expect(Access::forActor($admin)->modules)->toEqualCanonicalizing(['USERS', 'PROFILE'])
        ->and(Access::forActor($admin)->mayReadAllData(ModuleCode::USERS))->toBeTrue();
    $this->get('/users')->assertOk();
    $this->get('/profile')->assertOk();

    $codes = array_column(modoffRolesTab()->viewData('modules'), 'code');
    expect($codes)->toContain('USERS')->and($codes)->toContain('PROFILE');
});
