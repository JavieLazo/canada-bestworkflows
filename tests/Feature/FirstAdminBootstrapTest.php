<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Modules\IdentityAccess\Application\Commands\BootstrapFirstAdmin;
use App\Modules\IdentityAccess\Application\PasswordPolicy;
use App\Support\Access;
use App\Support\FirstAdmin;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;

it('offers credential-only setup on a fresh installation while ordinary registration stays disabled', function () {
    expect(FirstAdmin::needed())->toBeTrue()
        ->and(Features::enabled(Features::registration()))->toBeFalse();

    $this->get(route('register'))->assertOk()
        ->assertSee('Set Up BestWorkflows')
        ->assertSee('Create First Administrator')
        ->assertDontSee('name="name"', false);
    $this->get(route('login'))->assertOk()->assertSee('Set up first administrator');
});

it('creates and signs in one first administrator without storing a personal name', function () {
    $password = 'Bootstrap-Administrator#2026';
    $this->post(route('register.store'), [
        'email' => 'FIRST@example.test',
        'password' => $password,
        'password_confirmation' => $password,
        'name' => 'Ignored independent identity',
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

    $account = User::query()->sole();
    $this->assertAuthenticatedAs($account);
    expect($account->email)->toBe('first@example.test')
        ->and($account->employee_id)->toBeNull()
        ->and($account->name)->toBe('Account #'.$account->id)
        ->and(Schema::hasColumn('users', 'name'))->toBeFalse()
        ->and($account->is_active)->toBeTrue()
        ->and($account->email_verified_at)->not->toBeNull()
        ->and(Hash::check($password, $account->password))->toBeTrue();

    $role = Role::query()->findOrFail($account->role_id);
    expect($role->name)->toBe('System Admin')
        ->and($role->all_data)->toBeTrue()
        ->and($role->grants()->pluck('module')->all())->toContain(ModuleCode::USERS)
        ->not->toContain(ModuleCode::TASKS);
    $audit = AuditLog::query()->where('action', AuditEvent::BOOTSTRAP_ADMIN->value)->sole();
    expect($audit->entity_id)->toBe((string) $account->id)
        ->and($audit->actor_name)->toBe('Account #'.$account->id);
});

it('rejects weak or unconfirmed browser credentials without provisioning anything', function (string $password, string $confirmation) {
    $this->post(route('register.store'), [
        'email' => 'first@example.test',
        'password' => $password,
        'password_confirmation' => $confirmation,
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'weak password' => ['weak', 'weak'],
    'different confirmation' => ['Bootstrap-Administrator#2026', 'Another-Administrator#2026'],
]);

it('closes both setup routes even when the existing account is inactive', function () {
    User::factory()->create(['is_active' => false]);

    expect(FirstAdmin::needed())->toBeFalse();
    $this->get(route('register'))->assertNotFound();
    $this->post(route('register.store'), [
        'email' => 'another@example.test',
        'password' => 'Bootstrap-Administrator#2026',
        'password_confirmation' => 'Bootstrap-Administrator#2026',
    ])->assertNotFound();
    $this->get(route('login'))->assertOk()->assertDontSee('Set up first administrator');
    expect(User::query()->count())->toBe(1);
});

it('enforces password strength inside the owning command for non-browser callers', function () {
    expect(fn () => app(BootstrapFirstAdmin::class)->handle(Access::forGuest(), 'first@example.test', 'weak'))
        ->toThrow(ValidationException::class);
    expect(User::query()->count())->toBe(0)->and(Role::query()->count())->toBe(0);
});

it('refuses direct bootstrap calls after an account exists', function () {
    $existing = User::factory()->create();
    $command = app(BootstrapFirstAdmin::class);
    expect($command->handle(Access::forGuest(), 'another@example.test', 'Bootstrap-Administrator#2026'))->toBeNull()
        ->and($command->handle(Access::forActor($existing), 'another@example.test', 'Bootstrap-Administrator#2026'))->toBeNull()
        ->and(User::query()->count())->toBe(1)
        ->and(Role::query()->count())->toBe(0);
});

it('fails closed when the migration-created setup mutex is missing', function () {
    DB::table('locks')->where('name', 'copems:setup')->delete();

    expect(fn () => app(BootstrapFirstAdmin::class)->handle(Access::forGuest(), 'first@example.test', 'Bootstrap-Administrator#2026'))
        ->toThrow(RuntimeException::class, 'The database lock is not ready.');
    expect(User::query()->count())->toBe(0)->and(Role::query()->count())->toBe(0);
});

it('supports the BestWorkflows console entry point and refuses subsequent provisioning through its EMS alias', function () {
    expect(Artisan::call('bestworkflows:create-admin', ['email' => 'console@example.test']))->toBe(0);
    preg_match('/One-time password: (.+)/', Artisan::output(), $generated);
    $password = trim($generated[1] ?? '');
    PasswordPolicy::validate($password);
    $account = User::query()->sole();
    expect(Hash::check($password, $account->password))->toBeTrue()
        ->and(Artisan::call('ems:create-admin', ['email' => 'another@example.test']))->toBe(1)
        ->and(User::query()->count())->toBe(1);
});
