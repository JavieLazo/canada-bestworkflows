<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Support\Access;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Features;

/**
 * A stale CSRF token must land somewhere that explains itself, and must leave
 * behind the one header that says WHY it happened.
 *
 * The middleware itself cannot be exercised from a test — PreventRequestForgery
 * short-circuits on runningUnitTests() — so these drive the handler the way the
 * framework does, through the bound ExceptionHandler.
 */
function csrfRender(Request $request)
{
    return app(ExceptionHandler::class)->render(
        $request,
        new TokenMismatchException('CSRF token mismatch.'),
    );
}

it('sends a signed-out caller back to sign-in with an explanation, not a 419', function () {
    $request = Request::create('/login', 'POST');
    $request->setLaravelSession(app('session.store'));

    $response = csrfRender($request);

    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toContain('/login');
    expect(session('status'))->toContain('session expired');
});

it('sends a signed-in caller back where they were', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $request = Request::create('/users', 'POST');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $user);

    $response = csrfRender($request);

    expect($response->getStatusCode())->toBe(302);
    expect(session('status'))->toContain('session expired');
});

it('records Sec-Fetch-Site, because its absence is the whole diagnosis', function () {
    Log::spy();

    $request = Request::create('/login', 'POST');
    $request->setLaravelSession(app('session.store'));
    $request->headers->set('User-Agent', 'ProbeAgent/1.0');

    csrfRender($request);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $message === '[csrf] token mismatch'
            && $context['sec_fetch_site'] === '(absent)'
            && $context['path'] === 'login'
            && $context['authenticated'] === false
    );
});

it('records the header when the client did send it', function () {
    Log::spy();

    $request = Request::create('/login', 'POST');
    $request->setLaravelSession(app('session.store'));
    $request->headers->set('Sec-Fetch-Site', 'cross-site');

    csrfRender($request);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $context['sec_fetch_site'] === 'cross-site'
    );
});

it('leaves an API caller with the status code, not a redirect', function () {
    $request = Request::create('/api/anything', 'POST');
    $request->headers->set('Accept', 'application/json');

    $response = csrfRender($request);

    expect($response->getStatusCode())->toBe(419);
});

/*
|--------------------------------------------------------------------------
| Registration is a one-time bootstrap, then it is provisioning only
|--------------------------------------------------------------------------
|
| /register exists only to stand up the first System Administrator on a fresh
| install. Fortify's own registration stays off — this is a gated route that
| makes a full admin, not a roleless stub — and it 404s the instant any account
| exists, after which accounts are made in User Management.
*/

it('leaves Fortify’s own registration feature off', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse();
});

it('opens registration on a fresh install and makes the first account a System Admin', function () {
    expect(User::query()->count())->toBe(0);

    $this->get('/register')->assertOk();

    $this->post('/register', [
        'name' => 'First Admin',
        'email' => 'admin@example.com',
        'password' => 'bootstrap-pass-1',
        'password_confirmation' => 'bootstrap-pass-1',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
    $role = Role::query()->whereKey($admin->role_id)->firstOrFail();

    expect((bool) $admin->is_active)->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($role->name)->toBe('System Admin')
        ->and((bool) $role->all_data)->toBeTrue();

    // The account can actually open an admin-only module — a roleless stub could not.
    expect(Access::forActor($admin)->mayReadAllData('USERS'))->toBeTrue();
});

it('closes registration the moment an account exists', function () {
    User::factory()->create();

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Walk In',
        'email' => 'walkin@example.com',
        'password' => 'bootstrap-pass-1',
        'password_confirmation' => 'bootstrap-pass-1',
    ])->assertNotFound();

    expect(User::query()->where('email', 'walkin@example.com')->exists())->toBeFalse();
});

it('reuses a System Admin role the seeder already created rather than duplicating it', function () {
    $seeded = tap(new Role, fn (Role $r) => $r->forceFill([
        'name' => 'System Admin', 'all_data' => true, 'is_system' => true,
    ])->save());

    $this->post('/register', [
        'name' => 'First Admin',
        'email' => 'admin@example.com',
        'password' => 'bootstrap-pass-1',
        'password_confirmation' => 'bootstrap-pass-1',
    ])->assertRedirect(route('dashboard'));

    expect(Role::query()->where('name', 'System Admin')->count())->toBe(1);
    expect((string) User::query()->where('email', 'admin@example.com')->value('role_id'))
        ->toBe((string) $seeded->getKey());
});
