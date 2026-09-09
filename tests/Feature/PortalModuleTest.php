<?php

declare(strict_types=1);

use App\Livewire\Portal\MyPunches;
use App\Livewire\Portal\PortalPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Portal module: /portal gates on the PORTAL grant; every scoped list on
 * the page is EMPTY for an employee-less actor (never IS NULL rows, never an
 * error) and stays the caller's OWN even for an all_data holder; filing mints
 * the LR code server-side, audits and notifies the Leave Management holders;
 * withdrawing is self-only; the payslip dialog opens own payslips only.
 */

/** Helpers are portal-prefixed: Pest loads every test file into one process. */
function portalRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Portal '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

function portalActor(array $modules, bool $allData = false, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => portalRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);

    return $user;
}

function portalEmployee(string $code, string $first, string $last): EmployeeProfile
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

    return $employee;
}

function portalRequest(EmployeeProfile $employee, string $code, array $overrides = []): LeaveTravelRequest
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

function portalPayslip(EmployeeProfile $employee, string $start, string $end, float $net): Payslip
{
    $payslip = new Payslip;
    $payslip->forceFill([
        'employee_id' => $employee->getKey(),
        'period_start' => $start,
        'period_end' => $end,
        'gross_pay' => $net,
        'total_deductions' => 0,
        'net_pay' => $net,
    ])->save();

    return $payslip;
}

/*
|--------------------------------------------------------------------------
| Route gating
|--------------------------------------------------------------------------
*/

it('sends a guest to the login page', function () {
    $this->get('/portal')->assertRedirect(route('login'));
});

it('turns away an actor whose role lacks the PORTAL module', function () {
    portalActor(['LEAVES']);

    $this->get('/portal')->assertRedirect();
});

it('renders for an actor holding PORTAL', function () {
    $employee = portalEmployee('EMP-101', 'Liza', 'Domingo');
    portalActor(['PORTAL'], employeeId: $employee->getKey());

    $this->get('/portal')
        ->assertOk()
        ->assertSee('My Portal');
});

/*
|--------------------------------------------------------------------------
| Row scoping — the employee-less-account leak lives or dies here
|--------------------------------------------------------------------------
*/

it('shows an employee-less actor the not-linked notice and empty lists, never an error', function () {
    $other = portalEmployee('EMP-102', 'Ana', 'Reyes');
    portalRequest($other, 'LR-001');
    portalPayslip($other, '2026-08-01', '2026-08-15', 7777.77);

    portalActor(['PORTAL']); // holds the module, no employee link

    $this->get('/portal')
        ->assertOk()
        ->assertSee('Ask HR to link your account.');

    Livewire::test(PortalPage::class)
        ->set('tab', 'requests')
        ->assertDontSee('Ana')
        ->set('tab', 'payslips')
        ->assertSee('No payslip has been posted for you yet.')
        ->assertDontSee('7,777.77');
});

it("keeps an all_data holder's portal lists to their own rows", function () {
    $mine = portalEmployee('EMP-103', 'Self', 'Service');
    $other = portalEmployee('EMP-104', 'Marco', 'Cruz');
    portalRequest($mine, 'LR-002', ['request_type' => 'SICK']);
    portalRequest($other, 'LR-003', ['request_type' => 'VACATION']);
    portalPayslip($mine, '2026-08-01', '2026-08-15', 1234.56);
    portalPayslip($other, '2026-08-01', '2026-08-15', 7777.77);

    portalActor(['PORTAL', 'LEAVES', 'PAYROLL'], allData: true, employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)
        ->set('tab', 'requests')
        ->assertSee('Sick Leave')
        ->assertDontSee('Vacation Leave')
        ->set('tab', 'payslips')
        ->assertSee('1,234.56')
        ->assertDontSee('7,777.77');
});

it('shows an employee-less actor zero punches in My punches', function () {
    portalActor(['PORTAL']);

    Livewire::test(MyPunches::class)
        ->assertSee('No punches recorded yet.');
});

/*
|--------------------------------------------------------------------------
| Filing — the port of POST /api/leave-requests, self-service branch
|--------------------------------------------------------------------------
*/

it('files a leave with a server-minted LR code, audit row and reviewer notification', function () {
    $type = new LeaveType;
    $type->forceFill(['code' => 'LT-001', 'name' => 'Sick Leave'])->save();

    $mine = portalEmployee('EMP-105', 'Liza', 'Domingo');
    $other = portalEmployee('EMP-106', 'Ana', 'Reyes');
    // A code already minted for somebody else: the next one must count past
    // it, not renumber from the caller's own (empty) list.
    portalRequest($other, 'LR-007', ['start_date' => '2026-01-05', 'end_date' => '2026-01-06']);

    // Somebody who reviews leave: active, all_data, holds LEAVES.
    $reviewer = User::factory()->create([
        'role_id' => portalRole(['LEAVES'], true)->getKey(),
        'employee_id' => null,
    ]);

    $actor = portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)
        ->set('tab', 'requests')
        ->set('type', 'SICK_LEAVE')
        ->set('dayType', 'HALF_DAY')
        ->set('start', '2026-09-01')
        ->set('end', '2026-09-02')
        ->set('reason', '  Flu  ')
        ->call('file')
        ->assertSet('type', '')
        ->assertSet('dayType', 'WHOLE_DAY')
        ->assertSet('reason', '');

    $created = LeaveTravelRequest::query()->where('employee_id', $mine->getKey())->first();
    expect($created)->not->toBeNull();
    expect($created->code)->toBe('LR-008');
    expect($created->request_type)->toBe('SICK_LEAVE');
    expect($created->day_type)->toBe('HALF_DAY');
    expect($created->status)->toBe('PENDING');
    expect($created->reason)->toBe('Flu');
    expect($created->start_date->format('Y-m-d'))->toBe('2026-09-01');
    expect($created->end_date->format('Y-m-d'))->toBe('2026-09-02');

    expect(
        AuditLog::query()
            ->where('action', 'REQUEST_FILED')
            ->where('entity', 'leave_travel_requests')
            ->where('entity_id', $created->getKey())
            ->where('details', "LR-008 filed 2026-09-01 \u{2192} 2026-09-02")
            ->exists()
    )->toBeTrue();

    $notification = Notification::query()->where('user_id', $reviewer->getKey())->first();
    expect($notification)->not->toBeNull();
    expect($notification->kind)->toBe('LEAVE_FILED');
    expect($notification->title)->toBe('LR-008 filed by '.$actor->name);
    expect($notification->body)->toBe('Sick Leave · 2026-09-01');
    expect($notification->href)->toBe('/leaves');

    // The actor's own filing is not news to them.
    expect(Notification::query()->where('user_id', $actor->getKey())->exists())->toBeFalse();
});

it('refuses dates that overlap an existing pending or approved request', function () {
    $type = new LeaveType;
    $type->forceFill(['code' => 'LT-002', 'name' => 'Vacation Leave'])->save();

    $mine = portalEmployee('EMP-107', 'Over', 'Lap');
    portalRequest($mine, 'LR-001', ['start_date' => '2026-09-01', 'end_date' => '2026-09-03']);

    portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)
        ->set('type', 'VACATION')
        ->set('start', '2026-09-02')
        ->set('end', '2026-09-02')
        ->call('file');

    expect(LeaveTravelRequest::query()->where('employee_id', $mine->getKey())->count())->toBe(1);
});

it('refuses a request type Leave Settings does not hold', function () {
    $mine = portalEmployee('EMP-108', 'No', 'Type');
    portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)
        ->set('type', 'MADE_UP_TYPE')
        ->set('start', '2026-09-01')
        ->set('end', '2026-09-01')
        ->call('file');

    expect(LeaveTravelRequest::query()->count())->toBe(0);
});

it('refuses filing for an employee-less account', function () {
    $type = new LeaveType;
    $type->forceFill(['code' => 'LT-003', 'name' => 'Sick Leave'])->save();

    portalActor(['PORTAL']);

    Livewire::test(PortalPage::class)
        ->set('type', 'SICK')
        ->set('start', '2026-09-01')
        ->set('end', '2026-09-01')
        ->call('file');

    expect(LeaveTravelRequest::query()->count())->toBe(0);
});

it('files official business without a Leave Settings row behind it', function () {
    $mine = portalEmployee('EMP-109', 'Field', 'Work');
    portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)
        ->set('type', 'BUSINESS_TRAVEL')
        ->set('start', '2026-09-04')
        ->set('end', '2026-09-04')
        ->call('file');

    $created = LeaveTravelRequest::query()->where('employee_id', $mine->getKey())->first();
    expect($created)->not->toBeNull();
    expect($created->request_type)->toBe('BUSINESS_TRAVEL');
    expect($created->code)->toBe('LR-001');
});

/*
|--------------------------------------------------------------------------
| Withdrawing — the port of POST /api/leave-requests/[id]/withdraw
|--------------------------------------------------------------------------
*/

it('withdraws the caller\'s own pending request, with the audit row', function () {
    $mine = portalEmployee('EMP-110', 'With', 'Draw');
    $request = portalRequest($mine, 'LR-004');

    $actor = portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)->call('withdraw', $request->getKey());

    $fresh = $request->fresh();
    expect($fresh->status)->toBe('WITHDRAWN');
    expect($fresh->is_paid)->toBeNull();
    expect($fresh->reviewed_by)->toBeNull();
    expect($fresh->reviewed_at)->toBeNull();

    expect(
        AuditLog::query()
            ->where('action', 'REQUEST_WITHDRAWN')
            ->where('entity', 'leave_travel_requests')
            ->where('entity_id', $request->getKey())
            ->where('details', 'LR-004 withdrawn by '.$actor->name)
            ->exists()
    )->toBeTrue();
});

it("refuses to withdraw somebody else's request", function () {
    $mine = portalEmployee('EMP-111', 'Self', 'Only');
    $other = portalEmployee('EMP-112', 'Not', 'Mine');
    $request = portalRequest($other, 'LR-005');

    portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)->call('withdraw', $request->getKey());

    expect($request->fresh()->status)->toBe('PENDING');
});

it('refuses to withdraw a request that is no longer pending', function () {
    $mine = portalEmployee('EMP-113', 'Al', 'Ready');
    $request = portalRequest($mine, 'LR-006', ['status' => 'APPROVED', 'is_paid' => true]);

    portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)->call('withdraw', $request->getKey());

    $fresh = $request->fresh();
    expect($fresh->status)->toBe('APPROVED');
    expect($fresh->is_paid)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The payslip dialog
|--------------------------------------------------------------------------
*/

it('opens an own payslip and refuses another employee\'s', function () {
    $mine = portalEmployee('EMP-114', 'Pay', 'Slip');
    $other = portalEmployee('EMP-115', 'Not', 'Yours');
    $own = portalPayslip($mine, '2026-08-01', '2026-08-15', 1500.00);
    $foreign = portalPayslip($other, '2026-08-01', '2026-08-15', 9000.00);

    portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)
        ->call('viewPayslip', $foreign->getKey())
        ->assertSet('viewingId', null)
        ->assertSet('payslipOpen', false)
        ->call('viewPayslip', $own->getKey())
        ->assertSet('viewingId', $own->getKey())
        ->assertSet('payslipOpen', true);
});

it('honours the ?tab= deep link for requests and payslips only', function () {
    $mine = portalEmployee('EMP-116', 'Deep', 'Link');
    portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::withQueryParams(['tab' => 'payslips'])
        ->test(PortalPage::class)
        ->assertSet('tab', 'payslips');

    Livewire::withQueryParams(['tab' => 'nonsense'])
        ->test(PortalPage::class)
        ->assertSet('tab', 'punch');
});
