{{--
    The header bell (NotificationBell.tsx). Alpine popover — no Flux dropdown,
    because the panel is a custom feed, not a menu. The tick calls
    pollForChanges (WithChangeStamp), so the shell's one every-page poll only
    redraws the bell when this account's notifications actually move.
    wire:poll.60s is the exact
    interval the reference refetched on.
--}}
<div class="relative" wire:poll.60s="pollForChanges" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    @if ($signedIn)
        <button
            type="button"
            x-on:click="open = !open"
            class="relative inline-flex size-9 cursor-pointer items-center justify-center rounded-md text-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
            aria-label="{{ $unread > 0 ? "Notifications, {$unread} unread" : 'Notifications' }}"
            x-bind:aria-expanded="open"
        >
            @include('layouts.app.module-icon', ['icon' => 'bell', 'class' => 'h-4 w-4'])
            @if ($unread > 0)
                <span class="num absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[0.6rem] font-semibold text-danger-foreground">
                    {{ $unread > 9 ? '9+' : $unread }}
                </span>
            @endif
        </button>

        <div
            x-show="open"
            x-cloak
            x-on:click.outside="open = false"
            x-transition.opacity.duration.100ms
            class="absolute right-0 top-full z-50 mt-2 w-88 max-w-[calc(100vw-2rem)] overflow-hidden rounded-md border border-border bg-popover text-popover-foreground shadow-lift"
        >
            <div class="flex items-center justify-between border-b border-border px-4 py-2.5">
                <p class="text-sm font-semibold">Notifications</p>
                @if ($unread > 0)
                    <button
                        type="button"
                        wire:click="markAllRead"
                        class="inline-flex h-7 cursor-pointer items-center rounded-md px-2 text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                    >
                        Mark All Read
                    </button>
                @endif
            </div>

            @if ($items->isEmpty())
                <p class="px-4 py-8 text-center text-sm text-muted-foreground">
                    Nothing to catch up on.
                </p>
            @else
                {{-- `overflow-y-auto` on the list itself: the bar is the only
                     thing saying the feed continues past the fold. --}}
                <ul class="max-h-80 divide-y divide-border overflow-y-auto">
                    @foreach ($items as $n)
                        <li wire:key="bell-{{ $n->id }}">
                            <button
                                type="button"
                                wire:click="follow('{{ $n->id }}')"
                                x-on:click="open = false"
                                class="flex w-full cursor-pointer gap-2.5 px-4 py-3 text-left transition-colors hover:bg-accent"
                            >
                                <span aria-hidden="true" class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full {{ $n->read_at ? 'bg-transparent' : 'bg-primary' }}"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm {{ $n->read_at ? 'text-muted-foreground' : 'font-medium' }}">
                                        {{ $n->title }}
                                    </span>
                                    @if ($n->body)
                                        <span class="mt-0.5 block truncate text-xs text-muted-foreground">
                                            {{ $n->body }}
                                        </span>
                                    @endif
                                    <span class="num mt-0.5 block text-xs text-muted-foreground">
                                        {{ $n->created_at->setTimezone(\App\Support\LocalClock::zone())->format('M d, Y, g:i A') }}
                                    </span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>

                <div class="border-t border-border p-1">
                    <a
                        href="{{ url('/notifications') }}"
                        x-on:click="open = false"
                        class="flex h-9 w-full items-center justify-center gap-1 rounded-md text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                    >
                        Show All Notifications
                        @include('layouts.app.module-icon', ['icon' => 'chevron-right', 'class' => 'h-3.5 w-3.5'])
                    </a>
                </div>
            @endif
        </div>
    @endif
</div>
