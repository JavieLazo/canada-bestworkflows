{{--
    The sign-in canvas — the port of the login page's frame (login-page.tsx:150-165):
    centered column, company logo above whatever card the page renders.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-background text-foreground antialiased">
        <main class="flex min-h-screen items-center justify-center bg-background px-4 py-8 sm:px-6">
            <div class="w-full max-w-md">
                <div class="mb-6 flex flex-col items-center gap-2">
                    <x-brand-logo class="w-full max-w-sm" />
                    <p class="text-center text-xs text-muted-foreground">Management Information System</p>
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
