{{--
    Notifications — the port of notifications-page.tsx: a header with a
    conditional "Mark all read (n)" button, one card holding a search box, the
    status filter, the feed list and the shared pagination footer.

    List row transcribed from the source (notifications-page.tsx:102-145):
    unread dot · title (font-semibold unread / muted read) · kind pill
    (`pill pill-muted`) · optional body · timestamp line with an "Unread" tag ·
    a chevron when the row goes somewhere. Clicking a row marks it read and
    follows its href.

    Empty states (notifications-page.tsx:94-99): "Nothing to catch up on."
    when the inbox is empty, "No notification matches those filters." when the
    filters hid everything. The source's third branch — "Loading your
    notifications…" — was the client fetch's pending state; a server render is
    never pending, so it has no equivalent here.

    wire:poll.60s="pollForChanges" (WithChangeStamp) keeps the reference's
    interval but reads a stamp per tick instead of re-loading 500 rows;
    60s is the exact interval the reference refetched on
    (useNotifications.ts:29-33: "Someone else's action … writes rows this
    account has no event for; without a poll the bell only moved on focus") —
    the page and the bell share that hook and its 60 s refetchInterval.
--}}
@php
    use App\Livewire\Notifications\Index as NotificationsIndex;
    use App\Support\ManilaClock;

    // fmtDateTime (hris.ts:199-207) — en-PH "Aug 31, 2026, 2:05 PM";
    // datetimes are stored UTC and display in Manila.
    $fmtDateTime = static fn ($at): string => $at->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A');
@endphp

<div class="space-y-6" wire:poll.60s="pollForChanges">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Notifications</h1>
            <p class="text-sm text-muted-foreground">
                Everything addressed to you, newest first.
                @if ($loadedCount >= NotificationsIndex::LIMIT)
                    Showing the most recent {{ NotificationsIndex::LIMIT }}.
                @endif
            </p>
        </div>

        @if ($unread > 0)
            <flux:button variant="outline" size="sm" wire:click="markAllRead">
                Mark all read
                <span class="num ml-1 text-muted-foreground">({{ $unread }})</span>
            </flux:button>
        @endif
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input
                aria-label="Search notifications"
                placeholder="Search title, message or type"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live="search"
            />

            <x-kit.filter-select label="Filter by status" class="w-[11rem]" wire:model.live="status">
                <flux:select.option value="ALL">All notifications</flux:select.option>
                <flux:select.option value="UNREAD">Unread only</flux:select.option>
                <flux:select.option value="READ">Read only</flux:select.option>
            </x-kit.filter-select>
        </header>

        @if ($rows->total() === 0)
            <p class="px-5 py-12 text-center text-sm text-muted-foreground">
                {{ $loadedCount === 0 ? 'Nothing to catch up on.' : 'No notification matches those filters.' }}
            </p>
        @else
            <ul class="divide-y divide-border">
                @foreach ($rows as $n)
                    <li wire:key="notif-{{ $n->id }}">
                        <button
                            type="button"
                            wire:click="follow('{{ $n->id }}')"
                            class="flex w-full cursor-pointer items-start gap-3 px-5 py-3.5 text-left transition-colors hover:bg-accent"
                        >
                            <span
                                aria-hidden="true"
                                class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full {{ $n->read_at ? 'bg-transparent' : 'bg-primary' }}"
                            ></span>

                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm {{ $n->read_at ? 'text-muted-foreground' : 'font-semibold' }}">
                                        {{ $n->title }}
                                    </span>
                                    <span class="pill pill-muted">{{ NotificationsIndex::kindLabel($n->kind) }}</span>
                                </span>
                                @if ($n->body)
                                    <span class="mt-1 block text-xs text-muted-foreground">{{ $n->body }}</span>
                                @endif
                                <span class="num mt-1 block text-xs text-muted-foreground">
                                    {{ $fmtDateTime($n->created_at) }}
                                    @unless ($n->read_at)
                                        <span class="ml-2 text-primary">Unread</span>
                                    @endunless
                                </span>
                            </span>

                            @if ($n->href)
                                @include('layouts.app.module-icon', ['icon' => 'chevron-right', 'class' => 'mt-1 h-4 w-4 shrink-0 text-muted-foreground'])
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-kit.pagination :paginator="$rows" noun="notifications" />
    </section>
</div>
