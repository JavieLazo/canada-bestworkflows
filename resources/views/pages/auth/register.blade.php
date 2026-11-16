<x-layouts::auth :title="__('Set Up BestWorkflows')">
    <div class="hris-card flex flex-col gap-6 p-6 sm:p-8">
        <x-auth-header :title="__('Set Up BestWorkflows')" :description="__('Create the first administrator account. Additional accounts are managed in User Management.')" />

        {{-- Session status --}}
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            {{-- Login credentials only; personal names belong to employee records. --}}
            <flux:input
                name="email"
                :label="__('Email Address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            {{-- Password --}}
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                :description="__('Use at least 15 characters with uppercase, lowercase, numbers, and a symbol.')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            {{-- Confirm password --}}
            <flux:input
                name="password_confirmation"
                :label="__('Confirm Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create First Administrator') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-muted-foreground">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
