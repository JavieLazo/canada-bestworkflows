{{--
    My Profile — the port of profile-page.tsx.

    A single column of short forms, capped at max-w-3xl rather than the 1400px
    shell every table page uses (profile-page.tsx:375-378). Section order is
    the spec: Account, [unlinked note], Personal, Emergency contact, Security,
    Employment (read-only).

    Fidelity notes where shadcn had no Flux twin:
      - Avatar/AvatarFallback are a span with an <img> or the initials, the
        same Tailwind the source's classes resolved to (h-14 w-14 rounded-full,
        object-cover so a tall photo is not squashed);
      - the source's shadcn Selects are Flux's native select; the "Not set"
        placeholder is a hidden disabled first option — and since it cannot be
        re-picked, it also reproduces the source's guard against the Radix
        empty-echo wiping a seeded value;
      - the popover DateField degrades to <x-kit.date-field> (typed input +
        native picker), per the shared kit;
      - the Save buttons' Loader2 spinner is Flux's built-in wire-loading
        spinner on the submitting button.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;
    use App\Livewire\Profile\ProfilePage;
@endphp
<div class="max-w-3xl space-y-4">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">My Profile</h1>
        <p class="text-sm text-muted-foreground">
            The details you maintain about yourself. Your position, employment status and rate stay
            with HR in Employee Records.
        </p>
    </div>

    {{-- ──────────────────────────────────────────────────────── Account --}}
    <section class="hris-card overflow-hidden">
        <header class="border-b border-border px-4 py-3">
            <h2 class="text-base font-bold">Account</h2>
            <p class="text-sm text-muted-foreground">How you appear across the system, and the address you sign in with.</p>
        </header>
        <form class="grid gap-3 p-4 sm:grid-cols-2" wire:submit="saveAccount">
            @if ($employee !== null)
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <span class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                        @if ($photoUrl)
                            {{-- Portraits are rarely square — without object-cover a tall
                                 photo comes out squashed (profile-page.tsx:395-399). --}}
                            <img src="{{ $photoUrl }}" alt="{{ $employeeName }}" class="h-full w-full object-cover" />
                        @else
                            <span class="num text-lg font-semibold">{{ $initials }}</span>
                        @endif
                    </span>
                    <div class="space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:button
                                type="button"
                                variant="outline"
                                size="sm"
                                icon="arrow-up-tray"
                                x-data
                                x-on:click="document.getElementById('profile-photo-input').click()"
                                wire:loading.attr="disabled"
                                wire:target="photo, saveAccount"
                            >
                                Upload photo
                            </flux:button>
                            @if ($photoUrl)
                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    wire:click="removeStagedPhoto"
                                    wire:loading.attr="disabled"
                                    wire:target="photo, saveAccount"
                                >
                                    Remove photo
                                </flux:button>
                            @endif
                        </div>
                        <p class="text-xs text-muted-foreground">
                            An image up to {{ EmployeeFields::humanSize(EmployeeFields::MAX_PHOTO_BYTES) }}.
                            It is resized to a small portrait and stored with the rest of your record when you save.
                        </p>
                    </div>
                    <input
                        type="file"
                        id="profile-photo-input"
                        accept="image/*"
                        class="hidden"
                        wire:model="photo"
                    />
                </div>
            @endif

            <div class="space-y-2">
                <flux:label for="profile-full-name">Full name</flux:label>
                <flux:input id="profile-full-name" required wire:model="fullName" />
            </div>

            <div class="space-y-2">
                <flux:label for="profile-email">Email</flux:label>
                <flux:input id="profile-email" type="email" disabled value="{{ $email }}" />
                <p class="text-xs text-muted-foreground">
                    Your email is your sign-in and is changed by an administrator.
                </p>
            </div>

            <div class="flex justify-end sm:col-span-2">
                <flux:button type="submit" variant="primary">Save account</flux:button>
            </div>
        </form>
    </section>

    @if ($employee === null)
        {{-- profile-page.tsx:463-468 — the server has no isPending window. --}}
        <p class="hris-card p-4 text-sm text-muted-foreground">
            Your personal, emergency contact and employment details appear here once an administrator
            links this account to an employee record.
        </p>
    @endif

    @if ($employee !== null)
        {{-- ─────────────────────────────────────────────────── Personal --}}
        <section class="hris-card overflow-hidden">
            <header class="border-b border-border px-4 py-3">
                <h2 class="text-base font-bold">Personal</h2>
                <p class="text-sm text-muted-foreground">Where we reach you, and the details that belong to you rather than to the job.</p>
            </header>
            <form class="grid gap-3 p-4 sm:grid-cols-2" wire:submit="savePersonal">
                <div class="space-y-2">
                    <flux:label for="profile-phone">Contact number</flux:label>
                    <flux:input id="profile-phone" type="tel" wire:model="personal.phone" />
                </div>

                <div class="space-y-2">
                    <flux:label for="profile-birth-date">Birth date</flux:label>
                    <x-kit.date-field id="profile-birth-date" wire:model="personal.birthDate" aria-label="Birth date" />
                </div>

                <div class="space-y-2 sm:col-span-2">
                    <flux:label for="profile-address">Address</flux:label>
                    <flux:textarea id="profile-address" rows="2" wire:model="personal.address" />
                </div>

                <div class="space-y-2">
                    <flux:label for="profile-sex">Sex</flux:label>
                    <flux:select id="profile-sex" wire:model="personal.sex" aria-label="Sex">
                        <flux:select.option value="" disabled hidden>Not set</flux:select.option>
                        @foreach (ProfilePage::SEXES as $s)
                            <flux:select.option value="{{ $s }}">{{ $s }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="space-y-2">
                    <flux:label for="profile-civil-status">Civil status</flux:label>
                    <flux:select id="profile-civil-status" wire:model="personal.civilStatus" aria-label="Civil status">
                        <flux:select.option value="" disabled hidden>Not set</flux:select.option>
                        @foreach (ProfilePage::CIVIL_STATUSES as $s)
                            <flux:select.option value="{{ $s }}">{{ $s }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="flex justify-end sm:col-span-2">
                    <flux:button type="submit" variant="primary">Save personal details</flux:button>
                </div>
            </form>
        </section>

        {{-- ────────────────────────────────────────── Emergency contact --}}
        <section class="hris-card overflow-hidden">
            <header class="border-b border-border px-4 py-3">
                <h2 class="text-base font-bold">Emergency contact</h2>
                <p class="text-sm text-muted-foreground">Who to call for you, and how they are related to you.</p>
            </header>
            <form class="grid gap-3 p-4 sm:grid-cols-2" wire:submit="saveEmergency">
                <div class="space-y-2 sm:col-span-2">
                    <flux:label for="profile-emergency-name">Full name</flux:label>
                    <flux:input id="profile-emergency-name" wire:model="emergency.name" />
                </div>

                <div class="space-y-2">
                    <flux:label for="profile-emergency-relation">Relationship</flux:label>
                    <flux:input id="profile-emergency-relation" wire:model="emergency.relation" />
                </div>

                <div class="space-y-2">
                    <flux:label for="profile-emergency-phone">Contact number</flux:label>
                    <flux:input id="profile-emergency-phone" type="tel" wire:model="emergency.phone" />
                </div>

                <div class="flex justify-end sm:col-span-2">
                    <flux:button type="submit" variant="primary">Save emergency contact</flux:button>
                </div>
            </form>
        </section>
    @endif

    {{-- ─────────────────────────────────────────────────────── Security --}}
    <section class="hris-card overflow-hidden">
        <header class="border-b border-border px-4 py-3">
            <h2 class="text-base font-bold">Security</h2>
            <p class="text-sm text-muted-foreground">Change the password you sign in with.</p>
        </header>
        <form class="grid gap-3 p-4 sm:grid-cols-2" wire:submit="changePassword">
            <div class="space-y-2 sm:col-span-2">
                <flux:label for="profile-current-password">Current password</flux:label>
                <flux:input
                    id="profile-current-password"
                    type="password"
                    autocomplete="current-password"
                    wire:model="currentPassword"
                />
            </div>

            <div class="space-y-2">
                <flux:label for="profile-new-password">New password</flux:label>
                <flux:input
                    id="profile-new-password"
                    type="password"
                    autocomplete="new-password"
                    wire:model="newPassword"
                />
                <p class="text-xs text-muted-foreground">At least 8 characters.</p>
            </div>

            <div class="space-y-2">
                <flux:label for="profile-confirm-password">Confirm new password</flux:label>
                <flux:input
                    id="profile-confirm-password"
                    type="password"
                    autocomplete="new-password"
                    wire:model="confirmPassword"
                />
            </div>

            <div class="flex justify-end sm:col-span-2">
                <flux:button type="submit" variant="primary">Change password</flux:button>
            </div>
        </form>
    </section>

    @if ($employee !== null)
        {{-- ───────────────────────────────────────────────── Employment --}}
        <section class="hris-card overflow-hidden">
            <header class="border-b border-border px-4 py-3">
                <h2 class="text-base font-bold">Employment</h2>
                <p class="text-sm text-muted-foreground">What the 201 file holds about your engagement — read-only here.</p>
            </header>
            <dl class="grid gap-3 px-4 pt-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Employee code</dt>
                    <dd class="mt-1 break-words text-sm"><span class="num">{{ $employee->employee_code !== '' ? $employee->employee_code : '—' }}</span></dd>
                </div>
                {{-- `?:`, not `??`: an unset position is stored as "" as often as null. --}}
                <div class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Position</dt>
                    <dd class="mt-1 break-words text-sm">{{ $employee->position ?: '—' }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Employment status</dt>
                    <dd class="mt-1 break-words text-sm">
                        <span class="pill pill-info">{{ EmployeeFields::employmentStatusLabel($employee->employment_status) }}</span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Date hired</dt>
                    <dd class="mt-1 break-words text-sm">
                        <span class="num">{{ $employee->date_hired !== null ? $employee->date_hired->format('M d, Y') : '—' }}</span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Monthly rate</dt>
                    <dd class="mt-1 break-words text-sm"><span class="num">{{ EmployeeFields::peso($employee->monthly_rate) }}</span></dd>
                </div>
            </dl>
            {{-- Outside the <dl>: its content model is dt/dd pairs only. --}}
            <p class="px-4 pb-4 pt-3 text-xs text-muted-foreground">
                Maintained by HR in Employee Records.
            </p>
        </section>
    @endif
</div>
