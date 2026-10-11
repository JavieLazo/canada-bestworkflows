<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Models\CommunityAttachment;
use App\Modules\Social\Application\Attachments;
use App\Modules\Social\Application\CommunityScope;
use App\Modules\Social\Application\SocialRules;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deletes a post with its photos, files, comments and reactions. ONLY ITS
 * AUTHOR MAY — nobody else deletes a post, company-wide moderators
 * included; someone else's goes only with its whole group (DeleteGroup).
 * Deleting one's own post is not audited.
 */
final class RemovePost
{
    public function handle(Access $actor, string $postId): void
    {
        $me = SocialRules::community($actor);
        $post = CommunityScope::post($me, $postId);

        if ($post === null) {
            throw new RuntimeException('That post was not found.');
        }

        if ($post->author_id !== $me) {
            throw new RuntimeException('You can only delete your own posts.');
        }

        $paths = array_values(array_map('strval', CommunityAttachment::query()->where('post_id', $post->id)->pluck('path')->all()));

        // The rows go in one transaction; the files only once it has
        // committed, so a failed delete never strands a post without its files.
        DB::transaction(static fn () => $post->delete());
        Attachments::discard($paths);

        Realtime::announce([Realtime::community()]);
    }
}
