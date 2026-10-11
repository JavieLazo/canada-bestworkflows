<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The calendar helpers from copems/src/lib/dtr.ts:231-246 and :180-192.
 *
 * Every one of them builds its date as `new Date(`${date}T00:00:00.000Z`)` —
 * UTC MIDNIGHT, deliberately, so that the weekday and the day-stepping are
 * independent of the ambient zone. That is NOT a Manila conversion and must
 * not be routed through ManilaClock: a calendar day here is a bare 'Y-m-d'
 * label, not an instant. Only punch timestamps go through ManilaClock.
 *
 * Pure: 'Y-m-d' strings in, 'Y-m-d' strings out.
 */
final class CalendarDays
{
    /**
     * TS: `eachDate(start, end)` — every 'Y-m-d' in the INCLUSIVE range.
     *
     * The reference loops `while (d <= last)` and steps with `setUTCDate(+1)`,
     * so `start > end` yields an empty list. Reproduced.
     *
     * Quirk carried over: the reference concatenates 'T00:00:00.000Z' onto
     * whatever it is handed, so a caller passing a full ISO timestamp gets
     * `Invalid Date` and silently receives `[]`. PHP throws instead. Callers
     * pass cut-off boundaries, which are always 'Y-m-d'.
     *
     * @return list<string>
     */
    public static function each(string $start, string $end): array
    {
        $utc = new DateTimeZone('UTC');
        $day = new DateTimeImmutable($start.'T00:00:00.000Z', $utc);
        $last = new DateTimeImmutable($end.'T00:00:00.000Z', $utc);
        $step = new DateInterval('P1D');

        $out = [];
        while ($day <= $last) {
            $out[] = $day->format('Y-m-d');
            $day = $day->add($step);
        }

        return $out;
    }

    /** TS: `nextDate(date)` — dtr.ts:242-246. */
    public static function next(string $date): string
    {
        return (new DateTimeImmutable($date.'T00:00:00.000Z', new DateTimeZone('UTC')))
            ->add(new DateInterval('P1D'))
            ->format('Y-m-d');
    }

    /** The calendar day before the given bare 'Y-m-d' date. */
    public static function previous(string $date): string
    {
        return (new DateTimeImmutable($date.'T00:00:00.000Z', new DateTimeZone('UTC')))
            ->sub(new DateInterval('P1D'))
            ->format('Y-m-d');
    }

    /**
     * TS: `new Date(`${date}T00:00:00.000Z`).getUTCDay()` — dtr.ts:182.
     * 0 is Sunday through 6 Saturday, matching `Shift::$workDays`.
     */
    public static function dayOfWeek(string $date): int
    {
        return (int) (new DateTimeImmutable($date.'T00:00:00.000Z', new DateTimeZone('UTC')))
            ->format('w');
    }
}
