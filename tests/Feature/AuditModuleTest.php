<?php

declare(strict_types=1);

use App\Livewire\Audit\Index;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Audit module: /audit gates on the AUDIT grant; the read reproduces
 * `requireAllDataModule("AUDIT")` (audit/route.ts:15) — an actor without the
 * all-data scope reads ZERO rows, exactly as the reference page rendered over
 * the API's 403; and the table lists entries newest first with the reference's
 * filters, search and "System" actor fallback.
 */

/**
 * A role holding the given modules. Helpers are audit-prefixed: Pest loads every test file into one process.
 *
 * @param  list<string>  $modules  ModuleCode strings, one RoleModuleGrant each
 */
function auditRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Audit '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/**
 * A signed-in user on a fresh role holding the given modules.
 *
 * @param  list<string>  $modules  ModuleCode strings passed through to auditRole()
 */
function auditActor(array $modules, bool $allData = false, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => auditRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);

    return $user;
}

/**
 * An audit row written the way only the trail itself may be written in tests:
 * the actor columns are not mass-assignable, so forceFill stands in for the
 * single write path of AuditService.
 */
function auditEntry(
    string $action,
    ?string $entity,
    ?string $details,
    string $actorName = 'System',
    string $at = '2026-08-30 01:00:00',
): AuditLog {
    $log = new AuditLog;
    $log->forceFill([
        'actor_id' => null,
        'actor_name' => $actorName,
        'action' => $action,
        'entity' => $entity,
        'entity_id' => null,
        'details' => $details,
        'created_at' => CarbonImmutable::parse($at, 'UTC'),
    ])->save();

    return $log;
}

/*
|--------------------------------------------------------------------------
| Route gating
|--------------------------------------------------------------------------
*/

it('sends a guest to the login page', function () {
    $this->get('/audit')->assertRedirect(route('login'));
});

it('turns away an actor whose role lacks the AUDIT module', function () {
    auditActor(['PORTAL']);

    $this->get('/audit')->assertRedirect();
});

it('renders for an actor holding AUDIT with the all-data scope', function () {
    auditActor(['AUDIT'], allData: true);

    $this->get('/audit')
        ->assertOk()
        ->assertSee('Audit Trail')
        ->assertSee('Append-only. Entries can never be edited or deleted from the app.');
});

/*
|--------------------------------------------------------------------------
| The all-data read gate (requireAllDataModule("AUDIT"))
|--------------------------------------------------------------------------
*/

it('shows an actor without all_data zero rows, even holding the module', function () {
    auditEntry('LOGIN_SUCCESS', 'user_accounts', 'Maria Reyes signed in', 'Maria Reyes');

    auditActor(['AUDIT']); // holds the module, no all_data, no employee link

    Livewire::test(Index::class)
        ->assertDontSee('Maria Reyes signed in')
        ->assertSee('No entry matches those filters.');
});

it('shows an employee-less all-data actor the full trail (the gate is scope, not an employee link)', function () {
    auditEntry('LOGIN_SUCCESS', 'user_accounts', 'Maria Reyes signed in', 'Maria Reyes');

    auditActor(['AUDIT'], allData: true); // no employee link on purpose

    Livewire::test(Index::class)
        ->assertSee('Maria Reyes signed in');
});

/*
|--------------------------------------------------------------------------
| The table: newest first, filters, search, actor fallback
|--------------------------------------------------------------------------
*/

it('lists entries newest first by default', function () {
    auditEntry('EMPLOYEE_CREATED', 'employee_profiles', 'Employee Liza Domingo created', 'Ana Cruz', at: '2026-08-29 01:00:00');
    auditEntry('LOGIN_SUCCESS', 'user_accounts', 'Maria Reyes signed in', 'Maria Reyes', at: '2026-08-30 01:00:00');

    auditActor(['AUDIT'], allData: true);

    Livewire::test(Index::class)
        ->assertSeeInOrder(['Maria Reyes signed in', 'Employee Liza Domingo created']);
});

it('narrows by the action filter and resets to page 1', function () {
    auditEntry('EMPLOYEE_CREATED', 'employee_profiles', 'Employee Liza Domingo created', 'Ana Cruz');
    auditEntry('LOGIN_SUCCESS', 'user_accounts', 'Maria Reyes signed in', 'Maria Reyes');

    auditActor(['AUDIT'], allData: true);

    Livewire::test(Index::class)
        ->set('action', 'Signed in')
        ->assertSet('paginators.page', 1)
        ->assertSee('Maria Reyes signed in')
        ->assertDontSee('Employee Liza Domingo created');
});

it('shows Action as plain words and Entity as words, keeping the action code in its title', function () {
    auditEntry('REQUEST_REJECTED', 'leave_travel_requests', 'Sick Leave for Liza Domingo', 'Ana Cruz');
    auditEntry('LEGACY_THING_DONE', 'legacy_rows', 'An older entry', 'Ana Cruz');

    auditActor(['AUDIT'], allData: true);

    Livewire::test(Index::class)
        ->assertSeeHtml('<span title="REQUEST_REJECTED">Leave/OB request disapproved</span>')
        ->assertDontSeeHtml('pill-info')
        ->assertSeeHtml('>Leave/OB request</td>')
        ->assertSeeHtml('>Legacy thing done</span>')
        ->assertSeeHtml('>Legacy rows</td>')
        ->assertDontSeeHtml('>leave_travel_requests<')
        ->assertViewHas('actions', ['Leave/OB request disapproved', 'Legacy thing done'])
        ->assertViewHas('entities', ['Leave/OB request', 'Legacy rows']);
});

it('offers one entity choice for tables that share a label, and filters by it', function () {
    auditEntry('BOOTSTRAP_ADMIN', 'users', 'First admin created', 'System');
    auditEntry('LOGIN_SUCCESS', 'user_accounts', 'Maria Reyes signed in', 'Maria Reyes');
    auditEntry('HOLIDAY_CREATED', 'holidays', 'Rizal Day added', 'Ana Cruz');

    auditActor(['AUDIT'], allData: true);

    $page = Livewire::test(Index::class);
    $page->assertViewHas('entities', ['Holiday', 'User account']);

    $page->set('entity', 'User account')
        ->assertSee('First admin created')
        ->assertSee('Maria Reyes signed in')
        ->assertDontSee('Rizal Day added');
});

it('finds an entry by its action label as well as its code', function () {
    auditEntry('REQUEST_REJECTED', 'leave_travel_requests', 'Sick Leave for Liza Domingo', 'Ana Cruz');
    auditEntry('HOLIDAY_CREATED', 'holidays', 'Rizal Day added', 'Ana Cruz');

    auditActor(['AUDIT'], allData: true);

    Livewire::test(Index::class)
        ->set('search', 'disapproved')
        ->assertSee('Sick Leave for Liza Domingo')
        ->assertDontSee('Rizal Day added')
        ->set('search', 'holiday_created')
        ->assertSee('Rizal Day added')
        ->assertDontSee('Sick Leave for Liza Domingo');
});

it('sorts the Action column by the label it shows', function () {
    // By code SITE_CREATED < USER_CREATED; by label "Account created" < "Worksite added".
    auditEntry('SITE_CREATED', 'work_sites', 'Makati added', 'Ana Cruz');
    auditEntry('USER_CREATED', 'user_accounts', 'Account for Liza created', 'Ana Cruz');

    auditActor(['AUDIT'], allData: true);

    Livewire::test(Index::class)
        ->call('sortBy', 'action')
        ->assertSet('sortDir', 'asc')
        ->assertSeeInOrder(['Account for Liza created', 'Makati added']);
});

it('searches action, actor and details as one needle', function () {
    auditEntry('EMPLOYEE_CREATED', 'employee_profiles', 'Employee Liza Domingo created', 'Ana Cruz');
    auditEntry('LOGIN_SUCCESS', 'user_accounts', 'Maria Reyes signed in', 'Maria Reyes');

    auditActor(['AUDIT'], allData: true);

    Livewire::test(Index::class)
        ->set('search', 'liza domingo')
        ->assertSee('Employee Liza Domingo created')
        ->assertDontSee('Maria Reyes signed in');
});

it('prints and filters a blank actor as "System"', function () {
    auditEntry('LOGIN_FAILED', 'user_accounts', 'Failed sign-in for ghost@example.com', 'System');
    auditEntry('LOGIN_SUCCESS', 'user_accounts', 'Maria Reyes signed in', 'Maria Reyes');

    auditActor(['AUDIT'], allData: true);

    Livewire::test(Index::class)
        ->set('actor', 'System')
        ->assertSee('Failed sign-in for ghost@example.com')
        ->assertDontSee('Maria Reyes signed in');
});
