<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Models\CommunityGroup;
use App\Models\CommunityGroupMember;
use App\Modules\Social\Application\Groups;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Joining and leaving Community groups. A public group lets anyone in; a
 * private one records a request, and the people who run it are told there's
 * one waiting.
 *
 * The owner can't just walk out of a group other people are still in:
 * someone has to be left running it, so they hand it on first.
 */
final class ChangeMembership
{
    /** @return string MEMBER, or REQUESTED for a private group */
    public function join(Access $actor, string $groupId): string
    {
        $me = SocialRules::community($actor);
        $group = CommunityGroup::query()->find($groupId);

        if ($group === null) {
            throw new RuntimeException('That group was not found.');
        }

        $existing = CommunityGroupMember::query()->where('group_id', $group->id)->where('user_id', $me)->value('status');
        if (is_string($existing)) {
            return $existing;
        }

        $status = $group->is_private ? Vocabulary::REQUESTED : Vocabulary::MEMBER;
        $row = new CommunityGroupMember;
        $row->fill(['group_id' => $group->id, 'status' => $status]);
        $row->user_id = $me;
        $row->role = Vocabulary::MEMBER;
        try {
            $row->save();
        } catch (UniqueConstraintViolationException) {
            // Joined from another tab at the same moment; that row stands.
            return $status;
        }

        if ($status === Vocabulary::REQUESTED) {
            Groups::tellRunners($group, $actor->fullName.' asked to join '.$group->name);
        }

        Realtime::announce([Realtime::community()]);

        return $status;
    }

    /** Leaves a group, or withdraws a request to join one. */
    public function leave(Access $actor, string $groupId): void
    {
        $me = SocialRules::community($actor);
        $row = CommunityGroupMember::query()->where('group_id', $groupId)->where('user_id', $me)->first();

        if ($row === null) {
            return;
        }

        if ($row->role === Vocabulary::OWNER && CommunityGroupMember::query()->where('group_id', $groupId)->where('status', Vocabulary::MEMBER)->where('user_id', '!=', $me)->exists()) {
            throw new RuntimeException('You own this group. Make someone else the owner first, from the members list.');
        }

        $row->delete();

        Realtime::announce([Realtime::community()]);
    }
}
