<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure;

use App\Models\User;
use App\Models\UserPasswordHistory;
use App\Modules\IdentityAccess\Application\PasswordPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Persists an already-authorized credential change and its database-session fence together. */
final class PasswordWriter
{
    public function replace(User $account, #[\SensitiveParameter] string $password, ?string $keepSessionId = null, #[\SensitiveParameter] ?string $currentPassword = null): void
    {
        PasswordPolicy::validate($password);
        $connection = DB::connection($account->getConnectionName());
        try {
            $saved = $connection->transaction(static function () use ($account, $password, $keepSessionId, $currentPassword, $connection): User {
                $locked = $account->newQuery()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
                $oldHash = (string) $locked->getAuthPassword();
                if ($currentPassword !== null && ! password_verify($currentPassword, $oldHash)) {
                    throw ValidationException::withMessages(['current_password' => 'Your current password is not correct.']);
                }
                if (password_verify($password, $oldHash)) {
                    throw ValidationException::withMessages(['password' => 'Choose a password you have never used for this account.']);
                }
                // The user row serializes history reads and inserts without locking a missing history range.
                $history = new UserPasswordHistory;
                $history->setConnection($account->getConnectionName());
                foreach ($history->newQuery()->where('user_id', $locked->getKey())->cursor() as $previous) {
                    if (password_verify($password, $previous->password_hash)) {
                        throw ValidationException::withMessages(['password' => 'Choose a password you have never used for this account.']);
                    }
                }
                if ($oldHash !== '') {
                    $history->forceFill(['user_id' => $locked->getKey(), 'password_hash' => $oldHash])->save();
                }
                $locked->password = $password;
                $locked->remember_token = Str::random(60);
                $locked->save();
                $connection->table('sessions')->where('user_id', $locked->getKey())
                    ->when($keepSessionId !== null, static fn ($q) => $q->where('id', '!=', $keepSessionId))
                    ->delete();

                return $locked;
            });
        } catch (QueryException $error) {
            throw CredentialStorage::failure($error, 'The password could not be changed. Try again.');
        }
        // Session middleware must see the committed credential on the guard's cached model.
        $account->setRawAttributes($saved->getAttributes(), true);
    }
}
