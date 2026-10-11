<?php

declare(strict_types=1);

namespace App\Modules\Social\Contracts;

use Carbon\CarbonImmutable;

/**
 * What the Community feed is asked to show. Every part narrows the feed
 * further, so a search, a tag and a date range all apply together.
 *
 * `groupId` is the group being looked at, or the group picked in the filters
 * while looking at the main feed. `has` is `photos`, `files`, `both` or ''.
 * `mentions` keeps posts that mention the viewer, in the post or in one of
 * its comments. `sort` is `newest`, `oldest`, `discussed` or `reactions`.
 */
final readonly class FeedFilter
{
    public const array SORTS = ['newest', 'oldest', 'discussed', 'reactions'];

    public const array HAS = ['photos', 'files', 'both'];

    public function __construct(
        public string $search = '',
        public ?string $groupId = null,
        public ?string $tag = null,
        public ?int $authorId = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public string $has = '',
        public bool $mentions = false,
        public string $sort = 'newest',
    ) {}

    /** Whether anything narrows the main feed, so pins step aside and it reads in plain date order. */
    public function narrows(): bool
    {
        return trim($this->search) !== '' || $this->tag !== null || $this->authorId !== null
            || $this->from !== null || $this->to !== null || $this->has !== '' || $this->mentions;
    }
}
