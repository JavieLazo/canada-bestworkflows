<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\User;
use App\Modules\IdentityAccess\Application\PasswordPolicy;
use App\Modules\IdentityAccess\Infrastructure\PasswordWriter;
use App\Support\Access;
use App\Support\AuditService;
use RuntimeException;

/** Administrative reset enforces the shared credential policy and revokes the target's other sessions. */
final class ResetAccountPassword
{
    public function __construct(private readonly PasswordWriter $passwords) {}

    public function handle(Access $actor, string $id, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirmation): string
    {
        if (! $actor->hasModule(ModuleCode::USERS)) {
            throw new RuntimeException('You do not have access to that.');
        }
        if (! $actor->mayReadAllData(ModuleCode::USERS)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        PasswordPolicy::confirm($password, $confirmation);
        $account = User::query()->find($id);
        if ($account === null) {
            throw new RuntimeException('That account no longer exists.');
        }
        $self = (string) $account->getKey() === $actor->userId;
        $this->passwords->replace($account, $password, $self ? session()->getId() : null);
        if ($self) {
            session()->regenerate();
        }
        AuditService::record($actor, AuditEvent::USER_PASSWORD_RESET, 'user_accounts', $account->getKey(), "{$account->name} password reset");

        return $account->name;
    }
}
