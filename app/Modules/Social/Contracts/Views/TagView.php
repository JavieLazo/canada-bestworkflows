<?php

declare(strict_types=1);

namespace App\Modules\Social\Contracts\Views;

/**
 * A tag, as the tag menu, the posts and the tag manager draw it. `posts` is
 * how many posts carry it, for the warning before a delete.
 */
final readonly class TagView
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $tone,
        public int $posts = 0,
    ) {}
}
