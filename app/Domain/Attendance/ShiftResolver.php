<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/**
 * Shift geometry — the half of copems/src/lib/dtr.ts that answers questions
 * about the SCHEDULE rather than about the punches.
 *
 * Ports, in source order:
 *   dtr.ts:104      DEFAULT_WORK_DAYS
 *   dtr.ts:119-122  workWeek()
 *   dtr.ts:133-142  paidShiftMinutes()
 *   dtr.ts:149-150  NIGHT_WINDOW_START / NIGHT_WINDOW_END
 *   dtr.ts:163-178  nightShiftMinutes()
 *   dtr.ts:181-183  worksOn()
 *   dtr.ts:186-192  scheduledWorkDays()
 *   dtr.ts:226-229  clockMinutes()
 *   dtr.ts:262-273  the derivation computeDtr opens with, as geometry()
 *   dtr.ts:479-484  openPunchExpiryMinutes()
 *
 * Pure: 'Y-m-d' strings and value objects in, floats out. No Eloquent, no
 * Carbon, no config().
 *
 * ── KNOWN DEFECT CARRIED OVER DELIBERATELY: FLEXIBLE SHIFTS ──────────────
 * A FLEXIBLE shift pattern stores NO clock times. `shiftFromPattern()`
 * (copems/src/lib/data/attendance.ts:87-102) and the shifts PUT route
 * (src/app/api/shifts/[employeeId]/route.ts:63-64) both substitute the
 * literals '08:00:00' and '17:00:00' when the pattern's own are null, and copy
 * the pattern's `grace_minutes` — which for the seeded "Flexi 8" pattern
 * (src/lib/seed.ts:1580) is 0. So a FLEXIBLE employee's row on disk reads
 * 08:00–17:00 grace 0 and is INDISTINGUISHABLE from a real fixed day shift.
 *
 * The engine papers over that in three places (no lateness, no early-arrival
 * clamp, `required_hours` instead of the span) but NOT in a fourth: `overnight`
 * is derived from those filler times, so it is always false for a FLEXIBLE
 * shift, and the overnight carry-over slice never runs for one. A flexible
 * employee who punches in at 21:00 and out at 05:00 loses the TIME_OUT
 * entirely: the day becomes INCOMPLETE, and payroll.ts:505 counts INCOMPLETE
 * on a scheduled day as an absence and docks a full day's pay.
 *
 * This is a REFERENCE BUG. It is ported faithfully — both systems must
 * reconcile to the centavo — and must NOT be "fixed" here.
 */
final class ShiftResolver
{
    /**
     * TS: `DEFAULT_WORK_DAYS` — Monday to Friday, 0 Sunday to 6 Saturday.
     *
     * @var list<int>
     */
    public const DEFAULT_WORK_DAYS = [1, 2, 3, 4, 5];

    /**
     * The night differential window, as minutes from midnight of the day a
     * shift starts: 22:00 through 06:00 the next morning. Labor Code Art. 86
     * fixes these hours, so unlike the rates around them they are not
     * configurable.
     */
    public const NIGHT_WINDOW_START = 22 * 60;

    public const NIGHT_WINDOW_END = 30 * 60;

    /** Punches may arrive up to one hour before the next scheduled slot. */
    public const EARLY_PUNCH_WINDOW_MINUTES = 60;

    private const MINUTES_PER_DAY = 24 * 60;

    /**
     * TS: `clockMinutes(t)` — minutes past midnight for an 'HH:MM:SS' string.
     *
     * QUIRK, PRESERVED: the reference destructures only `[h, m]` and DROPS THE
     * SECONDS. A shift declared '08:00:30' starts at minute 480, not 480.5.
     * That is the opposite of `minutesOfDay()`, which keeps a punch's seconds —
     * the asymmetry is real and it is what makes lateness comparable to a
     * whole-minute grace allowance.
     *
     * Returns float rather than int so that a malformed clock string yields NAN
     * and propagates, the way `Number(undefined)` yields NaN in the source,
     * instead of raising a TypeError deep inside a payroll run.
     */
    public static function clockMinutes(string $t): float
    {
        $parts = explode(':', $t);

        $hour = is_numeric($parts[0]) ? (float) $parts[0] : NAN;
        $minute = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : NAN;

        return $hour * 60 + $minute;
    }

    /**
     * TS: `workWeek(shift)` — a shift's scheduled days.
     *
     * An EMPTY list would make every day a rest day and leave nothing to divide
     * a monthly rate by, so it reads as "not configured" and falls back to
     * Monday–Friday. Null does the same.
     *
     * @return list<int>
     */
    public static function workWeek(?Shift $shift): array
    {
        return self::workDaysOrDefault($shift?->workDays);
    }

    /**
     * The same rule for callers that hold only the day list — the reference's
     * `workWeek({ work_days: days })` call sites.
     *
     * @param  list<int>|null  $days
     * @return list<int>
     */
    public static function workDaysOrDefault(?array $days): array
    {
        return $days !== null && count($days) > 0 ? $days : self::DEFAULT_WORK_DAYS;
    }

    /**
     * TS: `paidShiftMinutes(shift)` — how many minutes a day on this shift is
     * PAID for: its scheduled span less the unpaid break, or the hours a
     * flexible shift owes.
     *
     * This is what a daily rate is divided by to reach an hourly one, so a
     * six-hour shift makes each hour worth more than an eight-hour shift on the
     * same daily rate. It comes from the roster rather than a settings box
     * precisely because it differs per employee.
     */
    public static function paidShiftMinutes(?Shift $shift): float
    {
        $s = $shift ?? Shift::default();

        if ($s->isFlexible()) {
            return max(0.0, Minutes::round($s->requiredHoursOrDefault() * 60));
        }

        $start = self::clockMinutes($s->startTime);
        $rawEnd = self::clockMinutes($s->endTime);
        $end = $rawEnd <= $start ? $rawEnd + self::MINUTES_PER_DAY : $rawEnd;

        // The doubled Math.max is the source's: the inner one floors a
        // degenerate span at 0 BEFORE the break is deducted, the outer one
        // floors the result again after a break longer than the shift.
        return max(0.0, max($end - $start, 0.0) - $s->breakAllowance());
    }

    /**
     * TS: `nightShiftMinutes(shift)` — how many minutes of a shift's SCHEDULED
     * span fall inside the night window.
     *
     * The window repeats daily, so the span is intersected with it on the day
     * before, the day of, and the day after — enough for any shift that does
     * not run longer than about two days. A 22:00–06:00 shift is night
     * throughout; an 18:00–03:00 one earns the differential only from 22:00 on.
     *
     * A FLEXIBLE shift returns 0: its clock times are the filler described in
     * this class's header, so there is no scheduled night span to measure.
     */
    public static function nightShiftMinutes(?Shift $shift): float
    {
        $s = $shift ?? Shift::default();

        if ($s->isFlexible()) {
            return 0.0;
        }

        $start = self::clockMinutes($s->startTime);
        $rawEnd = self::clockMinutes($s->endTime);
        $end = $rawEnd <= $start ? $rawEnd + self::MINUTES_PER_DAY : $rawEnd;

        $total = 0.0;
        for ($day = -1; $day <= 1; $day++) {
            $from = self::NIGHT_WINDOW_START + $day * self::MINUTES_PER_DAY;
            $to = self::NIGHT_WINDOW_END + $day * self::MINUTES_PER_DAY;
            $total += max(0.0, min($end, (float) $to) - max($start, (float) $from));
        }

        return $total;
    }

    /**
     * TS: `worksOn(date, workDays)` — whether a date is one of the days this
     * shift is scheduled on.
     *
     * `null` means "argument omitted" and takes the Monday–Friday default;
     * an explicitly EMPTY array means no day is scheduled, exactly as JS
     * distinguishes `undefined` from `[]` on a defaulted parameter.
     *
     * The membership test is strict, matching `Array.prototype.includes`, so
     * the day numbers must be ints — `Shift::fromArray()` guarantees that.
     *
     * @param  list<int>|null  $workDays
     */
    public static function worksOn(string $date, ?array $workDays = null): bool
    {
        return in_array(
            CalendarDays::dayOfWeek($date),
            $workDays ?? self::DEFAULT_WORK_DAYS,
            true,
        );
    }

    /**
     * TS: `scheduledWorkDays(start, end, workDays)` — how many scheduled days
     * the INCLUSIVE date range holds. The divisor behind a daily rate.
     *
     * @param  list<int>|null  $workDays
     */
    public static function scheduledWorkDays(string $start, string $end, ?array $workDays = null): int
    {
        $days = $workDays ?? self::DEFAULT_WORK_DAYS;

        $count = 0;
        foreach (CalendarDays::each($start, $end) as $date) {
            if (self::worksOn($date, $days)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The block computeDtr opens with — dtr.ts:262-273 — as one value object.
     *
     * `requiredMinutes` is the source's `shiftLength` as well: dtr.ts:271
     * aliases the two, and the half-day allowance at dtr.ts:421 halves it.
     */
    public static function geometry(?Shift $shift): ShiftGeometry
    {
        $s = $shift ?? Shift::default();

        $start = self::clockMinutes($s->startTime);
        $rawEnd = self::clockMinutes($s->endTime);
        $overnight = $rawEnd <= $start;
        $end = $overnight ? $rawEnd + self::MINUTES_PER_DAY : $rawEnd;
        $fixedLength = max($end - $start, 0.0);

        $requiredMinutes = $s->isFlexible()
            ? max(0.0, Minutes::round($s->requiredHoursOrDefault() * 60))
            : $fixedLength;

        return new ShiftGeometry(
            start: $start,
            rawEnd: $rawEnd,
            overnight: $overnight,
            end: $end,
            fixedLength: $fixedLength,
            requiredMinutes: $requiredMinutes,
            paidMinutes: self::paidShiftMinutes($s),
        );
    }

    /**
     * A configured break window projected into the same timeline as geometry().
     *
     * Clock values can cross midnight independently of the shift itself. The
     * stable way to place them is by offset from shift start: 23:30-00:30 on a
     * 20:00-05:00 roster becomes 1410-1470, and 02:00-03:00 on a 22:00-06:00
     * roster becomes 1560-1620. A noon break on that same night shift falls
     * outside the shift and is ignored here; the write policies already block
     * new rows in that shape.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function scheduledBreakWindow(?Shift $shift, ?ShiftGeometry $geometry = null): ?array
    {
        $s = $shift ?? Shift::default();
        if ($s->isFlexible() || $s->breakStart === null || $s->breakEnd === null || $s->breakAllowance() <= 0) {
            return null;
        }

        $geometry ??= self::geometry($s);

        $breakStart = self::clockMinutes($s->breakStart);
        $breakEnd = self::clockMinutes($s->breakEnd);
        if (! is_finite($breakStart) || ! is_finite($breakEnd) || ! is_finite($geometry->start)) {
            return null;
        }

        $windowLength = fmod($breakEnd - $breakStart + self::MINUTES_PER_DAY, self::MINUTES_PER_DAY);
        if ($windowLength <= 0.0) {
            return null;
        }

        if (abs($windowLength - $s->breakAllowance()) > 0.0001) {
            return null;
        }

        $offset = fmod($breakStart - $geometry->start + self::MINUTES_PER_DAY, self::MINUTES_PER_DAY);
        if ($offset + $windowLength > $geometry->fixedLength) {
            return null;
        }

        $start = $geometry->start + $offset;

        return [$start, $start + $windowLength];
    }

    /**
     * TS: `openPunchExpiryMinutes(shift, maxOvertimeHours)` — dtr.ts:479-484.
     *
     * How long a Time In can stay open before it stops being one. Past the
     * shift's own hours plus every hour of overtime the Formulas will pay for,
     * nobody is still working — they forgot to clock out. Holding that punch
     * open would refuse them their next shift over a mistake made on the last
     * one, so after this it is treated as though it were never open and HR
     * corrects the day at leisure.
     *
     * Kept here rather than in a punch-policy class because it is pure shift
     * geometry; the pairing rule that CONSUMES it (dtr.ts:498-521) is punch
     * station behaviour and is not part of this port.
     */
    public static function openPunchExpiryMinutes(?Shift $shift, float $maxOvertimeHours): float
    {
        return self::paidShiftMinutes($shift) + max(0.0, $maxOvertimeHours) * 60;
    }
}
