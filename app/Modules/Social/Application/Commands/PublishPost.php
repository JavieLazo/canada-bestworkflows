<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Models\CommunityAttachment;
use App\Models\CommunityPost;
use App\Models\CommunityTag;
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
 * A new post, to Everyone or to one group the author is in, with up to ten
 * photos and files. Typed @mentions (people, groups, @everyone) are stored as
 * tokens, and everyone they reach who can read the post is told.
 */
final class PublishPost
{
    /**
     * @param  list<UploadedFile>  $attachments
     * @return string the new post's id
     */
    public function handle(Access $actor, ?string $groupId, ?string $tag, string $body, array $attachments = []): string
    {
        $me = SocialRules::community($actor);
        $text = SocialRules::text($body, SocialRules::MAX_POST, 'A post');

        if ($text === '' && $attachments === []) {
            throw new RuntimeException('Write something or add a photo or file first.');
        }
        if ($tag !== null && ! CommunityTag::query()->where('code', $tag)->exists()) {
            throw new RuntimeException('Pick one of the listed tags.');
        }
        // Only a group the author is in. A group id the client made up, or one
        // they have since left, is refused rather than quietly posted to
        // Everyone: the author chose an audience and should not be surprised.
        if ($groupId !== null && ! CommunityScope::isMember($me, $groupId)) {
            throw new RuntimeException('You can only post to a group you are in.');
        }
        Attachments::check($attachments);

        $text = Mentions::tokenize($actor, $text, $groupId);

        $stored = [];
        try {
            $post = DB::transaction(static function () use ($me, $groupId, $tag, $text, $attachments, &$stored): CommunityPost {
                $post = new CommunityPost;
                $post->fill(['group_id' => $groupId, 'tag' => $tag, 'body' => $text]);
                $post->author_id = $me;
                $post->save();

                foreach ($attachments as $position => $file) {
                    Attachments::store($file, 'community', static function (array $saved) use ($post, $position): CommunityAttachment {
                        $row = new CommunityAttachment;
                        $row->fill(['post_id' => $post->id, 'position' => $position] + $saved);
                        $row->save();

                        return $row;
                    }, $stored);
                }

                return $post;
            });
        } catch (Throwable $error) {
            Attachments::discard($stored);
            throw $error;
        }

        $names = app(People::class)->mentionable($actor) + [$me => $actor->fullName];
        $preview = SocialRules::preview(SocialFormat::plain($text, $names));
        Mentions::tell($actor, $text, $groupId, 'a post', $preview, '/community?post='.$post->id);

        Realtime::announce([Realtime::community()]);

        return $post->id;
    }
}
