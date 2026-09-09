<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\FirstAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The registration screen exists ONLY to stand up the first account.
 *
 * The moment any account exists it is not a sign-up page for an internal HRIS,
 * it is an open door onto the users table — so once the system is set up this
 * gate makes /register 404, exactly as if the route were never registered.
 * Accounts after the first are provisioned through User Management, behind
 * all_data + USERS.
 */
final class RegistrationBootstrapGate
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(FirstAdmin::needed(), 404);

        return $next($request);
    }
}
