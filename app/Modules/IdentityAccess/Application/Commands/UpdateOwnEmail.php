<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Commands;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Facades\Validator;

/** Login email belongs to the account; personal contact details belong to the employee record. */
final class UpdateOwnEmail
{
    use ProfileValidationRules;

    public function handle(Access $actor, string $email): void
    {
        abort_unless($actor->isSignedIn(), 403);
        $user = User::query()->findOrFail($actor->userId);
        $validated = Validator::make(['email' => $email], $this->profileRules($user->id))->validate();
        $user->email = $validated['email'];

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();
    }
}
