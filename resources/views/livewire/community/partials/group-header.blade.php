{{--
    The head of one group: its photo and name, whether it's private and how
    many are in it, and one button for the thing people come to do here:
    join (or ask to), or for members, add people. Everything rarer sits in
    the ⋮ menu: editing the group, leaving it, deleting it. Waiting requests
    show as a count on the Members tab, and the member count opens that tab
    too. Tabs under it switch between the group's posts, its members, and
    the photos and files shared in it.

    Anyone in the group can add people. The people who run it (its owner and
    moderators) also see the waiting requests and can edit the group.
    Company-wide moderators can delete it. An owner can't leave a group
    others are still in, so their Leave says so and offers the Members tab,
    where they can hand it on.

    Leaving, withdrawing and deleting ask first, in the page's own dialog
    (components/social/confirm). The card is keyed by group, so opening
    another group draws its buttons fresh instead of reusing these.

    Expects: $group (GroupView), $activeTab, $requests.
--}}
@php
    $tabClass = 'inline-flex h-10 cursor-pointer items-center gap-1.5 border-b-2 px-1 text-sm font-medium transition-colors';
    $locked = $group->private && ! $group->joined();
    $askLeave = $group->owned() && $group->members > 1
        ? [
            'title' => 'Hand '.$group->name.' on first',
            'body' => "You own it, so someone else has to own it before you leave. Make someone the owner from the Members tab.",
            'confirm' => 'Go to Members',
            'call' => ['showTab', 'members'],
        ]
        : [
            'title' => 'Leave '.$group->name.'?',
            'body' => $group->private ? "It's private, so getting back in takes a yes from its owner or a moderator." : 'You can join again whenever you like.',
            'confirm' => 'Leave Group',
            'danger' => true,
            'call' => ['leave', $group->id],
        ];
    $askWithdraw = [
        'title' => 'Withdraw your request to join '.$group->name.'?',
        'body' => 'You can ask again later.',
        'confirm' => 'Withdraw Request',
        'call' => ['leave', $group->id],
    ];
    $askDelete = [
        'title' => 'Delete '.$group->name.'?',
        'body' => "Every post in it goes too. This can't be undone, and it's recorded in the audit trail.",
        'confirm' => 'Delete Group',
        'danger' => true,
        'call' => ['deleteGroup'],
    ];
@endphp

<div class="space-y-3" wire:key="group-header-{{ $group->id }}">
    <button
        type="button"
        wire:click="showFeed"
        class="inline-flex cursor-pointer items-center gap-1 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
    >
        <flux:icon.arrow-left class="size-4" />
        All Posts
    </button>

    <div class="hris-card">
        <div class="flex items-center gap-4 p-5">
            <x-social.avatar :name="$group->name" :photo="$group->photo" icon="user-group" size="lg" />
            <div class="min-w-0 flex-1">
                <h2 class="line-clamp-2 break-words text-lg font-bold leading-snug">{{ $group->name }}</h2>
                <p class="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
                    <span class="inline-flex items-center gap-1">
                        @if ($group->private)
                            <flux:icon.lock-closed class="size-3.5" /> Private
                        @else
                            <flux:icon.globe-asia-australia class="size-3.5" /> Public
                        @endif
                    </span>
                    <span aria-hidden="true">&middot;</span>
                    @if ($locked)
                        <span><span class="num">{{ $group->members }}</span> {{ $group->members === 1 ? 'member' : 'members' }}</span>
                    @else
                        <button type="button" wire:click="showTab('members')" class="cursor-pointer hover:text-foreground hover:underline"><span class="num">{{ $group->members }}</span> {{ $group->members === 1 ? 'member' : 'members' }}</button>
                    @endif
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-1.5">
                @if ($group->joined())
                    <flux:button size="sm" icon="user-plus" wire:click="openPeople">
                        <span class="max-sm:sr-only">Add People</span>
                    </flux:button>
                @elseif ($group->requested())
                    <flux:button size="sm" icon="clock" x-on:click="$dispatch('social-confirm', @js($askWithdraw))">
                        Requested
                    </flux:button>
                @else
                    <flux:button size="sm" variant="primary" icon="user-plus" wire:click="join('{{ $group->id }}')">
                        {{ $group->private ? 'Ask to Join' : 'Join' }}
                    </flux:button>
                @endif

                @if ($group->joined() || $group->canDelete)
                    <x-kit.row-actions :label="$group->name">
                        @if ($group->canManage)
                            <flux:menu.item icon="pencil" wire:click="editGroup">Edit Group</flux:menu.item>
                        @endif
                        @if ($group->joined())
                            <flux:menu.item icon="arrow-right-start-on-rectangle" x-on:click="$dispatch('social-confirm', @js($askLeave))">
                                Leave Group
                            </flux:menu.item>
                        @endif
                        @if ($group->canDelete)
                            <flux:menu.item icon="trash" variant="danger" x-on:click="$dispatch('social-confirm', @js($askDelete))">
                                Delete Group
                            </flux:menu.item>
                        @endif
                    </x-kit.row-actions>
                @endif
            </div>
        </div>

        @if ($group->description !== null && $group->description !== '')
            <p class="-mt-1 break-words px-5 pb-4 text-sm text-muted-foreground">{{ $group->description }}</p>
        @endif

        @unless ($locked)
            <nav class="flex gap-5 border-t border-border px-5" role="tablist" aria-label="{{ $group->name }}">
                @foreach (['posts' => 'Posts', 'members' => 'Members', 'media' => 'Photos & files'] as $key => $label)
                    <button
                        type="button"
                        role="tab"
                        aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}"
                        wire:click="showTab('{{ $key }}')"
                        @class([
                            $tabClass,
                            'border-primary text-foreground' => $activeTab === $key,
                            'border-transparent text-muted-foreground hover:text-foreground' => $activeTab !== $key,
                        ])
                    >
                        {{ $label }}
                        @if ($key === 'members' && $group->canManage && $group->requests > 0)
                            <span class="num flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-primary px-1 text-[0.65rem] font-semibold text-primary-foreground" aria-hidden="true">{{ $group->requests }}</span>
                            <span class="sr-only">{{ $group->requests }} waiting to join</span>
                        @endif
                    </button>
                @endforeach
            </nav>
        @endunless
    </div>
</div>
