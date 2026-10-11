<?php

declare(strict_types=1);

namespace App\Livewire\Chat;

use App\Livewire\Concerns\WithChangeStamp;
use App\Modules\Social\Application\Queries\ChatInbox;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The unread count beside Chat in the sidebar: every unread message in the
 * conversations that aren't muted, and only the unread mentions in the ones
 * muted with mentions allowed (ChatInbox::unread).
 *
 * It checks on its own every 60 seconds while the page is open: the app's
 * standard poll, because the badge sits on every page for everyone with
 * Chat, so a new message shows up on any page within a minute without a
 * reload. Each check is one small count, and nothing redraws unless the
 * number moved. With realtime on it also redraws the moment a message
 * knocks on the account's channel. The Chat page tells it straight away
 * when reading a chat changes the count.
 */
class UnreadBadge extends Component
{
    use WithChangeStamp;

    /** Reading, muting or leaving a chat on the Chat page changed the count. */
    #[On('chat-read')]
    public function refresh(): void
    {
        // Falls through to a render, which reads the count fresh.
    }

    public function render(): View
    {
        $this->markStampRendered();

        return view('livewire.chat.unread-badge', [
            'unread' => app(ChatInbox::class)->unread(app(Access::class)),
        ]);
    }

    protected function changeStamp(): string
    {
        return (string) app(ChatInbox::class)->unread(app(Access::class));
    }

    protected function realtimeChannel(): string
    {
        return Realtime::account(app(Access::class)->userId);
    }
}
