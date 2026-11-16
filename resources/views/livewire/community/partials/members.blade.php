{{--
    A group's Members tab: the requests waiting to be answered (for the
    people who run the group), then everyone in it, the owner and moderators
    first.

    The owner can make members moderators, take it back, or hand the whole
    group to someone else. The owner and moderators can remove ordinary
    members; only the owner can remove a moderator. Whatever someone posted
    stays when they leave or are removed. Handing the group on and removing
    someone ask first, in the page's own dialog (components/social/confirm).

    Expects: $group (GroupView), $members, $requests (list of MemberView),
    $viewer.
--}}
@php
    $owner = $group->role === 'OWNER';
    $roleLabel = ['OWNER' => 'Owner', 'MODERATOR' => 'Moderator'];
@endphp

<div class="space-y-4">
    @if ($group->canManage && $requests !== [])
        <section class="hris-card" aria-labelledby="group-requests">
            <header class="flex items-center justify-between gap-3 border-b border-border px-5 py-3">
                <h3 id="group-requests" class="text-sm font-semibold">Waiting to Join <span class="num font-normal text-muted-foreground">{{ count($requests) }}</span></h3>
                <p class="text-xs text-muted-foreground">Whoever you let in is told.</p>
            </header>
            <ul class="divide-y divide-border">
                @foreach ($requests as $request)
                    <li wire:key="request-{{ $request->person->id }}" class="flex flex-wrap items-center gap-3 px-5 py-3">
                        <x-social.avatar :name="$request->person->name" :photo="$request->person->photo" size="sm" />
                        <span class="min-w-0 flex-1 text-sm">
                            <span class="block truncate font-medium">{{ $request->person->name }}</span>
                            <span class="block truncate text-xs text-muted-foreground">
                                @if ($request->addedBy !== null)
                                    Added by {{ $request->addedBy->name }}
                                @else
                                    Asked to join
                                @endif
                                &middot; {{ \App\Support\SocialFormat::ago($request->since, $now) }}
                            </span>
                        </span>
                        <div class="flex gap-2">
                            <flux:button size="sm" variant="primary" wire:click="decideRequest({{ $request->person->id }}, true)">Let In</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="decideRequest({{ $request->person->id }}, false)">Decline</flux:button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="hris-card" aria-labelledby="group-members">
        <header class="flex items-center justify-between gap-3 border-b border-border px-5 py-3">
            <h3 id="group-members" class="text-sm font-semibold">Members <span class="num font-normal text-muted-foreground">{{ count($members) }}</span></h3>
            @if ($group->joined())
                <flux:button size="sm" variant="ghost" icon="user-plus" wire:click="openPeople">Add People</flux:button>
            @endif
        </header>
        <ul class="divide-y divide-border">
            @forelse ($members as $member)
                @php
                    $me = $member->person->id === $viewer->id;
                    $canRemove = ! $me && $group->canManage && ! $member->isOwner() && ($owner || ! $member->isModerator());
                    $canPromote = ! $me && $owner;
                    $askOwner = [
                        'title' => 'Make '.$member->person->name.' the owner of '.$group->name.'?',
                        'body' => "You'll stay on as a moderator.",
                        'confirm' => 'Make the Owner',
                        'call' => ['setRole', $member->person->id, 'OWNER'],
                    ];
                    $askRemove = [
                        'title' => 'Remove '.$member->person->name.' from '.$group->name.'?',
                        'body' => 'What they posted stays.',
                        'confirm' => 'Remove',
                        'danger' => true,
                        'call' => ['removeMember', $member->person->id],
                    ];
                @endphp
                <li wire:key="member-{{ $member->person->id }}" class="flex items-center gap-3 px-5 py-3">
                    <x-social.avatar :name="$member->person->name" :photo="$member->person->photo" size="sm" />
                    <span class="min-w-0 flex-1 text-sm">
                        <span class="flex items-center gap-1.5">
                            <span class="truncate font-medium">{{ $member->person->name }}</span>
                            @if ($me)
                                <span class="shrink-0 text-xs text-muted-foreground">(you)</span>
                            @endif
                            @if (isset($roleLabel[$member->role]))
                                <span class="pill {{ $member->isOwner() ? 'pill-primary' : 'pill-muted' }} shrink-0">{{ $roleLabel[$member->role] }}</span>
                            @endif
                        </span>
                        @if ($member->person->position !== null)
                            <span class="block truncate text-xs text-muted-foreground">{{ $member->person->position }}</span>
                        @endif
                    </span>
                    @if ($canRemove || $canPromote)
                        <x-kit.row-actions :label="$member->person->name">
                            @if ($canPromote && ! $member->isModerator())
                                <flux:menu.item icon="shield-check" wire:click="setRole({{ $member->person->id }}, 'MODERATOR')">Make a Moderator</flux:menu.item>
                            @endif
                            @if ($canPromote && $member->isModerator())
                                <flux:menu.item icon="shield-exclamation" wire:click="setRole({{ $member->person->id }}, 'MEMBER')">Remove as Moderator</flux:menu.item>
                            @endif
                            @if ($canPromote)
                                <flux:menu.item icon="key" x-on:click="$dispatch('social-confirm', @js($askOwner))">Make the Owner</flux:menu.item>
                            @endif
                            @if ($canRemove)
                                <flux:menu.separator />
                                <flux:menu.item icon="user-minus" variant="danger" x-on:click="$dispatch('social-confirm', @js($askRemove))">Remove from Group</flux:menu.item>
                            @endif
                        </x-kit.row-actions>
                    @endif
                </li>
            @empty
                <li class="px-5 py-6 text-center text-sm text-muted-foreground">Only members can see who's in a private group.</li>
            @endforelse
        </ul>
    </section>
</div>
