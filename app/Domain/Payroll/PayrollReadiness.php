<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A literal port of copems/src/lib/payroll-readiness.ts.
 *
 * Conditions the current payslip shape cannot price defensibly are blockers,
 * not silent approximations. This keeps finalization honest while those pay
 * categories are added as first-class earnings in a later schema revision.
 *
 * Pure: no Eloquent, no HTTP, no clock of its own. The DTR itself is computed by
 * a callable the caller supplies, because the reference re-derives the DTR here
 * from a log slice it builds itself (core logs plus the overnight carry) rather
 * than from whatever the payslip pass computed. Injecting it keeps that exact
 * behaviour without binding this module to the attendance module's class names.
 *
 * Rows (logs, DTR days, holidays) are read structurally through read(), so an
 * array with the reference's own keys, a readonly DTO and an Eloquent model all
 * work. The canonical shape is the TypeScript one:
 *   log     { log_type: string, logged_at: string|DateTimeInterface }
 *   day     { date: string, status: string, workedMinutes: int, overtimeMinutes: int }
 *   holiday { date: string, schedule: string, type: string, percent: int|float }
 */
final class PayrollReadiness
{
    public const CODE_UNPAIRED_ATTENDANCE = 'UNPAIRED_ATTENDANCE';

    public const CODE_REST_DAY_WORK = 'REST_DAY_WORK';

    public const CODE_NIGHT_WORK = 'NIGHT_WORK';

    public const CODE_HOLIDAY_CONFIGURATION = 'HOLIDAY_CONFIGURATION';

    public const CODE_COMPOUND_HOLIDAY = 'COMPOUND_HOLIDAY';

    public const CODE_HOLIDAY_REST_DAY = 'HOLIDAY_REST_DAY';

    public const CODE_HOLIDAY_OVERTIME = 'HOLIDAY_OVERTIME';

    /**
     * The codes that actually stop a run being finalized.
     *
     * The class docblock's rule holds — a condition the payslip cannot price
     * defensibly is a blocker, not a silent approximation — but four of the
     * detected codes carry the reference's message for a gap this payslip
     * does not have, so they do not block:
     *
     *   NIGHT_WORK        says the payslip has no field for it. It has
     *                     `night_differential`, computed from the shift and
     *                     Formulas -> NSD, and hand-editable in the workspace.
     *   REST_DAY_WORK     asks for a rest-day premium. Formulas -> Rest Day,
     *                     into `rest_day_pay`.
     *   HOLIDAY_OVERTIME  asks for a holiday overtime rate. The holiday's own
     *                     percent from Records -> Holidays, times
     *                     PayslipCalculator::HOLIDAY_OT_PREMIUM.
     *   HOLIDAY_REST_DAY  asks for compound pricing, and it compounds:
     *                     holiday percent times the Rest Day percentage.
     *
     * What blocks is not a pricing gap at all. An unpaired punch means a day
     * that pays nothing until somebody corrects it; a holiday percent below
     * the statutory minimum is a settings error with a settings fix; and a
     * date carrying two holidays is genuinely ambiguous, because the
     * calculator asks HolidaySetting::on() for one and gets one.
     *
     * Their detection is kept whole on purpose. It costs nothing on a pass
     * that is already walking every date, and the day an advisory panel
     * wants to show "this run contains rest-day work", the answer is already
     * computed.
     *
     * @var list<string>
     */
    public const array BLOCKING_CODES = [
        self::CODE_UNPAIRED_ATTENDANCE,
        self::CODE_HOLIDAY_CONFIGURATION,
        self::CODE_COMPOUND_HOLIDAY,
    ];

    /** Labor Code Art. 86: the night differential window is 22:00 to 06:00. */
    private const NIGHT_START_MS = 22 * 3_600_000;

    private const NIGHT_END_MS = 6 * 3_600_000;

    private const DAY_MS = 86_400_000;

    /** TS: listDates()'s default `limit = 3`. */
    private const LIST_LIMIT = 3;

    /** en-PH short month names, as `fmtDate` (hris.ts) renders them. */
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /**
     * TS: payrollReadinessIssues(). The finalize blockers for ONE employee.
     *
     * $computeDtr is the DTR calculator, called exactly as the reference calls
     * computeDtr(logs, shift, leaves, periodStart, periodEnd, holidays) and
     * expected to return one row per calendar day of [start, end].
     *
     * $shift and $leaves are opaque here — they are only forwarded to $computeDtr.
     *
     * @param  callable(list<mixed>, mixed, list<mixed>, string, string, list<mixed>): iterable<mixed>  $computeDtr
     * @param  list<mixed>  $logs
     * @param  list<mixed>  $leaves
     * @param  list<mixed>  $holidays
     * @param  bool  $compoundPriced  whether the pay rules price a day with two holidays (no COMPOUND_HOLIDAY issue then)
     * @return list<array{code: string, message: string}>
     */
    public static function issues(
        callable $computeDtr,
        array $logs,
        mixed $shift,
        array $leaves,
        string $periodStart,
        string $periodEnd,
        string $employmentStart,
        ?string $employmentEnd = null,
        array $holidays = [],
        bool $compoundPriced = false,
    ): array {
        $start = $employmentStart > $periodStart ? $employmentStart : $periodStart;
        // `input.employmentEnd && ...` is a TRUTHINESS test in the reference, so an
        // empty string reads as "still employed" exactly as null does.
        $end = ($employmentEnd !== null && $employmentEnd !== '' && $employmentEnd < $periodEnd)
            ? $employmentEnd
            : $periodEnd;

        if ($start > $end) {
            return [];
        }

        $coreLogs = [];
        foreach ($logs as $log) {
            $date = self::dateKey($log);
            if ($date >= $start && $date <= $end) {
                $coreLogs[] = $log;
            }
        }
        $coreLogs = self::withoutPreviousDayTail($coreLogs, $start);

        $nextDate = PayrollPeriod::addCalendarDays($end, 1);
        $nextDayLogs = [];
        foreach ($logs as $log) {
            if (self::dateKey($log) === $nextDate) {
                $nextDayLogs[] = $log;
            }
        }
        $nextDayLogs = self::sortByInstant($nextDayLogs);

        // findIndex(...) < 0 ? whole array : slice up to the first TIME_IN.
        // `array_search` would return int(0) for a match at position 0, and
        // `false == 0`, so the test must be `=== false`. Getting it backwards
        // either pulls the next morning's whole shift into this period or drops a
        // legitimate overnight TIME_OUT.
        $nextTimeIn = array_search(true, array_map(
            static fn (mixed $log): bool => self::logType($log) === 'TIME_IN',
            $nextDayLogs,
        ), true);
        $carryLogs = $nextTimeIn === false
            ? $nextDayLogs
            : array_slice($nextDayLogs, 0, $nextTimeIn);

        $relevantLogs = array_merge($coreLogs, $carryLogs);
        $days = $computeDtr($relevantLogs, $shift, $leaves, $start, $end, $holidays);

        $issues = [];
        $pairing = self::attendancePairingIssues($relevantLogs);

        $incomplete = [];
        $byDate = [];
        foreach ($days as $day) {
            $date = (string) self::read($day, 'date');
            // `days.find(...)` takes the FIRST row for a date; computeDtr emits one
            // row per day, but keep the semantics rather than the assumption.
            $byDate[$date] ??= $day;
            if (self::read($day, 'status') === 'INCOMPLETE') {
                $incomplete[] = $date;
            }
        }

        if ($pairing['unpaired'] || count($incomplete) > 0) {
            $issues[] = [
                'code' => self::CODE_UNPAIRED_ATTENDANCE,
                'message' => 'Resolve every unmatched or duplicate attendance punch before finalizing'
                    .self::listDates($incomplete).'.',
            ];
        }
        if ($pairing['nightWork']) {
            $issues[] = [
                'code' => self::CODE_NIGHT_WORK,
                'message' => 'Night differential is not represented by the current payslip fields.',
            ];
        }

        foreach (PayrollPeriod::eachDate($start, $end) as $date) {
            $day = $byDate[$date] ?? null;
            $worked = ($day === null ? 0 : (float) self::read($day, 'workedMinutes', 'worked_minutes')) > 0;
            $dayHolidays = self::matchingHolidays($date, $holidays);

            // DIVERGENCE 13: a Pay Basis & Rates version that prices a double
            // holiday (PayRules::prices('DOUBLE')) lifts the block.
            if (count($dayHolidays) > 1 && ! $compoundPriced) {
                $issues[] = [
                    'code' => self::CODE_COMPOUND_HOLIDAY,
                    'message' => "{$date} has multiple holidays, which requires compound holiday pricing.",
                ];
            }

            $belowMinimum = false;
            foreach ($dayHolidays as $holiday) {
                $percent = (float) self::read($holiday, 'percent');
                $belowMinimum = self::read($holiday, 'type') === 'REGULAR'
                    ? $percent < 100
                    : $percent < 30;
                if ($belowMinimum) {
                    break;
                }
            }
            if ($belowMinimum) {
                $issues[] = [
                    'code' => self::CODE_HOLIDAY_CONFIGURATION,
                    'message' => "{$date} has a holiday premium below the statutory minimum.",
                ];
            }

            if (! $worked) {
                continue;
            }

            $weekend = self::weekend($date);
            if ($weekend && count($dayHolidays) === 0) {
                $issues[] = [
                    'code' => self::CODE_REST_DAY_WORK,
                    'message' => "{$date} includes rest-day work, which requires a rest-day premium.",
                ];
            }
            if ($weekend && count($dayHolidays) > 0) {
                $issues[] = [
                    'code' => self::CODE_HOLIDAY_REST_DAY,
                    'message' => "{$date} combines holiday and rest-day work, which requires compound pricing.",
                ];
            }
            $overtime = $day === null ? 0.0 : (float) self::read($day, 'overtimeMinutes', 'overtime_minutes');
            if (count($dayHolidays) > 0 && $overtime > 0) {
                $issues[] = [
                    'code' => self::CODE_HOLIDAY_OVERTIME,
                    'message' => "{$date} includes holiday overtime, which requires a holiday overtime rate.",
                ];
            }
        }

        // TS keeps the FIRST occurrence of each (code, message) pair.
        $seen = [];
        $unique = [];
        foreach ($issues as $issue) {
            $key = $issue['code']."\0".$issue['message'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if (! in_array($issue['code'], self::BLOCKING_CODES, true)) {
                continue;
            }

            $unique[] = $issue;
        }

        return $unique;
    }

    /**
     * DELIBERATE DIVERGENCE: the first day's punches BEFORE its first TIME_IN
     * are the tail of the day before — a night shift's after-midnight Time
     * Out — and that day is not in this period.
     *
     * The reference slices core logs by calendar day
     * (payroll-readiness.ts:131-140), so a period that starts the morning
     * after a worked night opens with that Time Out, and the pairing walk
     * reads it as closing nothing: "Resolve every unmatched or duplicate
     * attendance punch" on a record the DTR reads as PRESENT.
     * The carry at the END already claims the next morning's punches up to
     * its first TIME_IN for this period, so this is the same cut at the
     * START: every punch is claimed by exactly ONE period. A first day with
     * no TIME_IN at all keeps nothing, as the end carry keeps everything when
     * the next day has none. The DTR itself needs no such cut — DtrCalculator
     * groups a punch by the shift it belongs to — only this walk does.
     *
     * @param  list<mixed>  $coreLogs
     * @return list<mixed>
     */
    private static function withoutPreviousDayTail(array $coreLogs, string $start): array
    {
        $firstDay = [];
        foreach ($coreLogs as $index => $log) {
            if (self::dateKey($log) === $start) {
                $firstDay[] = ['ms' => LocalClock::ms(self::instantOf($log)), 'index' => $index, 'log' => $log];
            }
        }
        // Integer milliseconds, as sortByInstant(): PHP's sort is stable, so
        // punches sharing a millisecond keep their insertion order.
        usort($firstDay, static fn (array $a, array $b): int => $a['ms'] <=> $b['ms']);

        $tail = [];
        foreach ($firstDay as $entry) {
            if (self::logType($entry['log']) === 'TIME_IN') {
                break;
            }
            $tail[$entry['index']] = true;
        }

        return array_values(array_diff_key($coreLogs, $tail));
    }

    /**
     * TS: attendancePairingIssues().
     *
     * A log that is not a TIME_IN closes the open punch, whatever its type —
     * ported as written rather than testing for TIME_OUT.
     *
     * @param  list<mixed>  $logs
     * @return array{unpaired: bool, nightWork: bool}
     */
    private static function attendancePairingIssues(array $logs): array
    {
        $sorted = self::sortByInstant($logs);
        $open = null;
        $nightWork = false;
        $unpaired = false;

        foreach ($sorted as $log) {
            if (self::logType($log) === 'TIME_IN') {
                if ($open !== null) {
                    $unpaired = true;
                }
                $open = $log;

                continue;
            }
            if ($open === null) {
                $unpaired = true;

                continue;
            }
            $nightWork = $nightWork || self::intervalHasNightWork(self::instantOf($open), self::instantOf($log));
            $open = null;
        }

        return ['unpaired' => $unpaired || $open !== null, 'nightWork' => $nightWork];
    }

    /**
     * TS: intervalHasNightWork().
     *
     * Whether a worked interval touches 22:00-06:00 on the local day the punch
     * started, the day before, or the day after — enough for any pair that does
     * not run longer than about two days.
     *
     * The reference does this in fixed-offset milliseconds; here the local
     * midnight of the start day is resolved through LocalClock and the offsets
     * are added to it, which is the same arithmetic with the timezone boundary in
     * its sanctioned place. All of it is integer milliseconds: no float
     * comparison, and no comparator whose return could be truncated to 0.
     */
    private static function intervalHasNightWork(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $startMs = LocalClock::ms($start);
        $endMs = LocalClock::ms($end);
        if (! ($startMs < $endMs)) {
            return false;
        }

        // The instant of midnight, local, on the day the pair opened. In the
        // reference this is `localDay - MANILA_OFFSET_MS`.
        $midnightMs = LocalClock::ms(LocalClock::instant(LocalClock::dayKey($start).'T00:00:00.000'));

        foreach ([-1, 0, 1] as $dayOffset) {
            $nightStart = $midnightMs + $dayOffset * self::DAY_MS + self::NIGHT_START_MS;
            $nightEnd = $midnightMs + ($dayOffset + 1) * self::DAY_MS + self::NIGHT_END_MS;
            if ($startMs < $nightEnd && $endMs > $nightStart) {
                return true;
            }
        }

        return false;
    }

    /**
     * TS: matchingHolidays(). Annual holidays match on month and day only.
     *
     * @param  list<mixed>  $holidays
     * @return list<mixed>
     */
    private static function matchingHolidays(string $date, array $holidays): array
    {
        $matched = [];
        foreach ($holidays as $holiday) {
            $holidayDate = (string) self::read($holiday, 'date');
            $isMatch = self::read($holiday, 'schedule') === 'ONE_TIME'
                ? $holidayDate === $date
                : substr($holidayDate, 5) === substr($date, 5);
            if ($isMatch) {
                $matched[] = $holiday;
            }
        }

        return $matched;
    }

    /**
     * TS: listDates().
     *
     * ` — Aug 17, Aug 18 and 2 more` for a message that would otherwise leave the
     * reader hunting through a fortnight for the day it means. Empty when there is
     * nothing to name, so the sentence still reads.
     *
     * @param  list<string>  $dates
     */
    private static function listDates(array $dates, int $limit = self::LIST_LIMIT): string
    {
        if (count($dates) === 0) {
            return '';
        }

        $shown = array_map(self::fmtDate(...), array_slice($dates, 0, $limit));
        $rest = count($dates) - count($shown);
        $last = $shown[count($shown) - 1];
        $list = count($shown) === 1
            ? $last
            : implode(', ', array_slice($shown, 0, -1)).' and '.$last;

        return ' — '.$list.($rest > 0 ? " and {$rest} more" : '');
    }

    /**
     * TS: fmtDate() (hris.ts) — a 'YYYY-MM-DD' as `toLocaleDateString('en-PH',
     * { year: 'numeric', month: 'short', day: '2-digit' })`, i.e. 'Aug 17, 2026'.
     *
     * Built from the string's own components rather than from a timestamp: the
     * reference parses a bare date as LOCAL midnight and formats it in the same
     * zone, so the rendered day is always the one written down.
     */
    private static function fmtDate(string $date): string
    {
        $month = (int) substr($date, 5, 2);
        $name = self::MONTHS[$month - 1] ?? substr($date, 5, 2);

        return $name.' '.substr($date, 8, 2).', '.substr($date, 0, 4);
    }

    /** TS: weekend() — Sunday or Saturday of the UTC calendar date. */
    private static function weekend(string $date): bool
    {
        $day = PayrollPeriod::dayOfWeek($date);

        return $day === 0 || $day === 6;
    }

    /**
     * TS: dateKey() — the local calendar day a punch belongs to.
     *
     * The reference adds a fixed eight hours; LocalClock reads the zone database,
     * which is the same answer for every instant since the Philippines last moved
     * its clocks in 1990 and matches what dtr.ts's Intl formatter does.
     */
    private static function dateKey(mixed $log): string
    {
        return LocalClock::dayKey(self::instantOf($log));
    }

    private static function logType(mixed $log): string
    {
        return (string) self::read($log, 'log_type', 'logType');
    }

    /**
     * An offset-less `logged_at` is local wall clock, never UTC — the reference
     * appends '+08:00' for exactly that reason, and so does LocalClock::instant.
     * A DateTimeInterface (an Eloquent cast) already carries its own zone.
     */
    private static function instantOf(mixed $log): CarbonImmutable
    {
        $value = self::read($log, 'logged_at', 'loggedAt');

        if ($value instanceof CarbonImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        return LocalClock::instant((string) $value);
    }

    /**
     * Sort rows chronologically.
     *
     * The reference sorts on a FLOAT millisecond difference. PHP casts a
     * comparator's return to int, so a fractional comparator silently leaves the
     * array untouched — this compares integer milliseconds with the spaceship
     * operator. PHP 8's sort is stable, matching JS, so punches sharing a
     * millisecond keep their insertion order.
     *
     * @param  list<mixed>  $logs
     * @return list<mixed>
     */
    private static function sortByInstant(array $logs): array
    {
        $decorated = [];
        foreach ($logs as $log) {
            $decorated[] = ['ms' => LocalClock::ms(self::instantOf($log)), 'log' => $log];
        }

        usort($decorated, static fn (array $a, array $b): int => $a['ms'] <=> $b['ms']);

        return array_column($decorated, 'log');
    }

    /**
     * Read one field off a row that may be an array, a DTO or an Eloquent model.
     * The first key that is present wins; a present null is returned as null.
     */
    private static function read(mixed $row, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($row)) {
                if (array_key_exists($key, $row)) {
                    return $row[$key];
                }

                continue;
            }
            if (is_object($row) && (isset($row->{$key}) || property_exists($row, $key))) {
                return $row->{$key};
            }
        }

        return null;
    }
}
