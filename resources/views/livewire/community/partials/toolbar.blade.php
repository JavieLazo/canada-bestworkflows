{{--
    Above the posts: search, filters and sort. They all add up, so a search
    for "shift" with the Question tag and "Has photos" shows exactly those
    posts. Each filter in use gets a chip underneath that takes it off again,
    and all of it rides in the address, so a refresh keeps it.

    The filters open in a panel under their button (a sheet-like panel on a
    phone). Every change applies at once, the way the search box does.

    Reads: $filter (FeedFilter), $tags, $tagsByCode, $authors, $joinedGroups,
    $otherGroups, $activeGroup, and the filter properties themselves.
--}}
@use('App\Livewire\Community\Support\CommunityLabels')
@php
    $active = array_filter([
        'tag' => $filter->tag !== null,
        'where' => $activeGroup === null && $filter->groupId !== null,
        'author' => $filter->authorId !== null,
        'dates' => $filter->from !== null || $filter->to !== null,
        'has' => $filter->has !== '',
        'mentions' => $filter->mentions,
    ]);
    $readableGroups = array_values(array_filter([...$joinedGroups, ...$otherGroups], static fn ($g) => ! $g->private || $g->joined()));
    $authorName = null;
    foreach ($authors as $person) {
        if ($person->id === $filter->authorId) {
            $authorName = $person->name;
        }
    }
    $whereName = $filter->groupId === 'everyone' ? 'Everyone' : null;
    foreach ($readableGroups as $g) {
        if ($g->id === $filter->groupId) {
            $whereName = $g->name;
        }
    }
    $dates = match (true) {
        $filter->from !== null && $filter->to !== null => $filter->from->format('M j, Y').' to '.$filter->to->format('M j, Y'),
        $filter->from !== null => 'From '.$filter->from->format('M j, Y'),
        $filter->to !== null => 'Until '.$filter->to->format('M j, Y'),
        default => '',
    };
    $hasLabel = ['photos' => 'Has Photos', 'files' => 'Has Files', 'both' => 'Has Photos and Files'][$filter->has] ?? '';
    $chip = 'inline-flex h-7 max-w-full items-center gap-1 rounded-full bg-primary-soft pl-3 pr-1 text-xs font-medium text-primary';
    $chipX = 'inline-flex size-5 shrink-0 cursor-pointer items-center justify-center rounded-full transition-colors hover:bg-primary hover:text-primary-foreground';
@endphp

<div class="space-y-2.5">
    <div class="flex flex-wrap items-center gap-2">
        <div class="min-w-0 flex-1 basis-56">
            <flux:input
                size="sm"
                icon="magnifying-glass"
                placeholder="Search posts or people"
                aria-label="Search posts"
                wire:model.live.debounce.300ms="search"
                clearable
            />
        </div>

        <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
            <flux:button
                size="sm"
                icon="adjustments-horizontal"
                x-ref="trigger"
                x-on:click="open = ! open"
                x-bind:aria-expanded="open"
            >
                Filters
                @if ($active !== [])
                    <span class="num ml-0.5 inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-primary px-1 text-[0.65rem] font-semibold text-primary-foreground">{{ count($active) }}</span>
                @endif
            </flux:button>

            <template x-teleport="body">
                <div
                    x-show="open"
                    x-cloak
                    x-anchor.bottom-end.offset.6="$refs.trigger"
                    x-on:click.outside="if (! $refs.trigger.contains($event.target) && ! $event.target.closest('[data-flux-select-options]')) open = false"
                    x-transition.opacity.duration.100ms
                    class="z-40 w-[min(22rem,calc(100vw-2rem))] rounded-xl border border-border bg-popover p-4 text-popover-foreground shadow-lift"
                    role="dialog"
                    aria-label="Filter posts"
                >
                    <div class="space-y-4">
                        <div class="space-y-1.5">
                            <flux:label for="filter-tag">Tag</flux:label>
                            <x-kit.select id="filter-tag" size="sm" wire:model.live="filterTag">
                                <flux:select.option value="">Any tag</flux:select.option>
                                @foreach ($tags as $t)
                                    <flux:select.option value="{{ $t->code }}">{{ $t->name }}</flux:select.option>
                                @endforeach
                            </x-kit.select>
                        </div>

                        @if ($activeGroup === null)
                            <div class="space-y-1.5">
                                <flux:label for="filter-where">Shared In</flux:label>
                                <x-kit.select id="filter-where" size="sm" wire:model.live="filterWhere">
                                    <flux:select.option value="">Anywhere</flux:select.option>
                                    <flux:select.option value="everyone">Everyone (the whole company)</flux:select.option>
                                    @foreach ($readableGroups as $g)
                                        <flux:select.option value="{{ $g->id }}">{{ $g->name }}</flux:select.option>
                                    @endforeach
                                </x-kit.select>
                            </div>
                        @endif

                        <div class="space-y-1.5">
                            <flux:label for="filter-author">Posted By</flux:label>
                            <x-kit.select id="filter-author" size="sm" wire:model.live="filterAuthor">
                                <flux:select.option value="0">Anyone</flux:select.option>
                                @foreach ($authors as $person)
                                    <flux:select.option value="{{ $person->id }}">{{ $person->name }}</flux:select.option>
                                @endforeach
                            </x-kit.select>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div class="space-y-1.5">
                                <flux:label for="filter-from">From</flux:label>
                                <flux:input id="filter-from" type="date" size="sm" wire:model.live="filterFrom" max="{{ $filterTo !== '' ? $filterTo : '' }}" />
                            </div>
                            <div class="space-y-1.5">
                                <flux:label for="filter-to">To</flux:label>
                                <flux:input id="filter-to" type="date" size="sm" wire:model.live="filterTo" min="{{ $filterFrom !== '' ? $filterFrom : '' }}" />
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <flux:label for="filter-has">Has</flux:label>
                            <x-kit.select id="filter-has" size="sm" wire:model.live="filterHas">
                                <flux:select.option value="">Anything</flux:select.option>
                                <flux:select.option value="photos">Photos</flux:select.option>
                                <flux:select.option value="files">Files</flux:select.option>
                                <flux:select.option value="both">Photos and files</flux:select.option>
                            </x-kit.select>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <flux:label for="filter-mentions">Mentions Me</flux:label>
                            <flux:switch id="filter-mentions" wire:model.live="filterMentions" aria-label="Only posts that mention me" />
                        </div>
                        <p class="-mt-2 text-xs text-muted-foreground">In the post or its comments, including @everyone and your groups.</p>

                        <div class="flex justify-between gap-2 border-t border-border pt-3">
                            <flux:button size="sm" variant="ghost" wire:click="clearFilters" x-on:click="open = false">Clear All</flux:button>
                            <flux:button size="sm" variant="primary" x-on:click="open = false">Done</flux:button>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <div class="w-40 shrink-0">
            <x-kit.select size="sm" wire:model.live="sort" aria-label="Order posts by">
                <flux:select.option value="newest">Newest first</flux:select.option>
                <flux:select.option value="oldest">Oldest first</flux:select.option>
                <flux:select.option value="discussed">Most discussed</flux:select.option>
                <flux:select.option value="reactions">Most reactions</flux:select.option>
            </x-kit.select>
        </div>
    </div>

    @if ($active !== [])
        <div class="flex flex-wrap items-center gap-1.5" aria-label="Filters in use">
            @if (isset($active['tag']))
                @php($t = $tagsByCode[$filter->tag] ?? null)
                <span class="{{ $chip }}">
                    <span class="truncate">Tag: {{ $t->name ?? $filter->tag }}</span>
                    <button type="button" wire:click="dropFilter('tag')" class="{{ $chipX }}" aria-label="Remove the tag filter"><flux:icon.x-mark class="size-3" /></button>
                </span>
            @endif
            @if (isset($active['where']))
                <span class="{{ $chip }}">
                    <span class="truncate">In {{ $whereName ?? 'a group' }}</span>
                    <button type="button" wire:click="dropFilter('where')" class="{{ $chipX }}" aria-label="Remove the group filter"><flux:icon.x-mark class="size-3" /></button>
                </span>
            @endif
            @if (isset($active['author']))
                <span class="{{ $chip }}">
                    <span class="truncate">By {{ $authorName ?? 'someone' }}</span>
                    <button type="button" wire:click="dropFilter('author')" class="{{ $chipX }}" aria-label="Remove the author filter"><flux:icon.x-mark class="size-3" /></button>
                </span>
            @endif
            @if (isset($active['dates']))
                <span class="{{ $chip }}">
                    <span class="truncate">{{ $dates }}</span>
                    <button type="button" wire:click="dropFilter('dates')" class="{{ $chipX }}" aria-label="Remove the date filter"><flux:icon.x-mark class="size-3" /></button>
                </span>
            @endif
            @if (isset($active['has']))
                <span class="{{ $chip }}">
                    <span class="truncate">{{ $hasLabel }}</span>
                    <button type="button" wire:click="dropFilter('has')" class="{{ $chipX }}" aria-label="Remove the attachment filter"><flux:icon.x-mark class="size-3" /></button>
                </span>
            @endif
            @if (isset($active['mentions']))
                <span class="{{ $chip }}">
                    <span class="truncate">Mentions Me</span>
                    <button type="button" wire:click="dropFilter('mentions')" class="{{ $chipX }}" aria-label="Remove the mentions filter"><flux:icon.x-mark class="size-3" /></button>
                </span>
            @endif
            <button type="button" wire:click="clearFilters" class="ml-1 cursor-pointer text-xs font-medium text-muted-foreground hover:text-foreground hover:underline">Clear All</button>
        </div>
    @endif
</div>
