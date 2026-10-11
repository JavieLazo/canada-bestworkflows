<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatReaction;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\Social\Application\Attachments;
use App\Modules\Social\Application\People;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Contracts\Views\ConversationView;
use App\Modules\Social\Contracts\Views\FileView;
use App\Modules\Social\Contracts\Views\MessageView;
use App\Modules\Social\Contracts\Views\PersonView;
use App\Modules\Social\Contracts\Views\QuoteView;
use App\Modules\Social\Contracts\Views\ThreadView;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\SocialFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everything the Chat screen reads, always from the viewer's side and only
 * ever for conversations they are in (ChatScope's one rule).
 *
 * Unread is counted, never stored: other people's live messages newer than
 * the viewer's `last_read_at`. System lines and removed messages don't count.
 * Unread mentions are the ones among them that hold the viewer's mention
 * token or @everyone's, for chats muted with mentions allowed.
 * "Seen by" is the same comparison from the other end, so it lists whoever
 * has read at least as far as the viewer's own last message.
 *
 * The newest message of a chat is always looked up, never taken from the
 * chat's `last_message_at`. That column is a copy kept for sorting, and a
 * copy rewound under a real message (a demo-data refresh can do that)
 * would leave the chat unread for good and its "Seen" stuck.
 */
final class ChatInbox
{
    /** Messages per screenful; "Load earlier" adds another. */
    public const int PAGE = 50;

    /** How many shared photos and files the details panel lists. */
    public const int MEDIA = 60;

    /**
     * The viewer's conversations, newest activity first.
     *
     * @param  string  $filter  `all`, `unread` or `groups`
     * @return list<ConversationView>
     */
    public function conversations(Access $actor, string $filter = 'all', string $search = ''): array
    {
        $me = SocialRules::chat($actor);
        $mine = ChatParticipant::query()->where('user_id', $me)->get()->keyBy('conversation_id');

        if ($mine->isEmpty()) {
            return [];
        }

        $ids = array_values(array_map('strval', $mine->keys()->all()));
        $conversations = ChatConversation::query()->whereIn('id', $ids)->get();
        $others = ChatParticipant::query()->whereIn('conversation_id', $ids)->where('user_id', '!=', $me)->get(['conversation_id', 'user_id'])->groupBy('conversation_id');
        $last = $this->lastMessages($ids);
        $unread = $this->unreadCounts($me, $ids);

        $mentioned = [];
        foreach ($last as $message) {
            array_push($mentioned, ...SocialFormat::mentions((string) $message->body));
        }
        $cards = app(People::class)->cards($actor, [
            ...array_map('intval', ChatParticipant::query()->whereIn('conversation_id', $ids)->where('user_id', '!=', $me)->pluck('user_id')->all()),
            ...array_values(array_filter($last->pluck('sender_id')->all(), 'is_int')),
            ...$mentioned,
        ]);
        $names = array_map(static fn (PersonView $card): string => $card->name, $cards);

        $views = [];
        foreach ($conversations as $conversation) {
            $people = array_values(array_map(
                static fn (ChatParticipant $p): PersonView => $cards[$p->user_id] ?? PersonView::missing($p->user_id),
                ($others->get($conversation->id) ?? collect())->all(),
            ));
            $message = $last->get($conversation->id);
            $participant = $mine[$conversation->id];
            $views[] = new ConversationView(
                $conversation->id,
                $conversation->kind,
                $this->title($conversation, $people),
                $people,
                (bool) $participant->is_muted,
                $unread[$conversation->id]['total'] ?? 0,
                $this->preview($conversation, $message, $me, $names),
                $message !== null ? $message->created_at : $conversation->last_message_at,
                $conversation->photo_path !== null ? route('chat.conversations.photo', ['conversation' => $conversation->id, 'v' => substr(md5($conversation->photo_path), 0, 8)]) : null,
                $participant->role === Vocabulary::ADMIN,
                (bool) $participant->is_muted && (bool) $participant->allow_mentions,
                $unread[$conversation->id]['mentions'] ?? 0,
            );
        }

        $needle = mb_strtolower(trim($search));
        $views = array_values(array_filter($views, static function (ConversationView $view) use ($filter, $needle): bool {
            if ($filter === 'unread' && $view->unread === 0) {
                return false;
            }
            if ($filter === 'groups' && ! $view->isGroup()) {
                return false;
            }
            if ($needle === '') {
                return true;
            }
            $haystack = $view->title.' '.implode(' ', array_map(static fn (PersonView $p): string => $p->name, $view->others));

            return str_contains(mb_strtolower($haystack), $needle);
        }));

        usort($views, static fn (ConversationView $a, ConversationView $b): int => ($b->lastAt?->getTimestampMs() ?? 0) <=> ($a->lastAt?->getTimestampMs() ?? 0));

        return $views;
    }

    /**
     * How many of a conversation's newest messages it takes to reach this
     * one, so a notification can open the thread far enough back to show it.
     * Null when the viewer isn't in the conversation, or the message isn't.
     */
    public function reach(Access $actor, string $conversationId, string $messageId): ?int
    {
        $me = SocialRules::chat($actor);

        if (! ChatParticipant::query()->where('conversation_id', $conversationId)->where('user_id', $me)->exists()) {
            return null;
        }

        // The stored value, milliseconds and all: messages a moment apart must not tie.
        $at = ChatMessage::query()->where('conversation_id', $conversationId)->whereKey($messageId)->toBase()->value('created_at');

        return $at === null ? null : ChatMessage::query()->where('conversation_id', $conversationId)->where('created_at', '>=', $at)->count();
    }

    /** One open conversation: its newest messages, oldest first. Null when the viewer is not in it. */
    public function thread(Access $actor, string $conversationId, int $limit = self::PAGE): ?ThreadView
    {
        $me = SocialRules::chat($actor);

        if (! ChatParticipant::query()->where('conversation_id', $conversationId)->where('user_id', $me)->exists()) {
            return null;
        }

        $conversation = null;
        foreach ($this->conversations($actor) as $view) {
            if ($view->id === $conversationId) {
                $conversation = $view;
            }
        }
        if ($conversation === null) {
            return null;
        }

        $limit = max(1, min($limit, 500));
        $rows = ChatMessage::query()
            ->where('conversation_id', $conversationId)
            ->with(['attachments:id,message_id,kind,name,mime,size,position', 'reactions:id,message_id,user_id,type'])
            ->orderByDesc('created_at')
            ->limit($limit + 1)
            ->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit)->reverse()->values();

        $quoted = ChatMessage::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('id', $rows->pluck('reply_to_id')->filter()->unique()->values()->all())
            ->withCount(['attachments as photos_count' => static fn (Builder $q) => $q->where('kind', Vocabulary::PHOTO)])
            ->withCount(['attachments as files_count' => static fn (Builder $q) => $q->where('kind', Vocabulary::FILE)])
            ->get()
            ->keyBy('id');
        $participants = ChatParticipant::query()->where('conversation_id', $conversationId)->get();

        $ids = [...$participants->pluck('user_id')->all()];
        foreach ([...$rows->all(), ...$quoted->all()] as $message) {
            if ($message->sender_id !== null) {
                $ids[] = $message->sender_id;
            }
            array_push($ids, ...SocialFormat::mentions((string) $message->body));
        }
        foreach ($rows as $message) {
            array_push($ids, ...$message->reactions->pluck('user_id')->all());
        }
        $cards = app(People::class)->cards($actor, array_values(array_map('intval', $ids)));
        $names = array_map(static fn (PersonView $card): string => $card->name, $cards);

        $messages = array_values($rows->map(function (ChatMessage $message) use ($me, $cards, $names, $quoted): MessageView {
            $reactions = [];
            foreach ($message->reactions as $reaction) {
                $reactions[$reaction->type][] = $reaction->user_id;
            }
            $quote = null;
            if ($message->reply_to_id !== null && $quoted->has($message->reply_to_id)) {
                $original = $quoted->get($message->reply_to_id);
                // "You replied to yourself", "Jose replied to you",
                // "You replied to Jose Rivera".
                $quotedName = match (true) {
                    $original->sender_id === $me => $message->sender_id === $me ? 'yourself' : 'you',
                    default => $cards[$original->sender_id ?? 0]->name ?? 'Former member',
                };
                $quote = new QuoteView(
                    $quotedName,
                    $original->removed_at !== null ? 'Message removed' : SocialFormat::plain((string) $original->body, $names),
                    $original->removed_at === null && (int) $original->getAttribute('photos_count') > 0,
                    $original->removed_at !== null,
                );
            }

            $photos = [];
            $files = [];
            foreach ($message->attachments as $attachment) {
                if ($attachment->kind === Vocabulary::PHOTO) {
                    $photos[] = $attachment->id;
                } else {
                    $files[] = self::fileView($attachment);
                }
            }

            return new MessageView(
                $message->id,
                $message->sender_id !== null ? ($cards[$message->sender_id] ?? PersonView::missing($message->sender_id)) : null,
                $message->kind === Vocabulary::SYSTEM,
                (string) $message->body,
                $photos,
                $quote,
                $reactions,
                $message->removed_at !== null,
                $message->sender_id === $me,
                $message->created_at,
                $files,
            );
        })->all());

        $media = $this->media($conversationId);

        return new ThreadView(
            $conversation,
            $messages,
            $more,
            $this->seenBy($rows, $participants, $me, $cards),
            $names,
            $media['photos'],
            $this->withoutChat($actor, $participants->pluck('user_id')->all(), $me),
            $cards,
            $media['files'],
            array_values(array_map('intval', $participants->where('role', Vocabulary::ADMIN)->pluck('user_id')->all())),
        );
    }

    /**
     * What the @ list offers in a chat: everyone else in it, and @everyone.
     * Only group chats, where there's someone other than the person you're
     * already talking to.
     *
     * @return array{people: list<array{id: int, name: string, sub: string|null, photo: string|null}>, groups: list<array{id: string, name: string}>, everyone: bool}
     */
    public function mentionOptions(Access $actor, string $conversationId): array
    {
        $me = SocialRules::chat($actor);
        $conversation = ChatConversation::query()->find($conversationId);

        if ($conversation === null || $conversation->kind !== Vocabulary::GROUP || ! ChatParticipant::query()->where('conversation_id', $conversationId)->where('user_id', $me)->exists()) {
            return ['people' => [], 'groups' => [], 'everyone' => false];
        }

        $ids = array_values(array_filter(
            array_map('intval', ChatParticipant::query()->where('conversation_id', $conversationId)->pluck('user_id')->all()),
            static fn (int $id): bool => $id !== $me,
        ));
        $list = [];
        foreach (app(People::class)->cards($actor, $ids) as $card) {
            $list[] = ['id' => $card->id, 'name' => $card->name, 'sub' => $card->position, 'photo' => $card->photo];
        }
        usort($list, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return ['people' => $list, 'groups' => [], 'everyone' => true];
    }

    /**
     * Who in this conversation can't open Chat any more, because their role
     * lost the module or their account was switched off. Their messages stay
     * where they are. The details panel just says so, so nobody sits waiting
     * for a reply that can't come.
     *
     * @param  array<array-key, mixed>  $participantIds
     * @return list<int>
     */
    private function withoutChat(Access $actor, array $participantIds, int $me): array
    {
        $ids = array_values(array_filter(array_map('intval', $participantIds), static fn (int $id): bool => $id !== $me));

        if ($ids === []) {
            return [];
        }

        return array_values(array_diff($ids, app(ModuleAccounts::class)->filter($actor, ModuleCode::CHAT, $ids)));
    }

    /**
     * Everyone the viewer could start a chat with, or add to one: active
     * accounts that can use Chat, the viewer and anyone in `$except` left
     * out.
     *
     * @param  list<int>  $except
     * @return list<PersonView>
     */
    public function people(Access $actor, string $search = '', array $except = []): array
    {
        $me = SocialRules::chat($actor);
        $needle = mb_strtolower(trim($search));

        return array_values(array_filter(
            app(People::class)->holders($actor, ModuleCode::CHAT),
            static fn (PersonView $person): bool => $person->id !== $me && ! in_array($person->id, $except, true)
                && ($needle === '' || str_contains(mb_strtolower($person->name.' '.$person->position), $needle)),
        ));
    }

    /**
     * The sidebar badge: every unread message in the viewer's conversations
     * that aren't muted, plus the unread mentions in the ones muted with
     * mentions allowed. ConversationView::badge() is the same sum, per chat.
     */
    public function unread(Access $actor): int
    {
        if ($actor->userId === null || ! $actor->hasModule(ModuleCode::CHAT)) {
            return 0;
        }

        $me = (int) $actor->userId;
        $heard = ChatParticipant::query()
            ->where('user_id', $me)
            ->where(static fn (Builder $q) => $q->where('is_muted', false)->orWhere('allow_mentions', true))
            ->pluck('is_muted', 'conversation_id');
        $counts = $this->unreadCounts($me, array_values(array_map('strval', $heard->keys()->all())));

        $total = 0;
        foreach ($heard as $conversationId => $muted) {
            $total += $counts[(string) $conversationId][(bool) $muted ? 'mentions' : 'total'] ?? 0;
        }

        return $total;
    }

    /**
     * What a tick compares, across the viewer's own conversations only: which
     * ones they are in, and the counts and newest instants of messages,
     * reactions and read positions inside them.
     */
    public function stamp(Access $actor): string
    {
        if ($actor->userId === null || ! $actor->hasModule(ModuleCode::CHAT)) {
            return 'none';
        }

        $me = (int) $actor->userId;
        $ids = array_values(array_map('strval', ChatParticipant::query()->where('user_id', $me)->orderBy('conversation_id')->pluck('conversation_id')->all()));
        $messages = static fn () => ChatMessage::query()->whereIn('conversation_id', $ids);

        $row = DB::query()
            ->selectSub($messages()->selectRaw('COUNT(*)'), 'messages')
            ->selectSub($messages()->selectRaw('MAX(updated_at)'), 'messages_at')
            ->selectSub(ChatReaction::query()->whereIn('message_id', $messages()->select('id'))->selectRaw('COUNT(*)'), 'reactions')
            ->selectSub(ChatReaction::query()->whereIn('message_id', $messages()->select('id'))->selectRaw('MAX(updated_at)'), 'reactions_at')
            ->selectSub(ChatParticipant::query()->whereIn('conversation_id', $ids)->selectRaw('COUNT(*)'), 'participants')
            ->selectSub(ChatParticipant::query()->whereIn('conversation_id', $ids)->selectRaw('MAX(updated_at)'), 'participants_at')
            ->selectSub(ChatConversation::query()->whereIn('id', $ids)->selectRaw('MAX(updated_at)'), 'conversations_at')
            ->first();

        return hash('xxh128', $me.'|'.implode(',', $ids).'|'.json_encode($row));
    }

    /** One message photo, for someone in the conversation. */
    public function photo(Access $actor, string $photoId, bool $download = false): StreamedResponse
    {
        $row = $this->attachment($actor, $photoId, Vocabulary::PHOTO);

        return Attachments::stream($row->path, $row->mime, $download);
    }

    /** One message file, downloaded under its own name, for someone in the conversation. */
    public function file(Access $actor, string $fileId): StreamedResponse
    {
        $row = $this->attachment($actor, $fileId, Vocabulary::FILE);

        return Attachments::stream($row->path, $row->mime, true, $row->name ?? 'file');
    }

    /** A group chat's photo, for someone in it. */
    public function conversationPhoto(Access $actor, string $conversationId): StreamedResponse
    {
        abort_unless($actor->userId !== null && $actor->hasModule(ModuleCode::CHAT), 403);

        $path = ChatConversation::query()->whereKey($conversationId)->value('photo_path');
        abort_if(
            ! is_string($path) || ! ChatParticipant::query()->where('conversation_id', $conversationId)->where('user_id', (int) $actor->userId)->exists(),
            404,
            'That photo was not found.',
        );

        return Attachments::stream($path, 'image/jpeg');
    }

    private function attachment(Access $actor, string $id, string $kind): ChatAttachment
    {
        abort_unless($actor->userId !== null && $actor->hasModule(ModuleCode::CHAT), 403);

        $row = ChatAttachment::query()->whereKey($id)->where('kind', $kind)->first();
        $message = $row !== null ? ChatMessage::query()->find($row->message_id) : null;
        abort_if(
            $row === null || $message === null || $message->removed_at !== null
                || ! ChatParticipant::query()->where('conversation_id', $message->conversation_id)->where('user_id', (int) $actor->userId)->exists(),
            404,
            'That file was not found.',
        );

        return $row;
    }

    private static function fileView(ChatAttachment $row): FileView
    {
        return new FileView($row->id, $row->name ?? 'file', $row->size, Attachments::FILE_TYPES[$row->mime] ?? 'File');
    }

    /**
     * Every photo and file still shared in the conversation, newest first,
     * for the details panel.
     *
     * @return array{photos: list<string>, files: list<FileView>}
     */
    private function media(string $conversationId): array
    {
        $rows = ChatAttachment::query()
            ->whereIn('message_id', ChatMessage::query()->where('conversation_id', $conversationId)->whereNull('removed_at')->select('id'))
            ->orderByDesc('created_at')
            ->orderByDesc('position')
            ->get(['id', 'kind', 'name', 'mime', 'size']);

        $photos = [];
        $files = [];
        foreach ($rows as $row) {
            if ($row->kind === Vocabulary::PHOTO && count($photos) < self::MEDIA) {
                $photos[] = $row->id;
            } elseif ($row->kind === Vocabulary::FILE && count($files) < self::MEDIA) {
                $files[] = self::fileView($row);
            }
        }

        return ['photos' => $photos, 'files' => $files];
    }

    /**
     * The newest message of each conversation: one grouped query for when,
     * one for the messages themselves.
     *
     * @param  list<string>  $ids
     * @return Collection<string, ChatMessage>
     */
    private function lastMessages(array $ids): Collection
    {
        $newest = [];
        foreach (ChatMessage::query()->whereIn('conversation_id', $ids)->groupBy('conversation_id')->selectRaw('conversation_id, MAX(created_at) AS newest')->toBase()->get() as $row) {
            $newest[(string) $row->conversation_id] = CarbonImmutable::parse((string) $row->newest, 'UTC');
        }

        if ($newest === []) {
            return collect();
        }

        $last = collect();
        $rows = ChatMessage::query()
            ->whereIn('conversation_id', array_keys($newest))
            ->whereIn('created_at', array_map(static fn (CarbonImmutable $instant): string => $instant->format('Y-m-d H:i:s.v'), array_values($newest)))
            ->withCount(['attachments as photos_count' => static fn (Builder $q) => $q->where('kind', Vocabulary::PHOTO)])
            ->withCount(['attachments as files_count' => static fn (Builder $q) => $q->where('kind', Vocabulary::FILE)])
            ->orderBy('id')
            ->get();
        foreach ($rows as $message) {
            if ($message->created_at->equalTo($newest[$message->conversation_id])) {
                $last->put($message->conversation_id, $message);
            }
        }

        return $last;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{total: int, mentions: int}> conversation id => unread messages, and how many of them mention the viewer
     */
    private function unreadCounts(int $me, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $counts = [];
        $rows = ChatMessage::query()
            ->join('chat_participants', static function (JoinClause $join) use ($me): void {
                $join->on('chat_participants.conversation_id', '=', 'chat_messages.conversation_id')
                    ->where('chat_participants.user_id', '=', $me);
            })
            ->whereIn('chat_messages.conversation_id', $ids)
            ->where('chat_messages.kind', Vocabulary::TEXT)
            ->whereNull('chat_messages.removed_at')
            ->where('chat_messages.sender_id', '!=', $me)
            ->where(static function ($q): void {
                $q->whereNull('chat_participants.last_read_at')
                    ->orWhereColumn('chat_messages.created_at', '>', 'chat_participants.last_read_at');
            })
            ->groupBy('chat_messages.conversation_id')
            ->selectRaw(
                'chat_messages.conversation_id AS conversation, COUNT(*) AS total, SUM(CASE WHEN chat_messages.body LIKE ? OR chat_messages.body LIKE ? THEN 1 ELSE 0 END) AS mentions',
                ['%@['.$me.']%', '%@['.SocialFormat::EVERYONE.']%'],
            )
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->conversation] = ['total' => (int) $row->total, 'mentions' => (int) $row->mentions];
        }

        return $counts;
    }

    /** @param list<PersonView> $others */
    private function title(ChatConversation $conversation, array $others): string
    {
        if ($conversation->kind === Vocabulary::GROUP) {
            return $conversation->title ?? 'Group';
        }

        return $others[0]->name ?? 'Former member';
    }

    /**
     * What the list shows under a conversation's name.
     *
     * @param  array<int, string>  $names
     */
    private function preview(ChatConversation $conversation, ?ChatMessage $last, int $me, array $names): string
    {
        if ($last === null) {
            return $conversation->kind === Vocabulary::GROUP ? 'Say hello to the group.' : 'Say hello.';
        }
        if ($last->kind === Vocabulary::SYSTEM) {
            return (string) $last->body;
        }

        $sender = $last->sender_id === $me ? 'You' : explode(' ', $names[$last->sender_id ?? 0] ?? 'Someone')[0];

        if ($last->removed_at !== null) {
            return $sender.' removed a message';
        }
        if (trim((string) $last->body) === '') {
            return $sender.' sent '.self::attachmentWords((int) $last->getAttribute('photos_count'), (int) $last->getAttribute('files_count'));
        }

        $text = SocialFormat::plain((string) $last->body, $names);

        // A one-to-one chat names only you; a group names whoever spoke.
        return match (true) {
            $last->sender_id === $me => 'You: '.$text,
            $conversation->kind === Vocabulary::GROUP => $sender.': '.$text,
            default => $text,
        };
    }

    /** "a photo", "4 photos", "a file", "2 photos and a file". */
    private static function attachmentWords(int $photos, int $files): string
    {
        $words = [];
        if ($photos > 0) {
            $words[] = $photos === 1 ? 'a photo' : $photos.' photos';
        }
        if ($files > 0) {
            $words[] = $files === 1 ? 'a file' : $files.' files';
        }

        return $words === [] ? 'a message' : implode(' and ', $words);
    }

    /**
     * Who else has read the viewer's own last message, as account ids sorted
     * by name. Null when the newest message isn't the viewer's, since a
     * receipt only ever sits under your own last word.
     *
     * @param  Collection<int, ChatMessage>  $rows
     * @param  Collection<int, ChatParticipant>  $participants
     * @param  array<int, PersonView>  $cards
     * @return list<int>|null
     */
    private function seenBy(Collection $rows, Collection $participants, int $me, array $cards): ?array
    {
        // System lines ("Maria added Jose") don't count as the last word.
        $last = $rows->reject(static fn (ChatMessage $message): bool => $message->kind === Vocabulary::SYSTEM)->last();

        if ($last === null || $last->sender_id !== $me || $last->removed_at !== null) {
            return null;
        }

        $seen = [];
        foreach ($participants as $participant) {
            if ($participant->user_id !== $me && $participant->last_read_at !== null && $participant->last_read_at->gte($last->created_at)) {
                $seen[] = (int) $participant->user_id;
            }
        }

        // By name, so the line and the list read the same way every time.
        usort($seen, static fn (int $a, int $b): int => strcasecmp(($cards[$a] ?? PersonView::missing($a))->name, ($cards[$b] ?? PersonView::missing($b))->name));

        return $seen;
    }
}
