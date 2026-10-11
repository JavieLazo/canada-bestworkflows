<?php

declare(strict_types=1);

namespace App\Modules\Social\Contracts\Views;

/**
 * Someone as Community and Chat draw them: the account's name, and the
 * position and portrait of the employee record it is linked to, if any.
 */
final readonly class PersonView
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $position,
        public ?string $photo,
        public bool $active = true,
    ) {}

    /** An account that no longer resolves, so an old post still has an author line. */
    public static function missing(int $id): self
    {
        return new self($id, 'Former member', null, null, false);
    }
}
