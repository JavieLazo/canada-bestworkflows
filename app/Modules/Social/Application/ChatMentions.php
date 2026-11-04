<?php

declare(strict_types=1);

namespace App\Modules\Social\Application;

use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\ChatParticipant;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Support\Access;
use App\Support\SocialFormat;

/**
 * @mentions in a group chat, written once for sending a message and for
 * editing one, the way Mentions does it for Community. Someone in the chat
 * can be named, and anyone in a group chat can use @everyone.
 */
final class ChatMentions
{
    private function __construct() {}

    /**
     * Who the writer can name: everyone else in the chat, as account id =>
     * name.
     *
     * @param  list<int>  $members
     * @return array<int, string>
     */
    public static function names(Access $actor, int $me, array $members): array
    {
        $names = [];
        foreach (app(People::class)->cards($actor, array_values(array_diff($members, [$me]))) as $id => $card) {
            $names[$id] = $card->name;
        }

        return $names;
    }

    /**
     * Rings the bells of everyone the message mentions: by name, or all of
     * them for @everyone. Only people still in the chat and still able to
     * open Chat, since a bell whose link bounces is worse than no bell, and
     * never someone who muted it without allowing mentions. After an edit,
     * `$before` is the old text, and whoever it already reached isn't rung
     * again.
     *
     * @param  list<int>  $members
     * @param  array<int, string>  $names
     */
    public static function tell(Access $actor, int $me, string $conversationId, string $messageId, ?string $title, string $text, array $members, array $names, string $before = ''): void
    {
        $named = array_values(array_intersect(SocialFormat::mentions($text), $members));
        $everyone = SocialFormat::mentionsEveryone($text);
        $reach = array_values(array_diff($everyone ? $members : $named, [$me]));
        if ($before !== '') {
            $reach = array_values(array_diff($reach, SocialFormat::mentionsEveryone($before) ? $members : SocialFormat::mentions($before)));
        }
        $reach = $reach === [] ? [] : app(ModuleAccounts::class)->filter($actor, ModuleCode::CHAT, $reach);

        if ($reach === []) {
            return;
        }

        $silenced = array_map('intval', ChatParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('is_muted', true)
            ->where('allow_mentions', false)
            ->pluck('user_id')
            ->all());
        $preview = SocialRules::preview(SocialFormat::plain($text, $names + [$me => $actor->fullName]));
        $where = $title ?? 'a chat';

        foreach (array_diff($reach, $silenced) as $userId) {
            $who = in_array($userId, $named, true) ? 'you' : 'everyone';
            Notifier::account($userId, 'CHAT_MENTION', $actor->fullName.' mentioned '.$who.' in '.$where, $preview, '/chat?c='.$conversationId.'&m='.$messageId);
        }
    }
}
