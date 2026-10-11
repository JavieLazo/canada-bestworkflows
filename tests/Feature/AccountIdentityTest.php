<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Users\Index;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\IdentityAccess\Application\Commands\ManageAccounts;
use App\Modules\IdentityAccess\Application\Commands\UpdateOwnEmail;
use App\Modules\IdentityAccess\Application\PasswordPolicy;
use App\Modules\IdentityAccess\Application\Queries\GetAccounts;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Platform\Records\AccountEmployeeLinks;
use App\Support\Access;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Accounts carry credentials and links; every personal name comes from the official employee record. */
function accountIdentityEmployee(string $first = 'Official'): EmployeeProfile
{
    return EmployeeProfile::create([
        'employee_code' => Str::random(10), 'first_name' => $first,
        'last_name' => 'Employee', 'suffix' => 'Jr.', 'date_hired' => '2024-01-01',
        'employment_status' => 'Regular', 'is_active' => true, 'email' => 'personal@example.test',
    ]);
}

function accountIdentityManager(): User
{
    $role = Role::create(['name' => 'Identity '.Str::random(8), 'all_data' => true, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $role->id, 'module' => ModuleCode::USERS]);

    return User::factory()->create(['role_id' => $role->id]);
}

it('provisions an unlinked account with no personal information and no editable name', function () {
    $manager = accountIdentityManager();
    $actor = Access::forActor($manager);
    app(ManageAccounts::class)->create($actor, 'unlinked@example.test', 'Provisioned-Password#2026', $manager->role_id, '');
    $user = User::query()->where('email', 'unlinked@example.test')->sole();
    expect($user->employee_id)->toBeNull()
        ->and($user->name)->toBe('Account #'.$user->id)
        ->and(Schema::hasColumn('users', 'name'))->toBeFalse();
    $this->actingAs($manager);
    Livewire::test(Index::class)->call('openAccount', (string) $user->id)
        ->assertDontSee('wire:model="createFullName"', false)
        ->assertDontSee('wire:model="accountFullName"', false);
});

it('uses the same official name for every linked account and ignores account-name assignments', function () {
    $employee = accountIdentityEmployee();
    $first = User::factory()->create(['employee_id' => $employee->id, 'name' => 'Independent name']);
    $second = User::factory()->create(['employee_id' => $employee->id]);
    $first->setAttribute('name', 'Another independent name');
    $first->setAttribute('full_name', 'Alias name');
    $first->save();
    expect($first->name)->toBe('Official Employee Jr.')
        ->and($first->full_name)->toBe('Official Employee Jr.')
        ->and(Access::forActor($second)->fullName)->toBe('Official Employee Jr.');
    $employee->first_name = 'Approved';
    $employee->save();
    expect($first->name)->toBe('Approved Employee Jr.')
        ->and($second->name)->toBe('Approved Employee Jr.');
    $first->employee_id = null;
    $first->save();
    expect($first->name)->toBe('Account #'.$first->id);
});

it('batches account names and keeps login emails out of public account labels', function () {
    $manager = accountIdentityManager();
    $employee = accountIdentityEmployee();
    foreach (range(1, 8) as $i) {
        User::factory()->create(['employee_id' => $employee->id, 'role_id' => $manager->role_id]);
    }
    $actor = Access::forActor($manager);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $labels = app(AccountNames::class)->list($actor);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(2)
        ->and($labels->where('employee_id', $employee->id)->pluck('name')->unique()->all())->toBe(['Official Employee Jr.'])
        ->and($labels->firstWhere('id', $manager->id)->name)->toBe('Account #'.$manager->id);
    foreach ($labels as $label) {
        expect($label->name)->not->toContain('@');
    }
    expect(app(ModuleAccounts::class)->holders($actor, ModuleCode::USERS)->where('employee_id', $employee->id)->first()->name)
        ->toBe('Official Employee Jr.');
    expect(app(GetAccounts::class)->account($actor, (string) $manager->id)->name)->toBe('Account #'.$manager->id);
});

it('rechecks active links and keeps login-email changes separate from employee contact details', function () {
    $employee = accountIdentityEmployee();
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $links = app(AccountEmployeeLinks::class);
    expect($links->isActiveLinked($user->id, $employee->id))->toBeTrue();
    app(UpdateOwnEmail::class)->handle(Access::forActor($user), 'login@example.test');
    expect($user->fresh()->email)->toBe('login@example.test')
        ->and($employee->fresh()->email)->toBe('personal@example.test');
    $user->is_active = false;
    $user->save();
    expect($links->isActiveLinked($user->id, $employee->id))->toBeFalse();
    expect(fn () => app(UpdateOwnEmail::class)->handle(Access::forGuest(), 'guest@example.test'))
        ->toThrow(HttpException::class);
});

it('locks current active account links in stable order and rejects a reviewer relinked to the target', function () {
    $employee = accountIdentityEmployee();
    $requester = User::factory()->create(['employee_id' => $employee->id]);
    $reviewer = User::factory()->create();
    $inactive = User::factory()->create(['is_active' => false]);
    $links = app(AccountEmployeeLinks::class);
    expect($links->lockCurrentLinks([$inactive->id, $reviewer->id, $requester->id, $reviewer->id]))
        ->toBe([$requester->id => $employee->id, $reviewer->id => null]);
    expect($links->isActiveIndependent($reviewer->id, $employee->id))->toBeTrue()
        ->and($links->isActiveIndependent($inactive->id, $employee->id))->toBeFalse();
    $reviewer->employee_id = $employee->id;
    $reviewer->save();
    expect($links->isActiveIndependent($reviewer->id, $employee->id))->toBeFalse();
});

it('drops independent names retry-safely while preserving links credentials and frozen audit attribution', function () {
    $employee = accountIdentityEmployee();
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $password = $user->password;
    $migration = require base_path('database/migrations/2026_10_08_0007_drop_name_from_users_table.php');
    $migration->down();
    $migration->down();
    DB::table('users')->where('id', $user->id)->update(['name' => 'Obsolete independent name']);
    $audit = new AuditLog;
    $audit->forceFill(['actor_id' => $user->id, 'actor_name' => 'Frozen actor name', 'action' => 'USER_UPDATED', 'entity' => 'user_accounts', 'entity_id' => (string) $user->id])->save();
    expect($user->fresh()->name)->toBe('Official Employee Jr.');
    $compat = User::factory()->create(['name' => 'Attempted personal name']);
    expect(DB::table('users')->where('id', $compat->id)->value('name'))->toBe('Account');
    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('users', 'name'))->toBeFalse()
        ->and($user->fresh()->employee_id)->toBe($employee->id)
        ->and($user->fresh()->password)->toBe($password)
        ->and($audit->fresh()->actor_name)->toBe('Frozen actor name');
});

it('bootstraps the first administrator without an independent personal name', function () {
    expect(Artisan::call('ems:create-admin', ['email' => 'bootstrap@example.test']))->toBe(0);
    preg_match('/One-time password: (.+)/', Artisan::output(), $generated);
    $password = trim($generated[1] ?? '');
    PasswordPolicy::validate($password);
    $account = User::query()->sole();
    expect($account->employee_id)->toBeNull()->and($account->name)->toBe('Account #'.$account->id)
        ->and(Hash::check($password, $account->password))->toBeTrue();
    expect(Artisan::call('ems:create-admin', ['email' => 'another@example.test']))->toBe(1);
    expect(User::query()->count())->toBe(1);
});
