<?php

declare(strict_types=1);

namespace App\Modules\Social\Contracts\Views;

use Carbon\CarbonImmutable;

/**
 * One post with everything the card draws: author, audience, photos and
 * files, reactions, the thread, and what the viewer may do with it.
 * `canDelete` is for its author ALONE — a company-wide moderator pins posts
 * (`canPin`) but is never offered the delete on someone else's.
 */
final readonly class PostView
{
    /**
     * @param  list<string>  $photos  photo ids, in order
     * @param  array<string, list<int>>  $reactions  reaction type => account ids, types nobody picked left out
     * @param  list<CommentView>  $comments  top-level comments, oldest first, each with its replies
     * @param  list<FileView>  $files  in order
     */
    public function __construct(
        public string $id,
        public PersonView $author,
        public ?string $groupId,
        public ?string $groupName,
        public ?string $tag,
        public string $body,
        public array $photos,
        public array $reactions,
        public ?string $mine,
        public array $comments,
        public int $commentCount,
        public bool $pinned,
        public CarbonImmutable $createdAt,
        public bool $canDelete,
        public bool $canPin,
        public bool $canInteract,
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
}
