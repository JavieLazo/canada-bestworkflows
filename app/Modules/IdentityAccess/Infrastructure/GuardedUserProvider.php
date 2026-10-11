<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A login rehash must never restore a credential replaced by a concurrent reset. */
final class GuardedUserProvider extends EloquentUserProvider
{
    /** @param array<string, mixed> $credentials */
    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false): void
    {
        if (! $user instanceof User || (! $force && ! $this->hasher->needsRehash((string) $user->getAuthPassword()))) {
            return;
        }
        $plain = $credentials['password'] ?? null;
        if (! is_string($plain)) {
            return;
        }
        try {
            $saved = DB::connection($user->getConnectionName())->transaction(function () use ($user, $plain): User {
                $locked = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first();
                if ($locked === null || ! hash_equals((string) $user->getAuthPassword(), (string) $locked->getAuthPassword())
                    || ! password_verify($plain, (string) $locked->getAuthPassword())) {
                    throw ValidationException::withMessages(['email' => (string) trans('auth.failed')]);
                }
                // This keeps the same credential; strength rules apply only when choosing a new one.
                $locked->password = $plain;
                $locked->save();

                return $locked;
            });
        } catch (QueryException $error) {
            throw CredentialStorage::failure($error, 'Sign-in could not be completed. Please try again.');
        }
        $user->setRawAttributes($saved->getAttributes(), true);
    }
}
