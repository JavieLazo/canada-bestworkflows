<?php

declare(strict_types=1);

namespace App\Modules\Social\Contracts\Views;

use Carbon\CarbonImmutable;

/**
 * A conversation as the Chat list draws it, from the viewer's side. `photo`
 * is a group chat's own photo, and `admin` is whether the viewer is one of
 * its admins. `allowMentions` is on when they muted it but still want to
 * hear mentions, and `unreadMentions` counts the unread messages that
 * mention them (by name or @everyone).
 */
final readonly class ConversationView
{
    /** @param list<PersonView> $others everyone in it but the viewer */
    public function __construct(
        public string $id,
        public string $kind,
        public string $title,
        public array $others,
        public bool $muted,
        public int $unread,
        public string $preview,
        public ?CarbonImmutable $lastAt,
        public ?string $photo = null,
        public bool $admin = false,
        public bool $allowMentions = false,
        public int $unreadMentions = 0,
    ) {}

    /** What this chat adds to the sidebar badge: every unread message, only the unread mentions, or nothing, by how it's muted. */
    public function badge(): int
    {
        return match (true) {
            ! $this->muted => $this->unread,
            $this->allowMentions => $this->unreadMentions,
            default => 0,
        };
    }

    public function isGroup(): bool
    {
        return $this->kind === 'GROUP';
    }

    /** The other person in a direct chat. */
    public function other(): ?PersonView
    {
        return $this->kind === 'DIRECT' ? ($this->others[0] ?? null) : null;
    }
}
