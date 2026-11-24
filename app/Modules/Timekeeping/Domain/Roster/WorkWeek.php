<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Roster;

use App\Domain\Attendance\CalendarDays;

/**
 * The week as the roster shows and pages it: Sunday to Saturday. THE ONE
 * PLACE THE FIRST DAY IS DECIDED — the week table's columns and paging, the
 * month grids' first column, the Default schedule's row order, the order a
 * week is named in an audit sentence or a refusal all read it from here, so
 * no screen or command keeps an order of its own.
 *
 * It decides order only. Weekdays are numbered 0 Sunday … 6 Saturday in
 * `shifts.work_days`, `roster_shifts.weekday` and `roster_locations.weekday`
 * whatever day the week starts on, and nothing stored depends on this class.
 *
 * Pure: 'Y-m-d' strings and weekday numbers in and out.
 */
final class WorkWeek
{
    /** The weekday a week starts on, in the stored numbering: 0 is Sunday. */
    public const int FIRST_DAY = 0;

    private function __construct() {}

    /**
     * The seven weekday numbers in the order a week reads, first day first.
     *
     * @return list<int>
     */
    public static function weekdays(): array
    {
        $days = [];
        for ($position = 0; $position < 7; $position++) {
            $days[] = (self::FIRST_DAY + $position) % 7;
        }

        return $days;
    }

    /** Where a weekday number sits in the week: 0 for its first day, 6 for its last. */
    public static function position(int $weekday): int
    {
        return ((($weekday - self::FIRST_DAY) % 7) + 7) % 7;
    }

    /** The first date of the week holding `$date`. */
    public static function startOf(string $date): string
    {
        for ($back = self::position(CalendarDays::dayOfWeek($date)); $back > 0; $back--) {
            $date = CalendarDays::previous($date);
        }

        return $date;
    }

    /**
     * The seven dates of the week holding `$date`, first day first.
     *
     * @return list<string>
     */
    public static function dates(string $date): array
    {
        $dates = [self::startOf($date)];
        for ($day = 1; $day < 7; $day++) {
            $dates[] = CalendarDays::next($dates[$day - 1]);
        }

        return $dates;
    }
}
