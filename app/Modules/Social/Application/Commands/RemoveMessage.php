<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Models\ChatAttachment;
use App\Models\ChatReaction;
use App\Modules\Social\Application\Attachments;
use App\Modules\Social\Application\ChatScope;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\Realtime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes the sender's own message. The row stays with its body cleared, so
 * the thread shows "removed a message" and a reply that quoted it still
 * reads; its photos, files and reactions go.
 */
final class RemoveMessage
{
    public function handle(Access $actor, string $messageId): void
    {
        $me = SocialRules::chat($actor);
        $message = ChatScope::message($me, $messageId);

        if ($message->sender_id !== $me || $message->kind !== Vocabulary::TEXT) {
            throw new RuntimeException('You can only remove your own messages.');
        }
        if ($message->removed_at !== null) {
            return;
        }

        $paths = array_values(array_map('strval', ChatAttachment::query()->where('message_id', $message->id)->pluck('path')->all()));

        DB::transaction(static function () use ($message): void {
            ChatAttachment::query()->where('message_id', $message->id)->delete();
            ChatReaction::query()->where('message_id', $message->id)->delete();
            $message->body = null;
            $message->removed_at = CarbonImmutable::now();
            $message->save();
        });
        Attachments::discard($paths);

        Realtime::announce(array_map(static fn (int $userId): string => Realtime::account($userId), ChatScope::memberIds($message->conversation_id)));
    }
}
