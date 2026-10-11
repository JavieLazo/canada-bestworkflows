{{--
    User Management — the port of src/app/(protected)/users/users-page.tsx.
    See App\Livewire\Users\Index for the state and actions behind this.

    Fidelity notes where shadcn had no Flux-free twin:
      - Tabs: the TabsList/TabsTrigger strip is the same Tailwind
        approximation the eDTR page uses; switching re-renders server-side,
        which matches Radix unmounting inactive content.
      - Dialog / AlertDialog are <flux:modal>; titles and descriptions are
        plain markup carrying DialogTitle/DialogDescription classes.
      - The Save button's floppy-disk icon (lucide `Save`) has no heroicon;
        `check` stands in. Loader2 spinners are Flux's built-in wire-loading
        spinner on the acting button.
      - The grant Checkboxes and select fields snap back on a refused write by
        re-keying, the way the reference's controlled inputs did.
      - A grant Checkbox only asks: the tick opens the grant confirm dialog
        and the box re-keys back to the stored state until that dialog's
        button writes. DELIBERATE DIVERGENCE from the reference's
        write-on-change checkbox (users-page.tsx:494). Each box sends the
        nonce it was drawn with, so a tick made before the dialog arrived
        cannot change what the dialog is asking about.
--}}
@php
    use App\Modules\IdentityAccess\Application\PasswordPolicy;

    $employeeName = static fn ($employee): string => \App\Support\EmployeeName::display($employee);
@endphp

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">User Management</h1>
        <p class="text-sm text-muted-foreground">
            Permissions are role-based: a role's module grants decide what each account can open.
        </p>
    </div>

    <div>
        {{-- TabsList — shadcn's muted strip with the active trigger raised. --}}
        <div class="inline-flex h-9 items-center justify-center rounded-lg bg-muted p-1 text-muted-foreground" role="tablist">
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === 'accounts' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'accounts')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'accounts' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
            >Accounts</button>
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === 'roles' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'roles')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'roles' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
            >Roles &amp; permissions</button>
        </div>

        @if ($tab === 'accounts')
            <div class="mt-4">
                <section class="hris-card overflow-hidden">
                    <header class="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3">
                        <p class="text-sm text-muted-foreground">
                            Sign-ins, their roles, and the employee each one belongs to.
                        </p>
                        <div class="ml-auto flex flex-wrap items-center gap-2">
                            <x-kit.export-button :href="route('users.export')" />
                            <flux:button variant="primary" icon="user-plus" wire:click="openCreate">
                                New account
                            </flux:button>
                        </div>
                    </header>
                    <div class="hris-table-wrap hris-table-scroll">
                        <table class="hris-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Linked employee</th>
                                    <th class="text-right">Active</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($accounts as $a)
                                    @php $aid = (string) $a->getKey(); @endphp
                                    <tr
                                        wire:key="acct-{{ $aid }}"
                                        tabindex="0"
                                        role="button"
                                        aria-label="Open account details for {{ $a->name }}"
                                        wire:click="openAccount('{{ $aid }}')"
                                        x-on:keydown.enter.prevent="$wire.openAccount('{{ $aid }}')"
                                        x-on:keydown.space.prevent="$wire.openAccount('{{ $aid }}')"
                                        class="cursor-pointer transition-colors hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none"
                                    >
                                        <td class="font-medium">{{ $a->name }}</td>
                                        <td class="num text-muted-foreground">{{ $a->email }}</td>
                                        <td>{{ $a->role_id !== null ? ($roleNames[(string) $a->role_id] ?? 'Unassigned') : 'Unassigned' }}</td>
                                        <td class="text-muted-foreground">
                                            {{ $employeeName($a->employee_id !== null ? ($employeesById[(string) $a->employee_id] ?? null) : null) }}
                                        </td>
                                        <td class="text-right">
                                            <span class="{{ $a->is_active ? 'pill pill-success' : 'pill pill-muted' }}">
                                                {{ $a->is_active ? 'Active' : 'Inactive' }}
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="pencil"
                                                aria-label="Edit {{ $a->name }}"
                                                wire:click.stop="openAccount('{{ $aid }}')"
                                            />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        @else
            <div class="mt-4">
                <section class="hris-card overflow-hidden">
                    <header class="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3">
                        <p class="text-sm text-muted-foreground">
                            Roles, employee-data scope, and module access.
                        </p>
                        <div class="ml-auto flex flex-wrap items-center gap-2">
                            <x-kit.export-button :href="route('users.roles.export')" />
                            <flux:button variant="primary" icon="plus" wire:click="openNewRole">
                                New role
                            </flux:button>
                        </div>
                    </header>
                    <div class="hris-table-wrap hris-table-scroll">
                        <table class="hris-table">
                            <thead>
                                <tr>
                                    <th>Role</th>
                                    <th>Scope</th>
                                    @foreach ($modules as $m)
                                        <th class="text-center">{{ $m['label'] }}</th>
                                    @endforeach
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($roles as $r)
                                    @php $rid = (string) $r->getKey(); @endphp
                                    <tr wire:key="role-{{ $rid }}">
                                        <td class="font-medium">{{ $r->name }}</td>
                                        <td>
                                            <span class="{{ $r->all_data ? 'pill pill-info' : 'pill pill-muted' }}">
                                                {{ $r->all_data ? 'All employees' : 'Own records' }}
                                            </span>
                                        </td>
                                        @foreach ($modules as $m)
                                            @php
                                                $on = isset($granted[$rid.'|'.$m['code']]);
                                                // A checked USERS box on the signed-in
                                                // admin's own role stays disabled — unchecking
                                                // it would strand them (users-page.tsx:488).
                                                $protectsCurrentUser = $actorRoleId === $rid && $m['code'] === 'USERS';
                                            @endphp
                                            <td class="text-center">
                                                <span wire:key="grant-{{ $rid }}-{{ $m['code'] }}-{{ $on ? 1 : 0 }}-{{ $grantNonce }}">
                                                    <flux:checkbox
                                                        :checked="$on"
                                                        :disabled="$protectsCurrentUser"
                                                        wire:click="askGrant('{{ $rid }}', '{{ $m['code'] }}', {{ $on ? 'false' : 'true' }}, {{ $grantNonce }})"
                                                        aria-label="{{ $r->name }} {{ $m['label'] }}"
                                                    />
                                                </span>
                                            </td>
                                        @endforeach
                                        <td class="text-right">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="pencil"
                                                aria-label="Edit {{ $r->name }}"
                                                wire:click="openEditRole('{{ $rid }}')"
                                            />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        @endif
    </div>

    {{-- Grant confirm — no reference dialog; modelled on the roster's
         confirm-assign. A ticked box in the matrix opens it, and nothing is
         written until its button calls confirmGrant().

         Accessible name: Flux forwards only class/style/autofocus from
         <flux:modal> to its <dialog>, so the heading carries an id and
         x-init points the dialog's aria-labelledby at it once Alpine boots
         (the dialog-project-delete trick). --}}
    <flux:modal
        wire:model.self="grantConfirmOpen"
        class="w-full max-w-md"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'grant-confirm-heading')"
    >
        <div class="space-y-4">
            <div>
                {{-- pe-8 keeps a long role or module name clear of the dialog's close button. --}}
                <flux:heading size="lg" id="grant-confirm-heading" class="pe-8">
                    @if ($grantRoleName === null || $grantModuleLabel === null)
                        Change this permission?
                    @elseif ($grantOn)
                        Give {{ $grantRoleName }} access to {{ $grantModuleLabel }}?
                    @else
                        Remove {{ $grantModuleLabel }} from {{ $grantRoleName }}?
                    @endif
                </flux:heading>
                <flux:text class="mt-2">
                    Nothing has been saved yet.
                    @if ($grantOn)
                        Every account with this role can open it once you confirm.
                    @else
                        Every account with this role loses it once you confirm.
                    @endif
                </flux:text>
            </div>
            <div class="flex items-center justify-end gap-2">
                <flux:button type="button" variant="outline" wire:click="$set('grantConfirmOpen', false)">
                    Cancel
                </flux:button>
                <flux:button type="button" variant="primary" icon="check" wire:click="confirmGrant">
                    {{ $grantOn ? 'Give access' : 'Remove access' }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Role dialog — create or edit (users-page.tsx:520-573). --}}
    <flux:modal wire:model.self="roleDialogOpen" class="w-full max-w-md">
        <div class="space-y-1.5">
            <h2 class="text-lg font-semibold leading-none tracking-tight">
                {{ $roleId === null ? 'New role' : 'Edit role' }}
            </h2>
            <p class="sr-only">
                {{ $roleId === null ? 'Create a user-management role.' : 'Update this user-management role.' }}
            </p>
        </div>
        <form class="mt-4 grid gap-4" wire:submit="saveRole">
            <div class="space-y-2">
                <flux:label for="role-name">Role name</flux:label>
                <flux:input id="role-name" required maxlength="80" wire:model="roleName" />
            </div>
            <div class="flex items-center justify-between gap-4 rounded-md border border-border p-4">
                <div>
                    <flux:label for="role-all-data">Employee-data scope</flux:label>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ $roleAllData ? 'All employees' : 'Own records' }}
                    </p>
                </div>
                <flux:switch
                    id="role-all-data"
                    wire:model.live="roleAllData"
                    aria-label="Organization-wide employee data"
                />
            </div>
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="outline" wire:click="$set('roleDialogOpen', false)">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary" icon="pencil">
                    {{ $roleId === null ? 'Create role' : 'Save role' }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- New account dialog (users-page.tsx:575-674). --}}
    <flux:modal wire:model.self="adding" class="w-full max-w-lg">
        <div class="space-y-1.5">
            <h2 class="text-lg font-semibold leading-none tracking-tight">New account</h2>
            <p class="text-sm text-muted-foreground">
                Create a sign-in and hand it a role &mdash; the role&rsquo;s grants decide which modules the
                account can open. Personal information comes from the linked employee record.
            </p>
        </div>
        <form class="mt-4 grid gap-4 sm:grid-cols-2" wire:submit="createAccount">
            {{-- The employee comes first: a pick offers the record's contact
                 email as the login email below, and names the sign-ins that
                 employee already has. --}}
            <div class="sm:col-span-2">
                <flux:label>Link to employee record (optional)</flux:label>
                <div class="mt-2" wire:key="create-employee-{{ $createEmployeeId === '' ? 'none' : $createEmployeeId }}">
                    {{--
                        The empty option is a real <option value="">, not Flux's
                        `placeholder`: Flux renders a placeholder as
                        <option value="" disabled>, which can be the opening
                        selection but can never be chosen again. The link is
                        optional, so unlinking has to stay reachable — the edit
                        dialog's picker below spells it the same way. (The Role
                        select keeps its placeholder on purpose: a role is
                        required, so "none" must NOT be selectable there.)
                    --}}
                    <x-kit.select
                        aria-label="Link to employee record (optional)"
                        wire:change="createEmployeeChanged($event.target.value)"
                    >
                        <flux:select.option value="" :selected="$createEmployeeId === ''">No linked employee</flux:select.option>
                        @foreach ($employees as $e)
                            <flux:select.option value="{{ $e->getKey() }}" :selected="$createEmployeeId === (string) $e->getKey()">{{ \App\Support\EmployeeIdentity::option($e, $employeeName($e)) }}</flux:select.option>
                        @endforeach
                    </x-kit.select>
                </div>
                {{-- ANOTHER SIGN-IN FOR THE SAME EMPLOYEE IS ALLOWED. The note
                     only makes sure it is deliberate: it blocks nothing. It
                     says so only for an active record — the save refuses to
                     link an inactive one, with or without this note. The
                     status region is always on the page, so a screen reader
                     hears the note when a pick fills it. --}}
                <div role="status" data-existing-accounts>
                    @if ($createEmployeeAccounts->isNotEmpty())
                        <div wire:key="create-existing-{{ $createEmployeeId }}" class="mt-2 flex gap-2 rounded-md border border-warning/20 bg-warning-soft px-3 py-2 text-sm text-foreground">
                            <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0 text-warning" />
                            <div class="min-w-0 space-y-1">
                                <p class="font-medium">{{ $employeeName($employeesById[$createEmployeeId] ?? null) }} already has {{ $createEmployeeAccounts->count() === 1 ? 'an account' : $createEmployeeAccounts->count().' accounts' }}.</p>
                                <ul class="space-y-0.5">
                                    @foreach ($createEmployeeAccounts as $existing)
                                        <li wire:key="create-existing-account-{{ $existing->getKey() }}" class="break-words">
                                            <span class="num">{{ $existing->email }}</span>
                                            &middot; {{ $existing->role_id !== null ? ($roleNames[(string) $existing->role_id] ?? 'Unassigned') : 'Unassigned' }}
                                            &middot; {{ $existing->is_active ? 'Active' : 'Inactive' }}
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($employeesById[$createEmployeeId]->is_active ?? false)
                                    <p>You can still create another sign-in for this employee. It needs its own login email.</p>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </div>
            <div class="space-y-2">
                <flux:label>Email</flux:label>
                {{-- `.live.blur` sends the address when the field is left, which
                     is what runs the check and clears a refusal once the
                     address is changed; a bare `.blur` only syncs in the
                     browser. The field is read-only while a pick is on its
                     way, so the offered address cannot land on top of typing.
                     Flux's own loading wiring is off because it would claim
                     the input's wire:target. --}}
                <flux:input
                    type="email"
                    required
                    wire:model.live.blur="createEmail"
                    :loading="false"
                    wire:loading.attr="readonly"
                    wire:target="createEmployeeChanged"
                    aria-invalid="{{ $createEmailError ? 'true' : 'false' }}"
                    class="{{ $createEmailError ? 'border-danger' : '' }}"
                />
                @if ($createEmailError)
                    <p class="text-xs text-danger">{{ $createEmailError }}</p>
                @endif
            </div>
            <div class="space-y-2">
                <flux:label>Role</flux:label>
                <div wire:key="create-role-{{ $createRoleId === '' ? 'none' : $createRoleId }}">
                    <x-kit.select
                        aria-label="Role"
                        placeholder="Select role"
                        wire:change="createRoleChanged($event.target.value)"
                    >
                        @foreach ($roles as $r)
                            <flux:select.option value="{{ $r->getKey() }}" :selected="$createRoleId === (string) $r->getKey()">{{ $r->name }}</flux:select.option>
                        @endforeach
                    </x-kit.select>
                </div>
            </div>
            {{-- The password is already on the form when the dialog opens. It
                 takes the full row so all of it shows once revealed, and it
                 re-keys on every open so the reveal control starts masked
                 again for the next account. --}}
            <div class="space-y-2 sm:col-span-2">
                <div wire:key="create-password-{{ $createOpened }}">
                    <flux:input label="Temporary password" type="password" class="num" :minlength="PasswordPolicy::MIN_LENGTH" required wire:model="createPassword" autocomplete="new-password" viewable copyable />
                </div>
                <div class="flex flex-col gap-x-4 gap-y-1 sm:flex-row sm:justify-between">
                    <p class="min-w-0 flex-1 text-xs text-muted-foreground">
                        Generated automatically. Copy it before you create the account &mdash; it is not shown again.
                        To type your own, use at least {{ PasswordPolicy::MIN_LENGTH }} characters, with uppercase and lowercase letters, a number and a symbol.
                    </p>
                    <flux:button type="button" size="xs" variant="ghost" icon="arrow-path" class="shrink-0 self-start" wire:click="generateCreatePassword">
                        Generate another
                    </flux:button>
                </div>
            </div>
            <div class="sm:col-span-2">
                <flux:button type="submit" variant="primary" icon="user-plus">
                    Create account
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Account edit dialog (users-page.tsx:676-876). --}}
    <flux:modal wire:model.self="accountDialogOpen" @close="closeAccount" class="w-full max-w-2xl">
        <div class="space-y-1.5">
            <h2 class="text-lg font-semibold leading-none tracking-tight">
                {{ $selectedAccountName !== null ? 'Edit '.$selectedAccountName : 'Edit account' }}
            </h2>
            <p class="text-sm text-muted-foreground">Edit account details and access for this sign-in.</p>
        </div>
        @if ($accountId !== null)
            <div class="mt-4 grid gap-5">
                <form class="grid gap-4 sm:grid-cols-2" wire:submit="saveAccount"
                    x-data="{
                        get hasChanges() {
                            const saved = $wire.accountOriginal;
                            return 'email' in saved && (
                                $wire.accountEmail.trim() !== saved.email ||
                                $wire.accountEmployeeId !== saved.employee ||
                                ($wire.accountId !== @js($actorUserId) && (
                                    $wire.accountRoleId !== saved.role ||
                                    $wire.accountIsActive !== saved.active
                                ))
                            );
                        },
                    }"
                >
                    <div class="space-y-2">
                        <flux:label for="account-email">Email</flux:label>
                        <flux:input
                            id="account-email"
                            type="email"
                            required
                            maxlength="200"
                            wire:model.live.blur="accountEmail"
                            aria-invalid="{{ $accountEmailError ? 'true' : 'false' }}"
                            class="{{ $accountEmailError ? 'border-danger' : '' }}"
                        />
                        @if ($accountEmailError)
                            <p class="text-xs text-danger">{{ $accountEmailError }}</p>
                        @endif
                    </div>
                    <div class="space-y-2">
                        <flux:label for="account-role">Role</flux:label>
                        <div wire:key="account-role-{{ $accountId }}-{{ $accountRoleId === '' ? 'none' : $accountRoleId }}">
                            <x-kit.select
                                id="account-role"
                                aria-label="Role"
                                :disabled="$isOwnAccount"
                                wire:change="accountRoleChanged($event.target.value)"
                            >
                                <flux:select.option value="" :selected="$accountRoleId === ''">Unassigned</flux:select.option>
                                @foreach ($roles as $r)
                                    <flux:select.option value="{{ $r->getKey() }}" :selected="$accountRoleId === (string) $r->getKey()">{{ $r->name }}</flux:select.option>
                                @endforeach
                            </x-kit.select>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <flux:label for="account-active">Account status</flux:label>
                        <div class="flex h-9 items-center gap-3">
                            <flux:switch
                                id="account-active"
                                wire:model.live="accountIsActive"
                                :disabled="$isOwnAccount"
                                aria-label="{{ $selectedAccountName }} active"
                            />
                            <span class="text-sm text-muted-foreground">
                                {{ $accountIsActive ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <flux:label for="account-employee">Linked employee</flux:label>
                        <div wire:key="account-employee-{{ $accountId }}-{{ $accountEmployeeId === '' ? 'none' : $accountEmployeeId }}">
                            <x-kit.select
                                id="account-employee"
                                aria-label="Linked employee"
                                wire:change="accountEmployeeChanged($event.target.value)"
                            >
                                <flux:select.option value="" :selected="$accountEmployeeId === ''">No linked employee</flux:select.option>
                                @foreach ($employees as $e)
                                    <flux:select.option value="{{ $e->getKey() }}" :selected="$accountEmployeeId === (string) $e->getKey()">{{ \App\Support\EmployeeIdentity::option($e, $employeeName($e)) }}</flux:select.option>
                                @endforeach
                            </x-kit.select>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 sm:col-span-2" x-cloak x-show="hasChanges">
                        <flux:button type="button" variant="outline" wire:click="resetAccountChanges">
                            Reset changes
                        </flux:button>
                        <flux:button type="submit" variant="primary" icon="check">
                            Save account
                        </flux:button>
                    </div>
                </form>

                <div class="space-y-3 border-t border-border pt-4">
                    <div class="flex flex-wrap items-center gap-4">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary" aria-hidden="true">
                            <flux:icon.lock-closed class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-sm font-semibold">Password</h3>
                            <p class="mt-1 text-sm text-muted-foreground">Set a unique password for this account.</p>
                        </div>
                        <flux:button variant="outline" wire:click="openPasswordReset">Reset password</flux:button>
                    </div>
                    @if ($passwordReset)
                        <p class="text-sm text-success" role="status">
                            {{ $isOwnAccount
                                ? 'Password reset. Your other sessions have been signed out. You are still signed in here.'
                                : 'Password reset. This account’s existing sessions have been signed out.' }}
                        </p>
                    @endif
                </div>

                <div class="flex flex-col gap-3 border-t border-danger/30 pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-danger">Delete account</h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Only unused, deactivated test accounts can be deleted.
                        </p>
                    </div>
                    <flux:button
                        type="button"
                        variant="danger"
                        icon="trash"
                        :disabled="$isOwnAccount"
                        wire:click="$set('deleteConfirmOpen', true)"
                    >
                        Delete account
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal wire:model.self="passwordResetDialogOpen" @close="cancelPasswordReset" class="w-full max-w-lg">
        @if ($passwordResetDialogOpen && $accountId !== null)
            <form
                wire:submit="resetAccountPassword"
                class="space-y-5"
                x-data="{
                    policy: @js($passwordPolicy),
                    get next() { return $wire.resetPassword; },
                    get byteLength() { return new TextEncoder().encode(this.next).length; },
                    get requirements() {
                        return [
                            { label: 'At least ' + this.policy.minLength + ' characters', met: Array.from(this.next).length >= this.policy.minLength },
                            { label: 'An uppercase letter', met: /\p{Lu}/u.test(this.next) },
                            { label: 'A lowercase letter', met: /\p{Ll}/u.test(this.next) },
                            { label: 'A number', met: /\p{N}/u.test(this.next) },
                            { label: 'A symbol', met: /[\p{S}\p{P}]/u.test(this.next) },
                            { label: 'Fits the password length limit', met: this.next.length > 0 && this.byteLength <= this.policy.maxBytes },
                        ];
                    },
                    get completed() { return this.requirements.filter(item => item.met).length; },
                    get matches() { return $wire.resetPasswordConfirm.length > 0 && this.next === $wire.resetPasswordConfirm; },
                    get ready() { return this.completed === this.requirements.length && this.matches; },
                }"
                x-init="$el.closest('dialog')?.setAttribute('aria-labelledby', 'reset-password-heading')"
            >
                <div class="space-y-2">
                    <flux:heading size="lg" id="reset-password-heading">Reset password</flux:heading>
                    <p class="break-words text-sm font-medium">{{ $selectedAccountName }} · {{ $selectedAccountEmail }}</p>
                    <p class="text-sm text-muted-foreground">Choose a password never used for this account.</p>
                    <p class="text-sm text-muted-foreground">Try several unrelated words with a number and symbol.</p>
                </div>
                <flux:error name="passwordResetForm" />
                <div class="space-y-3">
                    <flux:input
                        id="reset-account-password"
                        label="New password"
                        type="password"
                        autocomplete="new-password"
                        wire:model="resetPassword"
                        aria-describedby="reset-password-requirements"
                        required
                        viewable
                    />
                    <div class="rounded-lg bg-muted/50 p-3" id="reset-password-requirements">
                        <h3 class="text-xs font-semibold">Requirements</h3>
                        <ul class="mt-2 grid gap-1.5 text-xs sm:grid-cols-2">
                            <template x-for="requirement in requirements" :key="requirement.label">
                                <li class="flex items-start gap-1.5" :class="requirement.met ? 'text-success' : 'text-muted-foreground'">
                                    <span class="mt-px shrink-0" aria-hidden="true">
                                        <flux:icon.check-circle variant="micro" class="size-3.5" x-show="requirement.met" />
                                        <flux:icon.minus-circle variant="micro" class="size-3.5" x-show="!requirement.met" />
                                    </span>
                                    <span x-text="requirement.label"></span>
                                    <span class="sr-only" x-text="requirement.met ? ' — met' : ' — not met'"></span>
                                </li>
                            </template>
                        </ul>
                        <p class="sr-only" role="status" aria-live="polite" x-text="next.length ? completed + ' of ' + requirements.length + ' password requirements met.' : 'Enter a new password to check the requirements.'"></p>
                    </div>
                    <p class="text-xs text-danger" x-cloak x-show="byteLength > policy.maxBytes">This password is too long. Try a shorter passphrase.</p>
                </div>
                <div class="space-y-2">
                    <flux:input
                        id="reset-account-password-confirm"
                        label="Confirm new password"
                        type="password"
                        autocomplete="new-password"
                        wire:model="resetPasswordConfirm"
                        aria-describedby="reset-password-match"
                        required
                        viewable
                    />
                    <p id="reset-password-match" class="text-xs" :class="matches ? 'text-success' : 'text-muted-foreground'" role="status" aria-live="polite"
                        x-text="$wire.resetPasswordConfirm.length ? (matches ? 'Passwords match.' : 'The passwords do not match yet.') : 'Enter your new password again to confirm it.'">Enter your new password again to confirm it.</p>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ $isOwnAccount
                        ? 'After resetting your password, your other sessions will be signed out. This session will stay signed in.'
                        : 'After resetting the password, this account’s existing sessions will be signed out. The user can change their password later in My Profile.' }}
                </p>
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:button variant="outline" wire:click="cancelPasswordReset">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" :loading="false" x-bind:disabled="!ready" wire:loading.attr="disabled" wire:target="resetAccountPassword">
                        <span wire:loading.remove wire:target="resetAccountPassword">Reset password</span>
                        <span wire:loading wire:target="resetAccountPassword">Resetting password…</span>
                    </flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    {{-- Delete confirm — the AlertDialog (users-page.tsx:878-908). --}}
    <flux:modal wire:model.self="deleteConfirmOpen" class="w-full max-w-lg">
        <div class="space-y-2">
            <h2 class="text-lg font-semibold leading-none tracking-tight">
                Delete {{ $selectedAccountName }}?
            </h2>
            <p class="text-sm text-muted-foreground">
                Deactivate and unlink the account first. Accounts referenced by projects, tasks,
                leave, payroll, documents, comments, or notifications must remain deactivated so
                historical attribution stays intact.
            </p>
        </div>
        <div class="mt-4 flex justify-end gap-2">
            <flux:button type="button" variant="outline" wire:click="$set('deleteConfirmOpen', false)">
                Cancel
            </flux:button>
            <flux:button type="button" variant="danger" icon="trash" wire:click="deleteAccount">
                Delete account
            </flux:button>
        </div>
    </flux:modal>
</div>
