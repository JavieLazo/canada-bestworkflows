<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Domain\Enums\ModuleCode;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\Social\Application\People;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One person picked opens a direct chat, reusing the one the pair already
 * has; two or more start a group, named by the starter or after its first
 * members, with the starter as its admin. Only active accounts that can use
 * Chat may be added.
 */
final class StartConversation
{
    /**
     * @param  list<int>  $userIds
     * @return string the conversation's id
     */
    public function handle(Access $actor, array $userIds, string $title = ''): string
    {
        $me = SocialRules::chat($actor);
        $ids = array_values(array_diff(array_unique(array_map('intval', $userIds)), [$me]));
        $ids = app(ModuleAccounts::class)->filter($actor, ModuleCode::CHAT, $ids);

        if ($ids === []) {
            throw new RuntimeException('Pick at least one person who can use Chat.');
        }

        sort($ids);

        return count($ids) === 1
            ? $this->direct($actor, $me, $ids[0])
            : $this->group($actor, $me, $ids, $title);
    }

    private function direct(Access $actor, int $me, int $other): string
    {
        $key = min($me, $other).':'.max($me, $other);
        $existing = ChatConversation::query()->where('direct_key', $key)->value('id');

        if (is_string($existing)) {
            return $existing;
        }

        try {
            $id = DB::transaction(static function () use ($me, $other, $key): string {
                $conversation = new ChatConversation;
                $conversation->fill(['kind' => Vocabulary::DIRECT, 'direct_key' => $key]);
                $conversation->created_by = $me;
                $conversation->save();

                foreach ([$me, $other] as $userId) {
                    $participant = new ChatParticipant;
                    $participant->fill(['conversation_id' => $conversation->id]);
                    $participant->user_id = $userId;
                    $participant->save();
                }

                return $conversation->id;
            });
        } catch (UniqueConstraintViolationException) {
            // Both people opened it at the same moment; the other request's
            // conversation is the one they share.
            return (string) ChatConversation::query()->where('direct_key', $key)->value('id');
        }

        Realtime::announce([Realtime::account($me), Realtime::account($other)]);

        return $id;
    }

    /** @param list<int> $ids everyone but the starter */
    private function group(Access $actor, int $me, array $ids, string $title): string
    {
        $title = mb_substr(trim($title), 0, SocialRules::MAX_GROUP_TITLE);

        if ($title === '') {
            $first = array_map(
                static fn ($card): string => explode(' ', $card->name)[0],
                array_values(app(People::class)->cards($actor, $ids)),
            );
            $title = count($first) > 3
                ? implode(', ', array_slice($first, 0, 3)).' and '.(count($first) - 3).' more'
                : implode(', ', $first);
            $title = mb_substr($title, 0, SocialRules::MAX_GROUP_TITLE);
        }

        $starter = $actor->fullName !== '' ? $actor->fullName : 'Someone';
        $id = DB::transaction(static function () use ($me, $ids, $title, $starter): string {
            $conversation = new ChatConversation;
            $conversation->fill(['kind' => Vocabulary::GROUP, 'title' => $title]);
            $conversation->created_by = $me;
            $conversation->save();

            $created = new ChatMessage;
            $created->fill(['conversation_id' => $conversation->id, 'kind' => Vocabulary::SYSTEM, 'body' => $starter.' created the group']);
            $created->save();

            $conversation->last_message_at = $created->created_at;
            $conversation->save();

            foreach ([$me, ...$ids] as $userId) {
                $participant = new ChatParticipant;
                $participant->fill(['conversation_id' => $conversation->id]);
                $participant->user_id = $userId;
                // Whoever starts a group runs it, and has seen the line
                // announcing it.
                $participant->role = $userId === $me ? Vocabulary::ADMIN : Vocabulary::MEMBER;
                $participant->last_read_at = $userId === $me ? $conversation->last_message_at : null;
                $participant->save();
            }

            return $conversation->id;
        });

        Realtime::announce(array_map(static fn (int $userId): string => Realtime::account($userId), [$me, ...$ids]));

        return $id;
    }
}
