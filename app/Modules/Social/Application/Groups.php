<?php

declare(strict_types=1);

namespace App\Modules\Social\Application;

use App\Domain\Notifications\Notifier;
use App\Models\CommunityGroup;
use App\Models\CommunityGroupMember;
use App\Modules\Social\Domain\Vocabulary;

/** The small group chores more than one command needs. */
final class Groups
{
    private function __construct() {}

    /**
     * The people who run a group: its owner and its moderators.
     *
     * @return list<int>
     */
    public static function runners(string $groupId): array
    {
        return array_values(array_map('intval', CommunityGroupMember::query()
            ->where('group_id', $groupId)
            ->where('status', Vocabulary::MEMBER)
            ->whereIn('role', [Vocabulary::OWNER, Vocabulary::MODERATOR])
            ->pluck('user_id')
            ->all()));
    }

    /**
     * Rings the owner's and the moderators' bells, for a request that needs
     * one of them to answer it.
     *
     * @param  list<int>  $except  never told, like whoever caused it
     */
    public static function tellRunners(CommunityGroup $group, string $title, array $except = []): void
    {
        foreach (array_diff(self::runners($group->id), $except) as $userId) {
            Notifier::account($userId, 'COMMUNITY_JOIN_REQUEST', $title, 'Open the group to let them in or decline.', '/community?group='.$group->id);
        }
    }
}
