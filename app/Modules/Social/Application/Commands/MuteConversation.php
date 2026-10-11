<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Modules\Social\Application\ChatScope;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\Realtime;

/**
 * Mutes or unmutes a conversation for the actor alone. A muted conversation
 * still counts its unread messages in the Chat list, but leaves the sidebar
 * badge alone and sends no @mention notifications.
 *
 * A group chat can be muted with mentions allowed: then a message that
 * mentions them (by name or @everyone) still rings their bell, and only
 * those count in the badge. A direct chat has no mentions, so there it's
 * always everything.
 */
final class MuteConversation
{
    public function handle(Access $actor, string $conversationId, bool $muted, bool $allowMentions = false): void
    {
        $me = SocialRules::chat($actor);
        $conversation = ChatScope::conversation($me, $conversationId);
        $participant = ChatScope::participant($me, $conversation->id);
        $allowMentions = $muted && $allowMentions && $conversation->kind === Vocabulary::GROUP;

        if ($participant->is_muted === $muted && $participant->allow_mentions === $allowMentions) {
            return;
        }

        $participant->is_muted = $muted;
        $participant->allow_mentions = $allowMentions;
        $participant->save();

        Realtime::announce([Realtime::account($me)]);
    }
}
