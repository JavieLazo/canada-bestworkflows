<?php

declare(strict_types=1);

use App\Models\User;
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
| Accounts are provisioned, never self-served
|--------------------------------------------------------------------------
*/

it('does not expose a public registration screen', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse();

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Walk In',
        'email' => 'walkin@example.com',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ])->assertNotFound();

    expect(User::query()->where('email', 'walkin@example.com')->exists())->toBeFalse();
});
