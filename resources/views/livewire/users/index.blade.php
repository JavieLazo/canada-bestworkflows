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
--}}
@php
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
                        <flux:button variant="primary" icon="user-plus" class="ml-auto" wire:click="openCreate">
                            New account
                        </flux:button>
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
                        <flux:button variant="primary" icon="plus" class="ml-auto" wire:click="openNewRole">
                            New role
                        </flux:button>
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
                                                        wire:click="toggleGrant('{{ $rid }}', '{{ $m['code'] }}')"
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
                account can open.
            </p>
        </div>
        <form class="mt-4 grid gap-4 sm:grid-cols-2" wire:submit="createAccount">
            <div class="space-y-2">
                <flux:label>Full name</flux:label>
                <flux:input required wire:model="createFullName" />
            </div>
            <div class="space-y-2">
                <flux:label>Email</flux:label>
                <flux:input
                    type="email"
                    required
                    wire:model.blur="createEmail"
                    aria-invalid="{{ $createEmailError ? 'true' : 'false' }}"
                    class="{{ $createEmailError ? 'border-danger' : '' }}"
                />
                @if ($createEmailError)
                    <p class="text-xs text-danger">{{ $createEmailError }}</p>
                @endif
            </div>
            <div class="space-y-2">
                <flux:label>Temporary password</flux:label>
                <flux:input type="password" minlength="8" required wire:model="createPassword" />
            </div>
            <div class="space-y-2">
                <flux:label>Role</flux:label>
                <div wire:key="create-role-{{ $createRoleId === '' ? 'none' : $createRoleId }}">
                    <flux:select
                        aria-label="Role"
                        placeholder="Select role"
                        wire:change="createRoleChanged($event.target.value)"
                    >
                        @foreach ($roles as $r)
                            <flux:select.option value="{{ $r->getKey() }}" :selected="$createRoleId === (string) $r->getKey()">{{ $r->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>
            <div class="space-y-2 sm:col-span-2">
                <flux:label>Link to employee record (optional)</flux:label>
                <div wire:key="create-employee-{{ $createEmployeeId === '' ? 'none' : $createEmployeeId }}">
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
                    <flux:select
                        aria-label="Link to employee record (optional)"
                        wire:change="createEmployeeChanged($event.target.value)"
                    >
                        <flux:select.option value="" :selected="$createEmployeeId === ''">No linked employee</flux:select.option>
                        @foreach ($employees as $e)
                            <flux:select.option value="{{ $e->getKey() }}" :selected="$createEmployeeId === (string) $e->getKey()">{{ $e->employee_code }} &mdash; {{ $employeeName($e) }}</flux:select.option>
                        @endforeach
                    </flux:select>
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
    <flux:modal wire:model.self="accountDialogOpen" class="w-full max-w-2xl">
        <div class="space-y-1.5">
            <h2 class="text-lg font-semibold leading-none tracking-tight">
                {{ $selectedAccountName !== null ? 'Edit '.$selectedAccountName : 'Edit account' }}
            </h2>
            <p class="text-sm text-muted-foreground">Edit account details and access for this sign-in.</p>
        </div>
        @if ($accountId !== null)
            <div class="mt-4 grid gap-5">
                <form class="grid gap-4 sm:grid-cols-2" wire:submit="saveAccount">
                    <div class="space-y-2">
                        <flux:label for="account-full-name">Full name</flux:label>
                        <flux:input id="account-full-name" required maxlength="200" wire:model="accountFullName" />
                    </div>
                    <div class="space-y-2">
                        <flux:label for="account-email">Email</flux:label>
                        <flux:input
                            id="account-email"
                            type="email"
                            required
                            maxlength="200"
                            wire:model.blur="accountEmail"
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
                            <flux:select
                                id="account-role"
                                aria-label="Role"
                                :disabled="$isOwnAccount"
                                wire:change="accountRoleChanged($event.target.value)"
                            >
                                <flux:select.option value="" :selected="$accountRoleId === ''">Unassigned</flux:select.option>
                                @foreach ($roles as $r)
                                    <flux:select.option value="{{ $r->getKey() }}" :selected="$accountRoleId === (string) $r->getKey()">{{ $r->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
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
                            <flux:select
                                id="account-employee"
                                aria-label="Linked employee"
                                wire:change="accountEmployeeChanged($event.target.value)"
                            >
                                <flux:select.option value="" :selected="$accountEmployeeId === ''">No linked employee</flux:select.option>
                                @foreach ($employees as $e)
                                    <flux:select.option value="{{ $e->getKey() }}" :selected="$accountEmployeeId === (string) $e->getKey()">{{ $e->employee_code }} &mdash; {{ $employeeName($e) }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 sm:col-span-2">
                        <flux:button type="button" variant="outline" wire:click="resetAccountChanges">
                            Reset changes
                        </flux:button>
                        <flux:button type="submit" variant="primary" icon="check">
                            Save account
                        </flux:button>
                    </div>
                </form>

                <div class="border-t border-border pt-4">
                    <h3 class="text-sm font-semibold">Reset password</h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Set a temporary password. The user can change it later from their profile.
                    </p>
                </div>
                <form class="grid gap-4" wire:submit="resetAccountPassword">
                    <div class="space-y-2">
                        <flux:label for="reset-account-password">Temporary password</flux:label>
                        <flux:input
                            id="reset-account-password"
                            type="password"
                            autocomplete="new-password"
                            minlength="8"
                            maxlength="400"
                            required
                            wire:model="resetPassword"
                        />
                        <p class="text-xs text-muted-foreground">At least 8 characters.</p>
                    </div>
                    <div class="space-y-2">
                        <flux:label for="reset-account-password-confirm">Confirm temporary password</flux:label>
                        <flux:input
                            id="reset-account-password-confirm"
                            type="password"
                            autocomplete="new-password"
                            minlength="8"
                            maxlength="400"
                            required
                            wire:model="resetPasswordConfirm"
                        />
                    </div>
                    <flux:button type="submit" variant="primary" icon="key" class="w-full sm:w-fit sm:justify-self-end">
                        Reset password
                    </flux:button>
                </form>

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
