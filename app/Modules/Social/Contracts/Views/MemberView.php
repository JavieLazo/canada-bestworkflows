<?php

declare(strict_types=1);

namespace App\Modules\Social\Contracts\Views;

use Carbon\CarbonImmutable;

/**
 * Someone in a group, or waiting to get in, as the members list draws them.
 * `role` is OWNER, MODERATOR or MEMBER. `addedBy` is who put them forward,
 * for a request someone else made on their behalf.
 */
final readonly class MemberView
{
    public function __construct(
        public PersonView $person,
        public string $role,
        public bool $requested,
        public ?PersonView $addedBy,
        public CarbonImmutable $since,
    ) {}

    public function isOwner(): bool
    {
        return $this->role === 'OWNER';
    }

    public function isModerator(): bool
    {
        return $this->role === 'MODERATOR';
    }
}
