{{--
    Community, the company-wide feed. See App\Livewire\Community\CommunityPage
    for the state and actions behind this.

    Layout: one reading column and a rail, centred at a width a post can be
    read at, rather than stretched to the 1400px every table page uses. The
    rail (your groups, groups to discover, coming up) is lg+ only; below that
    the same three open from a row of buttons above the feed.

    Above the posts: the search, the filters and the sort, which all add up,
    and a chip for each filter in use. Pins sit under their own label above
    the rest, and the post box always sits above both. A post opened from a
    notification sits above everything, with a way back to the feed. It sits
    in a soft brand frame, or, when the notification was about one of its
    comments, that comment is highlighted instead.

    Inside a group, tabs switch between its posts, its members and what's
    been shared in it.

    The root polls through pollForChanges (WithChangeStamp), so the tick only
    redraws when something in Community actually moved.
--}}
@php
    $postPartial = [
        'names' => $names,
        'people' => $people,
        'groupNames' => $groupNames,
        'tagsByCode' => $tagsByCode,
        'viewer' => $viewer,
        'now' => $now,
        'inGroup' => $activeGroup !== null,
    ];
    $mobileButton = 'inline-flex h-9 shrink-0 cursor-pointer items-center gap-1.5 rounded-full border border-border bg-card px-3.5 text-sm font-medium transition-colors hover:bg-accent';
    $lockedOut = $activeGroup !== null && $activeGroup->private && ! $activeGroup->joined();
@endphp

<div
    class="mx-auto w-full max-w-5xl space-y-6"
    wire:poll.visible.60s="pollForChanges"
    x-data
    x-on:community-posted.window="requestAnimationFrame(() => document.getElementById($event.detail.id)?.scrollIntoView({ block: 'center', behavior: 'smooth' }))"
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Community</h1>
            <p class="text-sm text-muted-foreground">
                Kudos, questions and everyday conversation across {{ config('copems.company_name') }}.
            </p>
        </div>
        {{-- Moderators only. The commands check again, whatever shows here. --}}
        @if ($canModerate)
            <div class="flex shrink-0 gap-2">
                <flux:button size="sm" icon="user-group" wire:click="newGroup">New group</flux:button>
                <flux:button size="sm" icon="tag" wire:click="openTags">Manage tags</flux:button>
            </div>
        @endif
    </div>

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="min-w-0 space-y-4">
            @if ($focusId !== null)
                <section class="space-y-3" aria-labelledby="community-focus">
                    <div class="flex items-center justify-between gap-3">
                        <h2 id="community-focus" class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">From your notifications</h2>
                        <button type="button" wire:click="closeFocus" class="inline-flex cursor-pointer items-center gap-1 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground">
                            Back to the feed
                            @include('layouts.app.module-icon', ['icon' => 'chevron-right', 'class' => 'size-4'])
                        </button>
                    </div>
                    @if ($focus !== null && $focus->posts !== [])
                        <div @class(['rounded-[calc(var(--radius-xl)+0.375rem)] bg-primary-soft p-1.5' => $focusCommentId === null])>
                            @include('livewire.community.partials.post', $postPartial + ['post' => $focus->posts[0], 'inGroup' => false, 'focusComment' => $focusCommentId])
                        </div>
                    @else
                        <div class="hris-card">
                            <x-kit.empty-state icon="eye-slash" title="That post is no longer available." hint="It was deleted, or it is in a group you are not in." />
                        </div>
                    @endif
                </section>
            @endif

            {{-- Below lg, the rail's three cards open from here instead. --}}
            <nav class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 lg:hidden" aria-label="Groups and what's coming up">
                <button type="button" wire:click="openGroups('yours')" class="{{ $mobileButton }}">
                    <flux:icon.user-group class="size-4 text-muted-foreground" />
                    Your groups
                    <span class="num text-muted-foreground">{{ count($joinedGroups) }}</span>
                </button>
                <button type="button" wire:click="openGroups('discover')" class="{{ $mobileButton }}">
                    <flux:icon.magnifying-glass class="size-4 text-muted-foreground" />
                    Discover
                </button>
                @if ($celebrations !== [])
                    <button type="button" wire:click="$set('comingUpDialog', true)" class="{{ $mobileButton }}">
                        <flux:icon.cake class="size-4 text-muted-foreground" />
                        Coming up
                        <span class="num text-muted-foreground">{{ count($celebrations) }}</span>
                    </button>
                @endif
            </nav>

            @if ($activeGroup !== null)
                @include('livewire.community.partials.group-header', ['group' => $activeGroup])
            @endif

            @if ($activeTab === 'members' && $activeGroup !== null)
                @include('livewire.community.partials.members', ['group' => $activeGroup])
            @elseif ($activeTab === 'media' && $activeGroup !== null)
                @include('livewire.community.partials.media', ['group' => $activeGroup])
            @elseif ($lockedOut)
                <div class="hris-card">
                    <x-kit.empty-state icon="lock-closed" title="This group is private." hint="Only members see its posts, members and files." />
                </div>
            @else
                @include('livewire.community.partials.toolbar')

                @if ($activeGroup === null || $activeGroup->joined())
                    @include('livewire.community.partials.composer')
                @endif

                @if ($page !== null && $page->pinned !== [])
                    <section class="space-y-3" aria-labelledby="community-pinned">
                        <h2 id="community-pinned" class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            @include('livewire.community.partials.pin-icon', ['class' => 'size-3.5'])
                            Pinned
                        </h2>
                        @foreach ($page->pinned as $post)
                            @include('livewire.community.partials.post', $postPartial + ['post' => $post])
                        @endforeach
                    </section>

                    @if ($page->posts !== [])
                        <h2 class="pt-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Latest</h2>
                    @endif
                @endif

                @foreach ($page->posts ?? [] as $post)
                    @include('livewire.community.partials.post', $postPartial + ['post' => $post])
                @endforeach

                @if ($page !== null && $page->posts === [] && $page->pinned === [])
                    <div class="hris-card">
                        @if ($filter->narrows() || $filter->groupId !== null)
                            <x-kit.empty-state icon="magnifying-glass" title="No post matches." hint="Try fewer filters, or clear them." />
                        @elseif ($activeGroup !== null)
                            <x-kit.empty-state icon="chat-bubble-left-right" title="No posts in this group yet." hint="Start the conversation. Say hello." />
                        @else
                            <x-kit.empty-state icon="megaphone" title="Nothing here yet." hint="Be the first to post something." />
                        @endif
                    </div>
                @endif

                @if ($page !== null && $page->more)
                    <div class="flex justify-center">
                        <flux:button wire:click="showMore" wire:loading.attr="disabled" wire:target="showMore">Show more posts</flux:button>
                    </div>
                @endif
            @endif
        </div>

        {{-- Sticky, and scrollable in its own right: a rail taller than a
             laptop screen would otherwise hide its bottom card for good. --}}
        <aside class="hidden space-y-4 lg:sticky lg:top-20 lg:block lg:max-h-[calc(100dvh-6rem)] lg:overflow-y-auto" aria-label="Groups and celebrations">
            @include('livewire.community.partials.rail')
        </aside>
    </div>

    <x-social.lightbox />
    <x-social.confirm />
    @include('livewire.community.partials.dialogs')
</div>
