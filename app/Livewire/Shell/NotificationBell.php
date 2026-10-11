<?php

declare(strict_types=1);

namespace App\Livewire\Shell;

use App\Livewire\Concerns\WithChangeStamp;
use App\Livewire\Notifications\Concerns\OwnsInbox;
use App\Platform\Notifications\Inbox;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Contracts\View\View;
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
 * pays. {@see WithChangeStamp} uses an event aggregate plus bounded reminder
 * authorization checks. The bell redraws when rows, read states or the current
 * actor's access to stored reminder subjects change.
 *
 * No public properties at all: the addressee is ALWAYS the session's account
 * (`Access::userId`), never anything the client could write — which is also
 * why both actions re-read the actor instead of trusting anything mounted.
 * `own()`, `follow()` and `changeStamp()` come from {@see OwnsInbox}, the one
 * copy the page shares.
 */
class NotificationBell extends Component
{
    use OwnsInbox;
    use WithChangeStamp;

    /**
     * How many the popover itself lists. The bell is a glance at what just
     * happened, not a place to read a backlog from — anything older is one
     * click away on /notifications, which lists the lot.
     */
    public const int PREVIEW_COUNT = 10;

    /** The whole inbox, unlike the page, which marks only its loaded window. */
    public function markAllRead(): void
    {
        $actor = app(Access::class);
        app(Inbox::class)->markAllRead($actor);
    }

    public function render(): View
    {
        $this->markStampRendered();

        // Both the preview and badge exclude reminders no longer authorized.
        return view('livewire.shell.notification-bell', [
            'signedIn' => $this->own() !== null,
            'items' => app(Inbox::class)->latest(app(Access::class), self::PREVIEW_COUNT),
            'unread' => app(Inbox::class)->summary(app(Access::class))['unread'],
        ]);
    }

    /**
     * The account's own inbox channel — the bell rings for nobody else's.
     */
    protected function realtimeChannel(): string
    {
        return Realtime::account(app(Access::class)->userId);
    }
}
