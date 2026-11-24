<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * The one boundary between stored UTC and the installation's local time.
 *
 * APP_TIMEZONE is UTC and the mysql connection is pinned to '+00:00', so
 * DATETIME(3) columns hold UTC. Every local conversion routes through here.
 * That is deliberate: under a local APP_TIMEZONE a Carbon::parse() of an
 * offset-less string happens to land correctly, which makes a missing guard
 * invisible in dev and catastrophic in production. Under UTC it fails loudly.
 *
 * The zone is a NAMED zone, never a fixed offset, so each date is read on the
 * offset that was in force on it. Alberta shows why: its clocks changed twice
 * a year through March 2026 and have kept one offset since November 2026, and
 * a record from either side of that must keep reading as it was written. The
 * rules come from PHP's time zone data, which therefore has to be current on
 * every machine that runs the application. The installation names its zone
 * once, in `copems.timezone`; nothing else in the application writes a zone.
 */
final class LocalClock
{
    /** The zone an installation runs on until its settings name another. */
    public const DEFAULT_ZONE = 'America/Edmonton';

    private static string $zone = self::DEFAULT_ZONE;

    /** The installation's zone, as an IANA name. */
    public static function zone(): string
    {
        return self::$zone;
    }

    /**
     * Name the installation's zone. Called once while the application boots,
     * from `copems.timezone`; a name PHP does not know is refused there rather
     * than left to shift every punch by a guessed offset.
     */
    public static function useZone(string $zone): void
    {
        try {
            $known = (new DateTimeZone($zone))->getName();
        } catch (Throwable) {
            throw new InvalidArgumentException("Unknown time zone [{$zone}]. Name an IANA zone, such as America/Edmonton.");
        }

        self::$zone = $known;
    }

    /**
     * An offset-less timestamp is LOCAL wall clock, NOT UTC.
     *
     * A string that names its own zone (`Z` or an offset) is that instant.
     * Anything else is read on the installation's zone, with its clock
     * changes: a wall clock a spring change skips (02:30 on that night) is
     * read an hour later, and one an autumn change repeats is read as its
     * first occurrence.
     */
    public static function instant(string $iso): CarbonImmutable
    {
        $hasZone = (bool) preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $iso);

        return $hasZone ? CarbonImmutable::parse($iso) : CarbonImmutable::parse($iso, self::$zone);
    }

    /**
     * Which local calendar day a punch belongs to.
     * Never call ->toDateString() on a UTC row.
     */
    public static function dayKey(CarbonImmutable $at): string
    {
        return $at->setTimezone(self::$zone)->format('Y-m-d');
    }

    /**
     * Today's local calendar day, `Y-m-d` (hris.ts:255 `todayKey`). The one
     * clock read; the module helpers' `todayKey()` methods all forward here.
     */
    public static function todayKey(): string
    {
        return self::dayKey(CarbonImmutable::now());
    }

    /**
     * Minute-of-day with SECONDS KEPT and sub-second TRUNCATED.
     *
     * The reference implementation reads an Intl formatter that emits a
     * 2-digit `second` field, so 08:15:00.500 is NOT late against grace 15,
     * but 08:15:01 is. Building this from format('s.u') once the column is
     * DATETIME(3) is a 31.25 pay error on an on-time punch.
     */
    public static function minutesOfDay(CarbonImmutable $at): float
    {
        $m = $at->setTimezone(self::$zone);

        return (int) $m->format('G') * 60
            + (int) $m->format('i')
            + (int) $m->format('s') / 60;
    }

    /**
     * Integer milliseconds since the epoch.
     * NEVER Carbon::diffInMinutes — it truncates and is unsigned.
     */
    public static function ms(CarbonImmutable $at): int
    {
        return $at->getTimestamp() * 1000 + intdiv((int) $at->format('u'), 1000);
    }

    /**
     * Pair duration: Math.max(0, Math.round((outMs - inMs) / 60000)), per pair.
     *
     * Unlike minutesOfDay this keeps FULL millisecond precision — pair
     * durations are computed from raw millisecond differences, not from the
     * second-granular formatter. It is elapsed time, so a pair across a
     * daylight saving change is an hour shorter or longer than its wall
     * clocks read.
     */
    public static function minutesBetween(CarbonImmutable $a, CarbonImmutable $b): int
    {
        return max(0, self::roundToWholeMinute((self::ms($b) - self::ms($a)) / 60000));
    }

    /**
     * JS Math.round to a whole number - half-UP toward +Infinity.
     *
     * DO NOT express this as (int) Money::round($n), which is what
     * 01-STARTER-PACK.md section 5.5 prescribes. Money::round() rounds to TWO
     * DECIMALS and the (int) cast then TRUNCATES, so a pair 480.5 minutes long
     * comes back as 480 where JS gives 481 - an off-by-one on roughly HALF of
     * all in->out pairs, because the fractional part of ms/60000 is uniformly
     * distributed on real punches.
     *
     * Money::round() is the MONEY boundary. This is the MINUTE boundary. They
     * round to different scales and must never substitute for one another.
     *
     * Uses ($n - floor($n)) >= 0.5 rather than floor($n + 0.5) for the same
     * reason Money::round() does: Math.round(0.49999999999999994) is 0 in JS,
     * but adding 0.5 to it reaches exactly 1.0 in IEEE-754 before floor sees it.
     *
     * App\Domain\Attendance\Minutes::round() is the DTR-facing equivalent and
     * must stay in agreement with this.
     */
    public static function roundToWholeMinute(float $n): int
    {
        if (! is_finite($n)) {
            return 0;
        }

        $floor = floor($n);

        return (int) (($n - $floor) >= 0.5 ? $floor + 1 : $floor);
    }
}
