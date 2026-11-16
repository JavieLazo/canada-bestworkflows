@if ($container !== null)
    <div @class(['space-y-4' => $membersOnly, 'flex max-h-[calc(100dvh-7rem)] min-h-0 flex-col gap-4' => ! $membersOnly])>
        <div class="shrink-0 space-y-1 pr-8">
            <flux:heading size="lg" level="2">{{ ucfirst($kind) }} members</flux:heading>
            @if (! $membersOnly)<p class="line-clamp-2 break-words text-sm text-muted-foreground" title="{{ $container->name }}">{{ $container->name }}</p>
            @endif
            <p class="text-xs text-muted-foreground">{{ $kind === 'portfolio' ? 'Contributors can add their own clients. Client, project and financial access stay separate.' : 'Client access keeps its projects within reach. Project roles and task assignments still apply.' }}</p>
        </div>
        @if ($container->can_manage)
            <form wire:submit="addMember" class="flex shrink-0 flex-wrap items-end gap-2">
                <x-kit.select wire:model="member" label="Add a person" placeholder="Choose a person" searchable class="min-w-48 flex-1" aria-label="Add a person">
                    @foreach ($candidates as $candidate)<flux:select.option value="{{ $candidate->id }}">{{ $candidate->name }}</flux:select.option>
                    @endforeach
                </x-kit.select>
                <flux:button type="submit" variant="primary" icon="plus">Add</flux:button>
            </form>
        @endif
        @include('livewire.projects.partials.people-controls')
        <ul @class(['divide-y divide-border', 'min-h-0 ppm-scroll-quiet overflow-y-auto overscroll-contain' => ! $membersOnly])>
            @forelse ($members ?? [] as $person)
                <li class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0" wire:key="{{ $kind }}-person-{{ $person->id }}">
                    <div class="min-w-0 flex-1"><span class="block truncate text-sm font-medium" title="{{ $person->name }}">{{ $person->name }}</span><span class="text-xs text-muted-foreground">{{ $person->access_label }}{{ $person->active ? '' : ' · Inactive account' }}</span></div>
                    @if ($container->can_manage && $person->explicit)
                        <flux:dropdown position="bottom" align="end"><flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" aria-label="Access actions for {{ $person->name }}" /><flux:menu><flux:menu.item variant="danger" icon="user-minus" wire:click="removeMember({{ $person->user_id }})">Remove access</flux:menu.item></flux:menu></flux:dropdown>
                    @endif
                </li>
            @empty
                <li class="py-6 text-center text-sm text-muted-foreground">No people match these filters.</li>
            @endforelse
        </ul>
        @if ($members !== null)<x-kit.pagination :paginator="$members" noun="people" page-size-model="peoplePerPage" page-size-label="Members per page" class="shrink-0 !border-0 !px-0" />
        @endif
    </div>
@else
    <flux:heading size="lg" level="2">This {{ $kind }} is no longer available.</flux:heading>
@endif
