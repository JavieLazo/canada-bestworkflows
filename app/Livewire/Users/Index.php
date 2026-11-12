<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Domain\Enums\ModuleCode;
use App\Modules\IdentityAccess\Application\Commands\ManageAccounts;
use App\Modules\IdentityAccess\Application\Commands\ManageRoles;
use App\Modules\IdentityAccess\Application\Commands\ResetAccountPassword;
use App\Modules\IdentityAccess\Application\PasswordPolicy;
use App\Modules\IdentityAccess\Application\Queries\GetAccounts;
use App\Modules\IdentityAccess\Application\TemporaryPassword;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\IdentityAccess\Contracts\Views\RoleView as Role;
use App\Modules\IdentityAccess\Contracts\Views\UserView as User;
use App\Modules\Workforce\Application\Queries\AccountEmployees;
use App\Modules\Workforce\Contracts\DirectoryEmployee as EmployeeProfile;
use App\Support\Access;
use App\Support\EmailRule;
use App\Support\ModuleRegistry;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * User Management — the port of src/app/(protected)/users/users-page.tsx.
 *
 * One component owns the whole screen, exactly as the one source file did:
 * the Accounts and Roles & permissions tabs, the New-account dialog, the
 * account edit dialog, the guided password reset dialog, the
 * role dialog, the delete confirm, and the per-role module-grant matrix with
 * its confirm dialog.
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
 * component never stores module lists or `all_data` in a property. A holder
 * with a page open is sent to their landing page by their very next
 * `/livewire/update` call once its grant is revoked: EnsureModuleAccess runs
 * again there as persistent middleware and redirects a Livewire request.
 */
#[Layout('layouts.app')]
#[Title('User Management')]
class Index extends Component
{
    /** The Unassigned sentinel the role selects use (NO_ROLE in the source). */
    public const string NO_ROLE = '';

    /** The no-linked-employee sentinel (NO_EMPLOYEE in the source). */
    public const string NO_EMPLOYEE = '';

    /**
     * Which tab is showing. Plain UI state; anything unknown collapses back.
     * It rides in the URL so the page redrawn after a change to the actor's
     * own role ({@see self::confirmGrant()}) opens on the tab it left.
     */
    #[Url(as: 'tab', except: 'accounts')]
    public string $tab = 'accounts';

    /* ── New account dialog (users-page.tsx `form` + `adding`) ──────────── */

    /** Whether the New-account dialog is open. Flipping it reveals nothing. */
    public bool $adding = false;

    public string $createEmail = '';

    /**
     * The address the employee picker last wrote into the form, '' when it
     * wrote none. The picker replaces the email only while the field still
     * holds this value, so an address typed by hand is never overwritten.
     * Set by the server alone → #[Locked].
     */
    #[Locked]
    public string $createEmailFilled = '';

    public string $createPassword = '';

    /**
     * The temporary password generated for this form, '' when there is none.
     * A refused {@see self::createAccount()} leaves the password on the form
     * only while it is still this one; one typed by hand is cleared from that
     * response. Set by the server alone → #[Locked].
     */
    #[Locked]
    public string $createGenerated = '';

    /**
     * Bumped each time the New-account dialog opens, so the password field
     * re-keys and its reveal control starts masked. Flux keeps "revealed" in
     * the browser, the dialog's markup is never removed from the page, and
     * the field opens already holding a credential — without the re-key the
     * next account's password would open in clear text. #[Locked].
     */
    #[Locked]
    public int $createOpened = 0;

    /**
     * The picked role. Id-bearing → #[Locked]; the select writes it through
     * {@see self::createRoleChanged()}, which re-resolves the id first.
     */
    #[Locked]
    public string $createRoleId = '';

    /** The picked employee link. Id-bearing → #[Locked]; set via action only. */
    #[Locked]
    public string $createEmployeeId = '';

    /**
     * The name of an account with no employee record. Kept as typed while an
     * employee is picked, and ignored by the save: the record names the
     * account then.
     */
    public string $createName = '';

    public ?string $createNameError = null;

    /**
     * The inline email message (`emailErr`): set when the field is left, when
     * a pick offers an address, and by a save refused for the address's shape.
     */
    public ?string $createEmailError = null;

    /* ── Account edit dialog (`selectedAccount` + `accountForm`) ────────── */

    public bool $accountDialogOpen = false;

    /**
     * The account being edited. Id-bearing → #[Locked]; only
     * {@see self::openAccount()} sets it.
     */
    #[Locked]
    public ?string $accountId = null;

    public string $accountEmail = '';

    public ?string $accountEmailError = null;

    /** '' = Unassigned. Id-bearing → #[Locked]; set via action only. */
    #[Locked]
    public string $accountRoleId = '';

    /** '' = no linked employee. Id-bearing → #[Locked]; set via action only. */
    #[Locked]
    public string $accountEmployeeId = '';

    /** The typed name of an account with no employee record; ignored while one is linked. */
    public string $accountName = '';

    public ?string $accountNameError = null;

    /**
     * The status switch. Form INPUT for the target row, not the actor's own
     * authority — {@see self::saveAccount()} drops it for the actor's own
     * account and re-gates before applying, so tampering buys nothing.
     */
    public bool $accountIsActive = true;

    /**
     * The last loaded or saved details keep the form actions hidden until
     * a draft differs, including across selector and validation requests.
     *
     * @var array{email: string, role: string, employee: string, name: string, active: bool}|array{}
     */
    #[Locked]
    public array $accountOriginal = [];

    /* ── Password reset (passwordResetForm) ─────────────────────────────── */

    public string $resetPassword = '';

    public string $resetPasswordConfirm = '';

    public bool $passwordResetDialogOpen = false;

    public bool $passwordReset = false;

    /* ── Delete confirm ─────────────────────────────────────────────────── */

    public bool $deleteConfirmOpen = false;

    /* ── Role dialog (`roleForm` + `selectedRole` + `roleDialogMode`) ───── */

    public bool $roleDialogOpen = false;

    /** null = create mode, an id = edit mode. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $roleId = null;

    public string $roleName = '';

    public bool $roleAllData = false;

    /* ── Grant confirm (the matrix's stage-then-confirm) ────────────────── */

    /** Whether the grant confirm dialog is open. Flipping it writes nothing. */
    public bool $grantConfirmOpen = false;

    /**
     * The staged grant's role. Id-bearing → #[Locked]: {@see self::askGrant()}
     * alone sets it, after re-resolving the id, and
     * {@see self::confirmGrant()} clears it whether or not the write
     * succeeds. A dialog dismissed in the browser leaves the grant staged,
     * which is harmless — the next tick overwrites it and nothing reads it
     * but the confirm dialog.
     */
    #[Locked]
    public ?string $grantRoleId = null;

    /** The staged grant's module code, set with `$grantRoleId`. #[Locked]. */
    #[Locked]
    public string $grantModule = '';

    /**
     * The staged grant's intended state: true grants, false revokes. It
     * decides what the confirm writes, so it is #[Locked] with the pair.
     */
    #[Locked]
    public bool $grantOn = false;

    /**
     * Bumped whenever a box is ticked or a staged grant is confirmed, so the
     * checkbox re-keys. Flux's checkbox flips itself in the browser on the
     * click; the re-key draws it back to what the database says, the way the
     * reference's controlled <Checkbox> did — unchanged while the dialog is
     * asking, and after a refused write. Each box also sends the value it was
     * drawn with, which is how {@see self::askGrant()} tells a tick on the
     * matrix as last drawn from one made before the dialog arrived.
     */
    #[Locked]
    public int $grantNonce = 0;

    /* ── guards on client-writable state ────────────────────────────────── */

    /** A tab named in the URL passes the same guard as one set from the page. */
    public function mount(): void
    {
        $this->updatedTab($this->tab);
    }

    public function updatedTab(string $value): void
    {
        $this->tab = in_array($value, ['accounts', 'roles'], true) ? $value : 'accounts';

        if ($this->tab !== 'accounts') {
            $this->closeAccount();
        }
    }

    /**
     * Open the New-account dialog on a form that holds nothing from the last
     * attempt (users-page.tsx resetForm, called on open rather than on close).
     *
     * The reset CANNOT hang off the close alone. Dismissing a <flux:modal> with
     * its × — or Escape, or the backdrop — closes it client-side without
     * writing `adding` back, so {@see self::updatedAdding()} never runs and
     * {@see self::resetCreateForm()} never fires. A $createEmployeeId picked
     * for an abandoned account would then survive into the NEXT create and be
     * written at createAccount(), silently linking the new sign-in to a
     * colleague's 201 record that whoever filled the form never chose.
     *
     * Resetting here instead makes the dialog's opening state depend on this
     * request, not on whether a previous close happened to reach the server.
     *
     * The form opens with its temporary password already generated, so
     * nobody has to invent one that meets the policy.
     */
    public function openCreate(): void
    {
        $this->resetCreateForm();
        $this->generateCreatePassword();
        $this->createOpened++;
        $this->adding = true;
    }

    /**
     * Put a fresh temporary password on the New-account form — on open, and
     * from its "Generate another" button. Nothing is stored: the password
     * only becomes a credential when {@see self::createAccount()} saves it.
     */
    public function generateCreatePassword(): void
    {
        $this->createGenerated = TemporaryPassword::generate();
        $this->createPassword = $this->createGenerated;
        $this->resetValidation('createPassword');
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

    public function closeAccount(): void
    {
        $this->accountDialogOpen = false;
        $this->clearAccountDialog();
    }

    public function updatedPasswordResetDialogOpen(bool $value): void
    {
        if (! $value) {
            $this->cancelPasswordReset();
        }
    }

    /** Closing the role dialog forgets the role (clearRoleDialog). */
    public function updatedRoleDialogOpen(bool $value): void
    {
        if (! $value) {
            $this->clearRoleDialog();
        }
    }

    /** Cancelling the grant confirm forgets the staged grant. */
    public function updatedGrantConfirmOpen(bool $value): void
    {
        if (! $value) {
            $this->clearGrantConfirm();
        }
    }

    /**
     * The onBlur email check of the create form (users-page.tsx:613): the
     * address's shape, then whether a sign-in already uses it — the refusal
     * the save would give, shown while it can still be changed.
     */
    public function updatedCreateEmail(): void
    {
        $this->createEmailError = $this->createEmailProblem(app(Access::class), $this->createEmail);
    }

    /** The onBlur email check of the edit form (users-page.tsx:718). */
    public function updatedAccountEmail(): void
    {
        $this->accountEmailError = self::emailError($this->accountEmail);
    }

    /* ── select bindings: Locked ids written through validating actions ── */

    public function createRoleChanged(string $id): void
    {
        if ($id === '' || app(GetAccounts::class)->role(app(Access::class), $id) !== null) {
            $this->createRoleId = $id;
        }
    }

    /**
     * The picked employee also offers the login email: the record's contact
     * email, read for an actor who may provision the account and nobody else.
     * It is a starting value the form can change, and the two addresses stay
     * separate once the account exists.
     */
    public function createEmployeeChanged(string $id): void
    {
        $actor = app(Access::class);
        $employees = app(AccountEmployees::class);

        if ($id !== '' && ! $employees->exists($actor, $id)) {
            return;
        }

        $this->createEmployeeId = $id;
        $this->offerCreateEmail($actor, $id === '' ? '' : (string) $employees->contactEmail($actor, $id));
    }

    public function accountRoleChanged(string $id): void
    {
        if ($id === '' || app(GetAccounts::class)->role(app(Access::class), $id) !== null) {
            $this->accountRoleId = $id;
        }
    }

    public function accountEmployeeChanged(string $id): void
    {
        if ($id === '' || app(AccountEmployees::class)->exists(app(Access::class), $id)) {
            $this->accountEmployeeId = $id;
        }
    }

    /* ── New account (createAccount + POST /api/accounts) ───────────────── */

    public function createAccount(): void
    {
        $password = $this->createPassword;

        // A refused attempt keeps the generated password — whoever is filling
        // the form may already have copied it — and drops one typed by hand.
        if ($this->createGenerated === '' || ! hash_equals($this->createGenerated, $password)) {
            $this->createPassword = '';
        }

        $this->resetValidation('createPassword');
        $actor = app(Access::class);

        if ($this->refuseWrite($actor)) {
            return;
        }

        $email = trim($this->createEmail);

        $emailProblem = self::emailError($email);

        if ($emailProblem !== null) {
            $this->createEmailError = $emailProblem;

            return;
        }

        // An account with no employee record is named here; a linked one
        // takes the record's name, so nothing typed is sent for it.
        $name = $this->createEmployeeId === '' ? trim($this->createName) : '';
        $this->createNameError = self::nameError($name, $this->createEmployeeId === '');

        if ($this->createNameError !== null) {
            return;
        }

        try {
            app(ManageAccounts::class)->create($actor, $email, $password, $this->createRoleId, $this->createEmployeeId, $name);
        } catch (ValidationException $e) {
            $this->showPasswordErrors($e, 'createPassword');

            return;
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'Could not create the account');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        $this->resetCreateForm();
        $this->adding = false;
        Flux::toast(variant: 'success', text: 'Account created.');
    }

    /* ── Account edit dialog ─────────────────────────────────────────────── */

    /** Open an account's dialog — the row click of users-page.tsx:410. */
    public function openAccount(string $id): void
    {
        $this->cancelPasswordReset();
        $this->passwordReset = false;
        $account = app(GetAccounts::class)->account(app(Access::class), $id);

        if ($account === null) {
            return;
        }

        $this->accountId = (string) $account->getKey();
        $this->fillAccountForm($account);
        $this->accountEmailError = null;
        $this->deleteConfirmOpen = false;
        $this->accountDialogOpen = true;
    }

    /** The "Reset changes" button: back to what is stored. */
    public function resetAccountChanges(): void
    {
        $account = $this->accountId !== null ? app(GetAccounts::class)->account(app(Access::class), $this->accountId) : null;

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

        $email = trim($this->accountEmail);
        $emailProblem = self::emailError($email);

        if ($emailProblem !== null) {
            $this->accountEmailError = $emailProblem;

            return;
        }

        // Blank is allowed on an existing account: it reads by its number.
        $name = $this->accountEmployeeId === '' ? trim($this->accountName) : '';
        $this->accountNameError = self::nameError($name, false);

        if ($this->accountNameError !== null) {
            return;
        }

        try {
            $account = app(ManageAccounts::class)->update($actor, $this->accountId, $email, $this->accountRoleId, $this->accountEmployeeId, $this->accountIsActive, $name);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'Could not update the account');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        $this->fillAccountForm($account);
        $this->accountEmailError = null;
        Flux::toast(variant: 'success', text: 'Account updated.');
    }

    public function openPasswordReset(): void
    {
        $this->cancelPasswordReset();
        $this->passwordReset = false;
        $actor = app(Access::class);

        if ($this->refuseWrite($actor) || $this->accountId === null) {
            return;
        }

        if (app(GetAccounts::class)->account($actor, $this->accountId) === null) {
            return;
        }

        $this->passwordResetDialogOpen = true;
    }

    public function cancelPasswordReset(): void
    {
        $this->passwordResetDialogOpen = false;
        $this->clearPasswordResetDraft();
    }

    /** submitPasswordReset + POST /api/accounts/[id]/password. */
    public function resetAccountPassword(): void
    {
        $password = $this->resetPassword;
        $confirmation = $this->resetPasswordConfirm;
        $this->clearPasswordResetDraft();
        $this->passwordReset = false;
        $actor = app(Access::class);
        if ($this->refuseWrite($actor) || $this->accountId === null) {
            return;
        }
        try {
            $name = app(ResetAccountPassword::class)->handle($actor, $this->accountId, $password, $confirmation);
        } catch (ValidationException $e) {
            $this->showPasswordErrors($e, 'resetPassword', 'resetPasswordConfirm');

            return;
        } catch (QueryException $e) {
            report($e);
            $this->addError('passwordResetForm', 'The password could not be reset. Please try again.');

            return;
        } catch (RuntimeException $e) {
            $this->addError('passwordResetForm', $e->getMessage());

            return;
        }
        $this->cancelPasswordReset();
        $this->passwordReset = true;
        Flux::toast(variant: 'success', text: "Password reset for {$name}.");
    }

    /** deleteSelectedAccount + DELETE /api/accounts/[id]. */
    public function deleteAccount(): void
    {
        $actor = app(Access::class);
        if ($this->refuseWrite($actor) || $this->accountId === null) {
            return;
        }
        try {
            $name = app(ManageAccounts::class)->delete($actor, $this->accountId);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The account could not be deleted. Please try again.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
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
        $role = app(GetAccounts::class)->role(app(Access::class), $id);

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
        $actor = app(Access::class);
        if (trim($this->roleName) === '') {
            Flux::toast(variant: 'danger', text: "Role name can't be empty.");

            return;
        }
        if ($this->refuseWrite($actor)) {
            return;
        }
        $created = $this->roleId === null;
        try {
            app(ManageRoles::class)->save($actor, $this->roleId, $this->roleName, $this->roleAllData);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The role could not be saved. Please try again.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $created ? 'Role created.' : 'Role updated.');
        $this->roleDialogOpen = false;
        $this->clearRoleDialog();
    }

    /* ── The grant matrix (toggleGrant + /api/roles/[id]/grants) ────────── */

    /**
     * A ticked box only STAGES its grant and opens the confirm dialog.
     *
     * DELIBERATE DIVERGENCE from the reference, whose checkbox writes on its
     * change (users-page.tsx:494): A TICK NEVER WRITES. One stray click on
     * the matrix would otherwise change what every account of the role can
     * open on its next request, so the dialog names the role and the module
     * first and {@see self::confirmGrant()} does the write.
     *
     * Both client arguments are re-resolved before they are staged: the role
     * against the stored roles, the module against the known codes. The
     * User Management box of the actor's own role is drawn disabled, and a
     * call for it stages nothing; the command refuses that removal as well.
     *
     * A TICK COUNTS ONLY ON THE MATRIX AS LAST DRAWN. `$drawn` is the
     * `$grantNonce` the box was drawn with. Livewire holds an action clicked
     * while a request is in flight and sends it afterwards, so a second box
     * ticked before the dialog arrived would otherwise restage the grant
     * under the open dialog, and its button would write a grant it never
     * named. That tick carries the earlier nonce: it stages nothing, and its
     * box is drawn back to the stored state like any other. A dialog
     * dismissed in the browser needs no such care — the matrix it returns to
     * carries the current nonce.
     */
    public function askGrant(string $roleId, string $module, bool $granted, int $drawn): void
    {
        $current = $drawn === $this->grantNonce;
        $this->grantNonce++;

        if (! $current) {
            return;
        }

        $actor = app(Access::class);
        if ($this->refuseWrite($actor)) {
            return;
        }

        $role = app(GetAccounts::class)->role($actor, $roleId);

        if ($role === null || ! in_array($module, ModuleCode::all(), true)) {
            return;
        }

        if (! $granted && $module === ModuleCode::USERS && $actor->roleId === (string) $role->getKey()) {
            return;
        }

        $this->grantRoleId = (string) $role->getKey();
        $this->grantModule = $module;
        $this->grantOn = $granted;
        $this->grantConfirmOpen = true;
    }

    /**
     * The confirm dialog's button: write the staged grant in its intended
     * state. IT TAKES NO ARGUMENT ON PURPOSE — an action argument comes from
     * the client, and a grant passed that way would skip the dialog. The
     * staged grant is cleared before the write, so a second click writes
     * nothing. The next request's Access re-reads `role_module_grants`, so
     * the change is live immediately — there is no cache to bust.
     *
     * A CHANGE TO THE ACTOR'S OWN ROLE REDRAWS THE WHOLE PAGE. The sidebar is
     * drawn by the layout from the grants of the request that loaded the
     * page, so a component update alone would leave the module the actor
     * just gave or took from themselves as it was until their next page
     * load. The redirect comes back to this tab.
     */
    public function confirmGrant(): void
    {
        $roleId = $this->grantRoleId;
        $module = $this->grantModule;
        $granted = $this->grantOn;

        $this->grantNonce++;
        $this->grantConfirmOpen = false;
        $this->clearGrantConfirm();

        if ($roleId === null || $module === '') {
            return;
        }

        $actor = app(Access::class);
        if ($this->refuseWrite($actor)) {
            return;
        }
        try {
            app(ManageRoles::class)->toggleGrant($actor, $roleId, $module, $granted);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The grant could not be changed. Please try again.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        if ($actor->roleId === $roleId) {
            $this->redirectRoute('users.index', ['tab' => 'roles'], navigate: true);
        }
    }

    /* ── render ─────────────────────────────────────────────────────────── */

    public function render(ModuleRegistry $registry): View
    {
        $actor = app(Access::class);

        // Account labels use the linked employee's current name.
        $listing = app(GetAccounts::class)->handle($actor);
        $accounts = $listing['accounts'];
        $roles = $listing['roles'];
        $employees = app(AccountEmployees::class)->list($actor);

        // listRoleGrants() — every role's grants, one set for the matrix.
        $granted = [];

        foreach ($listing['grants'] as $grant) {
            $granted[$grant->role_id.'|'.$grant->module] = true;
        }

        $roleNames = $roles->mapWithKeys(
            static fn (Role $role) => [(string) $role->getKey() => $role->name],
        )->all();

        $employeesById = $employees->keyBy(
            static fn (EmployeeProfile $employee) => (string) $employee->getKey(),
        );

        // The sign-ins the picked employee already has, named under the picker
        // of the New-account form: a further one is allowed, never a surprise.
        $createEmployeeAccounts = $this->createEmployeeId === ''
            ? $accounts->take(0)
            : $accounts
                ->filter(fn (User $account): bool => (string) $account->employee_id === $this->createEmployeeId)
                ->sortBy('email', SORT_NATURAL | SORT_FLAG_CASE)
                ->values();

        // Dialog titles resolve the same employee name as the account list.
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
            'createEmployeeAccounts' => $createEmployeeAccounts,
            // The confirm dialog names the staged grant by the STORED role
            // name and the module's label; null while nothing is staged.
            'grantRoleName' => $this->grantRoleId === null ? null : ($roleNames[$this->grantRoleId] ?? null),
            'grantModuleLabel' => $this->grantModule === '' ? null : $registry->moduleLabel($this->grantModule),
            // MODULES, in spec order, for the matrix columns.
            'modules' => $registry->all(),
            'selectedAccountName' => $selectedAccount?->name,
            'selectedAccountEmail' => $selectedAccount?->email,
            'passwordPolicy' => [
                'minLength' => PasswordPolicy::MIN_LENGTH,
                'maxBytes' => PasswordPolicy::MAX_BYTES,
            ],
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
        $this->accountEmail = (string) $account->email;
        $this->accountRoleId = $account->role_id === null ? '' : (string) $account->role_id;
        $this->accountEmployeeId = $account->employee_id === null ? '' : (string) $account->employee_id;
        $this->accountName = (string) ($account->account_name ?? '');
        $this->accountNameError = null;
        $this->accountIsActive = (bool) $account->is_active;
        $this->accountOriginal = [
            'email' => $this->accountEmail,
            'role' => $this->accountRoleId,
            'employee' => $this->accountEmployeeId,
            'name' => $this->accountName,
            'active' => $this->accountIsActive,
        ];
    }

    /**
     * Why a typed account name cannot be used, or null. `$required` is the
     * New-account form's rule for an account with no employee record.
     */
    private static function nameError(string $name, bool $required): ?string
    {
        if ($name === '') {
            return $required ? 'Enter a name for the account, or link an employee record.' : null;
        }

        return mb_strlen($name) > AccountLabel::NAME_CAP
            ? 'Name must be at most '.AccountLabel::NAME_CAP.' characters.'
            : null;
    }

    private function resetCreateForm(): void
    {
        $this->createEmail = '';
        $this->createEmailFilled = '';
        $this->createPassword = '';
        $this->createGenerated = '';
        $this->createRoleId = '';
        $this->createEmployeeId = '';
        $this->createName = '';
        $this->createNameError = null;
        $this->createEmailError = null;
        $this->resetValidation('createPassword');
    }

    /**
     * Write the picked employee's contact email into the New-account form,
     * or take the last one back out when the new pick has none.
     *
     * AN ADDRESS TYPED BY HAND IS LEFT ALONE: the field is written only while
     * it is empty or still holds what the picker put there. The offer is
     * checked like a typed address, so one a sign-in already uses arrives
     * with its refusal.
     */
    private function offerCreateEmail(Access $actor, string $email): void
    {
        if (trim($this->createEmail) !== '' && $this->createEmail !== $this->createEmailFilled) {
            return;
        }

        $this->createEmail = $email;
        $this->createEmailFilled = $email;
        $this->createEmailError = $this->createEmailProblem($actor, $email);
    }

    /**
     * Why the create form's email cannot be used, or null: its shape first,
     * then the duplicate-login refusal in the words the save uses. Blank is
     * fine here — the field's own `required` says otherwise.
     */
    private function createEmailProblem(Access $actor, string $email): ?string
    {
        $problem = self::emailError($email);

        if ($problem !== null || trim($email) === '') {
            return $problem;
        }

        return app(GetAccounts::class)->emailTaken($actor, $email) ? ManageAccounts::EMAIL_TAKEN : null;
    }

    private function clearAccountDialog(): void
    {
        $this->accountId = null;
        $this->accountEmail = '';
        $this->accountEmailError = null;
        $this->accountRoleId = '';
        $this->accountEmployeeId = '';
        $this->accountName = '';
        $this->accountNameError = null;
        $this->accountIsActive = true;
        $this->accountOriginal = [];
        $this->cancelPasswordReset();
        $this->passwordReset = false;
        $this->deleteConfirmOpen = false;
    }

    private function clearPasswordResetDraft(): void
    {
        $this->reset('resetPassword', 'resetPasswordConfirm');
        $this->resetValidation(['resetPassword', 'resetPasswordConfirm', 'passwordResetForm']);
    }

    private function showPasswordErrors(ValidationException $exception, string $passwordField, ?string $confirmationField = null): void
    {
        foreach ($exception->errors() as $field => $messages) {
            $input = in_array($field, ['confirmation', 'password_confirmation'], true)
                ? ($confirmationField ?? $passwordField)
                : $passwordField;
            foreach ($messages as $message) {
                $this->addError($input, $message);
            }
        }
    }

    private function clearRoleDialog(): void
    {
        $this->roleId = null;
        $this->roleName = '';
        $this->roleAllData = false;
    }

    private function clearGrantConfirm(): void
    {
        $this->grantRoleId = null;
        $this->grantModule = '';
        $this->grantOn = false;
    }

    /**
     * `emailError` (src/lib/hris.ts:232-238): why an email address is
     * unacceptable, or null when it is fine. Blank is fine too — whether the
     * field is required is the form's own decision. The one rule is
     * EmailRule, shared with EmployeeFields.
     */
    private static function emailError(string $value): ?string
    {
        return EmailRule::error($value);
    }
}
