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
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Lets the author change their own post: its text, its tag, and its photos
 * and files. Who it's shared with stays as it was, and the card says
 * "Edited" from then on. The version it replaces stays on the post, so
 * anyone who can see the post can look back at every version. A photo or
 * file taken off stays too, for that history, and only deleting the whole
 * post clears them. One added back later is the same one again, not a copy.
 * Only the people an edit newly mentions are told, so fixing a typo never
 * rings anyone twice. A save that lands on a version other than the one it
 * started from is refused, so two saves at once never drop a version from
 * the history. Not audited, the same as deleting your own post.
 */
final class EditPost
{
    /**
     * @param  string|null  $tag  the tag code the post should have, or null for none
     * @param  list<string>  $remove  ids of the post's photos and files to take off
     * @param  list<UploadedFile>  $add
     */
    public function handle(Access $actor, string $postId, string $body, ?string $tag, array $remove = [], array $add = []): void
    {
        $me = SocialRules::community($actor);
        $text = SocialRules::text($body, SocialRules::MAX_POST, 'A post');
        $post = CommunityScope::post($me, $postId);

        if ($post === null) {
            throw new RuntimeException('That post was not found.');
        }
        // Nobody else edits a post, moderators included, so the words on a
        // post are always its author's own.
        if ($post->author_id !== $me) {
            throw new RuntimeException('You can only edit your own posts.');
        }
        // Editing is taking part, like commenting: an author who left the
        // group, or was removed from it, can still delete the post but no
        // longer reword it, re-mention people or add files to it.
        if (! CommunityScope::canInteract($me, $post)) {
            throw new RuntimeException('Join the group to edit your posts in it.');
        }
        if ($tag !== $post->tag && $tag !== null && ! CommunityTag::query()->where('code', $tag)->exists()) {
            throw new RuntimeException('Pick one of the listed tags.');
        }

        // Only what's on the post now can come off it, whatever ids were sent.
        $positions = CommunityAttachment::query()
            ->where('post_id', $post->id)->whereNull('comment_id')->whereNull('removed_at')
            ->orderBy('position')->orderBy('id')->pluck('position', 'id')->all();
        $current = array_map('strval', array_keys($positions));
        $removed = array_values(array_intersect($current, $remove));
        $left = count($current) - count($removed) + count($add);

        if ($left > SocialRules::MAX_ATTACHMENTS) {
            throw new RuntimeException('A post holds up to '.SocialRules::MAX_ATTACHMENTS.' photos and files.');
        }
        if ($text === '' && $left === 0) {
            throw new RuntimeException('Write something, or delete the post instead.');
        }

        // A photo or file the post had before, added again, comes back instead
        // of being stored twice. Matched by its bytes, since names repeat.
        $before = [];
        if ($add !== []) {
            $rows = CommunityAttachment::query()->where('post_id', $post->id)->whereNull('comment_id')
                ->where(static fn ($q) => $q->whereNotNull('removed_at')->orWhereIn('id', $removed))
                ->get(['id', 'path']);
            foreach ($rows as $row) {
                $hash = Attachments::fingerprint($row->path);
                if ($hash !== null) {
                    $before[$hash] ??= $row->id;
                }
            }
        }
        // In the order they were picked: an id to bring back, or a file to store.
        $adding = [];
        foreach ($add as $file) {
            $hash = (string) hash_file('sha256', (string) $file->getRealPath());
            $adding[] = $before[$hash] ?? $file;
            unset($before[$hash]);
        }
        Attachments::check(array_values(array_filter($adding, static fn (string|UploadedFile $item): bool => $item instanceof UploadedFile)));

        $text = Mentions::tokenize($actor, $text, $post->group_id);
        if ($text === $post->body && $tag === $post->tag && $removed === [] && $add === []) {
            return;
        }

        // Everyone the first version reached was told when it went up.
        $told = array_keys(Mentions::reach($actor, $post->body, $post->group_id));
        $now = CarbonImmutable::now()->utc();

        $stored = [];
        try {
            DB::transaction(static function () use ($post, $text, $tag, $positions, $current, $removed, $adding, $now, &$stored): void {
                // Everything above was worked out from the version read
                // first. Another save of this post since then would lose its
                // place in the history, so this one is refused instead.
                $locked = CommunityPost::query()->whereKey($post->id)->lockForUpdate()->first();
                if ($locked === null) {
                    throw new RuntimeException('That post was not found.');
                }
                if ($locked->body !== $post->body || $locked->tag !== $post->tag
                    || $locked->edited_at?->format('Y-m-d H:i:s.v') !== $post->edited_at?->format('Y-m-d H:i:s.v')) {
                    throw new RuntimeException('This post changed while you were editing it. Please try again.');
                }

                $locked->earlier_versions = [...($locked->earlier_versions ?? []), [
                    'body' => $locked->body,
                    'at' => ($locked->edited_at ?? $locked->created_at)->format('Y-m-d H:i:s.v'),
                    'tag' => $locked->tag,
                    'attachments' => $current,
                ]];
                $locked->body = $text;
                $locked->tag = $tag;
                $locked->edited_at = $now;
                $locked->save();

                if ($removed !== []) {
                    CommunityAttachment::query()->whereIn('id', $removed)->update(['removed_at' => $now->format('Y-m-d H:i:s.v')]);
                }

                // The post's photos and files are numbered 0 to n-1 in the
                // order they show: the ones that stay, as they were, then the
                // ones added or brought back, as they were picked. So the
                // numbers never outgrow the ten a post can hold. A row taken
                // off keeps its old number, which nothing goes by: every
                // earlier version lists its own ids in order.
                $position = 0;
                foreach (array_diff($current, $removed) as $id) {
                    if ((int) $positions[$id] !== $position) {
                        CommunityAttachment::query()->whereKey($id)->update(['position' => $position]);
                    }
                    $position++;
                }
                foreach ($adding as $item) {
                    if (is_string($item)) {
                        CommunityAttachment::query()->whereKey($item)->update(['removed_at' => null, 'position' => $position]);
                    } else {
                        Attachments::store($item, 'community', static function (array $saved) use ($post, $position): CommunityAttachment {
                            $row = new CommunityAttachment;
                            $row->fill(['post_id' => $post->id, 'position' => $position] + $saved);
                            $row->save();

                            return $row;
                        }, $stored);
                    }
                    $position++;
                }
            });
        } catch (Throwable $error) {
            Attachments::discard($stored);
            throw $error;
        }

        $names = app(People::class)->mentionable($actor) + [$me => $actor->fullName];
        $preview = SocialRules::preview(SocialFormat::plain($text, $names));
        Mentions::tell($actor, $text, $post->group_id, 'a post', $preview, '/community?post='.$post->id, $told);

        Realtime::announce([Realtime::community()]);
    }
}
