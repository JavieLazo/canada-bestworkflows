<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

/**
 * ONE date of one employee's schedule as the Shift Roster draws it — the
 * week table's cell and the employee's calendar day — resolved through the
 * same per-date composition the DTR is measured against
 * (`Infrastructure\ShiftRoster::calendarFor`), so a cell never names a shift
 * the figures were not measured against.
 *
 * Typed properties rather than a Record, like ScheduleDayView: a misspelt
 * field is an error at render time, not a silently blank cell.
 */
final readonly class RosterDayView
{
    /**
     * @param  array{start: string, end: string, break_start: string, break_end: string, grace: string}|null  $custom_hours  the draft that reproduces the date's temporary custom hours; null for any other shift choice
     */
    public function __construct(
        public string $date,
        /** False when nothing is rostered on the date: no shift, so no Time In. */
        public bool $rostered,
        /** False on a rest day, and on a date with no shift. */
        public bool $works,
        /** The pattern's name ("AM"), "Rest day", "No shift", "Custom hours" for hours a temporary schedule typed in, or the hours themselves for any other shift with no pattern behind it. */
        public string $label,
        /** "6:00 AM–2:00 PM" or "Flexible · 8 h per day"; null on a rest day or a date with no shift. */
        public ?string $hours,
        /** "6:00 AM", where the hours have a fixed start; for the rest warning. */
        public ?string $starts,
        /** "2:00 PM", where the hours have a fixed end. */
        public ?string $ends,
        /** True for hours with no pattern behind them: custom hours, a deleted pattern's copy, or hours saved without a pattern. */
        public bool $custom,
        /** TEMPORARY | WEEKLY | DEFAULT | NONE (Domain\Roster\ScheduleBasis::SOURCE_*). */
        public string $source,
        /** Minutes of rest after the previous date's shift when they fall short of eight hours; null otherwise. */
        public ?int $short_rest_minutes,
        /** The approved leave or official business on the date, by type; null when none, or when the viewer may not see that employee's leave. */
        public ?string $leave,
        /** True for official business rather than leave. */
        public bool $official_business,
        /** True for a half-day leave. */
        public bool $half_day,
        /** ONSITE | WFH, or null for the Assigned Worksite. */
        public ?string $place_kind,
        /** The site of an ONSITE place. */
        public ?string $place_site_id,
        /** What the date's own temporary schedule sets as its shift: DEFAULT (none), REST, a pattern id, CUSTOM or KEEP (a deleted pattern's copy). */
        public string $shift_choice,
        public ?array $custom_hours,
        /** What the date's own temporary schedule sets as its place: DEFAULT (none), WFH or a site id. */
        public string $location_choice,
        /** The weekly schedule's shift for the date, without its temporary schedules: "AM 6:00 AM–2:00 PM" (the hours alone with no pattern behind them), "Rest day" or "No shift". */
        public string $usual,
        /** The holiday on the date, by name ("Rizal Day"); null when none. The DTR reads an unworked holiday as excused. */
        public ?string $holiday = null,
        /** The temporary schedule that decides the date's hours, else its place; null when neither comes from one. */
        public ?string $temporary_id = null,
        /** That temporary schedule's first date, 'Y-m-d'. */
        public ?string $temporary_from = null,
        /** The last date it applies on, 'Y-m-d'. */
        public ?string $temporary_to = null,
    ) {}
}
