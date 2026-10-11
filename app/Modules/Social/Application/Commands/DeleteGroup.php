<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\CommunityAttachment;
use App\Models\CommunityGroup;
use App\Models\CommunityPost;
use App\Modules\Social\Application\Attachments;
use App\Modules\Social\Application\SocialRules;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deletes a group, with every post in it (and their photos, files, comments
 * and reactions), its photo, and everyone's membership. Company-wide
 * moderators only, and audited, since it takes other people's posts with it.
 */
final class DeleteGroup
{
    public function handle(Access $actor, string $groupId): void
    {
        SocialRules::moderator($actor, 'Only moderators can delete groups.');

        $group = CommunityGroup::query()->find($groupId);
        if ($group === null) {
            throw new RuntimeException('That group was not found.');
        }

        $postIds = CommunityPost::query()->where('group_id', $group->id)->pluck('id')->all();
        $paths = array_values(array_map('strval', CommunityAttachment::query()->whereIn('post_id', $postIds)->pluck('path')->all()));
        if ($group->photo_path !== null) {
            $paths[] = $group->photo_path;
        }

        // The database takes the posts, their threads and the memberships
        // with the group. The files go only once that has committed, so a
        // failed delete never strands a post without its photos.
        DB::transaction(static fn () => $group->delete());
        Attachments::discard($paths);

        $count = count($postIds);
        AuditService::record($actor, AuditEvent::COMMUNITY_GROUP_DELETED, 'community_groups', $group->id, "Group {$group->name} deleted with {$count} ".($count === 1 ? 'post' : 'posts'));
        Realtime::announce([Realtime::community()]);
    }
}
