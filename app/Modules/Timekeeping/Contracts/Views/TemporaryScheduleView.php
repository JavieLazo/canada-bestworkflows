<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

/**
 * One temporary schedule — a date or a From–To period — as the Shift Roster
 * lists it: the dates it was saved for, the dates it actually applies to
 * once changed or cancelled, what it sets, and who did what.
 * Detached: no model, no lazy relation.
 */
final readonly class TemporaryScheduleView
{
    public function __construct(
        public string $id,
        public string $employee_id,
        /** 'Y-m-d', the first date it was saved for. */
        public string $from,
        /** 'Y-m-d', the last date it was saved for. */
        public string $to,
        /** 'Y-m-d', the last date it applies; before `from` when it never applied. */
        public string $applies_through,
        /** UPCOMING, ACTIVE, ENDED or CANCELLED (never applied). */
        public string $state,
        /** The shift's name, "Rest day", or null when it follows the weekly shift. */
        public ?string $shift,
        /** Its hours, "10:00 PM–6:00 AM", or null for a rest day or the weekly shift. */
        public ?string $hours,
        /** "Work from home", a site's name, or null when it follows the weekly location. */
        public ?string $place,
        public bool $works_rest_days,
        /** The select values that reproduce it in the dialog. */
        public string $shift_choice,
        public string $location_choice,
        public ?string $made_by,
        public ?string $made_at,
        /** Who cut it short or cancelled it, and when; null while it stands as saved. */
        public ?string $cancelled_by,
        public ?string $cancelled_at,
        public ?string $revision_of,
        public bool $can_change,
    ) {}
}
