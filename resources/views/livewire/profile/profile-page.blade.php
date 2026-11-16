{{-- The employee record is shared with management; personal changes remain proposals until approved. --}}
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">My Profile</h1>
        <p class="text-sm text-muted-foreground">View your employee record and submit corrections for approval.</p>
    </div>

    <div class="inline-flex max-w-full flex-wrap gap-1 rounded-lg bg-muted p-1 text-sm text-muted-foreground" role="tablist" aria-label="My Profile views">
        @foreach (['record' => 'My Record', 'requests' => 'My Change Requests', 'account' => 'Account & Security'] as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                wire:click="$set('tab', '{{ $key }}')"
                class="rounded-md px-3 py-1.5 font-medium {{ $tab === $key ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'record')
        @if ($employee === null)
            <section class="hris-card space-y-2 p-6">
                <h2 class="text-base font-bold">Employee Record Not Linked</h2>
                <p class="text-sm text-muted-foreground">Your information will appear here once management links this account to your employee record.</p>
            </section>
        @else
            <livewire:employees.show :own="true" :key="'own-record-'.$employee->id" />
        @endif
    @elseif ($tab === 'requests')
        <livewire:employees.change-requests :own="true" />
    @else
        <section class="hris-card space-y-3 p-4">
            <h2 class="text-base font-bold">Account</h2>
            <dl class="grid gap-3 sm:grid-cols-2">
                <div><dt class="text-xs text-muted-foreground">Account Name</dt><dd class="break-words text-sm">{{ $accountName }}</dd></div>
                <div><dt class="text-xs text-muted-foreground">Sign-In Email</dt><dd class="break-words text-sm">{{ $email }}</dd></div>
            </dl>
            <p class="text-xs text-muted-foreground">Your name comes from the linked employee record. You can update your sign-in email in Account settings. Management maintains your employee link.</p>
        </section>
        @if ($employee !== null)
            <livewire:social.privacy-card />
        @endif
        <section class="hris-card overflow-hidden" aria-labelledby="profile-security-heading">
            <header class="border-b border-border px-4 py-3">
                <h2 class="text-base font-bold" id="profile-security-heading">Security</h2>
                <p class="text-sm text-muted-foreground">Protect access to your account.</p>
            </header>
            <div class="flex flex-wrap items-center gap-4 p-4">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary" aria-hidden="true">
                    <flux:icon.lock-closed class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-semibold">Password</h3>
                    <p class="mt-1 text-sm text-muted-foreground">Use a unique password for this account.</p>
                </div>
                <flux:button variant="outline" wire:click="openPasswordChange">Change Password</flux:button>
            </div>
            @if ($passwordChanged)
                <p class="border-t border-border px-4 py-3 text-sm text-success" role="status">Password changed. Your other sessions have been signed out. You are still signed in here.</p>
            @endif
        </section>

        <flux:modal wire:model.self="passwordDialogOpen" @close="cancelPasswordChange" class="w-full max-w-lg">
            <form
                wire:submit="changePassword"
                class="space-y-5"
                x-data="{
                    policy: @js($passwordPolicy),
                    get next() { return $wire.newPassword; },
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
                    get matches() { return $wire.confirmPassword.length > 0 && this.next === $wire.confirmPassword; },
                    get differs() { return $wire.currentPassword.length > 0 && this.next.length > 0 && this.next !== $wire.currentPassword; },
                    get ready() { return this.completed === this.requirements.length && this.matches && this.differs; },
                }"
                x-init="$el.closest('dialog')?.setAttribute('aria-labelledby', 'profile-password-heading')"
            >
                <div class="space-y-2">
                    <flux:heading size="lg" id="profile-password-heading">Change Password</flux:heading>
                    <p class="text-sm text-muted-foreground">Choose a password you have never used for this account.</p>
                    <p class="text-sm text-muted-foreground">Try several unrelated words with a number and symbol.</p>
                </div>
                <flux:error name="passwordChange" />
                <flux:input
                    id="profile-current-password"
                    label="Current Password"
                    type="password"
                    autocomplete="current-password"
                    wire:model="currentPassword"
                    required
                    viewable
                />
                <div class="space-y-3">
                    <flux:input
                        id="profile-new-password"
                        label="New Password"
                        type="password"
                        autocomplete="new-password"
                        wire:model="newPassword"
                        aria-describedby="profile-password-requirements"
                        required
                        viewable
                    />
                    <div class="rounded-lg bg-muted/50 p-3" id="profile-password-requirements">
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
                    <p class="text-xs text-danger" x-cloak x-show="next.length > 0 && $wire.currentPassword.length > 0 && !differs">Choose a password different from your current password.</p>
                </div>
                <div class="space-y-2">
                    <flux:input
                        id="profile-confirm-password"
                        label="Confirm New Password"
                        type="password"
                        autocomplete="new-password"
                        wire:model="confirmPassword"
                        aria-describedby="profile-password-match"
                        required
                        viewable
                    />
                    <p id="profile-password-match" class="text-xs" :class="matches ? 'text-success' : 'text-muted-foreground'" role="status" aria-live="polite"
                        x-text="$wire.confirmPassword.length ? (matches ? 'Passwords match.' : 'The passwords do not match yet.') : 'Enter your new password again to confirm it.'">Enter your new password again to confirm it.</p>
                </div>
                <p class="text-xs text-muted-foreground">After changing your password, your other sessions will be signed out. This session will stay signed in.</p>
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:button variant="outline" wire:click="cancelPasswordChange">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" :loading="false" x-bind:disabled="!ready" wire:loading.attr="disabled" wire:target="changePassword">
                        <span wire:loading.remove wire:target="changePassword">Update Password</span>
                        <span wire:loading wire:target="changePassword">Updating Password…</span>
                    </flux:button>
                </div>
            </form>
        </flux:modal>

    @endif
</div>
