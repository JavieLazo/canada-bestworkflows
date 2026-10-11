<?php

declare(strict_types=1);

namespace App\Modules\Social\Contracts\Views;

/** What one person chose to show about themselves in Community and Chat. */
final readonly class PrivacyView
{
    public function __construct(
        public bool $showPosition = true,
        public bool $showBirthday = false,
        public bool $showAnniversary = false,
    ) {}
}
