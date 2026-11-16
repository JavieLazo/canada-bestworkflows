{{--
    The rail beside the feed: the viewer's groups, groups they could join, and
    what's coming up for people who shared it (work anniversaries and
    birthdays), each with a Greet button that drafts the post for them.

    Each list shows five, and "See all" opens every group with a search, so
    the rail stays the same size however many groups there are.

    Reads: $joinedGroups, $otherGroups, $group, $celebrations.
--}}
@php
    $rowClasses = 'flex w-full cursor-pointer items-center gap-3 rounded-md p-2 text-left text-sm transition-colors hover:bg-accent hover:text-accent-foreground';
    $seeAll = 'cursor-pointer text-xs font-medium text-muted-foreground transition-colors hover:text-foreground hover:underline';
@endphp

<section class="hris-card p-4" aria-labelledby="rail-groups">
    <div class="flex items-center justify-between gap-2 px-2">
        <h2 id="rail-groups" class="text-sm font-semibold">Your Groups</h2>
        <button type="button" wire:click="openGroups('yours')" class="{{ $seeAll }}">See All</button>
    </div>
    <ul class="mt-2 space-y-0.5">
        <li>
            <button type="button" wire:click="showFeed" @class([$rowClasses, 'bg-accent font-medium text-accent-foreground' => $group === ''])>
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                    @include('layouts.app.module-icon', ['icon' => 'newspaper', 'class' => 'size-4'])
                </span>
                <span class="min-w-0 flex-1 truncate">All Posts</span>
            </button>
        </li>
        @forelse (array_slice($joinedGroups, 0, 5) as $g)
            @include('livewire.community.partials.group-row', ['group' => $g, 'groupId' => $group, 'key' => 'rail-joined'])
        @empty
            <li class="px-2 py-1 text-xs text-muted-foreground">You have not joined a group yet.</li>
        @endforelse
    </ul>
    @if (count($joinedGroups) > 5)
        <button type="button" wire:click="openGroups('yours')" class="{{ $seeAll }} mt-1 px-2">And {{ count($joinedGroups) - 5 }} More</button>
    @endif
</section>

@if ($otherGroups !== [])
    <section class="hris-card p-4" aria-labelledby="rail-discover">
        <div class="flex items-center justify-between gap-2 px-2">
            <h2 id="rail-discover" class="text-sm font-semibold">Discover Groups</h2>
            <button type="button" wire:click="openGroups('discover')" class="{{ $seeAll }}">See All</button>
        </div>
        <ul class="mt-2 space-y-0.5">
            @foreach (array_slice($otherGroups, 0, 5) as $g)
                @include('livewire.community.partials.group-row', ['group' => $g, 'groupId' => $group, 'key' => 'rail-other'])
            @endforeach
        </ul>
        @if (count($otherGroups) > 5)
            <button type="button" wire:click="openGroups('discover')" class="{{ $seeAll }} mt-1 px-2">And {{ count($otherGroups) - 5 }} More</button>
        @endif
    </section>
@endif

@if ($celebrations !== [])
    <section class="hris-card p-4" aria-labelledby="rail-week">
        <h2 id="rail-week" class="px-2 text-sm font-semibold">Coming Up</h2>
        @include('livewire.community.partials.celebrations')
    </section>
@endif
