<?php

declare(strict_types=1);

namespace App\Livewire\Actions;

use App\Domain\Enums\AuditEvent;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signs the current user out of the app shell — the ONE implementation behind
 * the sidebar's "Sign out" (POST /sign-out, route `shell.logout`). The sidebar
 * asks for confirmation first (layouts/app/sidebar.blade.php), so this runs
 * only once the user has confirmed.
 *
 * The LOGOUT audit row is written BEFORE the guard forgets the actor, exactly
 * as `endSession()` writes it (protected-shell.tsx:63-74). The session then
 * ends the way Fortify's own POST /logout ends one: invalidated, with the CSRF
 * token rotated, so the old cookie and any open form are dead. Fortify's
 * /logout stays untouched and unaudited; only the pre-auth verify-email screen
 * posts to it.
 *
 * There is no self-service delete-account modal, and there must NOT be one
 * (auth-and-credentials guide). KEEP THE REQUEST AS THE FIRST PARAMETER: a
 * stale copy of that modal, which production may still hold as a file, calls
 * `$logout($user)` through `tap()`, and with this signature that call dies on
 * a TypeError before any account is signed out or deleted.
 */
final class Logout
{
    public function __invoke(Request $request, Access $access): RedirectResponse
    {
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
    }
}
