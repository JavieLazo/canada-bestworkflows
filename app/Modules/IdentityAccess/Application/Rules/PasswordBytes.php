<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Rules;

use App\Modules\IdentityAccess\Application\PasswordPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;

/** Bcrypt must receive exact plaintext bytes without truncation, NULs or pre-encoded hashes. */
final class PasswordBytes implements ValidationRule
{
    public function validate(string $attribute, #[\SensitiveParameter] mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        if (strlen($value) > PasswordPolicy::MAX_BYTES) {
            $fail('This password is too long. Try a shorter passphrase.');
        } elseif (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8') || Hash::isHashed($value)) {
            $fail('Choose a different password.');
        }
    }
}
