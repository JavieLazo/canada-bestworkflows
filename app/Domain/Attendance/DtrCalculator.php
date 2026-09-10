<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Support\ManilaClock;

/**
 * A literal port of `computeDtr` — copems/src/lib/dtr.ts:252-447.
 *
 * Builds per-day DTR records from raw punches, the employee's shift and
 * approved leave / business-travel requests. Everything downstream of it —
 * the eDTR screen, `payroll.ts`, the finalize readiness pass — reads its
 * output, so a one-minute divergence here is a peso divergence on a payslip.
 *
 * Pure: 'Y-m-d' strings and value objects in, `DayRecord`s out. No Eloquent,
 * no config(), no DB. The only framework dependency is `ManilaClock`, which is
 * the single timezone boundary.
 *
 * ── THE FOUR PRECISION RULES, EACH DIFFERENT ────────────────────────────────
 *
 *  1. `ManilaClock::minutesOfDay()` is SECOND-GRANULAR. The reference reads an
 *     `Intl.DateTimeFormat` that emits a 2-digit `second` field (dtr.ts:194-218),
 *     so sub-second precision is discarded BEFORE the grace comparison. Against
 *     an 08:00 shift with 15 minutes of grace: 08:15:00 is not late, 08:15:00.500
 *     is not late, 08:15:01 IS late by 15 minutes. Building minute-of-day from
 *     `format('s.u')` once `logged_at` is DATETIME(3) is a ₱31.25 error on a
 *     punch that is, to the second, exactly on time.
 *
 *  2. PAIR DURATIONS keep FULL MILLISECOND precision — dtr.ts:351-354 and
 *     :372-375 divide a raw millisecond difference by 60000 and round. They are
 *     computed here from `ManilaClock::ms()`, never from `minutesOfDay()`.
 *
 *  3. `ShiftResolver::clockMinutes()` DROPS a shift's configured seconds
 *     entirely — the mirror image of rule 1.
 *
 *  4. Rounding to a whole minute is JS `Math.round` (half-UP), via
 *     `Minutes::round()`. It is NOT `Money::round()`, which is the two-decimal
 *     PESO boundary, and it is NOT PHP's `round()`, which is
 *     half-away-from-zero. See the note in Minutes on why
 *     `ManilaClock::minutesBetween()` is not used.
 *
 * ── SORTING ─────────────────────────────────────────────────────────────────
 * dtr.ts:294-296 sorts the merged day logs with a comparator that returns a
 * FLOAT millisecond difference. PHP casts a comparator's return to int, so a
 * fractional comparator silently sorts NOTHING — `usort($a, fn($x,$y) =>
 * ($x-$y)*0.4)` on [3,1,2] returns [3,1,2]. Sorting is therefore done with the
 * spaceship operator on INTEGER milliseconds. PHP 8's sort is stable and so is
 * JS's, so punches sharing a millisecond keep insertion order — and the
 * insertion order is pinned to `[...currentLogs, ...carryLogs]`, exactly as the
 * reference builds it.
 */
final class DtrCalculator
{
    /** TS: `OFFICIAL_BUSINESS` — worked time off-site, not an absence. */
    public const OFFICIAL_BUSINESS = 'BUSINESS_TRAVEL';

    private const MINUTES_PER_DAY = 24 * 60;

    /**
     * Per-instance memo of the three derivations a punch string feeds. Purely a
     * speed measure — every entry is a pure function of the key, so it cannot
     * change an answer. A finalize at 200 employees re-parses the same strings
     * dozens of times without it.
     *
     * @var array<string, int>
     */
    private array $msCache = [];

    /** @var array<string, string> */
    private array $dayCache = [];

    /** @var array<string, float> */
    private array $minuteCache = [];

    private function __construct() {}

    /**
     * TS: `computeDtr(logs, shift, leaves, periodStart, periodEnd, holidays)`.
     *
     * @param  list<AttendanceLog>  $logs
     * @param  list<LeaveRequest>  $leaves
     * @param  list<HolidaySetting>  $holidays
     * @return list<DayRecord>
     */
    public static function compute(
        array $logs,
        ?Shift $shift,
        array $leaves,
        string $periodStart,
        string $periodEnd,
        array $holidays = [],
    ): array {
        return (new self)->run($logs, $shift, $leaves, $periodStart, $periodEnd, $holidays);
    }

    /**
     * @param  list<AttendanceLog>  $logs
     * @param  list<LeaveRequest>  $leaves
     * @param  list<HolidaySetting>  $holidays
     * @return list<DayRecord>
     */
    private function run(
        array $logs,
        ?Shift $shift,
        array $leaves,
        string $periodStart,
        string $periodEnd,
        array $holidays,
    ): array {
        $s = $shift ?? Shift::default();
        $week = ShiftResolver::workWeek($s);

        $geometry = ShiftResolver::geometry($s);
        $shiftStart = $geometry->start;
        $shiftEnd = $geometry->end;
        $overnight = $geometry->overnight;
        $requiredMinutes = $geometry->requiredMinutes;
        // dtr.ts:271 aliases these two; the half-day UNDERTIME allowance
        // halves this one (lateness halves $paidShiftLength -- see below).
        $shiftLength = $requiredMinutes;
        /** Fixed shifts pay the scheduled span less break; flexible shifts owe worked hours. */
        $paidShiftLength = $geometry->paidMinutes;
        $configuredBreakWindow = self::configuredBreakWindow($s, $overnight);
        $scheduledBreakWindow = ShiftResolver::scheduledBreakWindow($s, $geometry);

        $approved = [];
        foreach ($leaves as $leave) {
            if ($leave->status === LeaveRequest::STATUS_APPROVED) {
                $approved[] = $leave;
            }
        }

        // Bucket every punch by its MANILA calendar day, preserving input order
        // within a bucket — that order is what the stable sort below falls back
        // to for punches sharing a millisecond.
        /** @var array<string, list<AttendanceLog>> $byDay */
        $byDay = [];
        foreach ($logs as $log) {
            // 'YYYY-MM-DD' is never a canonical integer string, so PHP does not
            // cast the key to an int and the bucket keys stay comparable.
            $byDay[$this->dayKey($log->loggedAt)][] = $log;
        }

        $records = [];

        foreach (CalendarDays::each($periodStart, $periodEnd) as $date) {
            $currentLogs = $byDay[$date] ?? [];

            /*
             * THE CARRY-OVER SLICE — dtr.ts:286-293.
             *
             * An overnight shift's punch-out lands on the NEXT calendar day, so
             * that day's logs are pulled in UP TO its own first TIME_IN — the
             * point at which the next shift begins.
             *
             * The reference writes the slice end as
             * `findIndex(...) < 0 ? undefined : findIndex(...)`. The natural PHP
             * transcription uses array_search, and array_search returns int(0)
             * when the next day's FIRST log is a TIME_IN but `false` when there
             * is none — and `false == 0` is TRUE. A loose test therefore cannot
             * tell "carry nothing" from "carry everything", and getting it
             * backwards either inflates workedMinutes with the whole of the next
             * morning or drops a legitimate overnight TIME_OUT and turns the day
             * INCOMPLETE, which payroll counts as an unpaid absence. The seeded
             * night shift is 22:00→06:00, so this is live configuration.
             *
             * Hence the strict `=== false`, and array_slice's null-vs-0 length
             * distinction, which matches JS exactly:
             *   array_slice($a, 0, null) → all      (JS slice(0, undefined))
             *   array_slice($a, 0, 0)    → none     (JS slice(0, 0))
             *
             * A scheduled break on an overnight shift is the exception: its
             * second Time In may itself be a next-day punch, so that path uses
             * the shift timeline rather than the old "stop at first TIME_IN"
             * slice.
             */
            $carryLogs = [];
            if ($overnight) {
                $next = $byDay[CalendarDays::next($date)] ?? [];
                if ($scheduledBreakWindow !== null) {
                    $carryLogs = $this->scheduledOvernightCarryLogs($date, $next, $shiftEnd);
                } else {
                    $types = array_map(static fn (AttendanceLog $l): string => $l->logType, $next);
                    $firstIn = array_search(AttendanceLog::TIME_IN, $types, true);
                    $carryLogs = array_slice($next, 0, $firstIn === false ? null : (int) $firstIn);
                }
            }

            $dayLogs = array_merge($currentLogs, $carryLogs);
            usort(
                $dayLogs,
                fn (AttendanceLog $a, AttendanceLog $b): int => $this->ms($a->loggedAt) <=> $this->ms($b->loggedAt),
            );

            $ins = [];
            $outs = [];
            foreach ($dayLogs as $log) {
                if ($log->logType === AttendanceLog::TIME_IN) {
                    $ins[] = $log;
                } elseif ($log->logType === AttendanceLog::TIME_OUT) {
                    $outs[] = $log;
                }
            }

            $timeIn = $ins === [] ? null : $ins[0]->loggedAt;
            $timeOut = $outs === [] ? null : $outs[count($outs) - 1]->loggedAt;

            // JS truthiness: `if (timeIn && …)` is false for an empty
            // logged_at string as well as for null, so an empty string is not a
            // punch for any test — but it IS still what gets reported as
            // `DayRecord.timeIn`, because the reference returns
            // `ins[0]?.logged_at ?? null` verbatim. Hence two variables:
            // `$timeIn` is what is reported, `$punchIn` is what is tested.
            $punchIn = $timeIn !== null && $timeIn !== '' ? $timeIn : null;
            $punchOut = $timeOut !== null && $timeOut !== '' ? $timeOut : null;

            // `.find()` — the FIRST approved request covering the day, compared
            // as plain strings, never parsed as dates.
            $excuse = null;
            foreach ($approved as $leave) {
                if (strcmp($date, $leave->startDate) >= 0 && strcmp($date, $leave->endDate) <= 0) {
                    $excuse = $leave;
                    break;
                }
            }

            // A day off the shift's own week, or a holiday, is not a scheduled
            // workday: staying home on either is a rest day, never an absence.
            // Which days those are comes from the shift, so a Monday–Saturday
            // employee is marked absent for a missed Saturday where a
            // Monday–Friday one is not. A holiday that is worked anyway is
            // measured like any other day.
            $restDay = ! ShiftResolver::worksOn($date, $week)
                || HolidaySetting::on($date, $holidays) !== null;

            $workedMinutes = 0.0;
            $lateMinutes = 0.0;
            $undertimeMinutes = 0.0;
            $overtimeMinutes = 0.0;
            $breakMinutes = 0.0;
            $flags = [];
            $remarks = null;

            /*
             * Arriving early does not start the paid day. Someone who punches at
             * 07:30 on an 08:00 shift is on the clock from 08:00, so those 30
             * minutes are neither worked time nor overtime — each stretch is
             * measured from whichever came later, the punch or the shift's own
             * start. The clamp only ever trims an early arrival; a late one is
             * already past the start and is untouched, and nothing here shortens
             * the other end of the day, so staying on past the shift still earns
             * overtime.
             *
             * A FLEXIBLE shift is exempt because it has no start to be early
             * for: it owes `required_hours` whenever they are worked, and its
             * `start_time` is only filler (see ShiftResolver's header).
             * `lateMinutes` skips FLEXIBLE for the same reason.
             *
             * `"{$date}T{$s->startTime}"` carries no offset, so ManilaClock
             * reads it as MANILA WALL CLOCK — which is what it is.
             */
            $clockOpensAt = $s->isFlexible()
                ? null
                : ManilaClock::ms(ManilaClock::instant($date.'T'.$s->startTime));

            /*
             * Sum each in→out pair rather than measuring first-in to last-out: a
             * lunch punch-out is time off the clock, not time worked.
             *
             * The pairs are kept because the gap between the first and the
             * second is the break itself — reading it off `outs[0]` instead
             * would mistake a stray TIME_OUT with no open TIME_IN for a lunch
             * punch.
             *
             * QUIRK, PRESERVED: the branch is `log_type === "TIME_IN"` and
             * EVERYTHING ELSE closes the pair. `log_type` is free text in the
             * schema, so a future 'BREAK_OUT' row would add to workedMinutes
             * here while being excluded from `$outs`, which is filtered strictly
             * on 'TIME_OUT'. The two filters disagree on purpose in the source
             * and the disagreement is ported.
             */

            /** @var list<array{in: string, out: string}> $pairs */
            $pairs = [];
            $openIn = null;
            foreach ($dayLogs as $log) {
                if ($log->logType === AttendanceLog::TIME_IN) {
                    // JS `if (!openIn)`: a second TIME_IN does not displace an
                    // open one, but an empty-string one would.
                    if ($openIn === null || $openIn === '') {
                        $openIn = $log->loggedAt;
                    }

                    continue;
                }
                if ($openIn !== null && $openIn !== '') {
                    $pairs[] = ['in' => $openIn, 'out' => $log->loggedAt];
                    $openIn = null;
                }
            }

            /** The punch out for the break, and the punch back in after it. */
            $breakOut = count($pairs) > 1 ? $pairs[0]['out'] : null;
            $breakIn = count($pairs) > 1 ? $pairs[1]['in'] : null;

            $unpaidWindow = $configuredBreakWindow;

            $usesScheduledBreakSlots = $scheduledBreakWindow !== null;
            $hasStatusBlockingOpenPunch = $usesScheduledBreakSlots && $openIn !== null && $openIn !== '';

            if ($usesScheduledBreakSlots) {
                $metrics = $this->scheduledBreakMetrics($date, $pairs, $openIn, $s, $geometry, $scheduledBreakWindow);

                $timeIn = $metrics['timeIn'];
                $breakOut = $metrics['breakOut'];
                $breakIn = $metrics['breakIn'];
                $timeOut = $metrics['timeOut'];
                $workedMinutes = $metrics['workedMinutes'];
                $lateMinutes = $metrics['lateMinutes'];
                $undertimeMinutes = $metrics['undertimeMinutes'];
                $overtimeMinutes = $metrics['overtimeMinutes'];
                $breakMinutes = $metrics['breakMinutes'];
                $flags = $metrics['flags'];
                $remarks = $metrics['remarks'];
            } else {
                foreach ($pairs as $pair) {
                    // `max(0, …)` covers a pair that also *closed* before the
                    // shift began: punching in and straight back out at 06:00
                    // earns nothing.
                    $workedMinutes += max(0.0, Minutes::round(
                        ($this->ms($pair['out']) - $this->onTheClockFrom($pair['in'], $clockOpensAt)) / 60000
                    ));
                }

                if ($punchIn !== null && ! $s->isFlexible()) {
                    // SECOND-granular, and the grace test is a strict `>`.
                    $inM = $this->minutesOfDay($punchIn);
                    if ($inM > $shiftStart + $s->graceMinutes) {
                        // Measured from the shift start, NOT from the end of grace:
                        // once someone is late, the grace period is not forgiven.
                        //
                        // But in PAYABLE minutes, not raw clock distance. Someone
                        // who arrives after the unpaid break has already passed was
                        // never being paid for it, and `late_deduction` is
                        // (lateMinutes / 60) * hourly — charging the break window
                        // here bills them for an hour nobody owed.
                        $lateMinutes = Minutes::round(
                            $inM - $shiftStart - self::breakOverlap($unpaidWindow, $shiftStart, $inM)
                        );
                    }
                }

                if ($punchIn !== null && $punchOut !== null) {
                    // Measured from the same clamped start as `workedMinutes`.
                    // Against the raw punch instead, every minute the clamp just
                    // dropped would reappear here as time spent on break.
                    $spanMinutes = max(0.0, Minutes::round(
                        ($this->ms($punchOut) - $this->onTheClockFrom($punchIn, $clockOpensAt)) / 60000
                    ));
                    $breakMinutes = max(0.0, $spanMinutes - $workedMinutes);

                    // QUIRK, PRESERVED: a flat +1440 whenever the two punches land
                    // on different Manila days — even if they are two days apart.
                    $outM = $this->minutesOfDay($punchOut)
                        + ($this->dayKey($punchOut) !== $this->dayKey($punchIn) ? (float) self::MINUTES_PER_DAY : 0.0);

                    // Nobody punched out for the break: deduct the shift's allowance
                    // instead, so a forgotten lunch punch is not quietly paid.
                    //
                    // Only when the break was there to take, though. A shift that
                    // says WHERE its break sits can be checked against the hours
                    // actually on the clock, and someone who clocks in after the
                    // window has closed never had one — deducting it turns four
                    // worked hours into three. A shift that gives only
                    // `breakMinutes` says nothing about when, so it keeps the flat
                    // deduction it has always had.
                    // The same clamp `workedMinutes` uses: a FIXED shift does not
                    // start paying before its start_time, and a FLEXIBLE one has no
                    // start to clamp to.
                    $clampedIn = $s->isFlexible()
                        ? $this->minutesOfDay($punchIn)
                        : max($this->minutesOfDay($punchIn), $shiftStart);

                    // A shift that says WHERE its break sits is charged exactly the
                    // minutes the window and the clock actually SHARE. Someone who
                    // leaves before the window opens never had a break and loses
                    // nothing; someone on the clock for half of it loses half.
                    //
                    // It used to be a flat hour whenever the window was so much as
                    // touched, which broke the arithmetic in both directions: a
                    // 08:00-12:30 day lost a full hour to a window it had only
                    // reached the first half of. Charging the shared minutes is
                    // what lets late, worked and undertime partition the paid span
                    // exactly -- and they must, because all three are priced from
                    // the same hourly rate.
                    $unpaidOnClock = $unpaidWindow === null
                        // No window means no "when", so the flat allowance stands.
                        ? $s->breakAllowance()
                        : self::breakOverlap($unpaidWindow, $clampedIn, $outM);

                    // The strict `>` is deliberate and leaves one case uneven: a
                    // day spent ENTIRELY inside the window keeps its worked minutes
                    // rather than dropping to zero. Zeroing them would be tidier
                    // arithmetic and worse pay -- `workedMinutes > 0` at
                    // PayslipCalculator.php:240 is what credits the work day at
                    // all, so the employee would lose the day's basic pay AND still
                    // carry the full late-plus-undertime deduction.
                    if ($breakMinutes === 0.0 && $unpaidOnClock > 0 && $workedMinutes > $unpaidOnClock) {
                        $workedMinutes -= $unpaidOnClock;
                        $breakMinutes = $unpaidOnClock;
                    }

                    if ($s->isFlexible()) {
                        $undertimeMinutes = max(0.0, Minutes::round($requiredMinutes - $workedMinutes));
                    } elseif ($outM < $shiftEnd) {
                        // In PAYABLE minutes, exactly like lateness above. The gap
                        // between an early punch out and the shift end can contain
                        // the unpaid window, and nobody was paying for that hour.
                        // Charging the raw clock distance bills the employee a
                        // second time for a break they were already not paid for.
                        //
                        // Clamped to the shift start for the same reason
                        // `workedMinutes` is: someone who punched out at 07:15 on
                        // an 08:00 shift owes the paid day, not the paid day plus
                        // the 45 minutes before it began.
                        $shortFrom = max($outM, $shiftStart);
                        $undertimeMinutes = Minutes::round(
                            $shiftEnd - $shortFrom - self::breakOverlap($unpaidWindow, $shortFrom, $shiftEnd)
                        );
                    }

                    // Overtime is measured against the PAID span, not the required
                    // one, so a shift with an unpaid hour of lunch starts earning
                    // overtime an hour before it ends.
                    if ($workedMinutes > $paidShiftLength) {
                        $overtimeMinutes = $workedMinutes - $paidShiftLength;
                    }
                }
            }

            $onOfficialBusiness = $excuse !== null && $excuse->requestType === self::OFFICIAL_BUSINESS;
            // A rest day is not a scheduled workday, so leave falling on one is
            // neither paid nor deducted.
            $onLeave = $excuse !== null && ! $onOfficialBusiness && ! ($restDay && $punchIn === null);
            $halfDay = $onLeave && $excuse->dayType === LeaveRequest::DAY_TYPE_HALF;

            if ($onOfficialBusiness) {
                // Approved OB is time worked elsewhere — never late, never an
                // absence, and credited with the shift's hours when no punch was
                // possible.
                $status = DayRecord::OFFICIAL_BUSINESS;
                if (! $workedMinutes) {
                    $workedMinutes = $paidShiftLength;
                }
                $lateMinutes = 0.0;
                $undertimeMinutes = 0.0;
                // QUIRK, PRESERVED: `overtimeMinutes` is NOT cleared here, so an
                // OB day that also carried punches past the paid span still
                // earns overtime — while a punchless OB day is credited exactly
                // the paid span and so earns none.
            } elseif ($punchIn !== null && $punchOut !== null && ! $hasStatusBlockingOpenPunch) {
                $status = $lateMinutes > 0 ? DayRecord::LATE : DayRecord::PRESENT;
            } elseif ($punchIn !== null) {
                $status = DayRecord::INCOMPLETE;
            } elseif ($excuse !== null) {
                $status = DayRecord::EXCUSED;
            } elseif ($restDay) {
                $status = DayRecord::EXCUSED;
            } else {
                $status = DayRecord::ABSENT;
            }

            if ($excuse !== null && $punchIn === null) {
                $lateMinutes = 0.0;
                $undertimeMinutes = 0.0;
            } elseif ($halfDay) {
                // Half the shift was approved off, so a punch inside the other
                // half is neither late nor undertime.
                //
                // The two are denominated differently, so they take different
                // halves. `lateMinutes` is PAYABLE minutes -- the unpaid break
                // is already netted out of it above -- so it is forgiven half
                // the PAID day. `undertimeMinutes` is still raw clock distance
                // back from `shiftEnd`, so it is forgiven half the RAW span.
                // Halving the raw span for both hands back half the break a
                // second time on a half-day that is also late.
                $lateMinutes = max(0.0, $lateMinutes - Minutes::round($paidShiftLength / 2));
                $undertimeMinutes = max(0.0, $undertimeMinutes - Minutes::round($shiftLength / 2));
            }

            $records[] = new DayRecord(
                date: $date,
                timeIn: $timeIn,
                breakOut: $breakOut,
                breakIn: $breakIn,
                timeOut: $timeOut,
                workedMinutes: $workedMinutes,
                lateMinutes: $lateMinutes,
                undertimeMinutes: $undertimeMinutes,
                overtimeMinutes: $overtimeMinutes,
                excused: $excuse !== null || ($punchIn === null && $restDay),
                excuseType: $excuse?->requestType,
                // `is_paid ?? true`: an approved row that never recorded the
                // flag is read as PAID.
                excusePaid: $onLeave ? ($excuse->isPaid ?? true) : null,
                breakMinutes: $breakMinutes,
                // Only the part of the day the employee was excused from counts:
                // half a day for a half-day request, and nothing at all when
                // someone on whole-day leave came in and worked anyway.
                excuseDays: ! $onLeave ? 0.0 : ($halfDay ? 0.5 : ($punchIn !== null ? 0.0 : 1.0)),
                status: $status,
                flags: $flags,
                remarks: $remarks,
            );
        }

        return $records;
    }

    /**
     * TS: `summarize(days)` — kept as a passthrough so callers that ported a
     * `summarize(computeDtr(...))` chain do not have to learn a second class.
     *
     * @param  list<DayRecord>  $days
     */
    public static function summarize(array $days): DtrSummary
    {
        return DtrSummary::of($days);
    }

    /**
     * Overnight shifts with scheduled break slots can have a next-day Time In
     * that still belongs to the same work date. The original carry slice stops
     * before the first next-day TIME_IN, which is right for a simple
     * Time-In/Time-Out night shift but wrong for a 22:00-06:00 shift with a
     * 02:00-03:00 break.
     *
     * @param  list<AttendanceLog>  $nextLogs
     * @return list<AttendanceLog>
     */
    private function scheduledOvernightCarryLogs(string $date, array $nextLogs, float $shiftEnd): array
    {
        usort(
            $nextLogs,
            fn (AttendanceLog $a, AttendanceLog $b): int => $this->ms($a->loggedAt) <=> $this->ms($b->loggedAt),
        );

        $carry = [];
        foreach ($nextLogs as $log) {
            $minute = $this->timelineMinute($date, $log->loggedAt);
            if (! is_finite($minute)) {
                continue;
            }

            if ($minute <= $shiftEnd) {
                $carry[] = $log;

                continue;
            }

            if ($log->logType !== AttendanceLog::TIME_IN && $minute <= $shiftEnd + (12 * 60)) {
                $carry[] = $log;

                continue;
            }

            break;
        }

        return $carry;
    }

    /**
     * A fixed shift with a real break window is two paid work segments:
     * shift start -> break start, then break end -> shift end. The raw stream
     * still stores only TIME_IN/TIME_OUT, but the DTR slots are read as
     * Time In, Time Out, Time In, Time Out.
     *
     * @param  list<array{in: string, out: string}>  $pairs
     * @param  array{0: float, 1: float}  $breakWindow
     * @return array{timeIn: ?string, breakOut: ?string, breakIn: ?string, timeOut: ?string,
     *     workedMinutes: float, lateMinutes: float, undertimeMinutes: float,
     *     overtimeMinutes: float, breakMinutes: float, flags: list<string>, remarks: ?string}
     */
    private function scheduledBreakMetrics(
        string $date,
        array $pairs,
        ?string $openIn,
        Shift $shift,
        ShiftGeometry $geometry,
        array $breakWindow,
    ): array {
        $firstStart = $geometry->start;
        $firstEnd = $breakWindow[0];
        $secondStart = $breakWindow[1];
        $secondEnd = $geometry->end;
        $midpoint = $firstEnd + (($secondStart - $firstEnd) / 2);

        $timeIn = null;
        $firstTimeOut = null;
        $secondTimeIn = null;
        $timeOut = null;

        $firstTimeInMinute = null;
        $firstTimeOutMinute = null;
        $secondTimeInMinute = null;
        $timeOutMinute = null;
        $firstSpanStart = null;
        $lastSpanEnd = null;
        $hasFirstSegment = false;
        $hasSecondSegment = false;
        $regularWorked = 0.0;
        $overtimeMinutes = 0.0;
        $flags = [];

        foreach ($pairs as $pair) {
            $inM = $this->timelineMinute($date, $pair['in']);
            $outM = $this->timelineMinute($date, $pair['out']);

            if (! is_finite($inM) || ! is_finite($outM) || $outM <= $inM) {
                continue;
            }

            $morning = self::spanOverlap($inM, $outM, $firstStart, $firstEnd);
            $afternoon = self::spanOverlap($inM, $outM, $secondStart, $secondEnd);

            if ($morning > 0.0) {
                $hasFirstSegment = true;
                if ($timeIn === null) {
                    $timeIn = $pair['in'];
                    $firstTimeInMinute = $inM;
                }

                // 12:29 still belongs to the first out side of a 12:00-13:00
                // break; from the midpoint forward the same clock range is the
                // second Time In window. Late/mistyped imports still get shown
                // in their nearest slot, with a flag below.
                if ($outM <= $secondStart) {
                    $firstTimeOut = $pair['out'];
                    $firstTimeOutMinute = $outM;
                }
            }

            if ($afternoon > 0.0) {
                $hasSecondSegment = true;
            }

            if ($inM >= $midpoint && $secondTimeIn === null) {
                $secondTimeIn = $pair['in'];
                $secondTimeInMinute = $inM;
            } elseif ($inM >= $firstEnd && $secondTimeIn === null) {
                // Imported/legacy data can have a TIME_IN before the live
                // station would allow it. Keep it visible, but flag it.
                $secondTimeIn = $pair['in'];
                $secondTimeInMinute = $inM;
            }

            if ($afternoon > 0.0 || $outM > $secondStart) {
                $timeOut = $pair['out'];
                $timeOutMinute = $outM;
            }

            if ($morning > 0.0 || $afternoon > 0.0) {
                $firstSpanStart = $firstSpanStart === null ? $inM : min($firstSpanStart, $inM);
                $lastSpanEnd = $lastSpanEnd === null ? $outM : max($lastSpanEnd, $outM);
            }

            $regularWorked += Minutes::round($morning) + Minutes::round($afternoon);

            if ($outM > $secondEnd) {
                $overtimeMinutes += Minutes::round(max(0.0, $outM - max($inM, $secondEnd)));
            }
        }

        if ($openIn !== null && $openIn !== '') {
            $openM = $this->timelineMinute($date, $openIn);
            if (is_finite($openM)) {
                if ($openM < $firstEnd) {
                    if ($timeIn === null) {
                        $timeIn = $openIn;
                        $firstTimeInMinute = $openM;
                    }
                } elseif ($secondTimeIn === null) {
                    $secondTimeIn = $openIn;
                    $secondTimeInMinute = $openM;
                }
            }
        }

        $lateMinutes = 0.0;
        $forgivenStartGap = 0.0;
        if ($firstTimeInMinute !== null) {
            $lateUntil = min(max($firstTimeInMinute, $firstStart), $firstEnd);

            if ($firstTimeInMinute > $firstStart + $shift->graceMinutes) {
                $lateMinutes = Minutes::round(max(0.0, $lateUntil - $firstStart));
            } elseif ($firstTimeInMinute > $firstStart) {
                $forgivenStartGap = Minutes::round(max(0.0, $lateUntil - $firstStart));
            }

            if ($firstTimeInMinute < $firstStart - ShiftResolver::EARLY_PUNCH_WINDOW_MINUTES) {
                $flags[] = 'First Time In was recorded before the allowed window.';
            }
        }

        if ($secondTimeInMinute !== null) {
            $lateUntil = min(max($secondTimeInMinute, $secondStart), $secondEnd);

            if ($secondTimeInMinute > $secondStart + $shift->graceMinutes) {
                $lateMinutes += Minutes::round(max(0.0, $lateUntil - $secondStart));
            } elseif ($secondTimeInMinute > $secondStart) {
                $forgivenStartGap += Minutes::round(max(0.0, $lateUntil - $secondStart));
            }

            if ($secondTimeInMinute < $midpoint) {
                $flags[] = 'Second Time In was recorded before its allowed window.';
            }
        }

        $breakMinutes = 0.0;
        if ($hasFirstSegment && $firstSpanStart !== null && $lastSpanEnd !== null) {
            $breakMinutes = Minutes::round(self::breakOverlap(
                $breakWindow,
                max($firstSpanStart, $firstStart),
                min($lastSpanEnd, $secondEnd),
            ));
        }

        $undertimeMinutes = $openIn !== null && $openIn !== ''
            ? 0.0
            : max(0.0, Minutes::round(
                $geometry->paidMinutes - $regularWorked - $lateMinutes - $forgivenStartGap
            ));

        if ($timeIn === null && $secondTimeIn !== null) {
            $flags[] = 'First work segment was missed.';
        }

        if ($hasFirstSegment && $hasSecondSegment && ($firstTimeOut === null || $secondTimeIn === null)) {
            $missing = [];
            if ($firstTimeOut === null) {
                $missing[] = 'First Time Out';
            }
            if ($secondTimeIn === null) {
                $missing[] = 'Second Time In';
            }
            $flags[] = 'Missing middle punch: '.implode(' and ', $missing).'.';
        }

        if ($firstTimeOut !== null && $secondTimeIn === null && ! $hasSecondSegment) {
            $flags[] = 'Second Time In was not recorded after First Time Out.';
        }

        if ($timeOut === null && ($timeIn !== null || $secondTimeIn !== null)) {
            $flags[] = 'Final Time Out was not recorded.';
        }

        if ($firstTimeOutMinute !== null) {
            if ($firstTimeOutMinute < $firstEnd) {
                $early = (int) Minutes::round($firstEnd - $firstTimeOutMinute);
                $flags[] = "First Time Out was {$early} min before the scheduled break.";
            } elseif ($firstTimeOutMinute >= $midpoint && $firstTimeOutMinute <= $secondStart) {
                $flags[] = 'First Time Out was recorded inside the Second Time In window.';
            }
        }

        if ($secondTimeInMinute !== null && $secondTimeInMinute > $secondStart + $shift->graceMinutes) {
            $late = (int) Minutes::round($secondTimeInMinute - $secondStart);
            $flags[] = "Second Time In was {$late} min late.";
        }

        if ($timeOutMinute !== null && $timeOutMinute < $secondEnd) {
            $early = (int) Minutes::round($secondEnd - $timeOutMinute);
            $flags[] = "Final Time Out was {$early} min before shift end.";
        }

        $flags = array_values(array_unique($flags));

        return [
            'timeIn' => $timeIn,
            'breakOut' => $firstTimeOut,
            'breakIn' => $secondTimeIn,
            'timeOut' => $timeOut,
            'workedMinutes' => $regularWorked + $overtimeMinutes,
            'lateMinutes' => $lateMinutes,
            'undertimeMinutes' => $undertimeMinutes,
            'overtimeMinutes' => $overtimeMinutes,
            'breakMinutes' => $breakMinutes,
            'flags' => $flags,
            'remarks' => $flags === [] ? null : implode(' ', $flags),
        ];
    }

    /**
     * TS: `onTheClockFrom(iso)` — dtr.ts:331-333. A punch, or the shift's start
     * when the punch landed before it. Integer milliseconds throughout.
     */
    private function onTheClockFrom(string $iso, ?int $clockOpensAt): int
    {
        $at = $this->ms($iso);

        return $clockOpensAt === null ? $at : max($at, $clockOpensAt);
    }

    /** Integer milliseconds since the epoch — never a float, see the class header. */
    private function ms(string $iso): int
    {
        return $this->msCache[$iso] ??= ManilaClock::ms(ManilaClock::instant($iso));
    }

    /** The Manila calendar day a punch belongs to. */
    private function dayKey(string $iso): string
    {
        return $this->dayCache[$iso] ??= ManilaClock::dayKey(ManilaClock::instant($iso));
    }

    /** Minutes past Manila midnight, SECOND-granular. See rule 1 in the header. */
    private function minutesOfDay(string $iso): float
    {
        return $this->minuteCache[$iso] ??= ManilaClock::minutesOfDay(ManilaClock::instant($iso));
    }

    /** Minutes from the Manila midnight of the DTR row's date. */
    private function timelineMinute(string $date, string $iso): float
    {
        $base = ManilaClock::ms(ManilaClock::instant($date.'T00:00:00'));

        return (ManilaClock::ms(ManilaClock::instant($iso)) - $base) / 60000;
    }

    /**
     * Legacy unpaid break window for the pre-slot arithmetic. This keeps the
     * TypeScript-compatible path stable for historical or malformed rows whose
     * break window exists but is not a valid scheduled slot window.
     *
     * @return array{0: float, 1: float}|null
     */
    private static function configuredBreakWindow(Shift $s, bool $overnight): ?array
    {
        if ($s->breakStart === null || $s->breakEnd === null || $s->breakAllowance() <= 0) {
            return null;
        }

        $start = ShiftResolver::clockMinutes($s->breakStart);
        $end = ShiftResolver::clockMinutes($s->breakEnd);
        if (! ($end > $start)) {
            return null;
        }

        if ($overnight && $start < ShiftResolver::clockMinutes($s->startTime)) {
            $start += self::MINUTES_PER_DAY;
            $end += self::MINUTES_PER_DAY;
        }

        return [$start, $end];
    }

    /**
     * TS: `breakOverlap(window, from, to)` — how many minutes of `window` fall
     * inside [from, to).
     *
     * @param  array{0: float, 1: float}|null  $window
     */
    private static function breakOverlap(?array $window, float $from, float $to): float
    {
        if ($window === null) {
            return 0.0;
        }

        return self::spanOverlap($from, $to, $window[0], $window[1]);
    }

    /** How many minutes two half-open spans share. */
    private static function spanOverlap(float $from, float $to, float $windowFrom, float $windowTo): float
    {
        return max(0.0, min($to, $windowTo) - max($from, $windowFrom));
    }
}
