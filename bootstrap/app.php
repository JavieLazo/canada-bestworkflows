<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureAllData;
use App\Http\Middleware\EnsureModuleAccess;
use App\Support\Access;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // /broadcasting/auth, on the WEB middleware group: a channel subscription
    // is authorised from the session cookie, and EnsureAccountActive is
    // appended to that group, so an account disabled mid-session loses its
    // subscriptions at its next authorisation exactly as it loses its pages.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // "A user disabled mid-session is signed out at their next request
        // rather than keeping access until the token lapses" (guard.ts:47-51).
        // Laravel's session guard does not re-check is_active, so this does.
        $middleware->web(append: [
            EnsureAccountActive::class,
        ]);

        $middleware->alias([
            'module' => EnsureModuleAccess::class,
            'module.all' => EnsureAllData::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * A stale CSRF token sends the caller back to a page that explains
         * itself, instead of Laravel's bare "419 | Page Expired".
         *
         * The nine forms that can reach here are the auth screens and sign-out
         * (login, register, the password screens, two-factor, logout) — every
         * other write in this app is Livewire, which redirects itself to
         * /login when the session behind a component has gone. A 419 therefore
         * lands almost exclusively on someone trying to sign in, and the page
         * it renders offers them nothing: no message, no link, and no clue
         * that reloading is the fix.
         *
         * The log line is the point as much as the redirect. Laravel 13 checks
         * `Sec-Fetch-Site: same-origin` BEFORE it compares the token
         * (PreventRequestForgery::handle), so a stale token cannot 419 a
         * browser that sends that header — Chrome 76+, Firefox 90+, Safari
         * 16.4+. Reaching here means either the client sent no Sec-Fetch-Site
         * (an older iOS Safari, an in-app WebView, or a proxy that strips it)
         * or the POST genuinely was not same-origin. Those two want different
         * fixes and are indistinguishable after the fact, so the header and
         * the session state are recorded while they are still in hand.
         *
         * MATCHED AS HttpException, NOT TokenMismatchException, and that is not
         * a style choice: Handler::render() calls prepareException() BEFORE
         * renderViaCallbacks(), and prepareException rewrites a
         * TokenMismatchException into `new HttpException(419, ..., $e)`, so a
         * callback typed against the original class is never reached. The
         * original survives as getPrevious(), which keeps this precise rather
         * than "any 419".
         */
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || ! $e->getPrevious() instanceof TokenMismatchException) {
                return null;
            }

            Log::warning('[csrf] token mismatch', [
                'path' => $request->path(),
                'sec_fetch_site' => $request->header('Sec-Fetch-Site') ?? '(absent)',
                'session_started' => $request->hasSession() && $request->session()->isStarted(),
                'authenticated' => $request->user() !== null,
                'agent' => $request->userAgent(),
            ]);

            // An API or fetch caller wants the status code, not a page.
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            $message = 'Your session expired before that could be submitted. Please try again.';

            if ($request->user() === null) {
                return redirect()->route('login')->with('status', $message);
            }

            return back()->with('status', $message);
        });
    })->create();

// cPanel fallback layout: the docroot is public_html, a SIBLING of the app
// root, holding the contents of Laravel's public/. Without this, web requests
// find the app through public_html/index.php but public_path() still resolves
// to <app>/public - which silently breaks the Vite manifest lookup ("Vite
// manifest not found" with the file sitting in public_html/build) - and
// artisan, which never loads index.php, disagrees with the web about where
// public even is. Declared here so web and CLI agree. Inert on any machine
// without a public_html sibling (dev, or a host with an editable docroot).
$cpanelPublic = dirname($app->basePath()).'/public_html';
if (is_dir($cpanelPublic) && is_file($cpanelPublic.'/index.php')) {
    $app->usePublicPath($cpanelPublic);
}

// Access is rebuilt from the session + database ONCE PER REQUEST and never
// cached beyond it (Access INVARIANT 1) — scoped(), never singleton(), so it
// cannot survive into the next request under Octane.
$app->scoped(
    Access::class,
    static fn (ApplicationContract $container): Access => Access::forActor(
        $container->make('auth')->guard()->user(),
    ),
);

return $app;
