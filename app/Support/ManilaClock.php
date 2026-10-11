<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The only place 'Asia/Manila' appears.
 *
 * APP_TIMEZONE is UTC and the mysql connection is pinned to '+00:00', so
 * DATETIME(3) columns hold UTC. Every Manila conversion routes through here.
 * That is deliberate: under APP_TIMEZONE=Asia/Manila a Carbon::parse() of an
 * offset-less string happens to land correctly, which makes a missing guard
 * invisible in dev and catastrophic in production. Under UTC it fails loudly.
 */
final class ManilaClock
{
    public const TZ = 'Asia/Manila';

    /**
     * An offset-less timestamp is Manila wall clock, NOT UTC.
     * Reference: copems/src/lib/dtr.ts:112-115, which appends '+08:00'
     * precisely because it cannot trust the ambient zone.
     */
    public static function instant(string $iso): CarbonImmutable
    {
        $hasZone = (bool) preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $iso);

        return CarbonImmutable::parse($hasZone ? $iso : $iso.'+08:00');
    }

    /**
     * Which Manila calendar day a punch belongs to.
     * Never call ->toDateString() on a UTC row.
     */
    public static function dayKey(CarbonImmutable $at): string
    {
        return $at->setTimezone(self::TZ)->format('Y-m-d');
    }

    /**
     * Today's Manila calendar day, `Y-m-d` (hris.ts:255 `todayKey`). The one
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
     * DATETIME(3) is a PHP 31.25 error on an on-time punch.
     */
    public static function minutesOfDay(CarbonImmutable $at): float
    {
        $m = $at->setTimezone(self::TZ);

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
     * second-granular formatter.
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
     * Money::round() is the PESO boundary. This is the MINUTE boundary. They
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

    /**
     * Sort punches chronologically.
     *
     * A float-returning comparator silently does nothing in PHP — the return
     * is cast to int, so usort($a, fn($x,$y) => ($x-$y)*0.4) on [3,1,2] yields
     * [3,1,2] unchanged. The reference sorts by a float millisecond difference
     * (dtr.ts:204-206), so this must use the spaceship operator on integers.
     *
     * PHP 8's sort is stable, so punches sharing a millisecond keep insertion order.
     *
     * @param  list<CarbonImmutable>  $instants
     * @return list<CarbonImmutable>
     */
    public static function sorted(array $instants): array
    {
        usort($instants, fn (CarbonImmutable $a, CarbonImmutable $b) => self::ms($a) <=> self::ms($b));

        return $instants;
    }
}
