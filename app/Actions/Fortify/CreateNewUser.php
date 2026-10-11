<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Modules\IdentityAccess\Infrastructure\CredentialStorage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(#[\SensitiveParameter] array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        try {
            return User::create([
                'email' => $input['email'],
                'password' => $input['password'],
            ]);
        } catch (QueryException $error) {
            throw CredentialStorage::failure($error, 'Could not create the account. Please try again.');
        }
    }
}
