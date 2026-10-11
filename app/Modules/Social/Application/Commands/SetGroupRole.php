<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Notifications\Notifier;
use App\Models\CommunityGroupMember;
use App\Modules\Social\Application\People;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The owner decides who helps run their group: they make members moderators
 * or take it back, and they can hand the whole group to someone else, who
 * becomes the owner while they stay on as a moderator. Audited.
 */
final class SetGroupRole
{
    public function handle(Access $actor, string $groupId, int $userId, string $role): void
    {
        $group = SocialRules::runGroup($actor, $groupId, 'Only the group\'s owner can change who runs it.');
        $me = (int) $actor->userId;

        if (SocialRules::groupRole($me, $group->id) !== Vocabulary::OWNER) {
            throw new RuntimeException('Only the group\'s owner can change who runs it.');
        }
        if (! in_array($role, Vocabulary::GROUP_ROLES, true)) {
            throw new RuntimeException('Pick one of the listed roles.');
        }
        if ($userId === $me) {
            throw new RuntimeException('Make someone else the owner to step down.');
        }

        $row = CommunityGroupMember::query()->where('group_id', $group->id)->where('user_id', $userId)->where('status', Vocabulary::MEMBER)->first();
        if ($row === null) {
            throw new RuntimeException('That person is not in the group.');
        }
        if ($row->role === $role) {
            return;
        }

        DB::transaction(static function () use ($row, $role, $group, $me): void {
            // Handing the group on: the old owner stays on as a moderator.
            if ($role === Vocabulary::OWNER) {
                CommunityGroupMember::query()->where('group_id', $group->id)->where('user_id', $me)->update(['role' => Vocabulary::MODERATOR, 'updated_at' => now()]);
            }
            $row->role = $role;
            $row->save();
        });

        $name = app(People::class)->card($actor, $userId)->name;
        $what = match ($role) {
            Vocabulary::OWNER => "{$group->name} handed to {$name}",
            Vocabulary::MODERATOR => "{$name} made a moderator of {$group->name}",
            default => "{$name} is no longer a moderator of {$group->name}",
        };

        if ($role !== Vocabulary::MEMBER) {
            Notifier::account($userId, 'COMMUNITY_GROUP_ROLE', $role === Vocabulary::OWNER
                ? $actor->fullName.' made you the owner of '.$group->name
                : $actor->fullName.' made you a moderator of '.$group->name, null, '/community?group='.$group->id);
        }

        AuditService::record($actor, AuditEvent::COMMUNITY_GROUP_ROLE_CHANGED, 'community_groups', $group->id, $what);
        Realtime::announce([Realtime::community()]);
    }
}
