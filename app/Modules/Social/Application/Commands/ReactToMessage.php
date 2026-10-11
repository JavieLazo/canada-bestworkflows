<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Models\ChatReaction;
use App\Modules\Social\Application\ChatScope;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * One reaction per person per message, from the same six as Community: the
 * same one again takes it back, a different one replaces it.
 */
final class ReactToMessage
{
    /** @return string|null the reaction left on the message, null when taken back */
    public function handle(Access $actor, string $messageId, string $type): ?string
    {
        $me = SocialRules::chat($actor);

        if (! Vocabulary::isReaction($type)) {
            throw new RuntimeException('That reaction is not available.');
        }

        $message = ChatScope::message($me, $messageId);
        if ($message->kind !== Vocabulary::TEXT || $message->removed_at !== null) {
            throw new RuntimeException('That message can no longer be reacted to.');
        }

        $existing = ChatReaction::query()->where('message_id', $message->id)->where('user_id', $me)->first();

        if ($existing !== null && $existing->type === $type) {
            $existing->delete();
            $result = null;
        } elseif ($existing !== null) {
            $existing->type = $type;
            $existing->save();
            $result = $type;
        } else {
            $row = new ChatReaction;
            $row->fill(['message_id' => $message->id, 'type' => $type]);
            $row->user_id = $me;
            try {
                $row->save();
            } catch (UniqueConstraintViolationException) {
                // A double click raced itself; the other request's reaction stands.
            }
            $result = $type;
        }

        Realtime::announce(array_map(static fn (int $userId): string => Realtime::account($userId), ChatScope::memberIds($message->conversation_id)));

        return $result;
    }
}
