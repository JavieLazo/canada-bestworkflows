<?php

declare(strict_types=1);

namespace App\Modules\Social\Contracts\Views;

use Carbon\CarbonImmutable;

/**
 * A comment on a post, or a reply at any level under it. `depth` is 0 for a
 * comment, 1 for a reply, 2 for a reply to that, the deepest a thread goes.
 * Replying at the deepest level still works: the reply lands beside the
 * comment instead of under it (CommentOnPost). `canDelete` is for its author
 * and for company-wide moderators — wider than a post's, which is offered
 * to its author alone.
 */
final readonly class CommentView
{
    /**
     * @param  array<string, list<int>>  $reactions  reaction type => account ids
     * @param  string|null  $reaction  the viewer's own reaction, if any
     * @param  list<CommentView>  $replies  oldest first
     * @param  list<string>  $photos  photo ids, in order
     * @param  list<FileView>  $files  in order
     */
    public function __construct(
        public string $id,
        public PersonView $author,
        public string $body,
        public array $reactions,
        public ?string $reaction,
        public CarbonImmutable $createdAt,
        public bool $mine,
        public int $depth = 0,
        public bool $canDelete = false,
        public array $replies = [],
        public array $photos = [],
        public array $files = [],
    ) {}

    public function reactionTotal(): int
    {
        return array_sum(array_map('count', $this->reactions));
    }

    /** @return array<string, int> reaction type => how many */
    public function reactionCounts(): array
    {
        return array_map('count', $this->reactions);
    }

    /** Every reply under this one, at every level. */
    public function replyTotal(): int
    {
        return array_sum(array_map(static fn (self $reply): int => 1 + $reply->replyTotal(), $this->replies));
    }

    /** Whether a reply somewhere under this one, at any level, has this id. */
    public function holds(string $commentId): bool
    {
        foreach ($this->replies as $reply) {
            if ($reply->id === $commentId || $reply->holds($commentId)) {
                return true;
            }
        }

        return false;
    }
}
