<?php

declare(strict_types=1);

namespace App\Livewire\Shell;

use App\Livewire\Concerns\WithChangeStamp;
use App\Models\Notification;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * The header bell — the port of NotificationBell.tsx + useNotifications.ts.
 *
 * Unread count on the badge, the ten newest in the feed, and clicking an entry
 * marks it read and follows it to the record it is about. The reference
 * refetched on a 60 s interval ("someone else's action writes rows this
 * account has no event for; without a poll the bell only moved on focus") —
 * here that is the `wire:poll.60s` on the component root.
 *
 * That poll rides in the app shell, so it is the one tick EVERY signed-in page
 * pays. It goes through {@see WithChangeStamp}: a tick reads one aggregate over
 * this account's rows and redraws the bell only when a notification arrives or
 * a read state moves — which, for an account reading a long page, is almost
 * never.
 *
 * No public properties at all: the addressee is ALWAYS the session's account
 * (`Access::userId`), never anything the client could write — which is also
 * why both actions re-read the actor instead of trusting anything mounted.
 */
class NotificationBell extends Component
{
    use WithChangeStamp;

    /**
     * How many the popover itself lists. The bell is a glance at what just
     * happened, not a place to read a backlog from — anything older is one
     * click away on /notifications, which lists the lot.
     */
    public const int PREVIEW_COUNT = 10;

    /** Mark one notification read, then follow it to the record it is about. */
    public function follow(string $id): void
    {
        $notification = $this->own()?->whereKey($id)->first();

        if ($notification === null) {
            return;
        }

        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
        }

        if ($notification->href !== null && $notification->href !== '') {
            $this->redirect($notification->href);
        }
    }

    public function markAllRead(): void
    {
        $this->own()?->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function render(): View
    {
        $this->markStampRendered();

        // Fresh builders on purpose — get() would otherwise leave its ORDER BY
        // and LIMIT on the builder the unread count runs on.
        return view('livewire.shell.notification-bell', [
            'signedIn' => $this->own() !== null,
            'items' => $this->own()?->orderByDesc('created_at')->limit(self::PREVIEW_COUNT)->get() ?? collect(),
            'unread' => $this->own()?->whereNull('read_at')->count() ?? 0,
        ]);
    }

    /**
     * The account's own inbox channel — the bell rings for nobody else's.
     */
    protected function realtimeChannel(): string
    {
        return Realtime::account(app(Access::class)->userId);
    }

    /**
     * How many this account has, how many are read, and when the newest
     * arrived — one aggregate answering everything the bell draws: the badge
     * (unread), the ten-row preview (a new row is always the newest, so
     * MAX(created_at) moves) and a read state marked from another tab
     * (COUNT(read_at) moves while the total does not).
     *
     * `notif_user_created_idx` covers the range and the timestamp; the read
     * count reaches the rows themselves, which is bounded by one account's
     * notifications and is still an order cheaper than hydrating ten models
     * and re-rendering the popover.
     */
    protected function changeStamp(): string
    {
        $own = $this->own();

        if ($own === null) {
            return 'signed-out';
        }

        $stamp = $own
            ->toBase()
            ->selectRaw('COUNT(*) AS total, COUNT(read_at) AS seen, MAX(created_at) AS newest')
            ->first();

        return ((int) ($stamp->total ?? 0))
            .':'.((int) ($stamp->seen ?? 0))
            .':'.((string) ($stamp->newest ?? ''));
    }

    /**
     * The session account's own notifications — null when nobody is signed in.
     * The ONLY way this component reaches the table, so no action can touch
     * another account's rows no matter what id the client posts.
     *
     * @return Builder<Notification>|null
     */
    private function own(): ?Builder
    {
        $access = app(Access::class);

        if (! $access->isSignedIn()) {
            return null;
        }

        return Notification::query()->where('user_id', (int) $access->userId);
    }
}
