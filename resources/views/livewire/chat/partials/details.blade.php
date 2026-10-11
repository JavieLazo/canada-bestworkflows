{{--
    The open conversation's details: who it is with, mute, and everything
    shared in it (photos, then files). For a group, also its photo and name
    (anyone in it can change both), its members with Add people, and the way
    out. Admins get a menu on each member: make or unmake an admin, or
    remove them. Removing and leaving ask first, in the page's own dialog
    (components/social/confirm).

    Muting a group asks whether mentions should still get through
    (partials/mute). While it's muted, a line says which, with a way to
    change it.

    Covers the thread below 2xl (a phone or a laptop has no room for a third
    column, and a squeezed thread is hard to read) and sits beside it from
    2xl up.

    Expects: $active (ConversationView), $thread (ThreadView), $other (the
    person in a direct chat, or null), $viewer; reads $staged, $maxBytes.
--}}
@php
    $sharedPhotos = array_map(static fn (string $id): string => route('chat.photos', ['photo' => $id]), $thread->photos);
    // Someone whose role lost Chat, or whose account was switched off. They
    // stay in the conversation and keep their messages, so we say it here
    // instead of letting people wait on a reply that cannot come.
    $gone = static fn (int $id): bool => in_array($id, $thread->withoutChat, true);
    $isAdmin = static fn (int $id): bool => in_array($id, $thread->admins, true);
    $staging = $staged['avatar'][0] ?? null;
    $stagingUrl = null;
    if ($staging !== null) {
        try {
            $stagingUrl = $staging->temporaryUrl();
        } catch (\Throwable) {
            $stagingUrl = null;
        }
    }
    $heading = 'text-xs font-semibold uppercase tracking-wide text-muted-foreground';
    $askRemovePhoto = ['title' => 'Remove the group photo?', 'body' => 'The chat goes back to its group icon, for everyone in it.', 'confirm' => 'Remove photo', 'danger' => true, 'call' => ['removeConversationPhoto']];
    $askLeave = ['title' => 'Leave '.$active->title.'?', 'body' => "You'll stop getting its messages, and only someone still in it can add you back.", 'confirm' => 'Leave group', 'danger' => true, 'call' => ['leaveConversation']];
@endphp
<aside class="absolute inset-0 z-10 flex flex-col bg-card md:left-auto md:w-96 md:border-l md:border-border md:shadow-lift 2xl:static 2xl:inset-auto 2xl:w-80 2xl:shrink-0 2xl:shadow-none" aria-label="Conversation details">
    <header class="flex h-16 shrink-0 items-center gap-2 border-b border-border px-4">
        <p class="flex-1 text-sm font-semibold">Details</p>
        <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="toggleDetails" aria-label="Close details" />
    </header>

    <div class="min-h-0 flex-1 space-y-6 overflow-y-auto p-5">
        <div class="flex flex-col items-center text-center">
            @if ($other !== null)
                <x-social.avatar :name="$other->name" :photo="$other->photo" size="lg" />
            @else
                <div class="relative" x-data="attachmentPicker({ maxBytes: @js($maxBytes), slot: 'avatar', photosOnly: true })">
                    <x-social.avatar :name="$active->title" :photo="$stagingUrl ?? $active->photo" icon="user-group" size="lg" />
                    <input x-ref="photo" type="file" accept="image/*" class="sr-only" tabindex="-1" aria-hidden="true" x-on:change="pick($event)" />
                    <button
                        type="button"
                        x-on:click="$refs.photo.click()"
                        class="absolute -bottom-1 -right-1 inline-flex size-7 cursor-pointer items-center justify-center rounded-full border border-border bg-card text-muted-foreground shadow-sm transition-colors hover:bg-accent hover:text-accent-foreground"
                        aria-label="Change the group photo"
                        title="Change the group photo"
                    >
                        <flux:icon.camera class="size-3.5" />
                    </button>
                </div>
                @if ($stagingUrl !== null)
                    <div class="mt-3 flex gap-2">
                        <flux:button size="xs" variant="primary" wire:click="saveConversationPhoto">Use this photo</flux:button>
                        <flux:button size="xs" variant="ghost" wire:click="unstage('avatar', 0)">Cancel</flux:button>
                    </div>
                @elseif ($active->photo !== null)
                    <button type="button" x-on:click="$dispatch('social-confirm', @js($askRemovePhoto))" class="mt-2 cursor-pointer text-xs text-muted-foreground hover:text-foreground hover:underline">Remove photo</button>
                @endif
            @endif

            @if ($other === null)
                <div class="mt-3 w-full" x-data="{ editing: false }" x-on:chat-renamed.window="editing = false">
                    <div x-show="! editing" class="flex items-center justify-center gap-1">
                        <p class="min-w-0 truncate text-base font-semibold">{{ $active->title }}</p>
                        <button
                            type="button"
                            x-on:click="editing = true; $wire.set('titleDraft', @js($active->title), false); $nextTick(() => $refs.title.select())"
                            class="inline-flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                            aria-label="Rename the group"
                            title="Rename the group"
                        >
                            <flux:icon.pencil class="size-3.5" />
                        </button>
                    </div>
                    <form x-show="editing" x-cloak wire:submit="renameConversation" class="flex items-center gap-1.5">
                        <input
                            x-ref="title"
                            type="text"
                            wire:model="titleDraft"
                            maxlength="60"
                            x-on:keydown.escape="editing = false"
                            aria-label="Group name"
                            class="h-8 min-w-0 flex-1 rounded-md border border-input bg-card px-2.5 text-sm outline-hidden focus:ring-2 focus:ring-ring"
                        />
                        <flux:button type="submit" size="sm" variant="primary">Save</flux:button>
                    </form>
                </div>
            @else
                <p class="mt-3 text-base font-semibold">{{ $active->title }}</p>
            @endif

            <p class="text-xs text-muted-foreground">
                @if ($other !== null)
                    {{ $other->position }}
                @else
                    Group &middot; <span class="num">{{ count($active->others) + 1 }}</span> members
                @endif
            </p>
            @if ($other !== null && $gone($other->id))
                <p class="mt-1 text-xs text-muted-foreground">{{ $other->name }} can no longer use Chat.</p>
            @endif
        </div>

        @if ($active->muted)
            <div class="space-y-1.5" wire:key="details-muted-{{ $active->id }}">
                <flux:button class="w-full" icon="bell" wire:click="unmute">Unmute conversation</flux:button>
                <p class="text-center text-xs text-muted-foreground">
                    {{ $active->allowMentions ? 'Muted, except when someone mentions you.' : 'Muted. Nothing from it notifies you.' }}
                    @if ($active->isGroup())
                        <button type="button" x-on:click="$wire.muteDialog = true" class="cursor-pointer font-medium text-primary hover:underline">Change</button>
                    @endif
                </p>
            </div>
        @elseif ($active->isGroup())
            <flux:button class="w-full" icon="bell-slash" x-on:click="$wire.muteDialog = true" wire:key="details-unmuted-{{ $active->id }}">Mute conversation</flux:button>
        @else
            <flux:button class="w-full" icon="bell-slash" wire:click="mute('ALL')" wire:key="details-unmuted-{{ $active->id }}">Mute conversation</flux:button>
        @endif

        @if ($active->isGroup())
            <section aria-labelledby="chat-members">
                <div class="flex items-center justify-between gap-2">
                    <h3 id="chat-members" class="{{ $heading }}">Members</h3>
                    <button type="button" wire:click="openAddPeople" class="inline-flex cursor-pointer items-center gap-1 text-xs font-medium text-primary hover:underline">
                        <flux:icon.user-plus class="size-3.5" />
                        Add people
                    </button>
                </div>
                <ul class="mt-2 space-y-1">
                    <li class="flex items-center gap-3 rounded-md py-1.5">
                        <x-social.avatar :name="$viewer->name" :photo="$viewer->photo" size="sm" />
                        <span class="min-w-0 flex-1 text-sm">
                            <span class="flex items-center gap-1.5">
                                <span class="truncate font-medium">{{ $viewer->name }}</span>
                                <span class="shrink-0 text-xs text-muted-foreground">(you)</span>
                                @if ($active->admin)
                                    <span class="pill pill-primary shrink-0">Admin</span>
                                @endif
                            </span>
                            @if ($viewer->position !== null)
                                <span class="block truncate text-xs text-muted-foreground">{{ $viewer->position }}</span>
                            @endif
                        </span>
                    </li>
                    @foreach ($active->others as $member)
                        <li wire:key="member-{{ $member->id }}" class="flex items-center gap-3 rounded-md py-1.5">
                            <x-social.avatar :name="$member->name" :photo="$member->photo" size="sm" />
                            <span class="min-w-0 flex-1 text-sm">
                                <span class="flex items-center gap-1.5">
                                    <span class="truncate font-medium">{{ $member->name }}</span>
                                    @if ($isAdmin($member->id))
                                        <span class="pill pill-primary shrink-0">Admin</span>
                                    @endif
                                </span>
                                @if ($gone($member->id))
                                    <span class="block truncate text-xs text-muted-foreground">Can no longer use Chat</span>
                                @elseif ($member->position !== null)
                                    <span class="block truncate text-xs text-muted-foreground">{{ $member->position }}</span>
                                @endif
                            </span>
                            @if ($active->admin)
                                <x-kit.row-actions :label="$member->name">
                                    @if ($isAdmin($member->id))
                                        <flux:menu.item icon="shield-exclamation" wire:click="setAdmin({{ $member->id }}, false)">Remove as admin</flux:menu.item>
                                    @else
                                        <flux:menu.item icon="shield-check" wire:click="setAdmin({{ $member->id }}, true)">Make an admin</flux:menu.item>
                                    @endif
                                    <flux:menu.separator />
                                    @php($askRemove = ['title' => 'Remove '.$member->name.' from '.$active->title.'?', 'body' => 'What they wrote stays.', 'confirm' => 'Remove', 'danger' => true, 'call' => ['removeMember', $member->id]])
                                    <flux:menu.item icon="user-minus" variant="danger" x-on:click="$dispatch('social-confirm', @js($askRemove))">Remove from chat</flux:menu.item>
                                </x-kit.row-actions>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section aria-labelledby="chat-photos">
            <h3 id="chat-photos" class="{{ $heading }}">Photos <span class="num font-normal normal-case">{{ count($sharedPhotos) }}</span></h3>
            @if ($sharedPhotos === [])
                <p class="mt-2 text-xs text-muted-foreground">Photos sent here will collect here.</p>
            @else
                <div class="mt-2 grid grid-cols-3 gap-1.5">
                    @foreach ($sharedPhotos as $k => $src)
                        <button
                            type="button"
                            x-on:click="$dispatch('lightbox', { images: @js($sharedPhotos), index: {{ $k }} })"
                            class="block aspect-square cursor-pointer overflow-hidden rounded-md bg-muted"
                            aria-label="Open shared photo {{ $k + 1 }}"
                        >
                            <img src="{{ $src }}" alt="" loading="lazy" class="size-full object-cover" />
                        </button>
                    @endforeach
                </div>
            @endif
        </section>

        <section aria-labelledby="chat-files">
            <h3 id="chat-files" class="{{ $heading }}">Files <span class="num font-normal normal-case">{{ count($thread->files) }}</span></h3>
            @if ($thread->files === [])
                <p class="mt-2 text-xs text-muted-foreground">Files sent here will collect here.</p>
            @else
                <x-social.files :files="$thread->files" route="chat.files" compact class="mt-2 flex-col" />
            @endif
        </section>

        @if ($active->isGroup())
            <flux:button
                class="w-full"
                variant="danger"
                icon="arrow-right-start-on-rectangle"
                x-on:click="$dispatch('social-confirm', @js($askLeave))"
            >
                Leave group
            </flux:button>
        @endif
    </div>
</aside>
