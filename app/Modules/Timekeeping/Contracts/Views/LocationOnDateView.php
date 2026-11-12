<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

/**
 * Where an employee works on ONE Manila calendar date, fallback applied —
 * what the punch station shows for today and the DTR correction dialog
 * shows for the corrected date, resolved through the per-date seam
 * (Infrastructure\ShiftRoster::locationForDate) so both read the same
 * answer RecordPunch and CorrectPunches write against.
 *
 * Typed properties rather than a Record, like ShiftOnDateView: a misspelt
 * field is an error at render time, not a silently blank cell.
 */
final readonly class LocationOnDateView
{
    public function __construct(
        public string $date,
        /** ONSITE | WFH (Domain\Roster\LocationKind). */
        public string $kind,
        /** Null on a WFH day — and on an assigned-worksite day for an employee with no worksite. */
        public ?string $site_id,
        /** Null when there is no site, or the site no longer resolves. */
        public ?string $site_name,
        /** True when a roster_locations row decided it; false means the Assigned Worksite fallback. */
        public bool $from_roster,
        /**
         * Whether a shift is rostered on the date — answered for the punch
         * station only, which refuses a Time In without one; null where it
         * was not asked (the correction dialog reads ShiftOnDateView).
         */
        public ?bool $has_shift = null,
        /**
         * Whether a Time In is open for a Time Out pressed now to close —
         * answered for the punch station only (`PunchPairing`, the rule
         * RecordPunch pairs by); null where it was not asked.
         */
        public ?bool $open_time_in = null,
    ) {}
}
