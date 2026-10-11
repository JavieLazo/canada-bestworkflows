<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

/**
 * A password the system makes up for an account someone else will sign in to.
 *
 * Whoever provisions the account hands it over once and the holder replaces
 * it, so nobody has to invent a string that satisfies {@see PasswordPolicy}
 * — and the first-account bootstrap and the New account form cannot drift
 * apart on what satisfying it takes.
 *
 * IT IS BUILT TO BE HANDED OVER: read off a screen, typed on a phone, said
 * aloud. Four groups of five letters and digits joined by hyphens, with the
 * look-alikes (I, O, l, o, 0, 1) left out. The hyphen is its only symbol on
 * purpose — the password travels in every request of the form that holds it,
 * and brackets, slashes and angle brackets are what a chat app reformats and
 * a web firewall reads as an attack.
 */
final class TemporaryPassword
{
    public const int GROUPS = 4;

    public const int GROUP_LENGTH = 5;

    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    private function __construct() {}

    /**
     * Twenty characters drawn from fifty-six, about 116 bits. A draw without
     * an uppercase letter, a lowercase letter or a digit is thrown away.
     *
     * NOTHING HERE CALLS {@see PasswordPolicy::validate()}: in production that
     * asks the compromised-password service over the network, and this runs
     * every time the New account dialog opens. The account's save applies the
     * whole policy; `TemporaryPasswordTest` pins that every draw passes it.
     */
    public static function generate(): string
    {
        $last = strlen(self::ALPHABET) - 1;

        do {
            $groups = [];

            for ($group = 0; $group < self::GROUPS; $group++) {
                $characters = '';

                for ($i = 0; $i < self::GROUP_LENGTH; $i++) {
                    $characters .= self::ALPHABET[random_int(0, $last)];
                }

                $groups[] = $characters;
            }

            $password = implode('-', $groups);
        } while (preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[0-9]/', $password) !== 1);

        return $password;
    }
}
