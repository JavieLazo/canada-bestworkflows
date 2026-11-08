<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Throwable;

/**
 * The kiosk sign-in has no browser session, so a confirmed second factor
 * has to be checked here. Accounts without one sign in with the password.
 */
final class MobileSecondFactor
{
    public function passes(User $user, mixed $code): bool
    {
        if ($user->two_factor_confirmed_at === null) {
            return true;
        }

        $code = preg_replace('/\s+/', '', (string) $code) ?? '';
        if ($code === '') {
            return false;
        }

        try {
            return $this->totp($user, $code) || $this->recovery($user, $code);
        } catch (Throwable) {
            return false;
        }
    }

    private function totp(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $plain = Fortify::currentEncrypter()->decrypt($secret);
        if (! is_string($plain) || $plain === '') {
            return false;
        }

        return app(TwoFactorAuthenticationProvider::class)->verify($plain, $code);
    }

    private function recovery(User $user, string $code): bool
    {
        foreach ($user->recoveryCodes() as $known) {
            if (! is_string($known) || ! hash_equals($known, $code)) {
                continue;
            }
            $user->replaceRecoveryCode($known);

            return true;
        }

        return false;
    }
}
