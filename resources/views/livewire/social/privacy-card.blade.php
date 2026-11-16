{{--
    What coworkers see beside your name in Community and Chat. One line in
    their My Profile page pulls this in, and the rest lives here.

    Expects: $available (false when the role holds neither module, and then
    the card draws nothing).
--}}
<div>
    @if ($available)
        <section class="hris-card overflow-hidden">
            <header class="border-b border-border px-4 py-3">
                <h2 class="text-base font-bold">Community and Chat</h2>
                <p class="text-sm text-muted-foreground">What coworkers see beside your name. Your name and photo always show.</p>
            </header>
            <form class="grid gap-4 p-4" wire:submit="save">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <flux:label for="social-show-position">Show My Job Title</flux:label>
                        <p class="mt-1 text-sm text-muted-foreground">Your position beside your name on your posts, at the top of a chat with you, and in people lists.</p>
                    </div>
                    <flux:switch id="social-show-position" wire:model="showPosition" aria-label="Show my job title" />
                </div>

                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <flux:label for="social-show-birthday">Show My Birthday</flux:label>
                        <p class="mt-1 text-sm text-muted-foreground">The day and month only in "Coming up", never the year and never your age. Off unless you turn it on.</p>
                    </div>
                    <flux:switch id="social-show-birthday" wire:model="showBirthday" aria-label="Show my birthday" />
                </div>

                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <flux:label for="social-show-anniversary">Show My Work Anniversary</flux:label>
                        <p class="mt-1 text-sm text-muted-foreground">The yearly date you joined the company, and how many years it's been, in "Coming up". Off unless you turn it on.</p>
                    </div>
                    <flux:switch id="social-show-anniversary" wire:model="showAnniversary" aria-label="Show my work anniversary" />
                </div>

                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary">Save What Others See</flux:button>
                </div>
            </form>
        </section>
    @endif
</div>
