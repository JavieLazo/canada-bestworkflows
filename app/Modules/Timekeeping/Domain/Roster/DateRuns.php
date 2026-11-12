<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Roster;

use App\Domain\Attendance\CalendarDays;

/**
 * A list of dates in a sentence, the way a person writes them: consecutive
 * dates as one run, the month said once while it holds, the year after the
 * last run of that year — "Nov 17–19, 2026", "Nov 28, Dec 1–3, 2026",
 * "Dec 31, 2026–Jan 2, 2027". A refusal that names the dates it is about
 * stays one readable line however many there are: past `RUNS_NAMED` runs
 * the rest are counted.
 *
 * Pure: 'Y-m-d' strings in, text out.
 */
final class DateRuns
{
    /** How many runs a sentence names before it counts the dates left. */
    public const int RUNS_NAMED = 4;

    private const array MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /**
     * Consecutive dates grouped, in date order, each date once.
     *
     * @param  list<string>  $dates
     * @return list<array{0: string, 1: string}> [first, last] of each run
     */
    public static function runs(array $dates): array
    {
        $dates = array_values(array_unique($dates));
        sort($dates, SORT_STRING);
        $runs = [];
        $current = null;
        foreach ($dates as $date) {
            if ($current !== null && CalendarDays::next($current[1]) === $date) {
                $current = [$current[0], $date];

                continue;
            }
            if ($current !== null) {
                $runs[] = $current;
            }
            $current = [$date, $date];
        }
        if ($current !== null) {
            $runs[] = $current;
        }

        return $runs;
    }

    /** @param  list<string>  $dates */
    public static function describe(array $dates): string
    {
        $runs = self::runs($dates);
        $named = array_slice($runs, 0, self::RUNS_NAMED);
        $text = '';
        $month = '';
        foreach ($named as $i => [$from, $to]) {
            $label = (substr($from, 0, 7) !== $month ? self::month($from).' ' : '').self::day($from);
            if (substr($from, 0, 4) !== substr($to, 0, 4)) {
                $label .= ', '.substr($from, 0, 4);
            }
            if ($from !== $to) {
                $label .= '–'.(substr($to, 0, 7) !== substr($from, 0, 7) || substr($from, 0, 4) !== substr($to, 0, 4) ? self::month($to).' ' : '').self::day($to);
            }
            // The year closes the last run of each year; runs of one year
            // are joined by commas, the years by semicolons.
            $next = $named[$i + 1][0] ?? null;
            $yearEnds = $next === null || substr($next, 0, 4) !== substr($to, 0, 4);
            $text .= $label.($yearEnds ? ', '.substr($to, 0, 4) : '').($next === null ? '' : ($yearEnds ? '; ' : ', '));
            $month = $yearEnds ? '' : substr($to, 0, 7);
        }
        $left = 0;
        foreach (array_slice($runs, self::RUNS_NAMED) as [$from, $to]) {
            $left += count(CalendarDays::each($from, $to));
        }

        return $text.($left > 0 ? " and {$left} more ".($left === 1 ? 'date' : 'dates') : '');
    }

    private static function month(string $date): string
    {
        return self::MONTHS[(int) substr($date, 5, 2) - 1];
    }

    private static function day(string $date): string
    {
        return (string) (int) substr($date, 8, 2);
    }
}
