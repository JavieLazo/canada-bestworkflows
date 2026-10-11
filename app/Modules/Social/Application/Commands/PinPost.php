<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\CommunityPost;
use App\Modules\Social\Application\CommunityScope;
use App\Modules\Social\Application\People;
use App\Modules\Social\Application\SocialRules;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\Realtime;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Pins a post above the main feed, or unpins it. Moderators only, posts to
 * Everyone only (a group's post has no main-feed slot to lift it into), and
 * at most three at a time. Audited either way.
 */
final class PinPost
{
    public function handle(Access $actor, string $postId, bool $pin): void
    {
        $me = SocialRules::community($actor);

        if (! SocialRules::moderates($actor)) {
            throw new RuntimeException('Only a moderator can pin posts.');
        }

        $post = CommunityScope::post($me, $postId);
        if ($post === null) {
            throw new RuntimeException('That post was not found.');
        }
        if ($pin === ($post->pinned_at !== null)) {
            return;
        }

        if ($pin) {
            if ($post->group_id !== null) {
                throw new RuntimeException('Only a post shared with Everyone can be pinned to the main feed.');
            }
            if (CommunityPost::query()->whereNotNull('pinned_at')->whereNull('group_id')->count() >= SocialRules::MAX_PINNED) {
                throw new RuntimeException('The feed holds '.SocialRules::MAX_PINNED.' pinned posts. Unpin one first.');
            }
            $post->pinned_at = CarbonImmutable::now();
            $post->pinned_by = $me;
        } else {
            $post->pinned_at = null;
            $post->pinned_by = null;
        }
        $post->save();

        $authorName = $post->author_id !== null ? app(People::class)->card($actor, $post->author_id)->name : 'a former member';
        AuditService::record(
            $actor,
            $pin ? AuditEvent::COMMUNITY_POST_PINNED : AuditEvent::COMMUNITY_POST_UNPINNED,
            'community_posts',
            $post->id,
            ($pin ? 'Pinned' : 'Unpinned')." a post by {$authorName}",
        );

        Realtime::announce([Realtime::community()]);
    }
}
