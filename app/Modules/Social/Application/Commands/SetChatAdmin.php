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
 * Makes someone in a group chat an admin, or takes it back. Admins only.
 * An admin can step down themselves as long as another admin stays.
 */
final class SetChatAdmin
{
    public function handle(Access $actor, string $conversationId, int $userId, bool $admin): void
    {
        $me = SocialRules::chat($actor);
        $conversation = ChatScope::conversation($me, $conversationId);

        if ($conversation->kind !== Vocabulary::GROUP || ! SocialRules::chatAdmin($me, $conversation->id)) {
            throw new RuntimeException('Only an admin of the group chat can change who the admins are.');
        }

        $row = ChatParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $userId)->first();
        if ($row === null) {
            throw new RuntimeException('That person is not in this chat.');
        }

        $role = $admin ? Vocabulary::ADMIN : Vocabulary::MEMBER;
        if ($row->role === $role) {
            return;
        }
        if (! $admin && ChatParticipant::query()->where('conversation_id', $conversation->id)->where('role', Vocabulary::ADMIN)->count() <= 1) {
            throw new RuntimeException('A group chat needs at least one admin. Make someone else an admin first.');
        }

        $name = app(People::class)->card($actor, $userId)->name;
        $changer = $actor->fullName !== '' ? $actor->fullName : 'Someone';

        DB::transaction(static function () use ($row, $role, $conversation, $changer, $name, $admin): void {
            $row->role = $role;
            $row->save();
            ChatLines::write($conversation, $admin ? $changer.' made '.$name.' an admin' : $name.' is no longer an admin');
        });

        Realtime::announce(array_map(static fn (int $id): string => Realtime::account($id), ChatScope::memberIds($conversation->id)));
    }
}
