<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

/** Untrusted editor input; validation remains inside the owning operation. */
final readonly class ShiftTypeDraft
{
    public function __construct(public string $formName,
        public string $formMode,
        /** @var list<int> */
        public array $formWorkDays,
        public string $formStartTime,
        public string $formEndTime,
        public string $formGrace,
        public string $formRequiredHours,
        public bool $formHasBreak,
        public string $formBreakStart,
        public string $formBreakEnd,
        public string $formBreakMinutes,
        public bool $formRequiresBreakPunch) {}
}
