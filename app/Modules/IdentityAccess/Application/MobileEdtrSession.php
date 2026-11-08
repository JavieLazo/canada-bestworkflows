<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Domain\Enums\AuditEvent;
use App\Models\EdtrMobileToken;
use App\Models\User;
use App\Support\Access;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Sign-in and device secrets for the biometric eDTR kiosk, which has no
 * browser session. A sign-in issues a registration secret, plus a kiosk
 * secret when a System Admin connects the device. A staff sign-in replaces
 * only the registration secret, so the punch sync from the last System Admin
 * keeps working. Only a SHA-256 of each secret is stored.
 */
final class MobileEdtrSession
{
    /** The role whose sign-in connects a device as a kiosk and may register any staff member. */
    public const string ROLE = 'System Admin';

    public function __construct(private readonly MobileSecondFactor $secondFactor) {}

    /**
     * Checks the email, password and second factor typed on the device.
     *
     * @throws MobileEdtrSignInRefused
     */
    public function verify(string $email, string $password, ?string $code): MobileEdtrAccount
    {
        $email = trim($email);
        $user = User::query()->with('role')->where('email', $email)->first();
        if (! $user instanceof User) {
            $user = User::query()->with('role')->where('email', mb_strtolower($email))->first();
        }

        if (! $user instanceof User || ! is_string($user->password) || ! Hash::check($password, $user->password)) {
            AuditService::anonymous($email, AuditEvent::LOGIN_FAILED, 'user_accounts', null, 'Invalid email or password.');

            throw new MobileEdtrSignInRefused('Those credentials are not valid.', 401);
        }
        if (! $user->is_active) {
            throw new MobileEdtrSignInRefused('This account is disabled.', 403);
        }
        if (! $this->secondFactor->passes($user, $code)) {
            throw new MobileEdtrSignInRefused('Enter the current authenticator code for this account.', 422);
        }

        return new MobileEdtrAccount(
            $user,
            $user->name,
            $user->email,
            $user->role->name ?? '',
            $user->role?->name === self::ROLE,
        );
    }

    /**
     * Records the sign-in and issues the device its secrets.
     *
     * @return array{admin: string, kiosk: ?string}
     */
    public function start(MobileEdtrAccount $account, string $deviceId): array
    {
        $user = $account->user;
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        AuditService::anonymous(
            $user->name,
            AuditEvent::LOGIN_SUCCESS,
            'user_accounts',
            (string) $user->getKey(),
            $user->name.' signed in',
        );

        return $this->issue($user, $deviceId, $account->systemAdmin);
    }

    /**
     * The device behind a bearer secret of the given ability, refused with
     * the status and message the app shows. The account is read again on
     * every call, so a disabled account or a lost System Admin role takes
     * the device on its next request.
     */
    public function authenticate(?string $plain, string $ability): MobileEdtrDevice
    {
        $token = $plain === null || $plain === ''
            ? null
            : EdtrMobileToken::query()->where('token_hash', hash('sha256', $plain))->whereNull('revoked_at')->first();

        if ($token === null || $token->ability !== $ability) {
            abort(401, $ability === EdtrMobileToken::ABILITY_ADMIN
                ? 'Sign in to register biometrics.'
                : 'This device is not connected to EMS.');
        }

        $user = User::query()->with('role')->find($token->user_id);
        if (! $user instanceof User || ! $user->is_active) {
            abort(403, 'This account is disabled.');
        }
        if ($ability === EdtrMobileToken::ABILITY_KIOSK && $user->role?->name !== self::ROLE) {
            abort(403, 'System Admin access is required.');
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(CarbonImmutable::now('UTC')->subMinutes(5))) {
            $token->forceFill(['last_used_at' => CarbonImmutable::now('UTC')])->save();
        }

        return new MobileEdtrDevice((string) $token->getKey(), $user);
    }

    /** A System Admin may register any staff member on the device; anyone else only their own record. */
    public function isSystemAdmin(Access $actor): bool
    {
        return $actor->roleName === self::ROLE;
    }

    /**
     * Ends the actor's registration session on the device. Only the actor's
     * own secret is revoked, and the device's kiosk secret keeps syncing.
     */
    public function signOut(Access $actor, string $tokenId): void
    {
        if (! $actor->isSignedIn()) {
            return;
        }

        $revoked = EdtrMobileToken::query()
            ->whereKey($tokenId)
            ->where('user_id', (int) $actor->userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => CarbonImmutable::now('UTC')]);
        if ($revoked === 0) {
            return;
        }

        AuditService::record(
            $actor,
            AuditEvent::LOGOUT,
            'user_accounts',
            $actor->userId,
            ($actor->fullName !== '' ? $actor->fullName : 'User').' signed out of the eDTR kiosk',
        );
    }

    /**
     * @return array{admin: string, kiosk: ?string}
     */
    private function issue(User $user, string $deviceId, bool $connectKiosk): array
    {
        return DB::transaction(function () use ($user, $deviceId, $connectKiosk): array {
            EdtrMobileToken::query()->where('device_id', $deviceId)->lockForUpdate()->get(['id']);
            EdtrMobileToken::query()
                ->where('device_id', $deviceId)
                ->whereNull('revoked_at')
                ->when(! $connectKiosk, static fn ($query) => $query->where('ability', EdtrMobileToken::ABILITY_ADMIN))
                ->update(['revoked_at' => CarbonImmutable::now('UTC')]);

            return [
                'admin' => $this->mint($user, $deviceId, EdtrMobileToken::ABILITY_ADMIN),
                'kiosk' => $connectKiosk ? $this->mint($user, $deviceId, EdtrMobileToken::ABILITY_KIOSK) : null,
            ];
        });
    }

    private function mint(User $user, string $deviceId, string $ability): string
    {
        $plain = bin2hex(random_bytes(32));
        EdtrMobileToken::query()->create([
            'user_id' => $user->getKey(),
            'device_id' => $deviceId,
            'ability' => $ability,
            'token_hash' => hash('sha256', $plain),
        ]);

        return $plain;
    }
}
