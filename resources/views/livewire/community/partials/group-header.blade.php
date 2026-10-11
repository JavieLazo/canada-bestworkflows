{{--
    The head of one group: its photo, who it is for, how many are in it, and
    what the viewer can do there. Tabs under it switch between the group's
    posts, its members, and the photos and files shared in it.

    Anyone in the group can add people. The people who run it (its owner and
    moderators) also see the waiting requests and can edit the group.
    Company-wide moderators can delete it.

    Leaving, withdrawing and deleting ask first, in the page's own dialog
    (components/social/confirm). The card is keyed by group, so opening
    another group draws its buttons fresh instead of reusing these.

    Expects: $group (GroupView), $activeTab, $requests.
--}}
@php
    $tabClass = 'inline-flex h-10 cursor-pointer items-center gap-1.5 border-b-2 px-1 text-sm font-medium transition-colors';
    $locked = $group->private && ! $group->joined();
    $askLeave = [
        'title' => 'Leave '.$group->name.'?',
        'body' => $group->private ? "It's private, so getting back in takes a yes from its owner or a moderator." : 'You can join again whenever you like.',
        'confirm' => 'Leave group',
        'danger' => true,
        'call' => ['leave', $group->id],
    ];
    $askWithdraw = [
        'title' => 'Withdraw your request to join '.$group->name.'?',
        'body' => 'You can ask again later.',
        'confirm' => 'Withdraw request',
        'call' => ['leave', $group->id],
    ];
    $askDelete = [
        'title' => 'Delete '.$group->name.'?',
        'body' => "Every post in it goes too. This can't be undone, and it's recorded in the audit trail.",
        'confirm' => 'Delete group',
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
        All posts
    </button>

    <div class="hris-card overflow-hidden">
        <div class="h-16 bg-primary-soft" aria-hidden="true"></div>
        <div class="flex flex-wrap items-end gap-4 px-5">
            <x-social.avatar :name="$group->name" :photo="$group->photo" icon="user-group" size="lg" class="-mt-8 ring-4 ring-card" />
            <div class="min-w-0 flex-1">
                <h2 class="truncate text-lg font-bold leading-tight">{{ $group->name }}</h2>
                <p class="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
                    @if ($group->private)
                        <flux:icon.lock-closed class="size-3.5" /> Private group
                    @else
                        <flux:icon.globe-asia-australia class="size-3.5" /> Public group
                    @endif
                    <span aria-hidden="true">&middot;</span>
                    <span><span class="num">{{ $group->members }}</span> {{ $group->members === 1 ? 'member' : 'members' }}</span>
                    @if ($group->role === 'OWNER' || $group->role === 'MODERATOR')
                        <span aria-hidden="true">&middot;</span>
                        <span>You're {{ $group->role === 'OWNER' ? 'the owner' : 'a moderator' }}</span>
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($group->canManage && $group->requests > 0)
                    <flux:button size="sm" icon="inbox" wire:click="showTab('members')">
                        <span class="num">{{ $group->requests }}</span> {{ $group->requests === 1 ? 'request' : 'requests' }}
                    </flux:button>
                @endif

                @if ($group->joined())
                    <flux:button size="sm" icon="user-plus" wire:click="openPeople">Add people</flux:button>
                    <flux:button size="sm" icon="check" x-on:click="$dispatch('social-confirm', @js($askLeave))">
                        Joined
                    </flux:button>
                @elseif ($group->requested())
                    <flux:button size="sm" icon="clock" x-on:click="$dispatch('social-confirm', @js($askWithdraw))">
                        Requested
                    </flux:button>
                @else
                    <flux:button size="sm" variant="primary" icon="user-plus" wire:click="join('{{ $group->id }}')">
                        {{ $group->private ? 'Ask to join' : 'Join group' }}
                    </flux:button>
                @endif

                @if ($group->canManage || $group->canDelete)
                    <x-kit.row-actions :label="$group->name">
                        @if ($group->canManage)
                            <flux:menu.item icon="pencil" wire:click="editGroup">Edit group</flux:menu.item>
                        @endif
                        @if ($group->canDelete)
                            <flux:menu.item icon="trash" variant="danger" x-on:click="$dispatch('social-confirm', @js($askDelete))">
                                Delete group
                            </flux:menu.item>
                        @endif
                    </x-kit.row-actions>
                @endif
            </div>
        </div>

        @if ($group->description !== null && $group->description !== '')
            <p class="px-5 pt-3 text-sm text-muted-foreground">{{ $group->description }}</p>
        @endif

        @unless ($locked)
            <nav class="mt-2 flex gap-5 border-t border-border px-5" role="tablist" aria-label="{{ $group->name }}">
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
                            <span class="num flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-primary px-1 text-[0.65rem] font-semibold text-primary-foreground">{{ $group->requests }}</span>
                        @endif
                    </button>
                @endforeach
            </nav>
        @else
            <div class="h-5"></div>
        @endunless
    </div>
</div>
