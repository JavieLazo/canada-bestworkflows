<?php

declare(strict_types=1);

namespace App\Livewire\Chat;

use App\Livewire\Chat\Support\ChatThread;
use App\Livewire\Concerns\WithChangeStamp;
use App\Livewire\Concerns\WithStagedAttachments;
use App\Livewire\Edtr\LiveFeed;
use App\Modules\Social\Application\Commands\AddChatMembers;
use App\Modules\Social\Application\Commands\ChangeConversationPhoto;
use App\Modules\Social\Application\Commands\EditMessage;
use App\Modules\Social\Application\Commands\LeaveConversation;
use App\Modules\Social\Application\Commands\MuteConversation;
use App\Modules\Social\Application\Commands\ReactToMessage;
use App\Modules\Social\Application\Commands\ReadConversation;
use App\Modules\Social\Application\Commands\RemoveChatMember;
use App\Modules\Social\Application\Commands\RemoveMessage;
use App\Modules\Social\Application\Commands\RenameConversation;
use App\Modules\Social\Application\Commands\SendMessage;
use App\Modules\Social\Application\Commands\SetChatAdmin;
use App\Modules\Social\Application\Commands\StartConversation;
use App\Modules\Social\Application\People;
use App\Modules\Social\Application\Queries\ChatInbox;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Contracts\Views\ConversationView;
use App\Support\Access;
use App\Support\Realtime;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Chat: one-to-one and group conversations. A list with unread counts and
 * mute, a thread with replies, reactions, photos, files and read receipts,
 * a New chat dialog that opens a direct chat or starts a group, and for a
 * group: adding people, a name and a photo, and admins who can remove
 * people.
 *
 * Reads go through App\Modules\Social\Application\Queries\ChatInbox and every
 * write through one of the Social module's commands; being in a conversation
 * is the whole permission model, and the module checks it on every call.
 *
 * Viewing is reading. The open conversation is marked read as it is drawn,
 * including when a message arrives while it is open, so "Seen" really means
 * the thread was on screen. That is also why nothing opens by itself: a phone
 * shows the list first, and a conversation opened behind it would be marked
 * read without anyone seeing it. Going back to the list closes it, for the
 * same reason.
 *
 * `?c=<id>` opens a conversation, and `&m=<id>` (from a mention's
 * notification) highlights that message, loading far enough back to reach
 * it.
 *
 * The page checks for news at the punch feeds' cadence while it's on screen
 * (`copems.poll_feed`, 15 seconds by default: with the two punch feeds, the
 * one exception to the 60-second poll), or at once when realtime is on, with
 * a 60-second check behind it. Each check is one cheap question
 * (WithChangeStamp), and the page only redraws when the answer changed.
 */
#[Layout('livewire.chat.layout')]
#[Title('Chat')]
class ChatPage extends Component
{
    use WithChangeStamp;
    use WithFileUploads;
    use WithStagedAttachments;

    /** The open conversation. An id, so it is #[Locked]: only openConversation() and mount set it. */
    #[Locked]
    public ?string $conversationId = null;

    /** Which pane a phone shows: `list` or `thread`. Plain UI state; md+ shows both. */
    public string $pane = 'list';

    /** Whether the open conversation's details panel is showing. */
    public bool $detailsOpen = false;

    /** Free text matched against conversation names and members. */
    public string $search = '';

    /** all | unread | groups. */
    public string $filter = 'all';

    /** @var array<string, string> Drafts by conversation, so switching away keeps what was typed. */
    public array $drafts = [];

    /** @var array<string, string> Edits to the viewer's own messages, keyed by message. The edit box fills its own when it opens. */
    public array $edits = [];

    /**
     * The message being answered. An id, so it is #[Locked]: only
     * startReply() sets it. SendMessage checks again that it is a live
     * message in the same conversation.
     */
    #[Locked]
    public ?string $replyToId = null;

    /** How many of the newest messages the thread shows; "Load earlier" adds a page. The query caps it. */
    public int $limit = ChatInbox::PAGE;

    /** The message a notification pointed at, highlighted. An id, so it is #[Locked]: only mount sets it. */
    #[Locked]
    public ?string $focusMessageId = null;

    /** Whether the "mute everything, or allow mentions" choice is open. */
    public bool $muteDialog = false;

    // The New chat and Add people dialogs.

    public bool $newChatOpen = false;

    public bool $addPeopleOpen = false;

    public string $peopleSearch = '';

    /**
     * @var list<int|string> The people ticked in either dialog, as the
     *                       checkboxes send them (strings from a browser).
     *                       Not #[Locked]: a wire:model draft, which the
     *                       commands re-validate against the accounts that
     *                       can use Chat.
     */
    public array $pickedIds = [];

    public string $groupName = '';

    /** A group chat's name, while it's being changed in the details panel. */
    public string $titleDraft = '';

    public function mount(): void
    {
        $requested = request()->query('c');

        if (! is_string($requested) || $requested === '') {
            return;
        }

        foreach (app(ChatInbox::class)->conversations(app(Access::class)) as $conversation) {
            if ($conversation->id === $requested) {
                $this->conversationId = $requested;
                $this->pane = 'thread';
            }
        }

        $message = request()->query('m');
        if ($this->conversationId !== null && is_string($message) && $message !== '') {
            $reach = app(ChatInbox::class)->reach(app(Access::class), $this->conversationId, $message);
            if ($reach !== null) {
                $this->focusMessageId = $message;
                $this->limit = max(ChatInbox::PAGE, $reach);
            }
        }
    }

    // Moving around.

    public function openConversation(string $id): void
    {
        $actor = app(Access::class);

        try {
            app(ReadConversation::class)->handle($actor, $id);
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $this->conversationId = $id;
        $this->pane = 'thread';
        $this->replyToId = null;
        $this->focusMessageId = null;
        $this->muteDialog = false;
        $this->clearStaged('chat');
        $this->limit = ChatInbox::PAGE;

        $this->dispatch('chat-opened');
        $this->dispatch('chat-read');
    }

    public function backToList(): void
    {
        $this->pane = 'list';
        $this->detailsOpen = false;
        $this->conversationId = null;
        $this->replyToId = null;
        $this->focusMessageId = null;
    }

    public function toggleDetails(): void
    {
        $this->detailsOpen = ! $this->detailsOpen;
    }

    public function showEarlier(): void
    {
        $this->limit = min($this->limit + ChatInbox::PAGE, 500);
    }

    public function updatedFilter(string $value): void
    {
        $this->filter = in_array($value, ['unread', 'groups'], true) ? $value : 'all';
    }

    /**
     * What the @ list offers in the open chat. Read by the list itself
     * (mention-picker.js), so nothing redraws.
     *
     * @return array{people: list<array{id: int, name: string, sub: string|null, photo: string|null}>, groups: list<array{id: string, name: string}>, everyone: bool}
     */
    #[Renderless]
    public function mentionOptions(?string $conversationId = null): array
    {
        return app(ChatInbox::class)->mentionOptions(app(Access::class), (string) ($conversationId ?? $this->conversationId));
    }

    // Writing.

    public function send(): void
    {
        $actor = app(Access::class);
        $id = $this->conversationId;

        if ($id === null) {
            return;
        }

        try {
            app(SendMessage::class)->handle($actor, $id, $this->drafts[$id] ?? '', $this->replyToId, $this->stagedFor('chat'));
        } catch (QueryException) {
            Flux::toast(text: 'The message could not be sent. Please try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        // Emptied, not unset: a box bound to a key that disappears keeps
        // showing what was typed.
        $this->drafts[$id] = '';
        $this->replyToId = null;
        $this->clearStaged('chat');

        $this->dispatch('chat-sent');
    }

    public function saveEdit(string $messageId): void
    {
        $actor = app(Access::class);
        $body = is_string($this->edits[$messageId] ?? null) ? $this->edits[$messageId] : '';

        if ($this->attempt(static fn () => app(EditMessage::class)->handle($actor, $messageId, $body))) {
            unset($this->edits[$messageId]);
            $this->dispatch('chat-edited', id: $messageId);
        }
    }

    public function startReply(string $messageId): void
    {
        $this->replyToId = $messageId;
        $this->dispatch('chat-compose');
    }

    public function cancelReply(): void
    {
        $this->replyToId = null;
    }

    public function react(string $messageId, string $type): void
    {
        $actor = app(Access::class);
        $this->attempt(static fn () => app(ReactToMessage::class)->handle($actor, $messageId, $type));
    }

    public function removeMessage(string $messageId): void
    {
        $actor = app(Access::class);

        if ($this->attempt(static fn () => app(RemoveMessage::class)->handle($actor, $messageId)) && $this->replyToId === $messageId) {
            $this->replyToId = null;
        }
    }

    // The conversation itself.

    /**
     * Mutes the open chat: `ALL` for everything, or `MENTIONS` to still hear
     * when someone mentions you. A direct chat has no mentions, so it's
     * always everything there.
     */
    public function mute(string $mode = 'ALL'): void
    {
        $actor = app(Access::class);
        $id = $this->conversationId;
        $mentions = $mode === 'MENTIONS';

        if ($id !== null && $this->attempt(static fn () => app(MuteConversation::class)->handle($actor, $id, true, $mentions))) {
            $this->muteDialog = false;
            $mentions = $this->current()->allowMentions ?? false;
            Flux::toast(text: $mentions ? 'Muted. You will still hear when someone mentions you.' : 'Muted. This chat will not notify you.', variant: 'success');
            $this->dispatch('chat-read');
        }
    }

    public function unmute(): void
    {
        $actor = app(Access::class);
        $id = $this->conversationId;

        if ($id !== null && $this->attempt(static fn () => app(MuteConversation::class)->handle($actor, $id, false))) {
            Flux::toast(text: 'Notifications are back on.', variant: 'success');
            $this->dispatch('chat-read');
        }
    }

    public function leaveConversation(): void
    {
        $actor = app(Access::class);
        $id = $this->conversationId;
        $title = $this->current()->title ?? 'the group';

        if ($id !== null && $this->attempt(static fn () => app(LeaveConversation::class)->handle($actor, $id))) {
            $this->conversationId = null;
            $this->detailsOpen = false;
            $this->pane = 'list';
            Flux::toast(text: 'You left '.$title.'.', variant: 'success');
            $this->dispatch('chat-read');
        }
    }

    public function renameConversation(): void
    {
        $actor = app(Access::class);
        $id = $this->conversationId;
        $title = $this->titleDraft;

        if ($id !== null && $this->attempt(static fn () => app(RenameConversation::class)->handle($actor, $id, $title))) {
            $this->titleDraft = '';
            $this->dispatch('chat-renamed');
        }
    }

    /** Uses the photo waiting in the `avatar` slot as the group chat's photo. */
    public function saveConversationPhoto(): void
    {
        $actor = app(Access::class);
        $id = $this->conversationId;
        $photo = $this->stagedFor('avatar')[0] ?? null;

        if ($id !== null && $photo !== null && $this->attempt(static fn () => app(ChangeConversationPhoto::class)->handle($actor, $id, $photo))) {
            $this->clearStaged('avatar');
            Flux::toast(text: 'Group photo changed.', variant: 'success');
        }
    }

    public function removeConversationPhoto(): void
    {
        $actor = app(Access::class);
        $id = $this->conversationId;

        if ($id !== null && $this->attempt(static fn () => app(ChangeConversationPhoto::class)->handle($actor, $id, null))) {
            $this->clearStaged('avatar');
            Flux::toast(text: 'Group photo removed.', variant: 'success');
        }
    }

    public function removeMember(int $userId): void
    {
        $actor = app(Access::class);
        $id = $this->conversationId;

        if ($id !== null && $this->attempt(static fn () => app(RemoveChatMember::class)->handle($actor, $id, $userId))) {
            Flux::toast(text: 'Removed from the chat.', variant: 'success');
        }
    }

    public function setAdmin(int $userId, bool $admin): void
    {
        $actor = app(Access::class);
        $id = $this->conversationId;

        if ($id !== null && $this->attempt(static fn () => app(SetChatAdmin::class)->handle($actor, $id, $userId, $admin))) {
            Flux::toast(text: $admin ? 'Made an admin.' : 'No longer an admin.', variant: 'success');
        }
    }

    public function openNewChat(): void
    {
        $this->reset('peopleSearch', 'pickedIds', 'groupName');
        $this->newChatOpen = true;
    }

    public function openAddPeople(): void
    {
        $this->reset('peopleSearch', 'pickedIds');
        $this->addPeopleOpen = true;
    }

    /** One person opens (or reopens) your chat with them; two or more start a group. */
    public function startChat(): void
    {
        $actor = app(Access::class);
        $picked = array_map('intval', $this->pickedIds);
        $title = $this->groupName;
        $id = null;

        if (! $this->attempt(static function () use ($actor, $picked, $title, &$id): void {
            $id = app(StartConversation::class)->handle($actor, $picked, $title);
        }) || ! is_string($id)) {
            return;
        }

        $this->newChatOpen = false;
        $this->openConversation($id);
    }

    public function addPeople(): void
    {
        $actor = app(Access::class);
        $id = $this->conversationId;
        $picked = array_map('intval', $this->pickedIds);
        $added = 0;

        if ($id === null || ! $this->attempt(static function () use ($actor, $id, $picked, &$added): void {
            $added = app(AddChatMembers::class)->handle($actor, $id, $picked);
        })) {
            return;
        }

        $this->addPeopleOpen = false;
        $this->reset('peopleSearch', 'pickedIds');
        Flux::toast(text: $added === 1 ? 'Added to the chat.' : $added.' people added to the chat.', variant: 'success');
    }

    public function render(): View
    {
        $actor = app(Access::class);
        $inbox = app(ChatInbox::class);
        $now = CarbonImmutable::now();

        // Viewing is reading (see the class docblock). A conversation the
        // viewer has since left, or that no longer exists, simply closes.
        if ($this->conversationId !== null) {
            try {
                if (app(ReadConversation::class)->handle($actor, $this->conversationId)) {
                    // The read moved this account's own change stamp; drop
                    // the value computed before it so the tick does not
                    // redraw once more for nothing.
                    $this->freshChangeStamp = null;
                    // And the sidebar's count, which lives outside this page.
                    $this->dispatch('chat-read');
                }
            } catch (RuntimeException) {
                $this->conversationId = null;
                $this->pane = 'list';
            }
        }

        $everything = $inbox->conversations($actor);
        $list = $this->filter === 'all' && trim($this->search) === '' ? $everything : $inbox->conversations($actor, $this->filter, $this->search);
        $thread = $this->conversationId !== null ? $inbox->thread($actor, $this->conversationId, $this->limit) : null;

        $replyTo = null;
        if ($thread !== null && $this->replyToId !== null) {
            foreach ($thread->messages as $message) {
                if ($message->id === $this->replyToId && ! $message->removed && ! $message->system) {
                    $replyTo = $message;
                }
            }
        }

        $unreadTotal = 0;
        $unreadChats = 0;
        foreach ($everything as $conversation) {
            if ($conversation->unread > 0) {
                $unreadChats++;
                $unreadTotal += $conversation->badge();
            }
        }

        $inChat = $thread !== null ? [...array_map(static fn ($p): int => $p->id, $thread->conversation->others)] : [];

        $this->markStampRendered();

        return view('livewire.chat.chat-page', [
            'now' => $now,
            'viewer' => app(People::class)->card($actor, SocialRules::chat($actor)),
            'list' => $list,
            'thread' => $thread,
            'active' => $thread?->conversation,
            'rows' => $thread !== null ? ChatThread::rows($thread->messages, $now) : [],
            'names' => $thread->names ?? [],
            'people' => $thread->people ?? [],
            'replyTo' => $replyTo,
            'directory' => match (true) {
                $this->newChatOpen => $inbox->people($actor, $this->peopleSearch),
                $this->addPeopleOpen => $inbox->people($actor, $this->peopleSearch, $inChat),
                default => [],
            },
            'unreadTotal' => $unreadTotal,
            'unreadChats' => $unreadChats,
            'pollSeconds' => self::pollSeconds(),
            'maxAttachments' => SocialRules::MAX_ATTACHMENTS,
            'maxBytes' => $this->stagedFileLimit(),
            'maxBody' => SocialRules::MAX_MESSAGE,
        ]);
    }

    /**
     * The punch feeds' own cadence, asked of LiveFeed as Portal\MyPunches does:
     * the feed cadence while the poll is the only way news arrives, and the
     * 60-second backstop once realtime carries it. One setting moves all three.
     */
    public static function pollSeconds(): int
    {
        return LiveFeed::pollSeconds();
    }

    protected function changeStamp(): string
    {
        return app(ChatInbox::class)->stamp(app(Access::class));
    }

    /** The account's own channel: every conversation it is in knocks there. */
    protected function realtimeChannel(): string
    {
        return Realtime::account(app(Access::class)->userId);
    }

    // Helpers.

    /**
     * Runs one command and turns its refusal into a toast. Returns whether it
     * went through.
     */
    private function attempt(callable $operation): bool
    {
        try {
            $operation();
        } catch (QueryException) {
            Flux::toast(text: 'That could not be saved. Please try again.', variant: 'danger');

            return false;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return false;
        }

        return true;
    }

    private function current(): ?ConversationView
    {
        foreach (app(ChatInbox::class)->conversations(app(Access::class)) as $conversation) {
            if ($conversation->id === $this->conversationId) {
                return $conversation;
            }
        }

        return null;
    }
}
