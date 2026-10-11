<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Modules\Social\Application\ChatLines;
use App\Modules\Social\Application\ChatScope;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Renames a group chat. Anyone in it can, and the thread says who did. */
final class RenameConversation
{
    public function handle(Access $actor, string $conversationId, string $title): void
    {
        $me = SocialRules::chat($actor);
        $conversation = ChatScope::conversation($me, $conversationId);

        if ($conversation->kind !== Vocabulary::GROUP) {
            throw new RuntimeException('Only a group chat has a name to change.');
        }

        $title = SocialRules::text($title, SocialRules::MAX_GROUP_TITLE, 'A group name');
        if ($title === '') {
            throw new RuntimeException('Give the group a name.');
        }
        if ($title === $conversation->title) {
            return;
        }

        $renamer = $actor->fullName !== '' ? $actor->fullName : 'Someone';

        DB::transaction(static function () use ($conversation, $title, $renamer): void {
            $conversation->title = $title;
            ChatLines::write($conversation, $renamer.' named the group '.$title);
        });

        Realtime::announce(array_map(static fn (int $id): string => Realtime::account($id), ChatScope::memberIds($conversation->id)));
    }
}
