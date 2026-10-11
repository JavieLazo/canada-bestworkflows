<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Models\CommunityComment;
use App\Models\CommunityCommentReaction;
use App\Modules\Social\Application\CommunityScope;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * One reaction per person per comment, from the same six as posts: the same
 * one again takes it back, a different one replaces it.
 */
final class ReactToComment
{
    /** @return string|null the reaction left on the comment, null when taken back */
    public function handle(Access $actor, string $commentId, string $type): ?string
    {
        $me = SocialRules::community($actor);

        if (! Vocabulary::isReaction($type)) {
            throw new RuntimeException('That reaction is not available.');
        }

        $comment = CommunityComment::query()->find($commentId);
        $post = $comment !== null ? CommunityScope::post($me, $comment->post_id) : null;

        if ($comment === null || $post === null) {
            throw new RuntimeException('That comment was not found.');
        }
        if (! CommunityScope::canInteract($me, $post)) {
            throw new RuntimeException('Join the group to take part in its threads.');
        }

        $existing = CommunityCommentReaction::query()->where('comment_id', $comment->id)->where('user_id', $me)->first();

        if ($existing !== null && $existing->type === $type) {
            $existing->delete();
            $result = null;
        } elseif ($existing !== null) {
            $existing->type = $type;
            $existing->save();
            $result = $type;
        } else {
            $row = new CommunityCommentReaction;
            $row->fill(['comment_id' => $comment->id, 'type' => $type]);
            $row->user_id = $me;
            try {
                $row->save();
            } catch (UniqueConstraintViolationException) {
                // A double click raced itself; the other request's reaction stands.
            }
            $result = $type;
        }

        Realtime::announce([Realtime::community()]);

        return $result;
    }
}
