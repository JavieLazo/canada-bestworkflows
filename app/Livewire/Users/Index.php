<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\Notification;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\ModuleRegistry;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * User Management — the port of src/app/(protected)/users/users-page.tsx.
 *
 * One component owns the whole screen, exactly as the one source file did:
 * the Accounts and Roles & permissions tabs, the New-account dialog, the
 * account edit dialog (details form + password reset + delete section), the
 * role dialog, the delete confirm, and the per-role module-grant matrix.
 *
 * THE PAGE IS GATED BY THE MODULE; EVERY WRITE IS GATED BY all_data + USERS.
 * The reference gates the segment with `requirePageModule("USERS")`
 * (users/layout.tsx) — module only — while every API route behind the buttons
 * calls `requireAllDataModule("USERS")`. Ported the same way: the route group
 * carries `module:USERS`, and each action here re-checks
 * {@see Access::mayReadAllData()} for itself, because a public method on a
 * mounted component is reachable without the page load.
 *
 * GRANT EDITS TAKE EFFECT ON THE NEXT REQUEST. Nothing here caches Access:
 * the container's Access is `scoped()` — rebuilt from `roles` +
 * `role_module_grants` on every request (Access INVARIANT 1) — and this
 * component never stores module lists or `all_data` in a property. A revoked
 * grant 403s the very next `/livewire/update` call.
 */
#[Layout('layouts.app')]
#[Title('User Management')]
class Index extends Component
{
    /** The Unassigned sentinel the role selects use (NO_ROLE in the source). */
    public const string NO_ROLE = '';

    /** The no-linked-employee sentinel (NO_EMPLOYEE in the source). */
    public const string NO_EMPLOYEE = '';

    /** Which tab is showing. Plain UI state; anything unknown collapses back. */
    public string $tab = 'accounts';

    /* ── New account dialog (users-page.tsx `form` + `adding`) ──────────── */

    /** Whether the New-account dialog is open. Flipping it reveals nothing. */
    public bool $adding = false;

    public string $createFullName = '';

    public string $createEmail = '';

    public string $createPassword = '';

    /**
     * The picked role. Id-bearing → #[Locked]; the select writes it through
     * {@see self::createRoleChanged()}, which re-resolves the id first.
     */
    #[Locked]
    public string $createRoleId = '';

    /** The picked employee link. Id-bearing → #[Locked]; set via action only. */
    #[Locked]
    public string $createEmployeeId = '';

    /** The inline email message (`emailErr`), set on blur. */
    public ?string $createEmailError = null;

    /* ── Account edit dialog (`selectedAccount` + `accountForm`) ────────── */

    public bool $accountDialogOpen = false;

    /**
     * The account being edited. Id-bearing → #[Locked]; only
     * {@see self::openAccount()} sets it.
     */
    #[Locked]
    public ?string $accountId = null;

    public string $accountFullName = '';

    public string $accountEmail = '';

    public ?string $accountEmailError = null;

    /** '' = Unassigned. Id-bearing → #[Locked]; set via action only. */
    #[Locked]
    public string $accountRoleId = '';

    /** '' = no linked employee. Id-bearing → #[Locked]; set via action only. */
    #[Locked]
    public string $accountEmployeeId = '';

    /**
     * The status switch. Form INPUT for the target row, not the actor's own
     * authority — {@see self::saveAccount()} drops it for the actor's own
     * account and re-gates before applying, so tampering buys nothing.
     */
    public bool $accountIsActive = true;

    /* ── Password reset (passwordResetForm) ─────────────────────────────── */

    public string $resetPassword = '';

    public string $resetPasswordConfirm = '';

    /* ── Delete confirm ─────────────────────────────────────────────────── */

    public bool $deleteConfirmOpen = false;

    /* ── Role dialog (`roleForm` + `selectedRole` + `roleDialogMode`) ───── */

    public bool $roleDialogOpen = false;

    /** null = create mode, an id = edit mode. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $roleId = null;

    public string $roleName = '';

    public bool $roleAllData = false;

    /**
     * Bumped on every grant toggle so the checkbox re-keys: a refused toggle
     * snaps the box back to what the database says, the way the reference's
     * controlled <Checkbox> did.
     */
    #[Locked]
    public int $grantNonce = 0;

    /* ── guards on client-writable state ────────────────────────────────── */

    public function updatedTab(string $value): void
    {
        $this->tab = in_array($value, ['accounts', 'roles'], true) ? $value : 'accounts';
    }

    /**
     * Open the New-account dialog on a form that holds nothing from the last
     * attempt (users-page.tsx resetForm, called on open rather than on close).
     *
     * The reset CANNOT hang off the close alone. Dismissing a <flux:modal> with
     * its × — or Escape, or the backdrop — closes it client-side without
     * writing `adding` back, so {@see self::updatedAdding()} never runs and
     * {@see self::resetCreateForm()} never fires. A $createEmployeeId picked
     * for an abandoned account then survived into the NEXT create and was
     * written at createAccount(), silently linking the new sign-in to a
     * colleague's 201 record that whoever filled the form never chose.
     *
     * Resetting here instead makes the dialog's opening state depend on this
     * request, not on whether a previous close happened to reach the server.
     */
    public function openCreate(): void
    {
        $this->resetCreateForm();
        $this->adding = true;
    }

    /** Closing the New-account dialog resets it (users-page.tsx resetForm). */
    public function updatedAdding(bool $value): void
    {
        if (! $value) {
            $this->resetCreateForm();
        }
    }

    /** Closing the edit dialog forgets the account (clearAccountDialog). */
    public function updatedAccountDialogOpen(bool $value): void
    {
        if (! $value) {
            $this->clearAccountDialog();
        }
    }

    /** Closing the role dialog forgets the role (clearRoleDialog). */
    public function updatedRoleDialogOpen(bool $value): void
    {
        if (! $value) {
            $this->clearRoleDialog();
        }
    }

    /** The onBlur email check of the create form (users-page.tsx:613). */
    public function updatedCreateEmail(): void
    {
        $this->createEmailError = self::emailError($this->createEmail);
    }

    /** The onBlur email check of the edit form (users-page.tsx:718). */
    public function updatedAccountEmail(): void
    {
        $this->accountEmailError = self::emailError($this->accountEmail);
    }

    /* ── select bindings: Locked ids written through validating actions ── */

    public function createRoleChanged(string $id): void
    {
        if ($id === '' || Role::query()->whereKey($id)->exists()) {
            $this->createRoleId = $id;
        }
    }

    public function createEmployeeChanged(string $id): void
    {
        if ($id === '' || EmployeeProfile::query()->whereKey($id)->exists()) {
            $this->createEmployeeId = $id;
        }
    }

    public function accountRoleChanged(string $id): void
    {
        if ($id === '' || Role::query()->whereKey($id)->exists()) {
            $this->accountRoleId = $id;
        }
    }

    public function accountEmployeeChanged(string $id): void
    {
        if ($id === '' || EmployeeProfile::query()->whereKey($id)->exists()) {
            $this->accountEmployeeId = $id;
        }
    }

    /* ── New account (createAccount + POST /api/accounts) ───────────────── */

    public function createAccount(): void
    {
        $actor = app(Access::class);

        if ($this->refuseWrite($actor)) {
            return;
        }

        $email = trim($this->createEmail);
        $fullName = trim($this->createFullName);

        $emailProblem = self::emailError($email);

        if ($emailProblem !== null) {
            $this->createEmailError = $emailProblem;

            return;
        }

        if ($this->createRoleId === '') {
            Flux::toast(variant: 'danger', text: 'Pick a role for the new account.');

            return;
        }

        // The reference leans on the form's required/minLength attributes;
        // these re-checks are its Zod schema (AccountInput) for a caller that
        // skipped the form.
        if ($fullName === '' || $email === '' || mb_strlen($fullName) > 200 || mb_strlen($email) > 200) {
            Flux::toast(variant: 'danger', text: 'Could not create the account');

            return;
        }

        if (mb_strlen($this->createPassword) < 8 || mb_strlen($this->createPassword) > 400) {
            Flux::toast(variant: 'danger', text: 'Password must be at least 8 characters.');

            return;
        }

        // The unique index is case-sensitive but sign-in matches insensitively,
        // so Admin@x.com next to admin@x.com would leave one of them unable to
        // log in. Refuse the near-duplicate here (api/accounts/route.ts:63-70).
        $clash = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->exists();

        if ($clash) {
            Flux::toast(variant: 'danger', text: 'An account with that email already exists.');

            return;
        }

        if (! Role::query()->whereKey($this->createRoleId)->exists()) {
            Flux::toast(variant: 'danger', text: 'Choose an existing role for the account.');

            return;
        }

        if ($this->createEmployeeId !== '') {
            $employee = EmployeeProfile::query()->find($this->createEmployeeId);

            if ($employee === null || ! $employee->is_active) {
                Flux::toast(variant: 'danger', text: 'Choose an active employee record for the account.');

                return;
            }

            // New accounts are created active, so any live holder is a clash.
            $holder = $this->activeAccountHolding($this->createEmployeeId);

            if ($holder !== null) {
                Flux::toast(variant: 'danger', text: "That employee record is already linked to {$holder->email}.");

                return;
            }
        }

        $user = new User;
        $user->name = $fullName;
        $user->email = $email;
        // The 'hashed' cast bcrypts on assignment — Fortify signs this in.
        $user->password = $this->createPassword;
        $user->role_id = $this->createRoleId;
        $user->employee_id = $this->createEmployeeId !== '' ? $this->createEmployeeId : null;
        $user->is_active = true;
        $user->save();

        AuditService::record(
            $actor,
            AuditEvent::USER_CREATED,
            'user_accounts',
            $user->getKey(),
            "{$fullName} ({$email}) provisioned",
        );

        $this->resetCreateForm();
        $this->adding = false;
        Flux::toast(variant: 'success', text: 'Account created.');
    }

    /* ── Account edit dialog ─────────────────────────────────────────────── */

    /** Open an account's dialog — the row click of users-page.tsx:410. */
    public function openAccount(string $id): void
    {
        $account = User::query()->find($id);

        if ($account === null) {
            return;
        }

        $this->accountId = (string) $account->getKey();
        $this->fillAccountForm($account);
        $this->accountEmailError = null;
        $this->resetPassword = '';
        $this->resetPasswordConfirm = '';
        $this->deleteConfirmOpen = false;
        $this->accountDialogOpen = true;
    }

    /** The "Reset changes" button: back to what is stored. */
    public function resetAccountChanges(): void
    {
        $account = $this->accountId !== null ? User::query()->find($this->accountId) : null;

        if ($account === null) {
            return;
        }

        $this->fillAccountForm($account);
        $this->accountEmailError = null;
    }

    /** Save the details form — submitAccountEdit + PATCH /api/accounts/[id]. */
    public function saveAccount(): void
    {
        $actor = app(Access::class);

        if ($this->refuseWrite($actor) || $this->accountId === null) {
            return;
        }

        $fullName = trim($this->accountFullName);

        if ($fullName === '') {
            Flux::toast(variant: 'danger', text: "Full name can't be empty.");

            return;
        }

        $email = trim($this->accountEmail);
        $emailProblem = self::emailError($email);

        if ($emailProblem !== null) {
            $this->accountEmailError = $emailProblem;

            return;
        }

        if ($email === '' || mb_strlen($email) > 200 || mb_strlen($fullName) > 200) {
            Flux::toast(variant: 'danger', text: 'Could not update the account');

            return;
        }

        $account = User::query()->find($this->accountId);

        if ($account === null) {
            Flux::toast(variant: 'danger', text: 'That account no longer exists.');

            return;
        }

        // `isOwnAccount` (users-page.tsx:263): role and status changes are
        // left out of one's own patch — the server's selfAccessChangeError
        // would refuse them with "You cannot change your own access."
        $isOwnAccount = $actor->userId === (string) $account->getKey();

        $patch = [
            'email' => $email,
            'full_name' => $fullName,
            'employee_id' => $this->accountEmployeeId !== '' ? $this->accountEmployeeId : null,
        ];

        if (! $isOwnAccount) {
            $patch['role_id'] = $this->accountRoleId !== '' ? $this->accountRoleId : null;
            $patch['is_active'] = $this->accountIsActive;
        }

        $clash = User::query()
            ->whereKeyNot($account->getKey())
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->exists();

        if ($clash) {
            Flux::toast(variant: 'danger', text: 'An account with that email already exists.');

            return;
        }

        if (($patch['role_id'] ?? null) !== null && ! Role::query()->whereKey($patch['role_id'])->exists()) {
            Flux::toast(variant: 'danger', text: 'Choose an existing role for the account.');

            return;
        }

        if ($patch['employee_id'] !== null) {
            $employee = EmployeeProfile::query()->find($patch['employee_id']);

            if ($employee === null || ! $employee->is_active) {
                Flux::toast(variant: 'danger', text: 'Choose an active employee record for the account.');

                return;
            }

            // Only a live account competes for the record, so the check follows
            // the status this save will LEAVE behind — reactivating a disabled
            // account onto a taken employee is the same clash as relinking it.
            // One's own patch carries no status, so that stays as stored.
            $willBeActive = $isOwnAccount ? (bool) $account->is_active : $patch['is_active'];

            if ($willBeActive) {
                $holder = $this->activeAccountHolding($patch['employee_id'], (string) $account->getKey());

                if ($holder !== null) {
                    Flux::toast(variant: 'danger', text: "That employee record is already linked to {$holder->email}.");

                    return;
                }
            }
        }

        $account->name = $patch['full_name'];
        $account->email = $patch['email'];
        $account->employee_id = $patch['employee_id'];

        if (! $isOwnAccount) {
            $account->role_id = $patch['role_id'];
            $account->is_active = $patch['is_active'];
        }

        $account->save();

        AuditService::record(
            $actor,
            AuditEvent::accountPatch(array_keys($patch), (bool) $account->is_active),
            'user_accounts',
            $account->getKey(),
            "{$account->name} updated",
        );

        $this->fillAccountForm($account);
        $this->accountEmailError = null;
        Flux::toast(variant: 'success', text: 'Account updated.');
    }

    /** submitPasswordReset + POST /api/accounts/[id]/password. */
    public function resetAccountPassword(): void
    {
        $actor = app(Access::class);

        if ($this->refuseWrite($actor) || $this->accountId === null) {
            return;
        }

        if ($this->resetPassword !== $this->resetPasswordConfirm) {
            Flux::toast(variant: 'danger', text: 'The passwords do not match.');

            return;
        }

        // passwordResetError (src/lib/account-policy.ts) — the shared copy for
        // administrator-set temporary passwords.
        if (mb_strlen($this->resetPassword) < 8) {
            Flux::toast(variant: 'danger', text: 'Password must be at least 8 characters.');

            return;
        }

        if (mb_strlen($this->resetPassword) > 400) {
            Flux::toast(variant: 'danger', text: 'Password must be 400 characters or fewer.');

            return;
        }

        $account = User::query()->find($this->accountId);

        if ($account === null) {
            Flux::toast(variant: 'danger', text: 'That account no longer exists.');

            return;
        }

        $account->password = $this->resetPassword;
        $account->save();

        AuditService::record(
            $actor,
            AuditEvent::USER_PASSWORD_RESET,
            'user_accounts',
            $account->getKey(),
            "{$account->name} password reset",
        );

        $this->resetPassword = '';
        $this->resetPasswordConfirm = '';
        Flux::toast(variant: 'success', text: "Password reset for {$account->name}.");
    }

    /** deleteSelectedAccount + DELETE /api/accounts/[id]. */
    public function deleteAccount(): void
    {
        $actor = app(Access::class);

        if ($this->refuseWrite($actor) || $this->accountId === null) {
            return;
        }

        // accountDeletionError: deleting the signed-in account would remove
        // the administrator's own access.
        if ($actor->userId === $this->accountId) {
            Flux::toast(variant: 'danger', text: 'You cannot delete your own account. Ask another administrator.');

            return;
        }

        $account = User::query()->find($this->accountId);

        if ($account === null) {
            Flux::toast(variant: 'danger', text: 'That account no longer exists.');

            return;
        }

        if ($account->is_active) {
            Flux::toast(variant: 'danger', text: 'Deactivate the account before deleting it.');

            return;
        }

        // The nine referencing tables the source counts before allowing a
        // delete (api/accounts/[id]/route.ts:118-136) — historical attribution
        // must stay intact.
        if ($account->employee_id !== null || $this->accountIsReferenced($account)) {
            Flux::toast(
                variant: 'danger',
                text: 'This account has linked or historical records and must remain deactivated instead of being deleted.',
            );

            return;
        }

        $name = $account->name;
        $account->delete();

        AuditService::record(
            $actor,
            AuditEvent::USER_DELETED,
            'user_accounts',
            $this->accountId,
            "{$name} account deleted",
        );

        $this->deleteConfirmOpen = false;
        $this->accountDialogOpen = false;
        $this->clearAccountDialog();
        Flux::toast(variant: 'success', text: "{$name}'s account was deleted.");
    }

    /* ── Roles (submitRole + POST/PATCH /api/roles) ─────────────────────── */

    public function openNewRole(): void
    {
        $this->roleId = null;
        $this->roleName = '';
        $this->roleAllData = false;
        $this->roleDialogOpen = true;
    }

    public function openEditRole(string $id): void
    {
        $role = Role::query()->find($id);

        if ($role === null) {
            return;
        }

        $this->roleId = (string) $role->getKey();
        $this->roleName = $role->name;
        $this->roleAllData = $role->all_data;
        $this->roleDialogOpen = true;
    }

    public function saveRole(): void
    {
        $name = trim($this->roleName);

        if ($name === '') {
            Flux::toast(variant: 'danger', text: "Role name can't be empty.");

            return;
        }

        $actor = app(Access::class);

        if ($this->refuseWrite($actor)) {
            return;
        }

        if (mb_strlen($name) > 80) {
            Flux::toast(variant: 'danger', text: 'Role name must be 80 characters or fewer.');

            return;
        }

        if ($this->roleId === null) {
            $role = Role::query()->create(['name' => $name, 'all_data' => $this->roleAllData, 'is_system' => false]);

            AuditService::record(
                $actor,
                AuditEvent::ROLE_CREATED,
                'roles',
                $role->getKey(),
                "{$role->name} role created",
            );
            Flux::toast(variant: 'success', text: 'Role created.');
        } else {
            $role = Role::query()->find($this->roleId);

            if ($role === null) {
                Flux::toast(variant: 'danger', text: 'That role no longer exists.');

                return;
            }

            // ownRoleScopeChangeError: the current role must retain
            // organization-wide scope so its holder can recover access.
            if ($actor->roleId === (string) $role->getKey() && ! $this->roleAllData) {
                Flux::toast(variant: 'danger', text: 'You cannot remove organization-wide scope from your own role.');

                return;
            }

            $role->name = $name;
            $role->all_data = $this->roleAllData;
            $role->save();

            AuditService::record(
                $actor,
                AuditEvent::ROLE_UPDATED,
                'roles',
                $role->getKey(),
                "{$role->name} role updated",
            );
            Flux::toast(variant: 'success', text: 'Role updated.');
        }

        $this->roleDialogOpen = false;
        $this->clearRoleDialog();
    }

    /* ── The grant matrix (toggleGrant + /api/roles/[id]/grants) ────────── */

    /**
     * Flip one (role, module) grant. The next request's Access re-reads
     * `role_module_grants`, so the change is live immediately — there is no
     * cache to bust, and nothing here to "refresh".
     */
    public function toggleGrant(string $roleId, string $module): void
    {
        $this->grantNonce++;

        $actor = app(Access::class);

        if ($this->refuseWrite($actor)) {
            return;
        }

        if (! in_array($module, ModuleCode::all(), true)) {
            Flux::toast(variant: 'danger', text: 'That module does not exist.');

            return;
        }

        $role = Role::query()->find($roleId);

        if ($role === null) {
            return;
        }

        $on = ! RoleModuleGrant::query()
            ->where('role_id', $role->getKey())
            ->where('module', $module)
            ->exists();

        if (! $on) {
            // ownUsersGrantError: removing USERS from the current role would
            // strand the signed-in admin. (The reference also disables this
            // checkbox; the server check is the one that holds.)
            if ($module === ModuleCode::USERS && $actor->roleId === (string) $role->getKey()) {
                Flux::toast(variant: 'danger', text: 'You cannot remove User Management from your own role.');

                return;
            }

            RoleModuleGrant::query()
                ->where('role_id', $role->getKey())
                ->where('module', $module)
                ->delete();
        } else {
            RoleModuleGrant::query()->firstOrCreate([
                'role_id' => (string) $role->getKey(),
                'module' => $module,
            ]);
        }

        AuditService::record(
            $actor,
            AuditEvent::grant($on),
            'role_module_grants',
            $role->getKey(),
            "{$role->name}: {$module} ".($on ? 'granted' : 'revoked'),
        );
    }

    /* ── render ─────────────────────────────────────────────────────────── */

    public function render(ModuleRegistry $registry): View
    {
        $actor = app(Access::class);

        // GET /api/accounts orders by full_name; /api/roles by name;
        // /api/employees by last_name.
        $accounts = User::query()->orderBy('name')->get();
        $roles = Role::query()->orderBy('name')->get();
        $employees = EmployeeProfile::query()->orderBy('last_name')->get();

        // listRoleGrants() — every role's grants, one set for the matrix.
        $granted = [];

        foreach (RoleModuleGrant::query()->get(['role_id', 'module']) as $grant) {
            $granted[$grant->role_id.'|'.$grant->module] = true;
        }

        $roleNames = $roles->mapWithKeys(
            static fn (Role $role) => [(string) $role->getKey() => $role->name],
        )->all();

        $employeesById = $employees->keyBy(
            static fn (EmployeeProfile $employee) => (string) $employee->getKey(),
        );

        // `selectedAccount` — the dialog titles and aria-labels read the STORED
        // name, not the half-edited form value (users-page.tsx:686, 756, 886).
        $selectedAccount = $this->accountId === null
            ? null
            : $accounts->first(fn (User $account): bool => (string) $account->getKey() === $this->accountId);

        return view('livewire.users.index', [
            'accounts' => $accounts,
            'roles' => $roles,
            'employees' => $employees,
            'employeesById' => $employeesById,
            'granted' => $granted,
            'roleNames' => $roleNames,
            // MODULES, in spec order, for the matrix columns.
            'modules' => $registry->all(),
            'selectedAccountName' => $selectedAccount?->name,
            'isOwnAccount' => $this->accountId !== null && $actor->userId === $this->accountId,
            'actorUserId' => $actor->userId,
            'actorRoleId' => $actor->roleId,
        ]);
    }

    /* ── helpers ────────────────────────────────────────────────────────── */

    /**
     * `requireAllDataModule("USERS")` for a Livewire action: refuse (with the
     * middleware's two messages, in the same order) unless the actor holds
     * BOTH the module and the organization-wide scope. Never cached — the
     * Access read here was rebuilt from the database for this very request.
     */
    private function refuseWrite(Access $actor): bool
    {
        if ($actor->mayReadAllData(ModuleCode::USERS)) {
            return false;
        }

        Flux::toast(variant: 'danger', text: $actor->hasModule(ModuleCode::USERS)
            ? 'Your role cannot manage organization-wide data.'
            : 'You do not have access to that.');

        return true;
    }

    private function fillAccountForm(User $account): void
    {
        $this->accountFullName = (string) $account->name;
        $this->accountEmail = (string) $account->email;
        $this->accountRoleId = $account->role_id === null ? '' : (string) $account->role_id;
        $this->accountEmployeeId = $account->employee_id === null ? '' : (string) $account->employee_id;
        $this->accountIsActive = (bool) $account->is_active;
    }

    /**
     * The live account already holding this employee record, if any.
     *
     * The database says the same thing — `users_active_employee_unq` refuses a
     * second active account on one employee — but a constraint violation
     * reaches the operator as a 500. This turns it into the screen's own
     * refusal, naming the account that holds the record so they can go and
     * unlink it. Deactivated accounts are ignored on purpose: keeping the old
     * login attached to the 201 record is how a rehire holds onto its history.
     *
     * @param  string|null  $exceptAccountId  the account being edited, which
     *                                        never clashes with itself
     */
    private function activeAccountHolding(string $employeeId, ?string $exceptAccountId = null): ?User
    {
        $query = User::query()
            ->where('employee_id', $employeeId)
            ->where('is_active', true);

        if ($exceptAccountId !== null) {
            $query->whereKeyNot($exceptAccountId);
        }

        return $query->orderBy('id')->first();
    }

    private function resetCreateForm(): void
    {
        $this->createFullName = '';
        $this->createEmail = '';
        $this->createPassword = '';
        $this->createRoleId = '';
        $this->createEmployeeId = '';
        $this->createEmailError = null;
    }

    private function clearAccountDialog(): void
    {
        $this->accountId = null;
        $this->accountFullName = '';
        $this->accountEmail = '';
        $this->accountEmailError = null;
        $this->accountRoleId = '';
        $this->accountEmployeeId = '';
        $this->accountIsActive = true;
        $this->resetPassword = '';
        $this->resetPasswordConfirm = '';
        $this->deleteConfirmOpen = false;
    }

    private function clearRoleDialog(): void
    {
        $this->roleId = null;
        $this->roleName = '';
        $this->roleAllData = false;
    }

    /**
     * The `_count` block of DELETE /api/accounts/[id]: notifications,
     * comments, tasks assigned/reviewed, projects managed, runs finalized,
     * requests reviewed, documents added, task files added.
     */
    private function accountIsReferenced(User $account): bool
    {
        $id = $account->getKey();

        return Notification::query()->where('user_id', $id)->exists()
            || TaskComment::query()->where('author_id', $id)->exists()
            || ProjectTask::query()->where('assigned_by', $id)->exists()
            || ProjectTask::query()->where('reviewed_by', $id)->exists()
            || Project::query()->where('manager_id', $id)->exists()
            || PayrollRun::query()->where('finalized_by', $id)->exists()
            || LeaveTravelRequest::query()->where('reviewed_by', $id)->exists()
            || EmployeeDocument::query()->where('uploaded_by', $id)->exists()
            || ProjectTaskFile::query()->where('uploaded_by', $id)->exists();
    }

    /**
     * `emailError` (src/lib/hris.ts:232-238): why an email address is
     * unacceptable, or null when it is fine. Blank is fine too — whether the
     * field is required is the form's own decision.
     */
    private static function emailError(string $value): ?string
    {
        $v = trim($value);

        if ($v === '') {
            return null;
        }

        return preg_match('/^[^\s@]+@[^\s@]+$/u', $v) === 1
            ? null
            : 'An email address needs an "@", like name@company.com.';
    }
}
