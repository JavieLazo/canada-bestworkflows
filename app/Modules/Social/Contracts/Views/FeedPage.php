<?php

declare(strict_types=1);

namespace App\Modules\Social\Contracts\Views;

/**
 * One screenful of a feed. `names` resolves every @mention in these posts and
 * their threads, so the page never looks a name up per mention. `people` has
 * the card of everyone the page shows, reactors included, for the lists of
 * who reacted.
 */
final readonly class FeedPage
{
    /**
     * @param  list<PostView>  $pinned  shown above the chronology, main feed only
     * @param  list<PostView>  $posts
     * @param  array<int, string>  $names  account id => name
     * @param  array<int, PersonView>  $people  account id => card
     */
    public function __construct(
        public array $pinned,
        public array $posts,
        public bool $more,
        public array $names,
        public array $people = [],
    ) {}
}
