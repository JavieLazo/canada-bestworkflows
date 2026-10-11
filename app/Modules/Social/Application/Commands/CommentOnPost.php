<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Domain\Notifications\Notifier;
use App\Models\CommunityAttachment;
use App\Models\CommunityComment;
use App\Modules\Social\Application\Attachments;
use App\Modules\Social\Application\CommunityScope;
use App\Modules\Social\Application\Mentions;
use App\Modules\Social\Application\People;
use App\Modules\Social\Application\SocialRules;
use App\Support\Access;
use App\Support\Realtime;
use App\Support\SocialFormat;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * A comment on a post, or a reply to a comment, with up to ten photos and
 * files. Threads go three levels deep (a comment, a reply, and a reply to
 * that), so a thread never narrows into a column too thin to read on a
 * phone. Replying at the deepest level still works: the reply lands beside
 * the comment it answers, and the box starts by mentioning whoever wrote it,
 * so it's clear who it's for.
 *
 * Tells, once each and never themself: the author of the comment being
 * answered, the post's author, and everyone the @mentions reach, as long as
 * they can read the post. Each gets the most specific line that fits:
 *
 * - the comment's author: "mentioned you in a reply to your comment", or
 *   "replied to your comment" when the reply doesn't name them;
 * - the post's author: "mentioned you in a comment (or reply) on your
 *   post", "replied to a comment on your post", or "commented on your post";
 * - anyone else it names: "mentioned you (a group, everyone) in a comment"
 *   or "in a reply".
 *
 * The link opens the post with this comment highlighted.
 */
final class CommentOnPost
{
    /**
     * @param  list<UploadedFile>  $attachments
     * @return string the new comment's id
     */
    public function handle(Access $actor, string $postId, ?string $parentId, string $body, array $attachments = []): string
    {
        $me = SocialRules::community($actor);
        $text = SocialRules::text($body, SocialRules::MAX_COMMENT, 'A comment');

        if ($text === '' && $attachments === []) {
            throw new RuntimeException('Write something or add a photo or file first.');
        }

        $post = CommunityScope::post($me, $postId);
        if ($post === null) {
            throw new RuntimeException('That post was not found.');
        }
        if (! CommunityScope::canInteract($me, $post)) {
            throw new RuntimeException('Join the group to comment on its posts.');
        }
        Attachments::check($attachments);

        // The comment the writer pressed Reply on, and the one the reply sits
        // under. They're the same until the thread is as deep as it goes.
        $answering = null;
        $parent = null;
        if ($parentId !== null) {
            $answering = CommunityComment::query()->where('post_id', $post->id)->whereKey($parentId)->first();
            if ($answering === null) {
                throw new RuntimeException('That comment was not found.');
            }
            $parent = $answering;
            while ($parent !== null && self::depth($parent) >= SocialRules::MAX_COMMENT_DEPTH) {
                $parent = $parent->parent_id !== null ? CommunityComment::query()->find($parent->parent_id) : null;
            }
        }

        $text = Mentions::tokenize($actor, $text, $post->group_id);

        $stored = [];
        try {
            $comment = DB::transaction(static function () use ($me, $post, $parent, $text, $attachments, &$stored): CommunityComment {
                $comment = new CommunityComment;
                $comment->fill(['post_id' => $post->id, 'parent_id' => $parent?->id, 'body' => $text]);
                $comment->author_id = $me;
                $comment->save();

                foreach ($attachments as $position => $file) {
                    Attachments::store($file, 'community', static function (array $saved) use ($post, $comment, $position): CommunityAttachment {
                        $row = new CommunityAttachment;
                        $row->fill(['post_id' => $post->id, 'comment_id' => $comment->id, 'position' => $position] + $saved);
                        $row->save();

                        return $row;
                    }, $stored);
                }

                return $comment;
            });
        } catch (Throwable $error) {
            Attachments::discard($stored);
            throw $error;
        }

        $names = app(People::class)->mentionable($actor) + [$me => $actor->fullName];
        $preview = SocialRules::preview(SocialFormat::plain($text, $names));
        $href = '/community?post='.$post->id.'&comment='.$comment->id;
        $postAuthor = $post->author_id;
        $answered = $answering?->author_id;
        $isReply = $answering !== null;
        $who = $actor->fullName;

        $readers = CommunityScope::audience($actor, array_values(array_filter(
            array_unique([$postAuthor ?? 0, $answered ?? 0]),
            static fn (int $id): bool => $id > 0 && $id !== $me,
        )), $post->group_id);
        $reach = Mentions::reach($actor, $text, $post->group_id);
        $named = static fn (int $id): bool => ($reach[$id] ?? null) === Mentions::PERSON;

        $told = [];
        if ($answered !== null && in_array($answered, $readers, true)) {
            $title = $named($answered) ? $who.' mentioned you in a reply to your comment' : $who.' replied to your comment';
            Notifier::account($answered, 'COMMUNITY_REPLY', $title, $preview, $href);
            $told[] = $answered;
        }
        if ($postAuthor !== null && in_array($postAuthor, $readers, true) && ! in_array($postAuthor, $told, true)) {
            $title = match (true) {
                $named($postAuthor) => $who.' mentioned you in '.($isReply ? 'a reply' : 'a comment').' on your post',
                $isReply => $who.' replied to a comment on your post',
                default => $who.' commented on your post',
            };
            Notifier::account($postAuthor, 'COMMUNITY_COMMENT', $title, $preview, $href);
            $told[] = $postAuthor;
        }
        Mentions::tell($actor, $text, $post->group_id, $isReply ? 'a reply' : 'a comment', $preview, $href, $told);

        Realtime::announce([Realtime::community()]);

        return $comment->id;
    }

    /** How deep a comment sits: 0 on the post itself, 1 for a reply, and so on. */
    private static function depth(CommunityComment $comment): int
    {
        $depth = 0;
        $parentId = $comment->parent_id;

        // Capped, so a broken chain in the data can't loop forever.
        while ($parentId !== null && $depth <= SocialRules::MAX_COMMENT_DEPTH) {
            $depth++;
            $parentId = CommunityComment::query()->whereKey($parentId)->value('parent_id');
        }

        return $depth;
    }
}
