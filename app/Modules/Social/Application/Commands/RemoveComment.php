<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\CommunityAttachment;
use App\Models\CommunityComment;
use App\Modules\Social\Application\Attachments;
use App\Modules\Social\Application\CommunityScope;
use App\Modules\Social\Application\People;
use App\Modules\Social\Application\SocialRules;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deletes a comment, and its replies go with it, the way deleting a post
 * takes its comments. Their photos, files and reactions go too. An author may delete their own; a moderator may delete
 * anyone's, and that is audited.
 */
final class RemoveComment
{
    public function handle(Access $actor, string $commentId): void
    {
        $me = SocialRules::community($actor);
        $comment = CommunityComment::query()->find($commentId);

        // One answer for a comment that's gone and one on a post out of reach.
        if ($comment === null || CommunityScope::post($me, $comment->post_id) === null) {
            throw new RuntimeException('That comment was not found.');
        }

        $own = $comment->author_id === $me;
        if (! $own && ! SocialRules::moderates($actor)) {
            throw new RuntimeException('You can only delete your own comments.');
        }

        // The comment and every reply under it. The database drops the rows
        // on its own; the files on disk are ours to clear.
        $ids = [$comment->id];
        $level = [$comment->id];
        while ($level !== []) {
            $level = array_values(array_map('strval', CommunityComment::query()->whereIn('parent_id', $level)->pluck('id')->all()));
            array_push($ids, ...$level);
        }
        $paths = array_values(array_map('strval', CommunityAttachment::query()->whereIn('comment_id', $ids)->pluck('path')->all()));
        $authorName = $comment->author_id !== null ? app(People::class)->card($actor, $comment->author_id)->name : 'a former member';

        // Files only once the rows are gone, so a failed delete never
        // leaves a comment pointing at nothing.
        DB::transaction(static fn () => $comment->delete());
        Attachments::discard($paths);

        if (! $own) {
            AuditService::record($actor, AuditEvent::COMMUNITY_COMMENT_REMOVED, 'community_comments', $comment->id, "Comment by {$authorName} removed from Community");
        }

        Realtime::announce([Realtime::community()]);
    }
}
