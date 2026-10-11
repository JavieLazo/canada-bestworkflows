<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

/**
 * The schedule ONE Manila calendar date was measured against, and how far it
 * can be trusted as history — the Daily Time Record's Shift column,
 * resolved through the same per-date seam the engine's inputs come from
 * (Infrastructure\ShiftRoster), so the label and the figures beside it can
 * never describe different shifts.
 *
 * Typed properties rather than a Record, like ShiftOnDateView: a misspelt
 * field is an error at render time, not a silently blank cell.
 */
final readonly class ScheduleDayView
{
    public function __construct(
        public string $date,
        /** TEMPORARY | WEEKLY | DEFAULT | NONE (Domain\Roster\ScheduleBasis::SOURCE_*). */
        public string $source,
        /** RECORDED | ASSUMED | MAY_DIFFER | NONE (Domain\Roster\ScheduleBasis). */
        public string $basis,
        /** False on a rest day of the schedule that applied. */
        public bool $works,
        /** "8:00 AM–5:00 PM" or "Flexible · 8 h per day" — the hours judged, the engine's default when nothing was assigned. */
        public string $hours,
    ) {}
}
