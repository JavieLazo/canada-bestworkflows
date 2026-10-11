<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\CommunityPost;
use App\Models\CommunityTag;
use App\Modules\Social\Application\SocialRules;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes a tag. Moderators only. The posts that carried it stay, untagged.
 * Audited.
 */
final class DeleteTag
{
    public function handle(Access $actor, string $tagId): void
    {
        SocialRules::moderator($actor, 'Only moderators can manage tags.');

        $tag = CommunityTag::query()->find($tagId);
        if ($tag === null) {
            throw new RuntimeException('That tag was not found.');
        }

        $untagged = DB::transaction(static function () use ($tag): int {
            $count = CommunityPost::query()->where('tag', $tag->code)->update(['tag' => null]);
            $tag->delete();

            return $count;
        });

        AuditService::record($actor, AuditEvent::COMMUNITY_TAG_DELETED, 'community_tags', $tag->id, "Tag {$tag->name} removed, {$untagged} ".($untagged === 1 ? 'post' : 'posts').' untagged');
        Realtime::announce([Realtime::community()]);
    }
}
