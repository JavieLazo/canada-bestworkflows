<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Modules\IdentityAccess\Infrastructure\PasswordWriter;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, #[\SensitiveParameter] array $input): void
    {
        Validator::make($input, ['password' => ['required', 'string', 'confirmed']])->validate();
        app(PasswordWriter::class)->replace($user, $input['password']);
    }
}
