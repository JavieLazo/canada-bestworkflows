<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\Role;
use App\Models\User;
use App\Modules\IdentityAccess\Application\AccountRetention;
use App\Modules\IdentityAccess\Application\PasswordPolicy;
use App\Modules\IdentityAccess\Application\Queries\GetAccounts;
use App\Modules\IdentityAccess\Contracts\Views\UserView;
use App\Modules\IdentityAccess\Infrastructure\CredentialStorage;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmailRule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Account lifecycle rules apply identically to every caller, not only the Users form. */
final class ManageAccounts
{
    /** The duplicate-login refusal, shared with the form that warns of it before the save. */
    public const string EMAIL_TAKEN = 'An account with that email already exists.';

    public function __construct(private readonly EmployeeDirectory $employees, private readonly AccountRetention $retention) {}

    public function create(Access $actor, string $email, #[\SensitiveParameter] string $password, string $roleId, string $employeeId): void
    {
        $this->authorize($actor);
        PasswordPolicy::validate($password);
        DB::transaction(function () use ($actor, $email, $password, $roleId, $employeeId): void {
            $email = trim($email);
            $this->validateEmail($email);
            if ($roleId === '') {
                throw new RuntimeException('Pick a role for the new account.');
            }
            if ($email === '' || mb_strlen($email) > 200) {
                throw new RuntimeException('Could not create the account');
            }
            $this->validateReferences($actor, $email, $roleId, $employeeId);
            $user = new User;
            $user->email = $email;
            $user->password = $password;
            $user->role_id = $roleId;
            $user->employee_id = $employeeId !== '' ? $employeeId : null;
            $user->is_active = true;
            try {
                $user->save();
            } catch (QueryException $error) {
                throw CredentialStorage::failure($error, 'Could not create the account. Please try again.');
            }
            RequiredAudit::record($actor, AuditEvent::USER_CREATED, 'user_accounts', $user->getKey(), "{$user->name} ({$email}) provisioned");
        });
    }

    public function update(Access $actor, string $id, string $email, string $roleId, string $employeeId, bool $active): UserView
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $id, $email, $roleId, $employeeId, $active): UserView {
            $email = trim($email);
            $this->validateEmail($email);
            if ($email === '' || mb_strlen($email) > 200) {
                throw new RuntimeException('Could not update the account');
            }
            $account = User::query()->whereKey($id)->lockForUpdate()->first();
            if ($account === null) {
                throw new RuntimeException('That account no longer exists.');
            }
            $own = $actor->userId === (string) $account->getKey();
            $this->validateReferences($actor, $email, $own ? '' : $roleId, $employeeId, $account);
            $patch = ['email' => $email, 'employee_id' => $employeeId !== '' ? $employeeId : null];
            $account->email = $email;
            $account->employee_id = $patch['employee_id'];
            if (! $own) {
                $patch['role_id'] = $roleId !== '' ? $roleId : null;
                $patch['is_active'] = $active;
                $account->role_id = $patch['role_id'];
                $account->is_active = $active;
            }
            $account->save();
            RequiredAudit::record($actor, AuditEvent::accountPatch(array_keys($patch), (bool) $account->is_active), 'user_accounts', $account->getKey(), "{$account->name} updated");

            return app(GetAccounts::class)->account($actor, (string) $account->id)
                ?? throw new RuntimeException('That account no longer exists.');
        });
    }

    public function delete(Access $actor, string $id): string
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $id): string {
            if ($actor->userId === $id) {
                throw new RuntimeException('You cannot delete your own account. Ask another administrator.');
            }
            $account = User::query()->whereKey($id)->lockForUpdate()->first();
            if ($account === null) {
                throw new RuntimeException('That account no longer exists.');
            }
            if ($account->is_active) {
                throw new RuntimeException('Deactivate the account before deleting it.');
            }
            if ($account->employee_id !== null || $this->retention->any($account->getKey())) {
                throw new RuntimeException('This account has linked or historical records and must remain deactivated instead of being deleted.');
            }
            $name = $account->name;
            $account->delete();
            RequiredAudit::record($actor, AuditEvent::USER_DELETED, 'user_accounts', $id, "{$name} account deleted");

            return $name;
        });
    }

    private function authorize(Access $actor): void
    {
        if (! $actor->mayReadAllData(ModuleCode::USERS)) {
            throw new RuntimeException($actor->hasModule(ModuleCode::USERS)
                ? 'Your role cannot manage organization-wide data.' : 'You do not have access to that.');
        }
    }

    private function validateEmail(string $email): void
    {
        $error = EmailRule::error($email);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
    }

    private function validateReferences(Access $actor, string $email, string $roleId, string $employeeId, ?User $except = null): void
    {
        if (User::query()->when($except !== null, static fn ($query) => $query->whereKeyNot($except?->getKey()))
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists()) {
            throw new RuntimeException(self::EMAIL_TAKEN);
        }
        if ($roleId !== '' && ! Role::query()->whereKey($roleId)->exists()) {
            throw new RuntimeException('Choose an existing role for the account.');
        }
        if ($employeeId !== '' && ! $this->employees->canLinkAccount($actor, $employeeId)) {
            throw new RuntimeException('Choose an active employee record for the account.');
        }
    }
}
