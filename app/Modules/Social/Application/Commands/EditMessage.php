<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Modules\Social\Application\ChatMentions;
use App\Modules\Social\Application\ChatScope;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\Realtime;
use App\Support\SocialFormat;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Lets the sender rewrite their own message, for as long as it's there.
 * There's no time limit, the way Teams does it. Its photos, files and the
 * message it answers stay as they were, and its bubble says "Edited". Only
 * the people an edit newly mentions are told, and nobody's unread count
 * moves: an edit isn't a new message.
 */
final class EditMessage
{
    public function handle(Access $actor, string $messageId, string $body): void
    {
        $me = SocialRules::chat($actor);
        $message = ChatScope::message($me, $messageId);
        $text = SocialRules::text($body, SocialRules::MAX_MESSAGE, 'A message');

        if ($message->sender_id !== $me || $message->kind !== Vocabulary::TEXT) {
            throw new RuntimeException('You can only edit your own messages.');
        }
        if ($message->removed_at !== null) {
            throw new RuntimeException('That message was removed.');
        }
        if ($text === '' && ! ChatAttachment::query()->where('message_id', $message->id)->exists()) {
            throw new RuntimeException('Write something, or remove the message instead.');
        }

        $conversation = ChatScope::conversation($me, $message->conversation_id);
        $members = ChatScope::memberIds($conversation->id);
        $group = $conversation->kind === Vocabulary::GROUP;
        $names = $group ? ChatMentions::names($actor, $me, $members) : [];
        $text = $group ? SocialFormat::tokenize($text, $names, [], true) : SocialFormat::disarm($text);

        $before = (string) $message->body;
        if ($text === $before) {
            return;
        }

        // The write re-checks removed_at itself, so a removal that commits after
        // the read above wins and the text never comes back onto it.
        $written = ChatMessage::query()->whereKey($message->id)->whereNull('removed_at')->update([
            'body' => $text === '' ? null : $text,
            'edited_at' => CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.v'),
        ]);
        if ($written === 0) {
            throw new RuntimeException('That message was removed.');
        }

        if ($group) {
            ChatMentions::tell($actor, $me, $conversation->id, $message->id, $conversation->title, $text, $members, $names, $before);
        }

        Realtime::announce(array_map(static fn (int $userId): string => Realtime::account($userId), $members));
    }
}
