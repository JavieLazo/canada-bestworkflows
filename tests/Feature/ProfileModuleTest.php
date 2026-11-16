<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\ChangeRequests;
use App\Livewire\Employees\PdsList;
use App\Livewire\Employees\Show;
use App\Livewire\Profile\ProfilePage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRecordChangeRequest;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/** My Profile has one employee record; personal edits are proposals while credential changes remain immediate. */

/** @param list<string> $modules ModuleCode constants, one grant each */
function profileRole(array $modules, bool $allData = false): Role
{
    $role = Role::query()->create([
        'name' => 'Test profile '.implode('-', $modules).($allData ? ' all' : ''),
        'all_data' => $allData,
        'is_system' => false,
    ]);

    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    return $role;
}

function profileUser(Role $role, ?EmployeeProfile $employee = null): User
{
    $user = User::factory()->create();
    $user->forceFill([
        'role_id' => $role->id,
        'employee_id' => $employee?->id,
    ])->save();

    return $user->refresh();
}

function profileEmployee(): EmployeeProfile
{
    return EmployeeProfile::query()->create([
        'employee_code' => 'COP-00002',
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'employment_status' => 'Regular',
        'date_hired' => '2024-02-01',
        'monthly_rate' => 25000,
    ]);
}

it('redirects guests to the login page', function () {
    $this->get('/profile')->assertRedirect();
});

it('gates the page on the PROFILE module grant', function () {
    $this->actingAs(profileUser(profileRole([ModuleCode::EMPLOYEES], true)))->get('/profile')->assertRedirect('/employees');
    app()->forgetInstance(Access::class);
    $this->actingAs(profileUser(profileRole([ModuleCode::PROFILE])))->get('/profile')->assertOk()->assertSee('My Profile');
});

it('shows an unlinked account no personal information or independent name editor', function () {
    profileEmployee();
    $this->actingAs(profileUser(profileRole([ModuleCode::PROFILE])));
    Livewire::test(ProfilePage::class)
        ->assertSee('Employee Record Not Linked')
        ->assertDontSee('Reyes')
        ->assertDontSeeHtml('wire:model="fullName"')
        ->set('tab', 'account')
        ->assertSee('Sign-In Email')
        ->assertDontSeeHtml('wire:model="fullName"');
    expect(EmployeeRecordChangeRequest::query()->count())->toBe(0);
});

it('shows the complete linked record through My Profile and offers approved corrections', function () {
    $employee = profileEmployee();
    $this->actingAs(profileUser(profileRole([ModuleCode::PROFILE]), $employee));
    Livewire::test(ProfilePage::class)
        ->assertSee('Ana Reyes')
        ->assertSee('Government IDs')
        ->assertSee('Education')
        ->assertSee('Declarations')
        ->assertDontSee('Delete Employee')
        ->assertDontSeeHtml('wire:model="fullName"');
    Livewire::test(Show::class, ['own' => true])
        ->call('startEdit', 'personal')
        ->assertDontSee('Reason for Change')
        ->assertSee('Submit for Approval');
});

it('refuses invalid personal and emergency fields before creating a proposal', function () {
    $employee = profileEmployee();
    $this->actingAs(profileUser(profileRole([ModuleCode::PROFILE]), $employee));
    Livewire::test(Show::class, ['own' => true])
        ->call('startEdit', 'personal')
        ->set('form.phone', 'abc')
        ->call('saveSection', 'personal')
        ->assertSet('editingSection', 'personal')
        ->set('form.phone', '0917 123 4567')
        ->set('form.sex', 'Invalid answer')
        ->call('saveSection', 'personal')
        ->call('startEdit', 'emergency')
        ->set('form.emergency_name', 'Ben Reyes')
        ->set('form.emergency_phone', '1')
        ->call('saveSection', 'emergency');
    expect($employee->refresh()->phone)->toBeNull()
        ->and($employee->emergency_name)->toBeNull()
        ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0);
});

it('submits emergency contact changes without editing the official record', function () {
    $employee = profileEmployee();
    $employee->forceFill(['emergency_name' => 'Maria Reyes', 'emergency_address' => '12 Luna Street'])->save();
    $this->actingAs(profileUser(profileRole([ModuleCode::PROFILE]), $employee));
    Livewire::test(Show::class, ['own' => true])
        ->call('startEdit', 'emergency')
        ->assertSet('form.emergency_address', '12 Luna Street')
        ->set('form.emergency_name', 'Pedro Reyes')
        ->set('form.emergency_relation', 'Father')
        ->set('form.emergency_phone', '0918 555 1111')
        ->set('form.emergency_address', '')
        ->call('saveSection', 'emergency')
        ->assertSet('editingSection', null)
        ->assertDispatched('record-change-submitted');
    $request = EmployeeRecordChangeRequest::query()->firstOrFail();
    expect($employee->refresh()->emergency_name)->toBe('Maria Reyes')
        ->and($employee->emergency_address)->toBe('12 Luna Street')
        ->and($request->employee_id)->toBe($employee->id)
        ->and($request->proposed['emergency_name'])->toBe('Pedro Reyes')
        ->and($request->reason)->toBe('')
        ->and($request->status)->toBe('PENDING');
});

it('requires manager approval in My Profile but saves their own Employee Records edits directly', function (bool $ownMode) {
    $employee = profileEmployee();
    $this->actingAs(profileUser(profileRole([ModuleCode::PROFILE, ModuleCode::EMPLOYEES], true), $employee));
    $page = Livewire::test(Show::class, ['employee' => $employee->id, 'own' => $ownMode])
        ->call('startEdit', 'personal')
        ->set('form.first_name', 'Anna')
        ->call('saveSection', 'personal');
    if ($ownMode) {
        $page->assertDispatched('record-change-submitted');
        expect($employee->refresh()->first_name)->toBe('Ana')
            ->and(EmployeeRecordChangeRequest::query()->value('status'))->toBe('PENDING');
    } else {
        $page->assertNotDispatched('record-change-submitted');
        expect($employee->refresh()->first_name)->toBe('Anna')
            ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0);
    }
})->with([true, false]);

it('keeps My Profile employment and employee ID read-only and omits documents and printing', function (bool $manager) {
    $employee = profileEmployee();
    $employee->forceFill(['employee_id_no' => 'STAFF-42'])->save();
    $this->actingAs(profileUser(profileRole([ModuleCode::PROFILE, ModuleCode::EMPLOYEES], $manager), $employee));

    try {
        Livewire::withQueryParams(['tab' => 'documents']);
        $page = Livewire::test(Show::class, ['employee' => $employee->id, 'own' => true])
            ->assertSet('tab', 'profile')
            ->assertDontSeeHtml('wire:click="startEdit(\'employment\')"')
            ->assertDontSeeHtml('wire:click="printPds"')
            ->assertDontSeeHtml('class="sheet-201')
            ->assertDontSeeHtml('value="documents"')
            ->assertDontSeeHtml('&quot;name&quot;:&quot;employees.documents&quot;')
            ->set('tab', 'documents')
            ->assertSet('tab', 'profile')
            ->assertDontSeeHtml('class="sheet-pds')
            ->call('startEdit', 'employment')
            ->assertSet('editingSection', null)
            ->set('editingSection', 'employment')
            ->assertSet('editingSection', null)
            ->call('startEdit', 'personal')
            ->assertSee('STAFF-42')
            ->assertDontSeeHtml('wire:model.blur="form.employee_id_no"')
            ->assertSeeHtml('wire:model.blur="form.first_name"');

        $page->set('form.employee_id_no', 'TAMPERED')
            ->set('form.first_name', 'Anna')
            ->call('saveSection', 'personal');
        expect($employee->refresh()->employee_id_no)->toBe('STAFF-42')
            ->and($employee->first_name)->toBe('Ana')
            ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0);

        Livewire::test(PdsList::class, ['employeeId' => $employee->id, 'list' => 'experience', 'own' => true])
            ->assertDontSee('Edit in Employee Details');
    } finally {
        Livewire::withQueryParams([]);
    }
})->with([false, true]);

it('refuses forged My Profile employment saves and print actions', function (string $action, bool $manager) {
    $employee = profileEmployee();
    $this->actingAs(profileUser(profileRole([ModuleCode::PROFILE, ModuleCode::EMPLOYEES], $manager), $employee));
    $page = Livewire::test(Show::class, ['employee' => $employee->id, 'own' => true]);
    if ($action === 'save') {
        $page->set('form.monthly_rate', '99999')
            ->call('saveSection', 'employment')->assertForbidden();
    } else {
        $page->call('printPds')->assertForbidden();
    }
    expect($employee->refresh()->monthly_rate)->toBe(25000.0)
        ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0);
})->with(['save', 'print'])->with([true, false]);

it('shows own change requests without a pending counter', function () {
    $this->actingAs(profileUser(profileRole([ModuleCode::PROFILE]), profileEmployee()));
    Livewire::test(ChangeRequests::class, ['own' => true])
        ->assertSee('My Change Requests')->assertDontSee('0 pending');
});

it('changes the password only when the current one is correct', function () {
    $user = profileUser(profileRole([ModuleCode::PROFILE]));
    $user->forceFill(['password' => Hash::make('old-password')])->save();

    $this->actingAs($user);

    // Wrong current password: refused, nothing stored.
    Livewire::test(ProfilePage::class)
        ->set('currentPassword', 'not-the-password')
        ->set('newPassword', 'Fresh-Password-2026!')
        ->set('confirmPassword', 'Fresh-Password-2026!')
        ->call('changePassword');

    expect(Hash::check('Fresh-Password-2026!', $user->refresh()->password))->toBeFalse();

    // Right current password: stored, and the change is on the trail.
    Livewire::test(ProfilePage::class)
        ->set('currentPassword', 'old-password')
        ->set('newPassword', 'Fresh-Password-2026!')
        ->set('confirmPassword', 'Fresh-Password-2026!')
        ->call('changePassword');

    expect(Hash::check('Fresh-Password-2026!', $user->refresh()->password))->toBeTrue();

    expect(AuditLog::query()
        ->where('action', 'PASSWORD_CHANGED')
        ->where('entity', 'user_accounts')
        ->exists())->toBeTrue();
});
