{{--
    Sign in — restyled to the reference (login-page.tsx:166-236): same title,
    copy and button label, inside the hris-card. Still Fortify underneath —
    POST route('login.store') with `email` / `password` untouched.

    A fresh database exposes the first-administrator setup. Once any account
    exists, subsequent provisioning belongs to User Management.
--}}
<x-layouts::auth :title="__('Sign In')">
    <div class="hris-card p-6 sm:p-8">
        <h1 class="text-2xl font-bold">Sign In</h1>
        <p class="mt-1 text-sm text-muted-foreground">Use your company email and password.</p>

        <x-auth-session-status class="mt-4" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
            @csrf

            <flux:input
                name="email"
                :label="__('Email')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
            />

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                {{ __('Sign In') }}
            </flux:button>
        </form>

        @if (\App\Support\FirstAdmin::needed())
            <p class="mt-5 text-center text-sm text-muted-foreground">
                <flux:link :href="route('register')" wire:navigate>Set up first administrator</flux:link>
            </p>
        @endif
    </div>
</x-layouts::auth>
