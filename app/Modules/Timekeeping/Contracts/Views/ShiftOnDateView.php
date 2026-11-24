<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

/**
 * The shift an employee is measured against on ONE local calendar date, as
 * the DTR correction dialog describes it — resolved through the per-date
 * seam (Infrastructure\ShiftRoster), so a roster that varies by weekday
 * changes what this carries and nothing in the dialog.
 *
 * Typed properties rather than a Record: a misspelt field here is an error
 * at render time, not a silently blank cell.
 */
final readonly class ShiftOnDateView
{
    public function __construct(
        public string $date,
        /** False when the employee has no roster row and Shift::default() stands in. */
        public bool $rostered,
        /** FIXED or FLEXIBLE; a null or unknown stored mode reads as FIXED, as the engine does. */
        public string $mode,
        /** HH:MM:SS — FILLER times on a flexible shift, which owes hours, not a clock. */
        public string $start_time,
        public string $end_time,
        public int $grace_minutes,
        public ?float $required_hours,
        public ?string $break_start,
        public ?string $break_end,
        public int $break_minutes,
        /** ShiftResolver::geometry()->overnight, filler times included — so a flexible shift reads false. Whether a slot may be ticked Next day is DayCorrectionView::$next_day_allowed. */
        public bool $overnight,
        /** ShiftResolver::scheduledBreakWindow() !== null: the four-slot DTR applies, else two punches. */
        public bool $uses_scheduled_break,
        /** ShiftResolver::worksOn($date, …): false is a rest day on this shift. */
        public bool $is_work_day,
    ) {}
}
