<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application;

/** Untrusted editor input; validation remains inside the owning operation. */
final readonly class SiteDraft
{
    public function __construct(public string $formName,
        public string $formLatitude,
        public string $formLongitude,
        public string $formRadius,
        public bool $formExempt) {}
}
