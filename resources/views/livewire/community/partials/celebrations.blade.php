{{--
    What's coming up in the next 30 days, for people who chose to share it on
    My Profile: work anniversaries and birthdays. A birthday says the day
    only; the year, and the age it would give away, never leaves the employee
    record. Greet drafts the post.

    Reads: $celebrations.
--}}
@php
    // The date is a Manila calendar day, so "today" has to be too.
    $todayKey = \App\Support\ManilaClock::todayKey();
@endphp

<ul class="mt-2 space-y-0.5">
    @foreach ($celebrations as $index => $celebration)
        <li wire:key="celebration-{{ $index }}-{{ $celebration->kind }}-{{ $celebration->date->format('md') }}" class="flex items-center gap-3 rounded-md p-2">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-success-soft text-success">
                @if ($celebration->isBirthday())
                    <flux:icon.cake class="size-4" />
                @else
                    <flux:icon.trophy class="size-4" />
                @endif
            </span>
            <span class="min-w-0 flex-1 text-sm">
                <span class="block truncate font-medium">{{ $celebration->name }}</span>
                <span class="block text-xs text-muted-foreground">
                    @if ($celebration->isBirthday())
                        Birthday
                    @else
                        {{ $celebration->years }} {{ $celebration->years === 1 ? 'year' : 'years' }} with us
                    @endif
                    &middot; {{ $celebration->date->format('Y-m-d') === $todayKey ? 'Today' : $celebration->date->format('M j') }}
                </span>
            </span>
            <flux:button size="xs" variant="ghost" wire:click="greet({{ $index }})">Greet</flux:button>
        </li>
    @endforeach
</ul>
