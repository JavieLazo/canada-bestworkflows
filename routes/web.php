<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Middleware\RegistrationBootstrapGate;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/*
 * Where sign-in lands: the first module the role holds IN MODULES ORDER, not
 * in the order the grants come back from the store (hris.ts:127-134). A role
 * granted nothing renders the shell's "No modules assigned" card instead of
 * redirect-looping on `/`.
 */
$landing = static function (): RedirectResponse|View {
    $path = app(ModuleRegistry::class)->landingPath(app(Access::class)->modules);

    return $path === '/'
        ? view('livewire.shell.no-modules')
        : redirect($path);
};

Route::get('/', static function () use ($landing): RedirectResponse|View {
    // Guests meet the public marketing landing; its "Sign In" points at /login.
    // A signed-in account skips it and goes straight to the app.
    if (! Auth::check()) {
        return view('pages::landing');
    }

    return $landing();
})->name('home');

Route::middleware('auth')->group(static function () use ($landing): void {
    // Fortify's post-login home (config/fortify.php 'home' => '/dashboard').
    // Kept under its starter-kit name; it only forwards to the landing path.
    Route::get('dashboard', static fn (): RedirectResponse|View => $landing())->name('dashboard');

    // Sign out with an audit row, exactly as `endSession()` writes one
    // (protected-shell.tsx:63-74). Fortify's own POST /logout stays untouched.
    Route::post('sign-out', static function (Request $request): RedirectResponse {
        $access = app(Access::class);

        AuditService::record(
            $access,
            AuditEvent::LOGOUT,
            'user_accounts',
            $access->userId,
            ($access->fullName !== '' ? $access->fullName : 'User').' signed out',
        );

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    })->name('shell.logout');
});

/*
 * First-run registration. Open ONLY while the system has no account, so it can
 * stand up the first System Administrator on a fresh install where accounts are
 * never seeded; RegistrationBootstrapGate 404s it the instant one exists. Every
 * account after that is provisioned through User Management.
 */
Route::middleware(['guest', RegistrationBootstrapGate::class])->group(static function (): void {
    Route::get('register', [RegisterController::class, 'show'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->name('register.store');
});

// Every module registers its own routes in routes/modules/<module>.php,
// each wrapped in its own Route::middleware(['web','auth','module:…']) group.
foreach (glob(__DIR__.'/modules/*.php') ?: [] as $moduleRoutes) {
    require $moduleRoutes;
}

require __DIR__.'/settings.php';
