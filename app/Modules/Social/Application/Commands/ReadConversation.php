<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Models\ChatMessage;
use App\Modules\Social\Application\ChatScope;
use App\Modules\Social\Application\SocialRules;
use App\Support\Access;
use App\Support\Realtime;
use Carbon\CarbonImmutable;

/**
 * Marks a conversation read up to its newest message. Opening a conversation
 * reads it; so does a new message arriving while it is open.
 */
final class ReadConversation
{
    /** @return bool whether anything changed */
    public function handle(Access $actor, string $conversationId): bool
    {
        $me = SocialRules::chat($actor);
        $participant = ChatScope::participant($me, $conversationId);
        // The newest message itself, not the list's copy of its time: that
        // copy is only for sorting, and it must never decide what's unread.
        $newest = ChatMessage::query()->where('conversation_id', $conversationId)->max('created_at');
        $latest = $newest !== null ? CarbonImmutable::parse((string) $newest, 'UTC') : null;

        if ($latest === null || ($participant->last_read_at !== null && $participant->last_read_at->gte($latest))) {
            return false;
        }

        $participant->last_read_at = $latest;
        $participant->save();

        // The others' "Seen by" lines move, and so does this account's own
        // unread badge in any other open tab.
        Realtime::announce(array_map(static fn (int $userId): string => Realtime::account($userId), ChatScope::memberIds($conversationId)));

        return true;
    }
}
