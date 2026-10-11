<?php

declare(strict_types=1);

namespace App\Modules\Social\Application;

use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\CommunityGroup;
use App\Models\CommunityGroupMember;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\SocialFormat;

/**
 * @mentions in Community, written once for posts and comments.
 *
 * Three kinds: a person, a group (everyone in it), and @everyone (everyone
 * who can read the post). Who may use which is SocialRules' call. Whoever a
 * mention reaches, it never reaches past the people who can already read the
 * post, so naming someone never widens who sees it.
 */
final class Mentions
{
    public const string PERSON = 'person';

    public const string EVERYONE = 'everyone';

    private function __construct() {}

    /**
     * What the writer typed, with every name they may use here turned into a
     * token. A name they may not use stays plain text, and so does a group
     * named inside a private group (SocialRules::mentionableGroups).
     */
    public static function tokenize(Access $actor, string $text, ?string $groupId): string
    {
        return SocialFormat::tokenize(
            $text,
            app(People::class)->mentionable($actor),
            SocialRules::mentionableGroups($actor, $groupId),
            SocialRules::mayMentionEveryone($actor, $groupId),
        );
    }

    /**
     * Everyone a body's mentions reach, each once and never the writer, as
     * account id => how they were named: `person`, `everyone`, or the name of
     * the group that reached them. Named directly beats named through a group,
     * which beats @everyone. Only people who can read the post are in it.
     *
     * @return array<int, string>
     */
    public static function reach(Access $actor, string $body, ?string $groupId): array
    {
        $me = SocialRules::community($actor);
        $reach = [];

        foreach (SocialFormat::mentions($body) as $id) {
            $reach[$id] ??= self::PERSON;
        }

        $groupIds = SocialFormat::groupMentions($body);
        if ($groupIds !== []) {
            $names = CommunityGroup::query()->whereKey($groupIds)->pluck('name', 'id');
            $members = CommunityGroupMember::query()
                ->whereIn('group_id', $groupIds)
                ->where('status', Vocabulary::MEMBER)
                ->get(['group_id', 'user_id']);

            foreach ($members as $member) {
                $reach[(int) $member->user_id] ??= (string) ($names[$member->group_id] ?? 'a group');
            }
        }

        if (SocialFormat::mentionsEveryone($body)) {
            $everyone = $groupId === null
                ? array_map(static fn (AccountLabel $label): int => $label->id, app(ModuleAccounts::class)->holders($actor, ModuleCode::COMMUNITY)->all())
                : array_map('intval', CommunityGroupMember::query()->where('group_id', $groupId)->where('status', Vocabulary::MEMBER)->pluck('user_id')->all());

            foreach ($everyone as $id) {
                $reach[$id] ??= self::EVERYONE;
            }
        }

        unset($reach[$me]);

        if ($reach === []) {
            return [];
        }

        $readers = array_flip(CommunityScope::audience($actor, array_keys($reach), $groupId));

        return array_intersect_key($reach, $readers);
    }

    /**
     * Rings the bell of everyone a post or comment mentions, except the
     * people already told something about it.
     *
     * @param  string  $where  `a post`, `a comment` or `a reply`
     * @param  list<int>  $told
     * @return list<int> everyone told, the earlier ones included
     */
    public static function tell(Access $actor, string $body, ?string $groupId, string $where, string $preview, string $href, array $told = []): array
    {
        foreach (self::reach($actor, $body, $groupId) as $userId => $how) {
            if (in_array($userId, $told, true)) {
                continue;
            }

            $named = match ($how) {
                self::PERSON => 'you',
                self::EVERYONE => 'everyone',
                default => $how,
            };
            Notifier::account($userId, 'COMMUNITY_MENTION', $actor->fullName.' mentioned '.$named.' in '.$where, $preview, $href);
            $told[] = $userId;
        }

        return $told;
    }
}
