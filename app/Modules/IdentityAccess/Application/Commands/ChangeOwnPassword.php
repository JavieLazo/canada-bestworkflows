<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\User;
use App\Modules\IdentityAccess\Application\PasswordPolicy;
use App\Modules\IdentityAccess\Infrastructure\PasswordWriter;
use App\Support\Access;
use App\Support\AuditService;

/** Verifies the current credential and fences other sessions before returning to the profile adapter. */
final class ChangeOwnPassword
{
    public function handle(Access $access, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword, #[\SensitiveParameter] string $confirmPassword, string $sessionId): bool
    {
        $user = $access->isSignedIn() ? User::query()->find($access->userId) : null;
        if (! $user instanceof User) {
            return false;
        }
        PasswordPolicy::confirm($newPassword, $confirmPassword);
        app(PasswordWriter::class)->replace($user, $newPassword, $sessionId, $currentPassword);
        // Deliberately nothing about the password itself: the audit trail is
        // read by anyone holding the Audit module (auth/password route).
        AuditService::record($access, AuditEvent::PASSWORD_CHANGED, 'user_accounts', $access->userId, "{$access->fullName} changed their own password");

        return true;
    }
}
