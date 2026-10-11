<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\RegisterController;
use App\Http\Middleware\RegistrationBootstrapGate;
use App\Livewire\Actions\Logout;
use App\Support\Access;
use App\Support\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
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
    if (! Auth::check()) {
        return view('pages::landing');
    }

    return $landing();
})->name('home');

// Public setup is closed while any account exists. Fortify's ordinary
// registration feature stays disabled; later accounts belong to User Management.
Route::middleware(['guest', RegistrationBootstrapGate::class])->group(static function (): void {
    Route::get('register', [RegisterController::class, 'show'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->name('register.store');
});

Route::middleware('auth')->group(static function () use ($landing): void {
    // Fortify's post-login home (config/fortify.php 'home' => '/dashboard').
    // Kept under its starter-kit name; it only forwards to the landing path.
    Route::get('dashboard', static fn (): RedirectResponse|View => $landing())->name('dashboard');

    // The sidebar's confirmed "Sign out": an audit row, then the session ends
    // (see Logout). Fortify's own POST /logout stays untouched.
    Route::post('sign-out', Logout::class)->name('shell.logout');
});

// Every module registers its own routes in routes/modules/<module>.php,
// each wrapped in its own Route::middleware(['web','auth','module:…']) group.
foreach (glob(__DIR__.'/modules/*.php') ?: [] as $moduleRoutes) {
    require $moduleRoutes;
}

require __DIR__.'/settings.php';
