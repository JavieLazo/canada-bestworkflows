<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\CommunityGroupMember;
use App\Modules\Social\Application\People;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\Realtime;
use RuntimeException;

/**
 * Takes someone out of a group. The owner can remove anyone but themself;
 * a moderator can remove ordinary members. What they posted stays.
 * Audited, since it's someone else deciding.
 */
final class RemoveGroupMember
{
    public function handle(Access $actor, string $groupId, int $userId): void
    {
        $group = SocialRules::runGroup($actor, $groupId, 'Only the group\'s owner or its moderators can remove people.');
        $me = (int) $actor->userId;

        if ($userId === $me) {
            throw new RuntimeException('To leave the group yourself, use Leave.');
        }

        $row = CommunityGroupMember::query()->where('group_id', $group->id)->where('user_id', $userId)->where('status', Vocabulary::MEMBER)->first();
        if ($row === null) {
            throw new RuntimeException('That person is not in the group.');
        }
        if ($row->role === Vocabulary::OWNER) {
            throw new RuntimeException('The owner can\'t be removed.');
        }
        if ($row->role === Vocabulary::MODERATOR && SocialRules::groupRole($me, $group->id) !== Vocabulary::OWNER) {
            throw new RuntimeException('Only the owner can remove a moderator.');
        }

        $row->delete();

        $name = app(People::class)->card($actor, $userId)->name;
        AuditService::record($actor, AuditEvent::COMMUNITY_MEMBER_REMOVED, 'community_groups', $group->id, "{$name} removed from {$group->name}");
        Realtime::announce([Realtime::community()]);
    }
}
