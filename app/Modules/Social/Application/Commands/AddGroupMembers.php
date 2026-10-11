<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\CommunityGroup;
use App\Models\CommunityGroupMember;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\Social\Application\Groups;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\Realtime;
use RuntimeException;

/**
 * Adds people to a Community group. Anyone in the group can.
 *
 * In a public group they're in straight away. In a private one, only the
 * people who run it can let someone straight in; anyone else who adds
 * someone makes a request instead, which the owner or a moderator answers,
 * and the list says who asked. Whoever is let in is told.
 */
final class AddGroupMembers
{
    /**
     * @param  list<int>  $userIds
     * @return array{added: int, requested: int}
     */
    public function handle(Access $actor, string $groupId, array $userIds): array
    {
        $me = SocialRules::community($actor);
        $group = CommunityGroup::query()->find($groupId);

        if ($group === null || SocialRules::groupRole($me, $group->id) === null) {
            throw new RuntimeException('Only people in the group can add others to it.');
        }

        $ids = array_values(array_diff(
            app(ModuleAccounts::class)->filter($actor, ModuleCode::COMMUNITY, array_values(array_unique(array_map('intval', $userIds)))),
            [$me],
        ));
        if ($ids === []) {
            throw new RuntimeException('Pick at least one person who can use Community.');
        }

        $straightIn = ! $group->is_private || SocialRules::runsGroup($me, $group->id);
        $existing = CommunityGroupMember::query()->where('group_id', $group->id)->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $added = [];
        $requested = [];

        foreach ($ids as $userId) {
            $row = $existing->get($userId);

            if ($row !== null && $row->status === Vocabulary::MEMBER) {
                continue;
            }
            if ($row !== null && ! $straightIn) {
                // Already waiting. Whoever runs the group will see it.
                continue;
            }

            $row ??= new CommunityGroupMember;
            $row->fill(['group_id' => $group->id, 'status' => $straightIn ? Vocabulary::MEMBER : Vocabulary::REQUESTED]);
            $row->user_id = $userId;
            $row->role = Vocabulary::MEMBER;
            $row->added_by ??= $me;
            $row->save();

            if ($straightIn) {
                $added[] = $userId;
            } else {
                $requested[] = $userId;
            }
        }

        foreach ($added as $userId) {
            Notifier::account($userId, 'COMMUNITY_GROUP_ADDED', $actor->fullName.' added you to '.$group->name, $group->description, '/community?group='.$group->id);
        }
        if ($requested !== []) {
            $who = count($requested) === 1 ? 'someone' : count($requested).' people';
            Groups::tellRunners($group, $actor->fullName.' wants to add '.$who.' to '.$group->name, [$me]);
        }

        Realtime::announce([Realtime::community()]);

        return ['added' => count($added), 'requested' => count($requested)];
    }
}
