<?php

declare(strict_types=1);

namespace App\Modules\Social\Application;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use RuntimeException;

/**
 * Being in a conversation is the whole of Chat's permission model: a person
 * reads and writes the conversations they are a participant of, and nothing
 * else. An outsider is told the conversation was not found, never that it
 * exists and is closed to them.
 */
final class ChatScope
{
    public const string NOT_FOUND = 'That conversation was not found.';

    private function __construct() {}

    /** The account's own participant row, or a refusal. */
    public static function participant(int $userId, string $conversationId): ChatParticipant
    {
        $row = ChatParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->first();

        if ($row === null) {
            throw new RuntimeException(self::NOT_FOUND);
        }

        return $row;
    }

    public static function conversation(int $userId, string $conversationId): ChatConversation
    {
        self::participant($userId, $conversationId);

        $conversation = ChatConversation::query()->find($conversationId);

        if ($conversation === null) {
            throw new RuntimeException(self::NOT_FOUND);
        }

        return $conversation;
    }

    /**
     * A message in a conversation the account is in, or a refusal. One
     * message for both misses, so an outsider cannot tell a real id from a
     * made-up one.
     */
    public static function message(int $userId, string $messageId): ChatMessage
    {
        $message = ChatMessage::query()->find($messageId);

        if ($message === null || ! ChatParticipant::query()->where('conversation_id', $message->conversation_id)->where('user_id', $userId)->exists()) {
            throw new RuntimeException('That message was not found.');
        }

        return $message;
    }

    /**
     * Everyone in a conversation.
     *
     * @return list<int>
     */
    public static function memberIds(string $conversationId): array
    {
        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            ChatParticipant::query()->where('conversation_id', $conversationId)->pluck('user_id')->all(),
        ));
    }
}
