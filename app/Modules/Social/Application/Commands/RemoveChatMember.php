<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Models\ChatParticipant;
use App\Modules\Social\Application\ChatLines;
use App\Modules\Social\Application\ChatScope;
use App\Modules\Social\Application\People;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Takes someone out of a group chat. Admins only. What they wrote stays, and
 * the thread says who removed them.
 */
final class RemoveChatMember
{
    public function handle(Access $actor, string $conversationId, int $userId): void
    {
        $me = SocialRules::chat($actor);
        $conversation = ChatScope::conversation($me, $conversationId);

        if ($conversation->kind !== Vocabulary::GROUP || ! SocialRules::chatAdmin($me, $conversation->id)) {
            throw new RuntimeException('Only an admin of the group chat can remove people.');
        }
        if ($userId === $me) {
            throw new RuntimeException('To leave the chat yourself, use Leave group.');
        }

        $row = ChatParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $userId)->first();
        if ($row === null) {
            throw new RuntimeException('That person is not in this chat.');
        }

        $members = ChatScope::memberIds($conversation->id);
        $name = app(People::class)->card($actor, $userId)->name;
        $remover = $actor->fullName !== '' ? $actor->fullName : 'Someone';

        DB::transaction(static function () use ($row, $conversation, $remover, $name): void {
            $row->delete();
            ChatLines::write($conversation, $remover.' removed '.$name);
        });

        Realtime::announce(array_map(static fn (int $id): string => Realtime::account($id), $members));
    }
}
