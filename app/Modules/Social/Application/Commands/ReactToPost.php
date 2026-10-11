<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Models\CommunityReaction;
use App\Modules\Social\Application\CommunityScope;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * One reaction per person per post: the same one again takes it back, a
 * different one replaces it.
 */
final class ReactToPost
{
    /** @return string|null the reaction left on the post, null when taken back */
    public function handle(Access $actor, string $postId, string $type): ?string
    {
        $me = SocialRules::community($actor);

        if (! Vocabulary::isReaction($type)) {
            throw new RuntimeException('That reaction is not available.');
        }

        $post = CommunityScope::post($me, $postId);
        if ($post === null) {
            throw new RuntimeException('That post was not found.');
        }
        if (! CommunityScope::canInteract($me, $post)) {
            throw new RuntimeException('Join the group to react to its posts.');
        }

        $existing = CommunityReaction::query()->where('post_id', $post->id)->where('user_id', $me)->first();

        if ($existing !== null && $existing->type === $type) {
            $existing->delete();
            $result = null;
        } elseif ($existing !== null) {
            $existing->type = $type;
            $existing->save();
            $result = $type;
        } else {
            $row = new CommunityReaction;
            $row->fill(['post_id' => $post->id, 'type' => $type]);
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
