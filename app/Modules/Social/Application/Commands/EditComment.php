<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Models\CommunityAttachment;
use App\Models\CommunityComment;
use App\Modules\Social\Application\CommunityScope;
use App\Modules\Social\Application\Mentions;
use App\Modules\Social\Application\People;
use App\Modules\Social\Application\SocialRules;
use App\Support\Access;
use App\Support\Realtime;
use App\Support\SocialFormat;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Lets the author rewrite their own comment or reply, the way EditPost does
 * for posts. Its photos, files and place in the thread stay as they were,
 * and it says "Edited" from then on. Only the people an edit newly mentions
 * are told. Not audited, the same as deleting your own comment.
 */
final class EditComment
{
    public function handle(Access $actor, string $commentId, string $body): void
    {
        $me = SocialRules::community($actor);
        $text = SocialRules::text($body, SocialRules::MAX_COMMENT, 'A comment');
        $comment = CommunityComment::query()->find($commentId);
        $post = $comment !== null ? CommunityScope::post($me, $comment->post_id) : null;

        // One answer for a comment that's gone and one on a post out of reach.
        if ($comment === null || $post === null) {
            throw new RuntimeException('That comment was not found.');
        }
        // Nobody else edits a comment, moderators included.
        if ($comment->author_id !== $me) {
            throw new RuntimeException('You can only edit your own comments.');
        }
        // Editing is taking part, the same as for a post.
        if (! CommunityScope::canInteract($me, $post)) {
            throw new RuntimeException('Join the group to edit your comments in it.');
        }
        if ($text === '' && ! CommunityAttachment::query()->where('comment_id', $comment->id)->exists()) {
            throw new RuntimeException('Write something, or delete the comment instead.');
        }

        $text = Mentions::tokenize($actor, $text, $post->group_id);
        if ($text === $comment->body) {
            return;
        }

        // Everyone the first version reached was told when it went up.
        $told = array_keys(Mentions::reach($actor, $comment->body, $post->group_id));

        $comment->body = $text;
        $comment->edited_at = CarbonImmutable::now()->utc();
        $comment->save();

        $names = app(People::class)->mentionable($actor) + [$me => $actor->fullName];
        $preview = SocialRules::preview(SocialFormat::plain($text, $names));
        $href = '/community?post='.$post->id.'&comment='.$comment->id;
        Mentions::tell($actor, $text, $post->group_id, $comment->parent_id === null ? 'a comment' : 'a reply', $preview, $href, $told);

        Realtime::announce([Realtime::community()]);
    }
}
