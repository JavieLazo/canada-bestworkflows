<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application;

/** Untrusted editor input; validation remains inside the owning operation. */
final readonly class HolidayDraft
{
    public function __construct(public string $formName,
        public string $formDate,
        public string $formSchedule,
        public string $formType,
        /**
         * The whole day's rate, not a premium on top of it: a regular holiday that
         * is worked pays double. 100 would pay an ordinary day's wage.
         */
        public string $formPercent) {}
}
