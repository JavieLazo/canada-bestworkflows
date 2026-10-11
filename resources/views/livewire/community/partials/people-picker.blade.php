{{--
    A search box and a list of people to tick, for adding people to a group
    or starting one with them. Plain checkboxes bound to one array, which is
    Livewire's own checkbox contract.

    Reads: $directory (PersonView list), $pickedPeople.
--}}
<div class="space-y-2">
    <flux:input
        icon="magnifying-glass"
        placeholder="Search by name or job title"
        aria-label="Search people"
        wire:model.live.debounce.200ms="peopleSearch"
    />

    <ul class="-mx-2 max-h-64 space-y-0.5 overflow-y-auto">
        @forelse ($directory as $person)
            <li wire:key="pick-person-{{ $person->id }}">
                <label class="flex cursor-pointer items-center gap-3 rounded-md px-2 py-2 transition-colors hover:bg-accent">
                    <x-social.avatar :name="$person->name" :photo="$person->photo" size="sm" />
                    <span class="min-w-0 flex-1 text-sm">
                        <span class="block truncate font-medium">{{ $person->name }}</span>
                        @if ($person->position !== null)
                            <span class="block truncate text-xs text-muted-foreground">{{ $person->position }}</span>
                        @endif
                    </span>
                    <input type="checkbox" value="{{ $person->id }}" wire:model.live="pickedPeople" class="size-4 cursor-pointer accent-primary" />
                </label>
            </li>
        @empty
            <li class="px-2 py-4 text-center text-sm text-muted-foreground">{{ trim($peopleSearch) !== '' ? 'Nobody matches that search.' : 'Everyone is already here.' }}</li>
        @endforelse
    </ul>

    @if ($pickedPeople !== [])
        <p class="text-xs text-muted-foreground"><span class="num">{{ count($pickedPeople) }}</span> picked</p>
    @endif
</div>
