<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

/**
 * One dated change to an employee's default shift or weekly schedule, as the
 * Shift Roster's history lists it: when it takes effect, what it sets, who
 * made it, and whether it was cancelled. Detached: no model, no lazy
 * relation.
 */
final readonly class ScheduleChangeView
{
    /**
     * @param  list<string>  $sets  what it sets, as sentences: "Default shift: 8:00 AM–5:00 PM", "Tue: Work from home"
     */
    public function __construct(
        public string $id,
        public string $employee_id,
        /** 'Y-m-d'. */
        public string $effective_from,
        /** MANUAL | IMPORT | PATTERN_EDIT. */
        public string $source,
        /** UPCOMING (not yet in effect), IN_EFFECT or CANCELLED. */
        public string $state,
        public array $sets,
        public ?string $made_by,
        /** Manila wall-clock 'M d, Y, g:i A'. */
        public ?string $made_at,
        public ?string $cancelled_by,
        public ?string $cancelled_at,
        /** Whether it can still be cancelled: made by hand or import, and not yet in effect. */
        public bool $can_cancel,
    ) {}
}
