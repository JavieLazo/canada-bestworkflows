<?php

declare(strict_types=1);

namespace App\Modules\Social\Contracts\Views;

use Carbon\CarbonImmutable;

/** One message in a thread. `sender` is null on SYSTEM lines. Only the sender edits a message (`mine`), while it isn't removed. */
final readonly class MessageView
{
    /**
     * @param  list<string>  $photos  photo ids, in order
     * @param  array<string, list<int>>  $reactions  reaction type => account ids
     * @param  list<FileView>  $files  in order
     */
    public function __construct(
        public string $id,
        public ?PersonView $sender,
        public bool $system,
        public string $body,
        public array $photos,
        public ?QuoteView $quote,
        public array $reactions,
        public bool $removed,
        public bool $mine,
        public CarbonImmutable $createdAt,
        public array $files = [],
        public ?CarbonImmutable $editedAt = null,
    ) {}

    public function reactionTotal(): int
    {
        return array_sum(array_map('count', $this->reactions));
    }

    public function reactedWith(int $userId, string $type): bool
    {
        return in_array($userId, $this->reactions[$type] ?? [], true);
    }
}
