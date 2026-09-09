<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a deactivated account out at its next request.
 *
 * The port of the deactivation rule `currentSession()` carried in the
 * reference (guard.ts:47-51): "Returns null for a deactivated account too — a
 * user disabled mid-session is signed out at their next request rather than
 * keeping access until the token lapses." Laravel's session guard never
 * re-reads `is_active`, so this runs at the end of the `web` group, after the
 * session has identified the user, and {@see Access} trusts that
 * it ran.
 *
 * Fails open when the column does not exist yet (`getAttribute` → null): an
 * account with no `is_active` flag is an active account.
 */
final class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // Access is container-SCOPED: one instance per request, rebuilt for
        // the next. PHP-FPM and Octane both reset scoped instances between
        // requests, but the HTTP *test* kernel does not — a second request in
        // the same test would reuse the first actor's Access. Dropping the
        // instance here, before anything resolves it, makes INVARIANT 1 hold
        // everywhere.
        app()->forgetInstance(Access::class);

        $user = Auth::user();

        if ($user instanceof User) {
            $isActive = $user->getAttribute('is_active');

            if ($isActive !== null && ! (bool) $isActive) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                // Livewire follows a redirect with a full page navigation;
                // only a genuine API caller should see a bare 403.
                if (! $request->hasHeader('X-Livewire') && $request->expectsJson()) {
                    abort(403, 'Account disabled — contact HR.');
                }

                return redirect('/');
            }
        }

        return $next($request);
    }
}
