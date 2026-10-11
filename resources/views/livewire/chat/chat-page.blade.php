{{--
    Chat. See App\Livewire\Chat\ChatPage for the state and actions.

    Fills everything under the app's header, edge to edge (its own frame,
    livewire/chat/layout), split into the conversation list, the open
    thread, and (on request) the details panel. The panes scroll on their
    own, so the page never does. Below md a phone shows one pane at a time:
    the list, or the thread with a back button. The details panel slides
    over the thread until 2xl, where it becomes a third column.

    The root checks for news at the punch feeds' cadence through pollForChanges
    (WithChangeStamp), so a tick only redraws when one of the viewer's own
    conversations moved.

    A muted chat shows a crossed-out bell and a grey count. One muted with
    mentions allowed turns loud again for a mention: its count becomes the
    unread mentions, with an @ in front. Muting a group chat asks which of
    the two (partials/mute); a direct chat has no mentions, so it just mutes.
--}}
@use('App\Livewire\Chat\Support\ChatThread')
@use('App\Support\SocialFormat')
@php
    $chipClasses = 'inline-flex h-7 cursor-pointer items-center gap-1 rounded-full px-3 text-xs font-medium transition-colors';
    $iconButton = 'inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground';
    $activeOther = $active?->other();
@endphp

<div class="flex h-[calc(100dvh-3.5rem)] min-h-[28rem] bg-card" wire:poll.visible.{{ $pollSeconds }}s="pollForChanges">
    <h1 class="sr-only">Chat</h1>
    <div class="relative flex min-h-0 min-w-0 flex-1 overflow-hidden">
        {{-- The conversation list. --}}
        <section
            @class([
                'min-h-0 w-full flex-col border-border md:flex md:w-80 md:shrink-0 md:border-r lg:w-88',
                'flex' => $pane === 'list',
                'hidden' => $pane !== 'list',
            ])
            aria-label="Conversations"
        >
            <div class="space-y-3 border-b border-border p-3">
                <div class="flex items-center gap-2">
                    <div class="min-w-0 flex-1">
                        <flux:input
                            size="sm"
                            icon="magnifying-glass"
                            placeholder="Search chats"
                            aria-label="Search chats"
                            wire:model.live.debounce.300ms="search"
                        />
                    </div>
                    <flux:button size="sm" variant="primary" icon="pencil-square" wire:click="openNewChat">New</flux:button>
                </div>
                <div class="flex gap-1" role="tablist" aria-label="Show">
                    @foreach (['all' => 'All', 'unread' => 'Unread', 'groups' => 'Groups'] as $value => $label)
                        <button
                            type="button"
                            role="tab"
                            aria-selected="{{ $filter === $value ? 'true' : 'false' }}"
                            wire:click="$set('filter', '{{ $value }}')"
                            @class([
                                $chipClasses,
                                'bg-primary-soft text-primary' => $filter === $value,
                                'text-muted-foreground hover:bg-accent hover:text-accent-foreground' => $filter !== $value,
                            ])
                        >
                            {{ $label }}
                            @if ($value === 'unread' && $unreadChats > 0)
                                <span class="num">{{ $unreadChats }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>

            <ul class="min-h-0 flex-1 space-y-0.5 overflow-y-auto p-1.5">
                @forelse ($list as $c)
                    @php
                        $isActive = $active !== null && $active->id === $c->id;
                        $badge = $c->badge();
                        $loud = $badge > 0;
                        $count = $loud ? $badge : $c->unread;
                        $person = $c->other();
                    @endphp
                    <li wire:key="conversation-{{ $c->id }}">
                        <button
                            type="button"
                            wire:click="openConversation('{{ $c->id }}')"
                            @class([
                                'flex w-full cursor-pointer items-center gap-3 rounded-md p-2.5 text-left transition-colors',
                                'bg-accent text-accent-foreground' => $isActive,
                                'hover:bg-accent/60' => ! $isActive,
                            ])
                            @if ($isActive) aria-current="true" @endif
                        >
                            @if ($person !== null)
                                <x-social.avatar :name="$person->name" :photo="$person->photo" />
                            @else
                                <x-social.avatar :name="$c->title" :photo="$c->photo" icon="user-group" />
                            @endif
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-1.5">
                                    <span @class(['truncate text-sm', 'font-semibold' => $loud, 'font-medium' => ! $loud])>{{ $c->title }}</span>
                                    @if ($c->muted)
                                        <flux:icon.bell-slash class="size-3.5 shrink-0 text-muted-foreground" />
                                        <span class="sr-only">{{ $c->allowMentions ? '(muted, except mentions)' : '(muted)' }}</span>
                                    @endif
                                    @if ($c->lastAt !== null)
                                        <span @class(['ml-auto shrink-0 text-xs', 'font-semibold text-primary' => $loud, 'text-muted-foreground' => ! $loud])>
                                            {{ SocialFormat::listTime($c->lastAt, $now) }}
                                        </span>
                                    @endif
                                </span>
                                <span class="mt-0.5 flex items-center gap-2">
                                    <span @class(['truncate text-xs', 'font-medium text-foreground' => $loud, 'text-muted-foreground' => ! $loud])>{{ $c->preview }}</span>
                                    @if ($c->unread > 0)
                                        <span
                                            @class([
                                                'num ml-auto flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 text-[0.65rem] font-semibold',
                                                'bg-primary text-primary-foreground' => $loud,
                                                'bg-muted text-muted-foreground' => ! $loud,
                                            ])
                                            aria-label="{{ $loud && $c->muted ? $count.' unread '.($count === 1 ? 'mention' : 'mentions') : $count.' unread' }}"
                                        >{{ $loud && $c->muted ? '@' : '' }}{{ $count > 9 ? '9+' : $count }}</span>
                                    @endif
                                </span>
                            </span>
                        </button>
                    </li>
                @empty
                    <li>
                        <x-kit.empty-state
                            icon="chat-bubble-left-right"
                            :title="trim($search) !== '' ? 'No chat matches that search.' : ($filter === 'unread' ? 'You are all caught up.' : 'No conversations yet.')"
                            :hint="$filter === 'all' && trim($search) === '' ? 'Start one with New.' : null"
                        />
                    </li>
                @endforelse
            </ul>
        </section>

        {{-- The open thread. --}}
        <section
            @class([
                'min-h-0 min-w-0 flex-1 flex-col md:flex',
                'flex' => $pane === 'thread',
                'hidden' => $pane !== 'thread',
            ])
            aria-label="{{ $active !== null ? 'Conversation with '.$active->title : 'Conversation' }}"
        >
            @if ($thread !== null && $active !== null)
                <header class="flex h-16 shrink-0 items-center gap-3 border-b border-border px-3 sm:px-4">
                    <button type="button" wire:click="backToList" class="{{ $iconButton }} -ml-1 md:hidden" aria-label="Back to conversations">
                        <flux:icon.arrow-left class="size-5" />
                    </button>
                    <button type="button" wire:click="toggleDetails" class="flex min-w-0 flex-1 cursor-pointer items-center gap-3 text-left" title="Details">
                        @if ($activeOther !== null)
                            <x-social.avatar :name="$activeOther->name" :photo="$activeOther->photo" />
                        @else
                            <x-social.avatar :name="$active->title" :photo="$active->photo" icon="user-group" />
                        @endif
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold">{{ $active->title }}</span>
                            <span class="block truncate text-xs text-muted-foreground">
                                @if ($activeOther !== null)
                                    {{ $activeOther->position ?? ($activeOther->active ? '' : 'No longer active') }}
                                @else
                                    <span class="num">{{ count($active->others) + 1 }}</span> members
                                @endif
                            </span>
                        </span>
                    </button>
                    <button
                        type="button"
                        wire:key="mute-{{ $active->id }}-{{ $active->muted ? 'on' : 'off' }}"
                        @if ($active->muted)
                            wire:click="unmute"
                        @elseif ($active->isGroup())
                            x-on:click="$wire.muteDialog = true"
                        @else
                            wire:click="mute('ALL')"
                        @endif
                        class="{{ $iconButton }}"
                        aria-pressed="{{ $active->muted ? 'true' : 'false' }}"
                        title="{{ $active->muted ? ($active->allowMentions ? 'Muted, except mentions. Click to unmute' : 'Unmute') : 'Mute' }}"
                    >
                        @if ($active->muted)
                            <flux:icon.bell-slash class="size-5" />
                        @else
                            <flux:icon.bell class="size-5" />
                        @endif
                        <span class="sr-only">{{ $active->muted ? 'Unmute' : 'Mute' }} this conversation</span>
                    </button>
                    <button
                        type="button"
                        wire:click="toggleDetails"
                        @class([$iconButton, 'bg-accent text-accent-foreground' => $detailsOpen])
                        aria-pressed="{{ $detailsOpen ? 'true' : 'false' }}"
                        title="Details"
                    >
                        <flux:icon.information-circle class="size-5" />
                        <span class="sr-only">Conversation details</span>
                    </button>
                </header>

                {{--
                    Stays on the newest message while the reader is there: a
                    message arriving (the poll, a knock, a send) or a photo
                    finishing loading scrolls it back down. Someone who has
                    scrolled up to read is left where they are.

                    Opened from a notification, it goes to the highlighted
                    message instead, and keeps it in view while photos above
                    it load, until the reader scrolls on their own.
                --}}
                <div
                    wire:key="thread-{{ $active->id }}"
                    class="min-h-0 flex-1 overflow-y-auto px-3 py-4 sm:px-6"
                    x-data="{
                        stick: true,
                        found: null,
                        observer: null,
                        init() {
                            this.found = this.$el.querySelector('[data-found]');
                            this.found ? this.center() : this.bottom();
                            this.observer = new MutationObserver(() => this.stick && this.bottom());
                            this.observer.observe(this.$el, { childList: true, subtree: true });
                        },
                        destroy() {
                            this.observer.disconnect();
                        },
                        bottom() {
                            this.$el.scrollTop = this.$el.scrollHeight;
                        },
                        center() {
                            this.stick = false;
                            this.found.scrollIntoView({ block: 'center' });
                        },
                    }"
                    x-on:scroll="if (! found) stick = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 96"
                    x-on:wheel.passive="found = null"
                    x-on:touchmove.passive="found = null"
                    x-on:keydown="found = null"
                    x-on:pointerdown="found = null"
                    x-on:load.capture="found ? center() : stick && bottom()"
                    x-on:chat-sent.window="found = null; stick = true; requestAnimationFrame(() => bottom())"
                    x-on:chat-opened.window="found = null; stick = true; requestAnimationFrame(() => bottom())"
                    role="log"
                    aria-live="polite"
                >
                    <div class="mx-auto max-w-3xl">
                        @if ($thread->more)
                            <div class="mb-2 flex justify-center">
                                <flux:button size="sm" variant="ghost" wire:click="showEarlier" wire:loading.attr="disabled" wire:target="showEarlier">Load earlier messages</flux:button>
                            </div>
                        @endif

                        @if ($rows === [])
                            <x-kit.empty-state icon="hand-raised" title="No messages yet." hint="Say hello." />
                        @endif

                        @foreach ($rows as $row)
                            @include('livewire.chat.partials.message', ['row' => $row])
                        @endforeach

                        {{-- One line under your last message: Sent, then who has seen it. In
                             a group, a click lists everyone who has. --}}
                        @php
                            $seenLabel = ChatThread::seenLabel($thread);
                        @endphp
                        @if ($seenLabel !== null)
                            <div class="mt-1.5 flex justify-end px-1 text-[0.7rem] text-muted-foreground">
                                @if ($active->isGroup() && $thread->seenBy !== [])
                                    <x-social.who :ids="$thread->seenBy" :people="$thread->people" title="Seen by" align="end" class="hover:text-foreground hover:underline">{{ $seenLabel }}</x-social.who>
                                @else
                                    <span>{{ $seenLabel }}</span>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>

                @include('livewire.chat.partials.composer')
            @else
                <div class="flex flex-1 items-center justify-center">
                    <div class="text-center">
                        <x-kit.empty-state icon="chat-bubble-left-right" title="Pick a conversation." hint="Or start a new one." />
                        <flux:button size="sm" icon="pencil-square" wire:click="openNewChat" class="-mt-6">New chat</flux:button>
                    </div>
                </div>
            @endif
        </section>

        {{-- The details panel. --}}
        @if ($detailsOpen && $thread !== null && $active !== null)
            @include('livewire.chat.partials.details', ['other' => $activeOther])
        @endif
    </div>

    @include('livewire.chat.partials.new-chat')

    @if ($active !== null && $active->isGroup())
        @include('livewire.chat.partials.mute')
    @endif

    <x-social.lightbox />
    <x-social.confirm />
</div>
