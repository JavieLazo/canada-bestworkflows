<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Modules\Social\Application\Attachments;
use App\Modules\Social\Application\ChatMentions;
use App\Modules\Social\Application\ChatScope;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\Realtime;
use App\Support\SocialFormat;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * A message into a conversation the sender is in, with up to ten photos and
 * files, optionally answering an earlier message.
 *
 * @mentions only mean something in a group, where there is someone else to
 * mean: a person in it, or @everyone, which anyone in a group chat can use.
 * In a direct chat the text is stored as typed, less anything typed to look
 * like a stored mention. Whoever a mention reaches is told through the bell,
 * unless they muted the chat without allowing mentions. The bell links to
 * the message itself.
 */
final class SendMessage
{
    /**
     * @param  list<UploadedFile>  $attachments
     * @return string the new message's id
     */
    public function handle(Access $actor, string $conversationId, string $body, ?string $replyToId = null, array $attachments = []): string
    {
        $me = SocialRules::chat($actor);
        $conversation = ChatScope::conversation($me, $conversationId);
        $text = SocialRules::text($body, SocialRules::MAX_MESSAGE, 'A message');

        if ($text === '' && $attachments === []) {
            throw new RuntimeException('Write a message or add a photo or file first.');
        }
        Attachments::check($attachments);

        $members = ChatScope::memberIds($conversation->id);
        $group = $conversation->kind === Vocabulary::GROUP;
        $names = $group ? ChatMentions::names($actor, $me, $members) : [];
        $text = $group ? SocialFormat::tokenize($text, $names, [], true) : SocialFormat::disarm($text);

        // Only a live message in this same conversation can be answered.
        $replyTo = $replyToId === null ? null : ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereKey($replyToId)
            ->where('kind', Vocabulary::TEXT)
            ->whereNull('removed_at')
            ->value('id');

        $stored = [];
        try {
            $message = DB::transaction(static function () use ($me, $conversation, $text, $replyTo, $attachments, &$stored): ChatMessage {
                $message = new ChatMessage;
                $message->fill([
                    'conversation_id' => $conversation->id,
                    'kind' => Vocabulary::TEXT,
                    'body' => $text === '' ? null : $text,
                    'reply_to_id' => is_string($replyTo) ? $replyTo : null,
                ]);
                $message->sender_id = $me;
                $message->save();

                foreach ($attachments as $position => $file) {
                    Attachments::store($file, 'chat', static function (array $saved) use ($message, $position): ChatAttachment {
                        $row = new ChatAttachment;
                        $row->fill(['message_id' => $message->id, 'position' => $position] + $saved);
                        $row->save();

                        return $row;
                    }, $stored);
                }

                // Saved through the models so the milliseconds survive: unread
                // counts and read receipts compare these instants exactly.
                $conversation->last_message_at = $message->created_at;
                $conversation->save();

                $own = ChatParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $me)->first();
                if ($own !== null) {
                    $own->last_read_at = $message->created_at;
                    $own->save();
                }

                return $message;
            });
        } catch (Throwable $error) {
            Attachments::discard($stored);
            throw $error;
        }

        if ($group) {
            ChatMentions::tell($actor, $me, $conversation->id, $message->id, $conversation->title, $text, $members, $names);
        }

        Realtime::announce(array_map(static fn (int $userId): string => Realtime::account($userId), $members));

        return $message->id;
    }
}
