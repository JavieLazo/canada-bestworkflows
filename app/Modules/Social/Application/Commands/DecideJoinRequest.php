<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Domain\Notifications\Notifier;
use App\Models\CommunityGroupMember;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\Realtime;
use RuntimeException;

/**
 * Lets someone into a private group, or turns them down. Only the people who
 * run the group decide: its owner and its moderators. Company-wide
 * moderators don't, unless they run it too, because who gets into a private
 * group is the group's own business.
 *
 * The person is told when they're let in, and not told when they're turned
 * down, the same way most apps handle it.
 */
final class DecideJoinRequest
{
    public function handle(Access $actor, string $groupId, int $userId, bool $approve): void
    {
        $group = SocialRules::runGroup($actor, $groupId, 'Only the group\'s owner or its moderators can answer requests.');

        $request = CommunityGroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $userId)
            ->where('status', Vocabulary::REQUESTED)
            ->first();

        if ($request === null) {
            throw new RuntimeException('That request was already answered.');
        }

        if ($approve) {
            $request->status = Vocabulary::MEMBER;
            $request->save();
            Notifier::account($userId, 'COMMUNITY_JOIN', "You're in {$group->name}", 'Your request to join was accepted.', '/community?group='.$group->id);
        } else {
            $request->delete();
        }

        Realtime::announce([Realtime::community()]);
    }
}
