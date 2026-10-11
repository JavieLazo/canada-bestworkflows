<?php

declare(strict_types=1);

namespace App\Livewire\Notifications\Concerns;

use App\Models\Notification;
use App\Platform\Notifications\Inbox;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * Shared inbox actions delegate both account ownership and current reminder
 * authorization to the platform inbox, alongside the read-and-follow and change
 * stamp the 60-second poll reads. Shared by the header bell
 * (`Shell\NotificationBell`) and the page (`Notifications\Index`), so the two
 * run one copy of these actions instead of two that could drift apart.
 *
 * The addressee is ALWAYS the session's account (`Access::userId`), re-read
 * from the container on every call and never mounted, so no posted id can
 * touch another account's rows. `markAllRead()` stays on each component on
 * purpose: the bell marks the whole inbox, the page only its loaded window.
 *
 * @phpstan-require-extends Component
 */
trait OwnsInbox
{
    /**
     * Mark one notification read, then follow it to the record it is about —
     * the row click (notifications-page.tsx:107-110; NotificationBell.tsx):
     * `markRead(n.id)` then `router.push(n.href)`. A row with no href still
     * marks read and stays.
     */
    public function follow(string $id): void
    {
        $actor = app(Access::class);
        $href = app(Inbox::class)->markRead($actor, $id);
        if ($href !== null && $href !== '') {
            $this->redirect($href);
        }
    }

    /**
     * Ordinary events use an account aggregate. Reminder references stream in
     * bounded batches through their module owner's current authorization rules.
     * The stamp therefore changes on permission or assignment changes as well
     * as new rows and read states, without loading the whole reminder history.
     */
    protected function changeStamp(): string
    {
        return app(Inbox::class)->summary(app(Access::class))['stamp'];
    }

    /**
     * The session account's own notifications — null when nobody is signed
     * in. This raw scope is used only to check sign-in; displaying or changing
     * rows must go through Inbox so stored reminder details are re-authorized.
     *
     * @return Builder<Notification>|null
     */
    private function own(): ?Builder
    {
        return app(Inbox::class)->own(app(Access::class));
    }
}
