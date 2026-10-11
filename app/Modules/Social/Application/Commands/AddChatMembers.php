<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\Social\Application\ChatLines;
use App\Modules\Social\Application\ChatScope;
use App\Modules\Social\Application\People;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\Realtime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds people to a group chat. Anyone in it can, the way Messenger works.
 * They see the chat's history, start with nothing unread, and are told
 * they were added. Only active accounts that can use Chat may be added.
 */
final class AddChatMembers
{
    /**
     * @param  list<int>  $userIds
     * @return int how many were added
     */
    public function handle(Access $actor, string $conversationId, array $userIds): int
    {
        $me = SocialRules::chat($actor);
        $conversation = ChatScope::conversation($me, $conversationId);

        if ($conversation->kind !== Vocabulary::GROUP) {
            throw new RuntimeException('People can only be added to a group chat. Start a new group instead.');
        }

        $members = ChatScope::memberIds($conversation->id);
        $ids = array_values(array_diff(
            app(ModuleAccounts::class)->filter($actor, ModuleCode::CHAT, array_values(array_unique(array_map('intval', $userIds)))),
            $members,
        ));
        if ($ids === []) {
            throw new RuntimeException('Pick at least one person who can use Chat and isn\'t in this chat yet.');
        }

        $cards = app(People::class)->cards($actor, $ids);
        $names = array_map(static fn (int $id): string => isset($cards[$id]) ? $cards[$id]->name : 'someone', $ids);
        $adder = $actor->fullName !== '' ? $actor->fullName : 'Someone';

        DB::transaction(static function () use ($conversation, $ids, $names, $adder): void {
            // Nothing already said counts as unread for them.
            $newest = ChatMessage::query()->where('conversation_id', $conversation->id)->max('created_at');

            foreach ($ids as $userId) {
                $participant = new ChatParticipant;
                $participant->fill(['conversation_id' => $conversation->id]);
                $participant->user_id = $userId;
                $participant->role = Vocabulary::MEMBER;
                $participant->last_read_at = $newest !== null ? CarbonImmutable::parse((string) $newest, 'UTC') : null;
                $participant->save();
            }

            ChatLines::write($conversation, $adder.' added '.ChatLines::names($names));
        });

        foreach ($ids as $userId) {
            Notifier::account($userId, 'CHAT_ADDED', $adder.' added you to '.($conversation->title ?? 'a group chat'), null, '/chat?c='.$conversation->id);
        }

        Realtime::announce(array_map(static fn (int $userId): string => Realtime::account($userId), [...$members, ...$ids]));

        return count($ids);
    }
}
