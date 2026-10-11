<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Livewire\Concerns\WithChangeStamp;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Notifications\Concerns\OwnsInbox;
use App\Models\Notification;
use App\Platform\Notifications\Inbox;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Notifications — the port of notifications-page.tsx: "Everything addressed
 * to you, newest first."
 *
 * The one data source is the port of `useNotifications(PAGE_FETCH_LIMIT)`
 * (useNotifications.ts:15-40): the account's newest {@see self::LIMIT} rows,
 * newest first. Search and the status filter then run over that loaded list in
 * PHP, mirroring the source's client-side `useMemo` pipeline
 * (notifications-page.tsx:28-40). "Already newest first from the store, and a
 * notification feed is never read any other way — so there is no sort control
 * here, unlike the audit trail" (notifications-page.tsx:42-43).
 *
 * The addressee is ALWAYS the session's account ({@see Access::$userId}),
 * never anything the client could write — every read and both actions go
 * through `own()`, so no posted id can touch another account's rows. That is
 * the whole permission story of this screen: an inbox is not a module, every
 * signed-in account has one (notifications/page.tsx:15-21). `own()`,
 * `follow()` and `changeStamp()` come from {@see OwnsInbox}, the one copy the
 * header bell shares.
 *
 * The 60-second poll goes through {@see WithChangeStamp}. It matters more here
 * than anywhere: without it a tick would re-read the newest 500 rows, hydrate
 * 500 models and re-run the whole search/status pipeline in PHP to redraw a
 * page that has not changed. The stamp is one aggregate, and the filters are
 * untouched by it — typing in the search box is its own request and renders
 * normally.
 */
#[Layout('layouts.app')]
#[Title('Notifications')]
class Index extends Component
{
    use OwnsInbox;
    use WithChangeStamp;
    use WithTableFilters;

    /**
     * PAGE_FETCH_LIMIT (useNotifications.ts:10-15): what the notifications
     * page reads, and "the ceiling on a single 'mark all read'" — the
     * reference endpoint accepted 500 ids at a time, "so a page that loaded
     * more than it could mark would have a button that half worked".
     */
    public const int LIMIT = 500;

    /**
     * Status filter: ALL | UNREAD | READ (notifications-page.tsx:26). Plain
     * filter state, deliberately unlocked; an unexpected value simply filters
     * nothing, exactly as the source's `if (status === …)` pair.
     */
    public string $status = 'ALL';

    /**
     * The select resets to page 1 (notifications-page.tsx:81, `list.setPage(1)`);
     * the search box does not — the clamp handles a shrunken list.
     */
    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Port of `markAllRead(items)` (useNotifications.ts:55-58): marks the
     * unread among the LOADED window — the newest {@see self::LIMIT} rows —
     * not the whole table, preserving the reference's ceiling.
     */
    public function markAllRead(): void
    {
        $actor = app(Access::class);
        app(Inbox::class)->markAllRead($actor, self::LIMIT);
    }

    public function render(): View
    {
        $this->markStampRendered();

        // useNotifications(PAGE_FETCH_LIMIT) — the newest 500, newest first.
        /** @var Collection<int, Notification> $items */
        $items = app(Inbox::class)->latest(app(Access::class), self::LIMIT);

        // The filter pipeline (notifications-page.tsx:28-40).
        $needle = mb_strtolower(trim($this->search));

        $visible = $items->filter(function (Notification $n) use ($needle): bool {
            if ($this->status === 'UNREAD' && $n->read_at !== null) {
                return false;
            }

            if ($this->status === 'READ' && $n->read_at === null) {
                return false;
            }

            if ($needle === '') {
                return true;
            }

            // "The kind is still searchable by its label even though there is
            // no dropdown for it — 'payslip' finds the payslip notifications."
            foreach ([$n->title, $n->body, self::kindLabel($n->kind)] as $v) {
                if (str_contains(mb_strtolower($v ?? ''), $needle)) {
                    return true;
                }
            }

            return false;
        })->values();

        return view('livewire.notifications.index', [
            'rows' => $this->paginateClamped($visible),
            // items.length — drives the "Showing the most recent 500." line
            // and the two-flavoured empty state.
            'loadedCount' => $items->count(),
            // unread within the loaded window (useNotifications.ts:36).
            'unread' => $items->filter(static fn (Notification $n): bool => $n->read_at === null)->count(),
        ]);
    }

    /** The same inbox channel the bell listens on: this account's, only. */
    protected function realtimeChannel(): string
    {
        return Realtime::account(app(Access::class)->userId);
    }

    /**
     * Port of `notificationKindLabel` (hris.ts:141-150): TASK_DUE_SOON →
     * "Task due soon". "Derived rather than looked up … a kind added by a
     * later migration should still render as words instead of falling through
     * to shouting underscores."
     */
    public static function kindLabel(string $kind): string
    {
        $words = trim(mb_strtolower(str_replace('_', ' ', $kind)));

        return $words === ''
            ? $kind
            : mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1);
    }
}
