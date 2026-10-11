<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Livewire\Actions\Logout;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Facades\Route;

/**
 * Signing out of the shell asks first, and only the confirmed POST ends the
 * session: it goes through App\Livewire\Actions\Logout, which writes ONE
 * LOGOUT audit row naming the actor, logs the guard out and lands on `/`.
 * The sidebar button itself posts nothing.
 */

/** A signed-in account with one module grant, and a fresh Access for it. */
function signOutUser(): User
{
    $role = Role::create(['name' => 'Sign-out tester', 'all_data' => false, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $role->id, 'module' => 'PORTAL']);
    $user = User::factory()->create(['role_id' => $role->id]);

    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

/* |---| The confirm step |---| */

it('routes the shell sign-out to the Logout action', function () {
    $route = Route::getRoutes()->getByName('shell.logout');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain('POST')
        ->and($route->uri())->toBe('sign-out')
        ->and($route->getControllerClass())->toBe(Logout::class)
        ->and($route->gatherMiddleware())->toContain('auth');
});

it('asks for confirmation before anything is posted', function () {
    signOutUser();

    $html = $this->get(route('notifications.index'))->assertOk()->getContent();
    if ($html === false) {
        throw new RuntimeException('The shell page rendered no body to inspect.');
    }
    $action = 'action="'.route('shell.logout').'"';

    // The footer button only opens the dialog: it is not a submit.
    expect($html)->toContain('type="button" title="Sign out"')
        ->and($html)->toContain('data-test="logout-button"')
        // Exactly one form posts to the sign-out, and it lives in the dialog.
        ->and(substr_count($html, $action))->toBe(1);

    // Trigger, then the dialog, whose form wraps the question and the confirm.
    $this->get(route('notifications.index'))->assertSeeInOrder([
        'data-test="logout-button"',
        'data-modal="confirm-sign-out"',
        $action,
        'id="confirm-sign-out-heading"',
        'Sign out?',
        'data-test="confirm-logout-button"',
    ], false);
});

/* |---| The confirmed sign-out |---| */

it('signs out, audits it once and lands on the home page once confirmed', function () {
    $user = signOutUser();
    $expectedName = Access::forActor($user)->fullName;

    $this->post(route('shell.logout'))->assertRedirect('/');

    $this->assertGuest();

    $rows = AuditLog::query()->where('action', AuditEvent::LOGOUT->value)->get();
    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->actor_id)->toBe($user->id)
        ->and($rows[0]->entity)->toBe('user_accounts')
        ->and((string) $rows[0]->entity_id)->toBe((string) $user->id)
        ->and($rows[0]->details)->toBe(($expectedName !== '' ? $expectedName : 'User').' signed out');
});

it('keeps a guest out of the sign-out and writes no audit row', function () {
    $this->post(route('shell.logout'))->assertRedirect(route('login'));

    expect(AuditLog::query()->where('action', AuditEvent::LOGOUT->value)->count())->toBe(0);
});

it('refuses the removed delete-account modal\'s call shape before signing anyone out', function () {
    // The modal production may still hold as a stale file ran
    // `tap(Auth::user(), $logout(...))->delete()`: it hands the signed-in User
    // to Logout as the first argument. That view is not in this repository, so
    // nothing type-checks its call site — hence the untyped `callable` below.
    // The Request-first signature must turn it into a TypeError before the
    // session or the account is touched.
    $user = signOutUser();
    $legacyModalCall = static fn (callable $logout, User $signedIn): mixed => $logout($signedIn);

    expect(fn () => $legacyModalCall(app(Logout::class), $user))->toThrow(TypeError::class);

    $this->assertAuthenticatedAs($user);
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditEvent::LOGOUT->value)->count())->toBe(0);
});
