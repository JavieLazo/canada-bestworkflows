{{--
    The sign-in canvas — the port of the login page's frame (login-page.tsx:150-165):
    centered column, company logo and name above whatever card the page renders.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-background text-foreground antialiased">
        <main class="flex min-h-screen items-center justify-center bg-background px-4 py-8 sm:px-6">
            <div class="w-full max-w-md">
                <div class="mb-6 flex items-center justify-center gap-3">
                    <img
                        src="{{ asset('bestworkflows-icon-32.png') }}"
                        alt="BestWorkflows logo"
                        class="size-10 shrink-0 rounded-md bg-white object-contain"
                    />
                    <div class="leading-tight">
                        <p class="text-base font-bold">BestWorkflows</p>
                        <p class="text-xs text-muted-foreground">Management Information System</p>
                    </div>
                </div>

                {{ $slot }}
            </div>
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
