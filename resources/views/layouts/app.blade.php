@php($__access = app(\App\Support\Access::class))
<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="!p-0 min-w-0">
        <div class="mx-auto w-full min-w-0 max-w-[1400px] overflow-x-clip px-4 py-6 sm:px-6 sm:py-8">
            @if ($__access->isSignedIn() && $__access->modules === [] && trim($slot) === '')
                {{-- "No modules assigned" — protected-shell.tsx:120-126. Only
                     in place of an EMPTY slot (the landing page for a role
                     granted nothing): pages with their own body — the account
                     settings pages, which no module gates — still render. --}}
                <div class="hris-card p-10 text-center">
                    <h1 class="text-lg font-semibold">No modules assigned</h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Your role has no module grants yet. Please contact HR.
                    </p>
                </div>
            @else
                {{ $slot }}
            @endif
        </div>
    </flux:main>
</x-layouts::app.sidebar>
