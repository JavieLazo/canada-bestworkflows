{{--
    The sign-in canvas — the port of the login page's frame (login-page.tsx:150-165):
    centered column, company logo above whatever card the page renders. The
    body is a column so the copyright line sits at the foot of the screen
    while the card stays centred in the room above it.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="flex min-h-screen flex-col bg-background text-foreground antialiased">
        <main class="flex flex-1 items-center justify-center bg-background px-4 py-8 sm:px-6">
            <div class="w-full max-w-md">
                <div class="mb-6 flex flex-col items-center gap-2">
                    <x-brand-logo class="w-full max-w-sm" />
                    <p class="text-center text-xs text-muted-foreground">Management Information System</p>
                </div>

                {{ $slot }}
            </div>
        </main>

        @include('partials.copyright')

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
