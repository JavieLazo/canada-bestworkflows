<?php

declare(strict_types=1);

namespace App\Modules\Social\Contracts\Views;

use Carbon\CarbonImmutable;

/**
 * One line in the "Coming up" card: a work anniversary, or a birthday for
 * someone who switched theirs on in My Profile.
 *
 * `years` counts the years completed, and is null for a birthday, because we
 * never show an age. `mention` is the name to @mention in a greeting: the
 * person's account name, or null when they have no account that can see
 * Community.
 */
final readonly class CelebrationView
{
    public const string ANNIVERSARY = 'ANNIVERSARY';

    public const string BIRTHDAY = 'BIRTHDAY';

    public function __construct(
        public string $name,
        public ?string $position,
        public ?string $photo,
        public CarbonImmutable $date,
        public ?int $years,
        public ?string $mention,
        public string $kind = self::ANNIVERSARY,
    ) {}

    public function isBirthday(): bool
    {
        return $this->kind === self::BIRTHDAY;
    }
}
