<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Infrastructure;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\RosterDayOverride;
use App\Modules\Timekeeping\Domain\Roster\ResolvedLocation;
use App\Modules\Timekeeping\Domain\Roster\ScheduleBasis;
use App\Modules\Timekeeping\Domain\Roster\ScheduleTimeline;
use App\Support\LocalClock;
use InvalidArgumentException;

/**
 * Resolves hours and places by the local date a shift STARTS, from the
 * schedule that applied ON THAT DATE: a temporary assignment wins, then the
 * weekday hours or place in effect that day, then the default shift or
 * assigned worksite in effect that day. A later change never reaches an
 * earlier date. No saved hours at any level means unrostered, preserving
 * callers' engine default. Batch readers use the same composition for DTR
 * and payroll, including captured finalization evidence.
 *
 * Each employee's result is one DomainShift. The schedule in effect at the
 * range's start is the base (default shift plus weekday hours); a date under
 * a later change, or under a temporary assignment, becomes a date-specific
 * shift that works or rests on that date alone. So an employee whose
 * schedule did not change inside the range gets the base itself with only
 * its temporary assignments laid over it — no recurring date is pinned — so
 * its payroll evidence and fingerprint are exactly those of the weekly
 * schedule and its assignments.
 *
 * These are internal trusted reads: public queries enforce their own Access
 * scope before passing employee ids. Location fallback stays with callers
 * holding the Workforce employee facts, avoiding a cross-owner model read.
 */
final class ShiftRoster
{
    /**
     * @throws InvalidArgumentException when `$date` is not a real Y-m-d day —
     *                                  a null answer would silently mean "default shift", so a bad
     *                                  date is an error, not a fallback
     */
    public function forDate(string $employeeId, string $date): ?DomainShift
    {
        if (! PayrollPeriod::isPayrollDate($date)) {
            throw new InvalidArgumentException("ShiftRoster::forDate() needs a Y-m-d calendar date, got \"{$date}\".");
        }

        return $this->scheduleForEmployee($employeeId, $date, $date)?->forDate($date);
    }

    /**
     * `$schedule`'s shift for `$date` — the ranged schedule the caller has
     * already composed — or null when nothing is rostered ON that date.
     * THERE IS NO DEFAULT SHIFT: a date has one only when its weekday has
     * hours of its own, a default shift assigned to the employee covers it,
     * or a temporary schedule sets it. The schedule the engine composes
     * stands its default (8:00 AM–5:00 PM, Monday–Friday) in for every other
     * date — a date whose shift was cleared, a weekday with no hours of its
     * own beside weekdays that have some — so the DTR measures it as it
     * measures anyone unrostered and counts it unscheduled; a caller asking
     * whether the date HAS a shift (the punch windows, the station, the
     * correction dialog's wording) must not read that stand-in as one.
     * Null exactly where the roster's calendar reads "No shift"
     * (`calendarFor`).
     */
    public function rosteredShift(string $employeeId, string $date, ?DomainShift $schedule): ?DomainShift
    {
        if ($schedule === null || ! PayrollPeriod::isPayrollDate($date)
            || ($this->calendarFor([$employeeId], $date, $date)[$employeeId][$date]['shift'] ?? null) === null) {
            return null;
        }

        return $schedule->forDate($date);
    }

    /**
     * Without a range, the schedule in effect on `$asOf` (today when null)
     * with every temporary assignment laid over it — the whole-roster view
     * the write commands validate. With one, each date in it resolves as it
     * applied.
     */
    public function scheduleForEmployee(string $employeeId, ?string $start = null, ?string $end = null, bool $lock = false, ?string $asOf = null): ?DomainShift
    {
        return $this->scheduleForEmployees([$employeeId], $start, $end, $lock, $asOf)[$employeeId] ?? null;
    }

    /**
     * Three batched reads, regardless of roster size. An assignment without a
     * recurring schedule uses the engine default for every other date.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, DomainShift>
     */
    public function scheduleForEmployees(array $employeeIds, ?string $start = null, ?string $end = null, bool $lock = false, ?string $asOf = null): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $ranged = $start !== null && $end !== null;
        $versions = app(RosterVersions::class);
        $asOf ??= LocalClock::todayKey();
        $timelines = $versions->timelines($employeeIds, $ranged ? $end : $asOf, $lock);
        $assignments = [];
        foreach ($versions->assignments($employeeIds, $start, $end, $lock) as $row) {
            $assignments[$row->employee_id][] = $row;
        }

        $out = [];
        foreach ($employeeIds as $employeeId) {
            $schedule = $ranged
                ? $this->composed($timelines[$employeeId], $assignments[$employeeId] ?? [], $start, $end)
                : $this->composed($timelines[$employeeId], $assignments[$employeeId] ?? [], $asOf, null);
            if ($schedule !== null) {
                $out[$employeeId] = $schedule;
            }
        }

        return $out;
    }

    /**
     * Each date of the range with what decided its hours — the SAME
     * composition the engine's inputs come from, so a label built on this
     * can never describe a shift the figures were not measured against.
     * `schedule` is null when nothing was ever assigned inside the range.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array{schedule: ?DomainShift, sources: array<string, string>}>
     */
    public function explainedForEmployees(array $employeeIds, string $start, string $end): array
    {
        if ($employeeIds === [] || ! PayrollPeriod::isPayrollDate($start) || ! PayrollPeriod::isPayrollDate($end) || $start > $end) {
            return [];
        }
        $versions = app(RosterVersions::class);
        $timelines = $versions->timelines($employeeIds, $end);
        $assignments = [];
        foreach ($versions->assignments($employeeIds, $start, $end) as $row) {
            $assignments[$row->employee_id][] = $row;
        }

        $out = [];
        foreach ($employeeIds as $employeeId) {
            $temporary = [];
            $schedule = $this->composed($timelines[$employeeId], $assignments[$employeeId] ?? [], $start, $end, $temporary);
            $sources = [];
            foreach (CalendarDays::each($start, $end) as $date) {
                $sources[$date] = isset($temporary[$date]) ? ScheduleBasis::SOURCE_TEMPORARY : $timelines[$employeeId]->partAt($date);
            }
            $out[$employeeId] = ['schedule' => $schedule, 'sources' => $sources];
        }

        return $out;
    }

    /**
     * Each date of the range as the Shift Roster's calendar draws it, from
     * the SAME composition the engine's inputs come from: the shift that
     * applies (null when nothing is rostered: no hours of its weekday's own,
     * no assigned default shift and no temporary schedule — exactly where
     * `rosteredShift()` finds none and a Time In is refused), whether it works, what
     * decided it, and — for changing the date — the schedule it has without
     * its temporary ones (`usual`) and the temporary schedules that decided
     * its hours and its place. `place` is the place it has: a temporary
     * one, else the weekday's, else null for the Assigned Worksite, which the
     * caller holding the employee's record fills in.
     *
     * Three batched reads whatever the batch size.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, array{shift: ?DomainShift, works: bool, source: string, usual: ?DomainShift, usual_works: bool, hours_from: ?RosterDayOverride, place_from: ?RosterDayOverride, place: array{kind: string, site: ?string}|null}>>
     */
    public function calendarFor(array $employeeIds, string $start, string $end): array
    {
        if ($employeeIds === [] || ! PayrollPeriod::isPayrollDate($start) || ! PayrollPeriod::isPayrollDate($end) || $start > $end) {
            return [];
        }
        $versions = app(RosterVersions::class);
        $timelines = $versions->timelines($employeeIds, $end, places: true);
        $assignments = [];
        foreach ($versions->assignments($employeeIds, $start, $end) as $row) {
            $assignments[$row->employee_id][] = $row;
        }

        $out = [];
        foreach ($employeeIds as $employeeId) {
            $timeline = $timelines[$employeeId];
            $rows = $assignments[$employeeId] ?? [];
            $temporary = [];
            $schedule = $this->composed($timeline, $rows, $start, $end, $temporary);
            $days = [];
            foreach (CalendarDays::each($start, $end) as $date) {
                $source = isset($temporary[$date]) ? ScheduleBasis::SOURCE_TEMPORARY : $timeline->partAt($date);
                // There is no default shift: rostered only where the
                // weekday has hours of its own, an assigned default shift
                // covers it, or a temporary schedule sets it — never on the
                // engine default that stands in for the other weekdays.
                // Only then may a Time In be recorded (rosteredShift()).
                $usual = $timeline->partAt($date) === ScheduleBasis::SOURCE_NONE ? null : $timeline->scheduleAt($date);
                $rostered = $schedule !== null && ($usual !== null || isset($temporary[$date]));
                // The latest save naming a place wins the date, as
                // locationForDate() reads it.
                $placeFrom = null;
                foreach ($rows as $row) {
                    if ($row->location_kind !== null && $row->date->format('Y-m-d') <= $date && RosterVersions::appliesThrough($row) >= $date) {
                        $placeFrom = $row;
                    }
                }
                $weekdayPlace = $timeline->placeAt($date);
                $days[$date] = [
                    'shift' => $rostered ? $schedule->forDate($date) : null,
                    'works' => $rostered && ShiftResolver::worksOnDate($date, $schedule),
                    'source' => $source,
                    'usual' => $usual?->forDate($date),
                    'usual_works' => $usual !== null && ShiftResolver::worksOnDate($date, $usual),
                    'hours_from' => $temporary[$date] ?? null,
                    'place_from' => $placeFrom,
                    'place' => $placeFrom !== null && $placeFrom->location_kind !== null
                        ? ['kind' => $placeFrom->location_kind, 'site' => $placeFrom->site_id]
                        : $weekdayPlace,
                ];
            }
            $out[$employeeId] = $days;
        }

        return $out;
    }

    /**
     * The date's temporary place wins, then the weekday place in effect that
     * date, then null — the caller falls back to the Assigned Worksite.
     *
     * @throws InvalidArgumentException as forDate(): a bad date is an error, never "the default place"
     */
    public function locationForDate(string $employeeId, string $date): ?ResolvedLocation
    {
        if (! PayrollPeriod::isPayrollDate($date)) {
            throw new InvalidArgumentException("ShiftRoster::locationForDate() needs a Y-m-d calendar date, got \"{$date}\".");
        }

        $versions = app(RosterVersions::class);
        $dated = $versions->assignments([$employeeId], $date, $date)->filter(static fn (RosterDayOverride $row): bool => $row->location_kind !== null)->last();
        if ($dated instanceof RosterDayOverride && $dated->location_kind !== null) {
            return ResolvedLocation::roster($dated->location_kind, $dated->site_id, fromDateOverride: true);
        }
        $place = $versions->timelines([$employeeId], $date, places: true, hours: false)[$employeeId]->placeAt($date);

        return $place === null ? null : ResolvedLocation::roster($place['kind'], $place['site']);
    }

    /**
     * One employee's schedule: the recurring schedule in effect at `$start`,
     * then each later date under a different one, then the assignments. An
     * open `$end` (the validation view) lays every assignment over today's
     * schedule without following later changes.
     *
     * @param  list<RosterDayOverride>  $assignments  oldest save first
     * @param  array<string, RosterDayOverride>  $temporary  filled with the dates whose hours an assignment decided, and which one
     */
    private function composed(ScheduleTimeline $timeline, array $assignments, string $start, ?string $end, array &$temporary = []): ?DomainShift
    {
        $base = $timeline->scheduleAt($start);
        $dated = [];
        if ($end !== null) {
            $changes = $timeline->hourChangesWithin($start, $end);
            foreach ($changes as $i => $from) {
                $segment = $timeline->scheduleAt($from) ?? DomainShift::default();
                // A change that leaves the hours exactly as the base has them
                // (a re-save, a rename) pins nothing: the base already reads
                // those dates, and the payroll inputs stay byte-identical.
                if ($base !== null && $segment->toArray() === $base->toArray()) {
                    continue;
                }
                $until = isset($changes[$i + 1]) ? CalendarDays::previous($changes[$i + 1]) : $end;
                foreach (CalendarDays::each($from, $until) as $date) {
                    $dated[$date] = self::pinned($segment, $date);
                }
            }
        }
        $recurring = $dated === [] ? $base : ($base ?? DomainShift::default())->withDateShifts($dated);

        foreach ($assignments as $row) {
            if ($row->shift_data === null) {
                continue;
            }
            $first = $row->date->format('Y-m-d');
            $last = RosterVersions::appliesThrough($row);
            $from = $end === null ? $first : max($first, $start);
            $to = $end === null ? $last : min($last, $end);
            foreach ($from <= $to ? CalendarDays::each($from, $to) : [] as $date) {
                // A period that keeps the usual rest days (D6) applies only
                // where the recurring schedule of THAT date works.
                if (! $row->include_rest_days && ! $row->is_rest_day
                    && ! ShiftResolver::worksOnDate($date, $recurring ?? DomainShift::default())) {
                    continue;
                }
                $dated[$date] = DomainShift::fromArray(array_replace($row->shift_data, [
                    'shift_type_id' => $row->shift_type_id,
                    'work_days' => $row->is_rest_day ? [-1] : [CalendarDays::dayOfWeek($date)],
                ]));
                $temporary[$date] = $row;
            }
        }

        return $dated === [] ? $base : ($base ?? DomainShift::default())->withDateShifts($dated);
    }

    /**
     * A recurring schedule's hours for one date, as a date-specific shift
     * that works (or rests) on that date alone, the way a date change is
     * stored — so a later schedule inside the range reads exactly as it
     * applied, without disturbing the base's own weekdays.
     */
    private static function pinned(DomainShift $schedule, string $date): DomainShift
    {
        $works = ShiftResolver::worksOnDate($date, $schedule);

        return DomainShift::fromArray(array_replace($schedule->forDate($date)->toArray(), [
            'work_days' => $works ? [CalendarDays::dayOfWeek($date)] : [-1],
            'weekday_shifts' => [],
            'date_shifts' => [],
        ]));
    }
}
