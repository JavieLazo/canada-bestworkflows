<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Roster;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\Shift as DomainShift;

/**
 * One employee's schedule as a history: which default shift, weekday hours
 * and weekday place applied on a date.
 *
 * Each entry applies from its `from` date (null = "since before history was
 * kept") until a later entry for the same part replaces it. The default
 * shift, each weekday's hours and each weekday's place are separate parts,
 * so a change to Tuesday's hours never undoes a pending change to Monday's,
 * and a change to the default shift reaches every weekday without an hours
 * entry of its own. Entries on the same date resolve by `order` (the row id,
 * a time-ordered ULID): the later-saved one wins.
 *
 * A weekday entry with no shift sends that weekday back to the default
 * shift, and a place entry with no kind sends it back to the Assigned
 * Worksite, from its date — how a return to the default is recorded without
 * erasing what came before. Cancelled changes never reach this class.
 *
 * Pure: 'Y-m-d' strings and detached shifts in, detached shifts out. The
 * caller has already turned each weekday row into a shift that works (or
 * rests) on that weekday alone, exactly as the weekly roster does.
 */
final class ScheduleTimeline
{
    /** @var list<array{from: ?string, order: string, shift: DomainShift}> */
    private array $standing;

    /** @var array<int, list<array{from: ?string, order: string, shift: ?DomainShift}>> */
    private array $weekdays;

    /** @var array<int, list<array{from: ?string, order: string, kind: ?string, site: ?string}>> */
    private array $places;

    /**
     * @param  list<array{from: ?string, order: string, shift: DomainShift}>  $standing
     * @param  list<array{from: ?string, order: string, weekday: int, shift: ?DomainShift}>  $weekdays
     * @param  list<array{from: ?string, order: string, weekday: int, kind: ?string, site: ?string}>  $places
     */
    public function __construct(array $standing = [], array $weekdays = [], array $places = [])
    {
        $this->standing = self::sorted($standing);
        $grouped = [];
        foreach ($weekdays as $entry) {
            $grouped[$entry['weekday']][] = ['from' => $entry['from'], 'order' => $entry['order'], 'shift' => $entry['shift']];
        }
        $this->weekdays = array_map(self::sorted(...), $grouped);
        $grouped = [];
        foreach ($places as $entry) {
            $grouped[$entry['weekday']][] = ['from' => $entry['from'], 'order' => $entry['order'], 'kind' => $entry['kind'], 'site' => $entry['site']];
        }
        $this->places = array_map(self::sorted(...), $grouped);
    }

    /**
     * The whole recurring schedule on a date: its default shift with that
     * date's weekday hours, or null when neither has ever been set. A
     * schedule with weekday hours but no default shift keeps the engine's
     * default for the other weekdays, as the weekly roster does.
     */
    public function scheduleAt(string $date): ?DomainShift
    {
        $i = self::latest($this->standing, $date);
        $standing = $i === null ? null : $this->standing[$i]['shift'];
        $weekdays = [];
        foreach ($this->weekdays as $weekday => $entries) {
            $i = self::latest($entries, $date);
            $shift = $i === null ? null : $entries[$i]['shift'];
            if ($shift !== null) {
                $weekdays[$weekday] = $shift;
            }
        }
        if ($standing === null) {
            return $weekdays === [] ? null : DomainShift::default()->withWeekdayShifts($weekdays);
        }

        return $weekdays === [] ? $standing : $standing->withWeekdayShifts($weekdays);
    }

    /**
     * Which part of the recurring schedule decides a date's hours: that
     * weekday's own hours (WEEKLY), else the default shift (DEFAULT), else
     * nothing (NONE) — the engine's default stands in, which is nobody's
     * assigned schedule.
     *
     * @return ScheduleBasis::SOURCE_WEEKLY|ScheduleBasis::SOURCE_DEFAULT|ScheduleBasis::SOURCE_NONE
     */
    public function partAt(string $date): string
    {
        $entries = $this->weekdays[CalendarDays::dayOfWeek($date)] ?? [];
        $i = self::latest($entries, $date);
        if ($i !== null && $entries[$i]['shift'] !== null) {
            return ScheduleBasis::SOURCE_WEEKLY;
        }

        return self::latest($this->standing, $date) === null ? ScheduleBasis::SOURCE_NONE : ScheduleBasis::SOURCE_DEFAULT;
    }

    /**
     * The recorded place for a date's weekday, or null for the Assigned
     * Worksite (no entry, or a return to it).
     *
     * @return array{kind: string, site: ?string}|null
     */
    public function placeAt(string $date): ?array
    {
        $entries = $this->places[CalendarDays::dayOfWeek($date)] ?? [];
        $i = self::latest($entries, $date);
        $entry = $i === null ? null : $entries[$i];
        if ($entry === null || $entry['kind'] === null) {
            return null;
        }

        return ['kind' => $entry['kind'], 'site' => $entry['site']];
    }

    /**
     * The dates after `$from`, up to and including `$to`, on which a new
     * default shift or weekday hours take effect — where the recurring
     * schedule may differ from the one at `$from`.
     *
     * @return list<string>
     */
    public function hourChangesWithin(string $from, string $to): array
    {
        $dates = [];
        foreach ([$this->standing, ...array_values($this->weekdays)] as $entries) {
            foreach ($entries as $entry) {
                if ($entry['from'] !== null && $entry['from'] > $from && $entry['from'] <= $to) {
                    $dates[$entry['from']] = true;
                }
            }
        }
        $dates = array_keys($dates);
        sort($dates, SORT_STRING);

        return $dates;
    }

    /**
     * @param  list<array{from: ?string, order: string}>  $entries  sorted by sorted()
     * @return int|null the index of the last entry in effect on `$date`
     */
    private static function latest(array $entries, string $date): ?int
    {
        $found = null;
        foreach ($entries as $i => $entry) {
            if ($entry['from'] !== null && $entry['from'] > $date) {
                break;
            }
            $found = $i;
        }

        return $found;
    }

    /**
     * Null (the baseline) first, then by date, then by save order.
     *
     * @template T of array{from: ?string, order: string}
     *
     * @param  list<T>  $entries
     * @return list<T>
     */
    private static function sorted(array $entries): array
    {
        usort($entries, static fn (array $a, array $b): int => [$a['from'] !== null, $a['from'] ?? '', $a['order']] <=> [$b['from'] !== null, $b['from'] ?? '', $b['order']]);

        return $entries;
    }
}
