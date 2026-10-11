<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Spaces may be part of a password, but only punctuation or symbols satisfy its symbol requirement. */
final class PasswordSymbol implements ValidationRule
{
    public function validate(string $attribute, #[\SensitiveParameter] mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match('/[\p{P}\p{S}]/u', $value) !== 1) {
            $fail('The password must contain at least one symbol.');
        }
    }
}
