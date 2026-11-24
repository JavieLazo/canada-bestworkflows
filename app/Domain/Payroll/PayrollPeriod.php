<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use DateTimeZone;

/**
 * A literal port of copems/src/lib/payroll-period.ts.
 *
 * Payroll dates are calendar days in the company's local timezone, carried
 * everywhere as 'YYYY-MM-DD' strings. Lexicographic comparison of that format
 * IS chronological comparison, which is why the reference compares periods with
 * `<`/`>` on strings and this port does the same rather than parsing first.
 *
 * All calendar arithmetic here is pure integer day arithmetic (see toEpochDay /
 * fromEpochDay). The reference does its arithmetic on UTC `Date` objects
 * precisely so that the server's ambient timezone cannot move a boundary; doing
 * it on integers is the same guarantee with nothing left to configure. The only
 * place a wall clock appears is attendanceInstants(), which routes through
 * LocalClock — the one sanctioned local-time boundary.
 */
final class PayrollPeriod
{
    /**
     * TS: isPayrollDate() — a 'YYYY-MM-DD' string naming a real calendar day.
     *
     * The reference re-parses the value and asserts it round-trips, which is what
     * rejects 2026-02-31 (V8 rolls it to 2026-03-03, so the round-trip differs).
     * checkdate-style day bounds reproduce that exactly for every month 1-12.
     *
     * DELIBERATE DIVERGENCE, the only one in this file: for a syntactically
     * well-formed but impossible MONTH ('2026-13-01', '2026-00-10') V8 produces
     * an Invalid Date and `.toISOString()` THROWS a RangeError, so the reference
     * 500s instead of returning false — including through payrollCutoffError()
     * and through zod's `payrollDate.safeParse`. That is reproducible in PHP but
     * it is a crash, not a payroll behaviour: no centavo depends on it, and every
     * in-range input answers identically. This returns false, so a malformed
     * query parameter becomes the intended 400 instead of a 500.
     */
    public static function isPayrollDate(?string $value): bool
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $year = (int) substr($value, 0, 4);
        $month = (int) substr($value, 5, 2);
        $day = (int) substr($value, 8, 2);

        if ($month < 1 || $month > 12 || $day < 1) {
            return false;
        }

        return $day <= self::daysInMonth($year, $month);
    }

    /**
     * Whether a date is the first day of a semi-monthly payroll period — the
     * 1st or the 16th. Pay Basis & Rates versions take effect only on one, so
     * no standard cut-off is ever priced under two versions.
     */
    public static function isCutoffStart(string $date): bool
    {
        if (! self::isPayrollDate($date)) {
            return false;
        }
        $day = (int) substr($date, 8, 2);

        return $day === 1 || $day === 16;
    }

    /**
     * The only rule a payroll period must satisfy.
     *
     * DIVERGENCE FROM THE REFERENCE, and the largest in this file. The source's
     * payrollCutoffError() additionally requires a COMPLETE SEMI-MONTHLY
     * CUT-OFF — the 1st-15th or the 16th-last day — and refuses a period whose
     * end date has not yet passed. Neither applies here: a run may pay any
     * range of days, and the payslip is computed from whatever the DTR holds
     * for them. A two-day period is a two-day payslip.
     *
     * The engine needs nothing special for it. PayslipCalculator prices a day
     * at a time — a daily rate spread over the month's scheduled working
     * days, multiplied by the days attendance actually shows — so a shorter
     * period is only fewer days.
     *
     * WHAT IS CHECKED, AND WHY EACH CHECK IS STRUCTURAL RATHER THAN POLICY:
     *
     *  - A real, ordered pair of dates. Nothing computes without it.
     *  - the statutory coverage: the `$coverage` closure the application hands
     *    in (the Contribution & Tax Table versions in force) or, for a pure
     *    caller, Statutory::scheduleError over the built-in figures' dates —
     *    reading the tables outside their dates would invent figures.
     * There is no `$today` parameter and no in-progress rule. A period may end
     * in the future; the days that have not happened carry no attendance and
     * so pay nothing, which is arithmetic rather than an error worth refusing.
     *
     * THERE IS NO ONE-CALENDAR-MONTH RULE EITHER, and unlike the others its
     * absence has a cost. The daily rate derived from `monthly_rate` is that
     * rate over the month's scheduled working days (an entered Rate Per Day,
     * `daily_rate`, never moves), and months hold 20 to 23 of them — so a period
     * running 28 Sep to 3 Oct has two daily rates in it, not one, and pricing
     * the October days at September's rate is a silent 4.5% error on each.
     * PayslipCalculator resolves the rate per calendar month and counts
     * every day, night, late minute and undertime minute against its own
     * month. A period inside one month reduces to the single-rate arithmetic
     * exactly, which is what the 6,921 golden vectors prove.
     */
    public static function cutoffError(string $periodStart, string $periodEnd, ?Closure $coverage = null): ?string
    {
        if (! self::isPayrollDate($periodStart) || ! self::isPayrollDate($periodEnd)) {
            return 'Choose valid start and end dates.';
        }
        if ($periodStart > $periodEnd) {
            return 'The end date cannot fall before the start date.';
        }

        // DELIBERATE DIVERGENCE: the reference bounds a period by constants
        // (Statutory::scheduleError, the built-in figures' coverage). The
        // application hands in the Contribution & Tax Tables' verdict instead
        // — `$coverage(start, end)`, null when every table covers the period
        // (Modules\Payroll\Application\StatutoryTables) — and the constants
        // answer only for a caller that hands nothing in.
        $scheduleError = $coverage === null ? Statutory::scheduleError($periodStart) : $coverage($periodStart, $periodEnd);
        if ($scheduleError !== null) {
            return $scheduleError;
        }

        return null;
    }

    /**
     * TS: calendarMonthRange().
     *
     * The first and last date of the calendar month a date falls in. Payroll
     * spreads a monthly rate over the month's scheduled working days, and asks
     * this once per month a period touches — which, because cutoffError
     * accepts a period that straddles months, may be more than one.
     *
     * @return array{from: string, to: string}
     */
    public static function calendarMonthRange(string $date): array
    {
        $year = (int) substr($date, 0, 4);
        $month = (int) substr($date, 5, 2);
        $lastDay = self::daysInMonth($year, $month);

        return [
            'from' => substr($date, 0, 7).'-01',
            'to' => substr($date, 0, 7).'-'.str_pad((string) $lastDay, 2, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * TS: addCalendarDays(). Adds calendar days without depending on any timezone.
     *
     * The reference throws a RangeError on a value that is not a real date; this
     * computes from the parsed components instead. Every caller passes a value
     * that has already been through isPayrollDate() or todayKey().
     */
    public static function addCalendarDays(string $value, int $days): string
    {
        $epochDay = self::toEpochDay(
            (int) substr($value, 0, 4),
            (int) substr($value, 5, 2),
            (int) substr($value, 8, 2),
        );

        return self::dateFromEpochDay($epochDay + $days);
    }

    /**
     * TS: attendanceDateRange().
     *
     * Payroll reads one adjacent day on either side so a night shift can pair a
     * previous-day TIME_IN with a next-day TIME_OUT without widening the query to
     * the employee's entire attendance history.
     *
     * @return array{from: string, to: string}
     */
    public static function attendanceDateRange(string $periodStart, string $periodEnd): array
    {
        return [
            'from' => self::addCalendarDays($periodStart, -1),
            'to' => self::addCalendarDays($periodEnd, 1),
        ];
    }

    /**
     * TS: attendanceInstants(). Instants for a local calendar-day range.
     *
     * The reference builds `${from}T00:00:00.000+08:00` and `${to}T23:59:59.999+08:00`
     * and hands Prisma the resulting instants. LocalClock::instant() appends the
     * same +08:00 to an offset-less string, so these are the identical instants.
     *
     * Returned already converted to UTC — same instant, different presentation.
     * That is a MySQL requirement, not a change of meaning: Laravel binds a
     * DateTimeInterface by calling format() on it, which would otherwise write the
     * local wall clock into a comparison against UTC DATETIME(3) columns and
     * shift every attendance window by the zone's offset.
     *
     * @return array{gte: CarbonImmutable, lte: CarbonImmutable}
     */
    public static function attendanceInstants(string $from, string $to): array
    {
        return [
            'gte' => LocalClock::instant($from.'T00:00:00.000')->utc(),
            'lte' => LocalClock::instant($to.'T23:59:59.999')->utc(),
        ];
    }

    /**
     * TS: dateKeyFromDate() — `date.toISOString().slice(0, 10)`.
     *
     * The UTC calendar day, matching respond.ts's `toDay`. Applied ONLY to DATE
     * columns (date_hired, date_resigned), which are stored at midnight, so the
     * UTC day and the local day of that instant are the same string.
     *
     * NEVER call this on a timestamp column. A punch late in the UTC day can fall on a different
     * local day than the one read here; for those rows the answer is LocalClock::dayKey().
     */
    public static function dateKeyFromDate(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d');
    }

    /**
     * TS: eachDate() (dtr.ts) — every calendar day in an inclusive range.
     *
     * Lives here because it is calendar arithmetic and the readiness pass needs it
     * without depending on the DTR module. The DTR port carries its own copy for
     * the same reason the reference does.
     *
     * @return list<string>
     */
    public static function eachDate(string $start, string $end): array
    {
        $out = [];
        $day = self::toEpochDay(
            (int) substr($start, 0, 4),
            (int) substr($start, 5, 2),
            (int) substr($start, 8, 2),
        );
        $last = self::toEpochDay(
            (int) substr($end, 0, 4),
            (int) substr($end, 5, 2),
            (int) substr($end, 8, 2),
        );

        while ($day <= $last) {
            $out[] = self::dateFromEpochDay($day);
            $day++;
        }

        return $out;
    }

    /**
     * The day of the week a calendar date falls on, 0 Sunday through 6 Saturday.
     *
     * TS reads `new Date(`${date}T00:00:00.000Z`).getUTCDay()`; epoch day 0
     * (1970-01-01) was a Thursday, hence the +4.
     */
    public static function dayOfWeek(string $date): int
    {
        $epochDay = self::toEpochDay(
            (int) substr($date, 0, 4),
            (int) substr($date, 5, 2),
            (int) substr($date, 8, 2),
        );

        return (($epochDay + 4) % 7 + 7) % 7;
    }

    /**
     * TS: `new Date(Date.UTC(year, month, 0)).getUTCDate()` — month is 1-based
     * here and 0-based in JS, so that expression names day 0 of the NEXT month,
     * which steps back to the last day of this one.
     *
     * Proleptic Gregorian, matching V8: year 0 is a leap year.
     */
    public static function daysInMonth(int $year, int $month): int
    {
        if ($month === 2) {
            $leap = $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);

            return $leap ? 29 : 28;
        }

        return in_array($month, [4, 6, 9, 11], true) ? 30 : 31;
    }

    /** Days since 1970-01-01, proleptic Gregorian. Howard Hinnant's days_from_civil. */
    private static function toEpochDay(int $year, int $month, int $day): int
    {
        $y = $year - ($month <= 2 ? 1 : 0);
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    /** The inverse: 'YYYY-MM-DD' for a day count since 1970-01-01. civil_from_days. */
    private static function dateFromEpochDay(int $epochDay): string
    {
        $z = $epochDay + 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $y = $yoe + $era * 400;
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $day = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $month = $mp + ($mp < 10 ? 3 : -9);

        return sprintf('%04d-%02d-%02d', $y + ($month <= 2 ? 1 : 0), $month, $day);
    }
}
