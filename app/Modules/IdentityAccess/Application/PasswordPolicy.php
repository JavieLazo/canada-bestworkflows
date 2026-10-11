<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Application\Rules\PasswordBytes;
use App\Modules\IdentityAccess\Application\Rules\PasswordSymbol;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Every credential entry point applies the same strength rules before persistence. */
final class PasswordPolicy
{
    public const MIN_LENGTH = 15;

    public const MAX_BYTES = 72;

    public static function strength(): Password
    {
        $rule = Password::min(self::MIN_LENGTH)->mixedCase()->letters()->numbers()->symbols();

        return app()->isProduction() ? $rule->uncompromised() : $rule;
    }

    /** @return list<string|Password|ValidationRule> */
    public static function rules(): array
    {
        return ['bail', 'required', 'string', new PasswordBytes, new PasswordSymbol, self::strength()];
    }

    public static function validate(#[\SensitiveParameter] string $password): void
    {
        Validator::make(['password' => $password], ['password' => self::rules()])->validate();
    }

    public static function confirm(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirmation): void
    {
        if ($password !== $confirmation) {
            throw ValidationException::withMessages(['password_confirmation' => 'The password confirmation does not match.']);
        }
    }
}
