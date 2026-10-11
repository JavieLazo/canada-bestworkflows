<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Modules\Social\Application\Attachments;
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
 * Leaves a group chat, saying so in the thread. The last person out takes
 * the conversation with them. When the last admin leaves, whoever has been
 * in it longest becomes the admin, so there's always someone who can remove
 * people. A direct chat cannot be left: there is nobody to leave it to.
 */
final class LeaveConversation
{
    public function handle(Access $actor, string $conversationId): void
    {
        $me = SocialRules::chat($actor);
        $conversation = ChatScope::conversation($me, $conversationId);

        if ($conversation->kind !== Vocabulary::GROUP) {
            throw new RuntimeException('Only a group chat can be left.');
        }

        $remaining = array_values(array_diff(ChatScope::memberIds($conversation->id), [$me]));
        $leaver = $actor->fullName !== '' ? $actor->fullName : 'Someone';
        $paths = [];

        DB::transaction(static function () use ($actor, $conversation, $me, $remaining, $leaver, &$paths): void {
            ChatParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $me)->delete();

            if ($remaining === []) {
                $paths = array_values(array_map('strval', ChatAttachment::query()
                    ->whereIn('message_id', ChatMessage::query()->where('conversation_id', $conversation->id)->select('id'))
                    ->pluck('path')
                    ->all()));
                if ($conversation->photo_path !== null) {
                    $paths[] = $conversation->photo_path;
                }
                $conversation->delete();

                return;
            }

            ChatLines::write($conversation, $leaver.' left the group');

            $admins = ChatParticipant::query()->where('conversation_id', $conversation->id)->where('role', Vocabulary::ADMIN)->exists();
            if (! $admins) {
                $next = ChatParticipant::query()->where('conversation_id', $conversation->id)->orderBy('created_at')->orderBy('user_id')->first();
                if ($next !== null) {
                    $next->role = Vocabulary::ADMIN;
                    $next->save();
                    ChatLines::write($conversation, app(People::class)->card($actor, $next->user_id)->name.' is now an admin');
                }
            }
        });
        Attachments::discard($paths);

        Realtime::announce(array_map(static fn (int $userId): string => Realtime::account($userId), [$me, ...$remaining]));
    }
}
