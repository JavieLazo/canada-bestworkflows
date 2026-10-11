<?php

declare(strict_types=1);

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Domain\Enums\ModuleCode;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\UserPasswordHistory;
use App\Modules\IdentityAccess\Application\Commands\ChangeOwnPassword;
use App\Modules\IdentityAccess\Application\Commands\ManageAccounts;
use App\Modules\IdentityAccess\Application\Commands\ResetAccountPassword;
use App\Modules\IdentityAccess\Application\PasswordPolicy;
use App\Modules\IdentityAccess\Infrastructure\GuardedUserProvider;
use App\Modules\IdentityAccess\Infrastructure\PasswordWriter;
use App\Support\Access;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** All credential paths enforce strong exact plaintext and retain every replaced password privately. */
function passwordPolicyUser(string $password = 'Original-Password#2026'): User
{
    $role = Role::create(['name' => 'Credentials '.Str::random(8), 'all_data' => true, 'is_system' => false]);
    foreach ([ModuleCode::USERS, ModuleCode::PROFILE] as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }

    return User::factory()->create(['role_id' => $role->id, 'password' => $password]);
}

it('rejects weak unsafe or overlong new passwords', function (string $candidate) {
    expect(fn () => PasswordPolicy::validate($candidate))->toThrow(ValidationException::class);
})->with([
    'short' => 'Short-Aa1!',
    'no uppercase' => 'all-lowercase-12!',
    'no lowercase' => 'ALL-UPPERCASE-12!',
    'no number' => 'NoDigits-Password!',
    'no symbol' => 'NoSymbolsPassword42',
    'spaces alone are not symbols' => 'No Symbols Password42',
    'multibyte overflow' => str_repeat('é', 35).'Aa1!',
    'nul' => "Password-Valid1!\0",
]);

it('counts Unicode characters and bytes separately while preserving password spaces', function () {
    PasswordPolicy::validate('ÉéAbcdefghij12!');
    PasswordPolicy::validate(str_repeat('é', 34).'Aa1!');
    $user = passwordPolicyUser();
    $password = '  Fresh-Phrase#2026  ';
    app(PasswordWriter::class)->replace($user, $password);
    expect(Hash::check($password, $user->fresh()->password))->toBeTrue()
        ->and(Hash::check(trim($password), $user->fresh()->password))->toBeFalse();
});

it('refuses encoded password hashes as new plaintext before the model cast can accept them', function () {
    $user = passwordPolicyUser();
    $before = $user->password;
    $encoded = Hash::make('Weak1!');
    expect(fn () => app(PasswordWriter::class)->replace($user, $encoded))->toThrow(ValidationException::class);
    expect($user->fresh()->password)->toBe($before)->and(UserPasswordHistory::query()->count())->toBe(0);
});

it('blocks current and every prior password without changing sessions or history on refusal', function () {
    $user = passwordPolicyUser();
    $writer = app(PasswordWriter::class);
    $writer->replace($user, 'Second-Password#2026');
    $writer->replace($user, 'Third-Password#2026');
    DB::table('sessions')->insert(['id' => 'password-policy-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $hash = $user->password;
    $remember = $user->remember_token;
    foreach (['Original-Password#2026', 'Second-Password#2026', 'Third-Password#2026'] as $previous) {
        expect(fn () => $writer->replace($user, $previous))->toThrow(ValidationException::class);
    }
    expect(UserPasswordHistory::query()->count())->toBe(2)
        ->and(UserPasswordHistory::query()->firstOrFail()->toArray())->not->toHaveKey('password_hash')
        ->and($user->fresh()->password)->toBe($hash)
        ->and($user->fresh()->remember_token)->toBe($remember)
        ->and(DB::table('sessions')->where('id', 'password-policy-session')->exists())->toBeTrue();
});

it('rechecks the current credential from the locked account instead of a stale model', function () {
    $user = passwordPolicyUser();
    $stale = User::query()->findOrFail($user->id);
    app(PasswordWriter::class)->replace($user, 'Second-Password#2026');
    expect(fn () => app(PasswordWriter::class)->replace($stale, 'Third-Password#2026', currentPassword: 'Original-Password#2026'))
        ->toThrow(ValidationException::class);
    expect(Hash::check('Second-Password#2026', $user->fresh()->password))->toBeTrue()
        ->and(UserPasswordHistory::query()->count())->toBe(1);
});

it('accepts an existing legacy current password beyond the new password length limit', function () {
    $legacy = str_repeat('a', 80);
    $user = passwordPolicyUser(Hash::make(substr($legacy, 0, 72)));
    app(PasswordWriter::class)->replace($user, 'Replacement-Password#2026', currentPassword: $legacy);
    expect(Hash::check('Replacement-Password#2026', $user->fresh()->password))->toBeTrue()
        ->and(UserPasswordHistory::query()->count())->toBe(1);
});

it('enforces password history through self service administration and recovery', function () {
    $user = passwordPolicyUser();
    $actor = Access::forActor($user);
    app(ChangeOwnPassword::class)->handle($actor, 'Original-Password#2026', 'Second-Password#2026', 'Second-Password#2026', 'current');
    foreach ([
        fn () => app(ChangeOwnPassword::class)->handle($actor, 'Second-Password#2026', 'Original-Password#2026', 'Original-Password#2026', 'current'),
        fn () => app(ResetAccountPassword::class)->handle($actor, (string) $user->id, 'Original-Password#2026', 'Original-Password#2026'),
        fn () => app(ResetUserPassword::class)->reset($user, ['password' => 'Original-Password#2026', 'password_confirmation' => 'Original-Password#2026']),
    ] as $attempt) {
        expect($attempt)->toThrow(ValidationException::class);
    }
    expect(Hash::check('Second-Password#2026', $user->fresh()->password))->toBeTrue()
        ->and(UserPasswordHistory::query()->count())->toBe(1);
});

it('rejects password reuse in security settings and clears every password draft', function () {
    $user = passwordPolicyUser();
    app(PasswordWriter::class)->replace($user, 'Second-Password#2026');
    $this->actingAs($user);
    app()->forgetInstance(Access::class);
    Livewire::test('pages::settings.security')
        ->set('current_password', 'Second-Password#2026')
        ->set('password', 'Original-Password#2026')
        ->set('password_confirmation', 'Original-Password#2026')
        ->call('updatePassword')->assertHasErrors(['password'])
        ->assertSet('current_password', '')->assertSet('password', '')->assertSet('password_confirmation', '');
    expect(Hash::check('Second-Password#2026', $user->fresh()->password))->toBeTrue();
});

it('requires strong passwords for account provisioning and the disabled registration adapter', function () {
    $manager = passwordPolicyUser();
    $actor = Access::forActor($manager);
    expect(fn () => app(ManageAccounts::class)->create($actor, 'weak@example.test', 'weakpassword', (string) $manager->role_id, ''))
        ->toThrow(ValidationException::class);
    expect(fn () => app(CreateNewUser::class)->create(['email' => 'weak@example.test', 'password' => 'weakpassword', 'password_confirmation' => 'weakpassword']))
        ->toThrow(ValidationException::class);
    expect(User::query()->count())->toBe(1);
});

it('rehashes the current password without new history and refuses a stale login rehash after reset', function () {
    $user = passwordPolicyUser();
    $stale = User::query()->findOrFail($user->id);
    $guard = Auth::guard('web');
    expect($guard)->toBeInstanceOf(SessionGuard::class);
    assert($guard instanceof SessionGuard);
    $provider = $guard->getProvider();
    expect($provider)->toBeInstanceOf(GuardedUserProvider::class);
    assert($provider instanceof GuardedUserProvider);
    app(PasswordWriter::class)->replace($user, 'Second-Password#2026');
    expect(fn () => $provider->rehashPasswordIfRequired($stale, ['password' => 'Original-Password#2026'], true))
        ->toThrow(ValidationException::class);
    expect(Hash::check('Second-Password#2026', $user->fresh()->password))->toBeTrue();
    $hash = $user->password;
    $remember = $user->remember_token;
    $provider->rehashPasswordIfRequired($user, ['password' => 'Second-Password#2026'], true);
    expect($user->password)->not->toBe($hash)
        ->and(Hash::check('Second-Password#2026', $user->password))->toBeTrue()
        ->and($user->remember_token)->toBe($remember)
        ->and(UserPasswordHistory::query()->count())->toBe(1);
});
