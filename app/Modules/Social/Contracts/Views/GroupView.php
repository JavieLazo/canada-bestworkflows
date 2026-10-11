<?php

declare(strict_types=1);

namespace App\Modules\Social\Contracts\Views;

/**
 * A Community group, and where the viewer stands in it.
 *
 * `membership` is NONE, MEMBER or REQUESTED, and `role` is the viewer's role
 * once they're in (OWNER, MODERATOR or MEMBER). `canManage` means they run
 * the group: they edit it, answer its requests and look after its members.
 * `canDelete` is company-wide moderators, who can take a group down without
 * being in it. `requests` is how many people are waiting, and it's only
 * counted for someone who runs the group. `photo` is the group photo's
 * address, or null for the plain icon.
 */
final readonly class GroupView
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public bool $private,
        public int $members,
        public string $membership,
        public bool $canManage = false,
        public bool $canDelete = false,
        public int $requests = 0,
        public ?string $role = null,
        public ?string $photo = null,
    ) {}

    public function joined(): bool
    {
        return $this->membership === 'MEMBER';
    }

    public function requested(): bool
    {
        return $this->membership === 'REQUESTED';
    }

    public function owned(): bool
    {
        return $this->role === 'OWNER';
    }
}
