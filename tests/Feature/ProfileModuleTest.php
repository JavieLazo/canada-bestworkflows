<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Profile\ProfilePage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * PROFILE module: route gating, the employee-less actor seeing no employee
 * data, and the page's own-record happy paths (contact fields, account name,
 * password).
 */
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
        'employee_code' => 'BWF-00002',
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
    // No PROFILE grant: bounced to the landing path, never rendered.
    $user = profileUser(profileRole([ModuleCode::EMPLOYEES], true));

    $this->actingAs($user)->get('/profile')->assertRedirect('/employees');

    $granted = profileUser(profileRole([ModuleCode::PROFILE]));

    $this->actingAs($granted)
        ->get('/profile')
        ->assertOk()
        ->assertSee('My Profile');
});

it('shows an employee-less actor the unlinked note and no employee sections', function () {
    // A record exists, but this account is not linked to it — the page must
    // resolve NO employee row, never someone else's.
    profileEmployee();

    $user = profileUser(profileRole([ModuleCode::PROFILE]));
    // A fixed name: the shell renders the account name, and a faker surname
    // colliding with the employee's would make the assertions below flaky.
    $user->forceFill(['name' => 'Unlinked Account'])->save();

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertSee('links this account to an employee record')
        ->assertDontSee('Emergency contact')
        ->assertDontSee('BWF-00002')
        ->assertDontSee('Reyes');
});

it('writes nothing when an employee-less actor calls the employee saves', function () {
    $record = profileEmployee();

    $user = profileUser(profileRole([ModuleCode::PROFILE]));
    $this->actingAs($user);

    Livewire::test(ProfilePage::class)
        ->set('personal.phone', '0917 000 0000')
        ->call('savePersonal')
        ->set('emergency.name', 'Someone Else')
        ->call('saveEmergency');

    $record->refresh();

    expect($record->phone)->toBeNull()
        ->and($record->emergency_name)->toBeNull();
});

it('saves personal and emergency details on the linked record and audits them', function () {
    $employee = profileEmployee();
    $user = profileUser(profileRole([ModuleCode::PROFILE]), $employee);

    $this->actingAs($user);

    Livewire::test(ProfilePage::class)
        ->set('personal.phone', '0917 555 1234')
        ->set('personal.sex', 'Female')
        ->set('personal.civilStatus', 'Single')
        ->call('savePersonal')
        ->set('emergency.name', 'Ben Reyes')
        ->set('emergency.relation', 'Brother')
        ->set('emergency.phone', '0917 555 9999')
        ->call('saveEmergency');

    $employee->refresh();

    expect($employee->phone)->toBe('0917 555 1234')
        ->and($employee->sex)->toBe('Female')
        ->and($employee->civil_status)->toBe('Single')
        ->and($employee->emergency_name)->toBe('Ben Reyes')
        ->and($employee->emergency_relation)->toBe('Brother')
        ->and($employee->emergency_phone)->toBe('0917 555 9999');

    expect(AuditLog::query()
        ->where('action', 'PROFILE_UPDATED')
        ->where('entity', 'employee_profiles')
        ->where('entity_id', $employee->id)
        ->count())->toBe(2);
});

it('renames the account from the profile page', function () {
    $user = profileUser(profileRole([ModuleCode::PROFILE]));

    $this->actingAs($user);

    Livewire::test(ProfilePage::class)
        ->set('fullName', 'Renamed Person')
        ->call('saveAccount');

    expect($user->refresh()->name)->toBe('Renamed Person');

    expect(AuditLog::query()
        ->where('action', 'PROFILE_UPDATED')
        ->where('entity', 'user_accounts')
        ->where('details', 'like', '%changed their display name to Renamed Person')
        ->exists())->toBeTrue();
});

it('changes the password only when the current one is correct', function () {
    $user = profileUser(profileRole([ModuleCode::PROFILE]));
    $user->forceFill(['password' => Hash::make('old-password')])->save();

    $this->actingAs($user);

    // Wrong current password: refused, nothing stored.
    Livewire::test(ProfilePage::class)
        ->set('currentPassword', 'not-the-password')
        ->set('newPassword', 'brand-new-secret')
        ->set('confirmPassword', 'brand-new-secret')
        ->call('changePassword');

    expect(Hash::check('brand-new-secret', $user->refresh()->password))->toBeFalse();

    // Right current password: stored, and the change is on the trail.
    Livewire::test(ProfilePage::class)
        ->set('currentPassword', 'old-password')
        ->set('newPassword', 'brand-new-secret')
        ->set('confirmPassword', 'brand-new-secret')
        ->call('changePassword');

    expect(Hash::check('brand-new-secret', $user->refresh()->password))->toBeTrue();

    expect(AuditLog::query()
        ->where('action', 'PASSWORD_CHANGED')
        ->where('entity', 'user_accounts')
        ->exists())->toBeTrue();
});
