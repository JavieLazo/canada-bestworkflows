<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Middleware\RegistrationBootstrapGate;
use App\Support\FirstAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * First-run registration. Only reachable while the system has no account —
 * {@see RegistrationBootstrapGate} 404s it otherwise — so
 * every submission that gets here is the bootstrap, and lands a System
 * Administrator through {@see FirstAdmin}.
 */
final class RegisterController
{
    public function show(): View
    {
        return view('pages::auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:200'],
            'password' => ['required', 'string', 'confirmed'],
        ]);

        $accountId = FirstAdmin::provision($data['email'], $data['password']);

        // Lost the race: someone provisioned between the gate and here. The
        // bootstrap is over, so send them to sign in rather than 500.
        if ($accountId === null) {
            return redirect()->route('login')
                ->with('status', 'The system has already been set up. Please sign in.');
        }

        Auth::loginUsingId($accountId);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
