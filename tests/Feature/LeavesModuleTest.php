<?php

declare(strict_types=1);

use App\Livewire\Leaves\Index;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveTravelRequest;
use App\Models\Notification;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ScheduleFixture;

/**
 * The Leaves module: /leaves gates on the LEAVES grant, the scoped list is
 * EMPTY for an employee-less actor (never IS NULL rows), and the review flow —
 * approve with/without pay, official business, reject — lands the right
 * status, is_paid tri-state, actor columns, audit row and notification.
 */

/**
 * A role holding the given modules. Helpers are leaves-prefixed: Pest loads every test file into one process.
 *
 * @param  list<string>  $modules  ModuleCode values to grant
 */
function leavesRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Leaves '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/**
 * @param  list<string>  $modules  ModuleCode values to grant
 */
function leavesActor(array $modules, bool $allData = false, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => leavesRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);

    return $user;
}

function leavesEmployee(string $code, string $first, string $last): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => $first,
        'last_name' => $last,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    ScheduleFixture::standard($employee);

    return $employee;
}

/**
 * @param  array<string, string>  $overrides  column => value, merged over the pending SICK defaults
 */
function leavesRequest(EmployeeProfile $employee, string $code, array $overrides = []): LeaveTravelRequest
{
    $request = new LeaveTravelRequest;
    $request->forceFill(array_merge([
        'code' => $code,
        'employee_id' => $employee->getKey(),
        'request_type' => 'SICK',
        'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-08-10',
        'end_date' => '2026-08-11',
        'reason' => 'Flu',
        'status' => 'PENDING',
    ], $overrides))->save();

    return $request;
}

/*
|--------------------------------------------------------------------------
| Route gating
|--------------------------------------------------------------------------
*/

it('sends a guest to the login page', function () {
    $this->get('/leaves')->assertRedirect(route('login'));
});

it('turns away an actor whose role lacks the LEAVES module', function () {
    leavesActor(['PORTAL']);

    $this->get('/leaves')->assertRedirect();
});

it('renders for an actor holding LEAVES', function () {
    leavesActor(['LEAVES'], allData: true);

    $this->get('/leaves')
        ->assertOk()
        ->assertSee('Leave Management');
});

/*
|--------------------------------------------------------------------------
| Row scoping
|--------------------------------------------------------------------------
*/

it('shows an employee-less actor zero rows, never IS NULL rows', function () {
    $employee = leavesEmployee('EMP-001', 'Liza', 'Domingo');
    leavesRequest($employee, 'LR-001');

    leavesActor(['LEAVES']); // holds the module, no all_data, no employee link

    Livewire::test(Index::class)
        ->assertDontSee('LR-001')
        ->assertSee('No request matches those filters.');
});

it('refuses to open a request outside the actor\'s visibility', function () {
    $employee = leavesEmployee('EMP-001', 'Liza', 'Domingo');
    $request = leavesRequest($employee, 'LR-001');

    leavesActor(['LEAVES']);

    Livewire::test(Index::class)
        ->call('show', $request->getKey())
        ->assertSet('openId', null)
        ->assertSet('detailOpen', false);
});

it('shows an all_data holder every request', function () {
    $employee = leavesEmployee('EMP-001', 'Liza', 'Domingo');
    leavesRequest($employee, 'LR-001');

    leavesActor(['LEAVES'], allData: true);

    Livewire::test(Index::class)
        ->assertSee('LR-001')
        ->assertSee('Liza Domingo');
});

it('shows the Manila day and time each request was filed and sorts by it', function () {
    $employee = leavesEmployee('EMP-001', 'Liza', 'Domingo');
    // 17:00 UTC on Aug 3 is 1:00 AM on Aug 4 in Manila; 02:00 UTC is 10:00 AM.
    leavesRequest($employee, 'LR-001', ['created_at' => '2026-08-03 17:00:00.000']);
    leavesRequest($employee, 'LR-002', ['created_at' => '2026-08-01 02:00:00.000']);

    leavesActor(['LEAVES'], allData: true);

    $page = Livewire::test(Index::class)
        ->assertDontSee('Aug 03, 2026');
    $page->assertSeeInOrder(['Date Filed', 'Leave Period']);
    $page->assertSeeInOrder(['LR-001', 'Aug 04, 2026', '1:00 AM', 'LR-002', 'Aug 01, 2026', '10:00 AM']);

    $page->call('sortBy', 'filed');
    $page->assertSeeInOrder(['LR-002', 'Aug 01, 2026', '10:00 AM', 'LR-001', 'Aug 04, 2026', '1:00 AM']);

    $page->call('sortBy', 'filed');
    $page->assertSeeInOrder(['LR-001', 'Aug 04, 2026', '1:00 AM', 'LR-002', 'Aug 01, 2026', '10:00 AM']);
});

it('opens with the newest filed request on top, and exports in that order when the link names no sort', function () {
    $employee = leavesEmployee('EMP-001', 'Liza', 'Domingo');
    // Ids and filing order disagree, so only the filed sort reads 2, 3, 1.
    leavesRequest($employee, 'LR-001', ['created_at' => '2026-08-01 02:00:00.000']);
    leavesRequest($employee, 'LR-002', ['created_at' => '2026-08-05 02:00:00.000']);
    leavesRequest($employee, 'LR-003', ['created_at' => '2026-08-03 02:00:00.000']);

    leavesActor(['LEAVES'], allData: true);

    Livewire::test(Index::class)
        ->assertSet('sortKey', 'filed')
        ->assertSet('sortDir', 'desc')
        ->assertSeeInOrder(['LR-002', 'LR-003', 'LR-001']);

    $csv = test()->get('/leaves/export')->assertOk()->streamedContent();
    expect(strpos($csv, 'LR-002'))->toBeLessThan((int) strpos($csv, 'LR-003'))
        ->and(strpos($csv, 'LR-003'))->toBeLessThan((int) strpos($csv, 'LR-001'));
});

/*
|--------------------------------------------------------------------------
| The review flow
|--------------------------------------------------------------------------
*/

it('approves a pending leave with pay, recording actor, audit row and notification', function () {
    $employee = leavesEmployee('EMP-001', 'Liza', 'Domingo');
    $request = leavesRequest($employee, 'LR-001');
    // Credits that cover the two days: approval pays what credits cover.
    (new LeaveCredit)->forceFill(['employee_id' => $employee->getKey(), 'year' => 2026, 'request_type' => 'SICK', 'credits' => 5])->save();

    // The requester's login, so the notification has somewhere to land.
    $requester = User::factory()->create([
        'role_id' => leavesRole(['PORTAL'], false)->getKey(),
        'employee_id' => $employee->getKey(),
    ]);

    $reviewer = leavesActor(['LEAVES'], allData: true);

    Livewire::test(Index::class)
        ->call('show', $request->getKey())
        ->assertSet('openId', $request->getKey())
        ->assertSet('detailOpen', true)
        ->call('review', 'APPROVED', true)
        ->assertSet('detailOpen', false)
        ->assertSet('openId', null);

    $fresh = $request->fresh();
    expect($fresh->status)->toBe('APPROVED');
    expect($fresh->is_paid)->toBeTrue();
    expect($fresh->reviewed_by)->toBe((int) $reviewer->getKey());
    expect($fresh->reviewed_at)->not->toBeNull();

    expect(
        AuditLog::query()
            ->where('action', 'REQUEST_APPROVED')
            ->where('entity', 'leave_travel_requests')
            ->where('entity_id', $request->getKey())
            ->where('details', 'Request LR-001 approved')
            ->exists()
    )->toBeTrue();

    $notification = Notification::query()->where('user_id', $requester->getKey())->first();
    expect($notification)->not->toBeNull();
    expect($notification->kind)->toBe('LEAVE_REVIEWED');
    expect($notification->title)->toBe('Request LR-001 approved');
    expect($notification->body)->toBe('Approved as paid leave');
    expect($notification->href)->toBe('/portal');
});

it('approves official business with a null is_paid — the tri-state is load-bearing', function () {
    $employee = leavesEmployee('EMP-002', 'Ana', 'Reyes');
    $request = leavesRequest($employee, 'LR-002', ['request_type' => 'BUSINESS_TRAVEL']);

    leavesActor(['LEAVES'], allData: true);

    Livewire::test(Index::class)
        ->call('show', $request->getKey())
        ->call('review', 'APPROVED');

    $fresh = $request->fresh();
    expect($fresh->status)->toBe('APPROVED');
    expect($fresh->is_paid)->toBeNull();
});

it('disapproves with a null is_paid whatever the caller sent', function () {
    $employee = leavesEmployee('EMP-003', 'Marco', 'Cruz');
    $request = leavesRequest($employee, 'LR-003');

    leavesActor(['LEAVES'], allData: true);

    Livewire::test(Index::class)
        ->call('show', $request->getKey())
        ->set('disapprovalReason', 'Not enough staff')
        ->call('review', 'REJECTED', true);

    $fresh = $request->fresh();
    expect($fresh->status)->toBe('REJECTED');
    expect($fresh->is_paid)->toBeNull();
});

it('refuses a reviewer without all_data even though they hold LEAVES', function () {
    $employee = leavesEmployee('EMP-004', 'Nina', 'Santos');
    $mine = leavesEmployee('EMP-005', 'Self', 'Viewer');
    $request = leavesRequest($employee, 'LR-004');

    // Linked employee so the row is visible (own-rows scope), but no all_data.
    leavesActor(['LEAVES'], allData: false, employeeId: $mine->getKey());

    Livewire::test(Index::class)->call('review', 'APPROVED', true);

    expect($request->fresh()->status)->toBe('PENDING');
});

it('lets an all-data holder review their own request', function () {
    $employee = leavesEmployee('EMP-006', 'Own', 'Request');
    $request = leavesRequest($employee, 'LR-005');

    leavesActor(['LEAVES'], allData: true, employeeId: $employee->getKey());

    Livewire::test(Index::class)
        ->call('show', $request->getKey())
        ->call('review', 'APPROVED', true);

    expect($request->fresh()->status)->toBe('APPROVED');
});

it('lets only one of two racing reviewers win', function () {
    $employee = leavesEmployee('EMP-007', 'Race', 'Condition');
    $request = leavesRequest($employee, 'LR-006');
    (new LeaveCredit)->forceFill(['employee_id' => $employee->getKey(), 'year' => 2026, 'request_type' => 'SICK', 'credits' => 5])->save();

    leavesActor(['LEAVES'], allData: true);

    $component = Livewire::test(Index::class)->call('show', $request->getKey());

    // First decision lands…
    $component->call('review', 'APPROVED', true);

    // …the second (same still-open dialog, as a racing reviewer would have)
    // must not overwrite it. The status WHERE clause on the UPDATE is the
    // server-side guard; this drives the same path end to end.
    $component->call('show', $request->getKey())->set('disapprovalReason', 'Too late')->call('review', 'REJECTED');

    $fresh = $request->fresh();
    expect($fresh->status)->toBe('APPROVED');
    expect($fresh->is_paid)->toBeTrue();
});
