<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Attendance;

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Support\HrisFormat;
use App\Support\ManilaClock;

/**
 * Which stored punches a DTR correction of one day stands in for, which of
 * them it may leave standing, and whether the punches it would write read
 * back as that day.
 *
 * Two readers share these rules and must never disagree: the correction
 * dialog (Queries\DayCorrections, a plain read that SHOWS the rows a save
 * would supersede) and the correction itself (Commands\CorrectPunches, the
 * same rows read FOR UPDATE). Both hand in the rows of the previous, the
 * corrected and the next Manila day and get the same answer back.
 *
 * DELIBERATE DIVERGENCE: a save replaces EXACTLY the punches the DTR reads
 * as that date's row — DtrCalculator::rowPunches, the engine's own
 * grouping, not a copy of it. The reference's
 * `PUT /api/attendance/corrections` replaces the whole calendar day plus
 * the next day up to its first TIME_IN. That slice takes the previous
 * night's after-midnight Time Out, which sits on the corrected calendar
 * day, so saving the day after a night — even unchanged — would delete it
 * and leave the night INCOMPLETE; and on a night shift with a scheduled
 * break it stops short of the 03:00 Time In and 06:00 Time Out the DTR
 * shows, so a re-save would insert them twice.
 *
 * DELIBERATE DIVERGENCE: the reference deletes and re-inserts the whole day
 * on every save. `reconcile()` instead keeps a row whose type and Manila
 * floor-minute still equal one of the corrected slots, so a correction of
 * one slot never launders the day's WEB_GPS punches — and their captured
 * coordinates, distance and accuracy — into location-less CORRECTION rows.
 */
final class CorrectionWindow
{
    /**
     * The refusal for a date a posted payslip covers (Commands\CorrectPunches),
     * which the dialog also shows in place of its Save.
     */
    public const string PAID_DAY = 'That day is already on a posted payslip, so its punches can no longer change. Settle any difference on the next payroll.';

    private function __construct() {}

    /**
     * The rows a correction of `$date` supersedes: the punches the DTR reads
     * as that date's row, oldest first. Rows from any other span are
     * ignored, so the caller may pass the four-day span it selected
     * without trimming it.
     *
     * @param  list<DomainLog>  $logs  the two previous, corrected and next Manila days, ids set, any order
     * @param  DomainShift|null  $shift  the full weekday schedule; null when not rostered (the engine's default)
     * @return list<DomainLog>
     */
    public static function replaceable(string $date, array $logs, ?DomainShift $shift): array
    {
        return self::sorted(DtrCalculator::rowPunches($logs, $shift, $date));
    }

    /**
     * Whether the date's shift can hold a punch on the next calendar day at
     * all: an overnight shift, or a FLEXIBLE one (whose stored 08:00–17:00
     * are filler, so its geometry never reads as overnight). On a fixed
     * shift that ends the same day the engine carries nothing back, so a
     * next-day time would show on the next date's row instead.
     */
    public static function allowsNextDay(?DomainShift $rostered): bool
    {
        $shift = $rostered ?? DomainShift::default();

        return $shift->isFlexible() || ShiftResolver::geometry($shift)->overnight;
    }

    /**
     * The refusal for a "Next day" tick on a shift that cannot hold one, or
     * null. A night is entered in one row only on an overnight or flexible
     * shift; anyone else needs one assigned for that weekday first.
     *
     * @param  array{time_in: bool, break_out: bool, break_in: bool, time_out: bool}  $nextDay  PunchSlots::nextDayFlags()
     */
    public static function nextDayError(string $date, ?DomainShift $rostered, array $nextDay): ?string
    {
        if (! in_array(true, $nextDay, true) || self::allowsNextDay($rostered)) {
            return null;
        }

        $nextDate = HrisFormat::date(CalendarDays::next($date));
        $shift = $rostered ?? DomainShift::default();
        $hours = HrisFormat::hm($shift->startTime).' – '.HrisFormat::hm($shift->endTime);

        if ($rostered === null) {
            return "This employee has no shift in Shift Roster, and the default {$hours} day ends the same day, "
                ."so a Next day time would show on {$nextDate} instead. Assign an overnight shift in Shift Roster first.";
        }

        return 'The shift on '.HrisFormat::date($date)." ({$hours}) ends the same day, "
            ."so a Next day time would show on {$nextDate} instead. Assign an overnight shift in Shift Roster first.";
    }

    /**
     * Which existing rows a corrected day keeps, which it deletes, and which
     * desired punches it has to insert.
     *
     * A desired punch claims the first still-unclaimed existing row (in time
     * order) of the same log type that reads as the same Manila minute —
     * seconds ignored, exactly as the dialog's prefill floors them
     * (PunchSlots::slotOf). The day key is part of the minute, so a Next day
     * slot (`06:01` ticked on a 22:00 night → `2026-09-03T06:01`) matches the
     * carried next-day row and never a same-day one.
     *
     * What falls out of that, all pinned by CorrectionWindowTest: two rows of
     * one type in the same minute (a double tap) keep the earlier and drop the
     * later; a row whose minute matches but whose type differs is replaced; a
     * kept row keeps its seconds, and with them its lateness verdict; clearing
     * every slot deletes every row. Because PunchSlots::error refuses a slot
     * that is not strictly later than the one before it once the Next day
     * ticks apply, desired punches occupy strictly increasing distinct
     * minutes — so a kept `:31` row and an inserted `:00` row can never swap
     * places when DtrCalculator sorts by millisecond.
     *
     * `standing` is what the day reads as once the plan is applied, one entry
     * per desired punch in slot order: a kept row's REAL stamp (seconds and
     * all — that is the stamp its lateness verdict comes from) or the
     * inserted punch's `:00` stamp. The audit sentence prints it, so the
     * record says what stands, not what was typed. `claimed` is the same
     * walk by id: the kept row's id, or null where the punch is inserted.
     *
     * @param  list<DomainLog>  $existing  replaceable(), ids set
     * @param  list<array{log_type: string, logged_at: string}>  $desired  PunchSlots::punches(), slot order
     * @return array{keep: list<string>, delete: list<string>, insert: list<array{log_type: string, logged_at: string}>, standing: list<array{log_type: string, logged_at: string}>, claimed: list<?string>}
     */
    public static function reconcile(array $existing, array $desired): array
    {
        $existing = self::sorted($existing);
        $used = [];
        $keep = [];
        $insert = [];
        $standing = [];
        $claimed = [];

        foreach ($desired as $punch) {
            $wanted = PunchSlots::minuteKey($punch['logged_at']);
            $match = null;
            foreach ($existing as $row) {
                $id = (string) $row->id;
                if (isset($used[$id]) || $row->logType !== $punch['log_type']) {
                    continue;
                }
                if (PunchSlots::minuteKey($row->loggedAt) === $wanted) {
                    $match = $row;
                    break;
                }
            }

            if ($match === null) {
                $insert[] = $punch;
                $standing[] = $punch;
                $claimed[] = null;

                continue;
            }
            $used[(string) $match->id] = true;
            $keep[] = (string) $match->id;
            $standing[] = ['log_type' => $match->logType, 'logged_at' => $match->loggedAt];
            $claimed[] = (string) $match->id;
        }

        $delete = [];
        foreach ($existing as $row) {
            $id = (string) $row->id;
            if (! isset($used[$id])) {
                $delete[] = $id;
            }
        }

        return ['keep' => $keep, 'delete' => $delete, 'insert' => $insert, 'standing' => $standing, 'claimed' => $claimed];
    }

    /**
     * Why applying `$plan` would NOT leave the DTR reading `$date` as the
     * punches just saved, or null — asked before anything is written.
     *
     * The engine decides which row a punch belongs to, and a correction must
     * not quietly move punches between rows. So the save is simulated: the
     * span after the plan (the rows that stay, plus the inserted punches) is
     * handed to DtrCalculator::rowPunches, and the date must own exactly the
     * kept rows plus the inserted punches. Because every punch of the
     * corrected calendar day belongs either to the previous date or to this
     * one, and the next day's either to this one or its own, that single
     * equality also means neither neighbour gains or loses a punch; which
     * side a stray lands on only decides the wording.
     *
     * In order, the first that applies:
     *
     *   1. An inserted punch on the exact instant of a row that stays. The
     *      DTR reads rows in no fixed order, so which of the two comes first
     *      — and with it which row they belong to — would be down to chance.
     *   2. A saved punch the date would not own (the previous night's, or the
     *      next date's), then a staying row the date would newly own.
     *   3. A change that leaves the date ending on a Time In dated the next
     *      day. The engine carries that open Time In, and the next date then
     *      reads it as its own Time In (a rest day after it turns
     *      INCOMPLETE). Only a scheduled-break night can carry a Time In; a
     *      save that changes nothing is let through, so a remark can still be
     *      written on a night recorded that way at the station.
     *
     * @param  list<DomainLog>  $logs  the same four-day rows replaceable() was given, ids set
     * @param  list<array{log_type: string, logged_at: string}>  $desired  PunchSlots::punches(), slot order
     * @param  array{keep: list<string>, delete: list<string>, insert: list<array{log_type: string, logged_at: string}>, standing: list<array{log_type: string, logged_at: string}>, claimed: list<?string>}  $plan  reconcile()
     * @param  list<string>  $slotKeys  PunchSlots::filledKeys(), aligned with `$desired`
     */
    public static function misattribution(string $date, array $logs, array $desired, array $plan, ?DomainShift $shift, array $slotKeys): ?string
    {
        $deleted = array_fill_keys($plan['delete'], true);
        $staying = array_values(array_filter($logs, static fn (DomainLog $log): bool => ! isset($deleted[(string) $log->id])));

        // The desired punches as the rows they will be, in slot order: a kept
        // row itself, or the inserted punch under an id no ULID can take.
        $byId = [];
        foreach ($logs as $log) {
            $byId[(string) $log->id] = $log;
        }
        $wanted = [];
        $inserted = [];
        foreach ($desired as $index => $punch) {
            $id = $plan['claimed'][$index] ?? null;
            if ($id !== null && isset($byId[$id])) {
                $wanted[$id] = ['log' => $byId[$id], 'slot' => $slotKeys[$index] ?? null];

                continue;
            }
            $log = new DomainLog($punch['log_type'], $punch['logged_at'], 'new#'.count($inserted));
            $inserted[] = $log;
            $wanted[(string) $log->id] = ['log' => $log, 'slot' => $slotKeys[$index] ?? null];
        }

        foreach ($inserted as $log) {
            $at = ManilaClock::ms(ManilaClock::instant($log->loggedAt));
            foreach ($staying as $row) {
                if (ManilaClock::ms(ManilaClock::instant($row->loggedAt)) === $at) {
                    return self::describe($date, $log, $wanted[(string) $log->id]['slot'])
                        .' is the exact moment of another punch on record. Use a different minute.';
                }
            }
        }

        $owned = [];
        foreach (DtrCalculator::rowPunches([...$staying, ...$inserted], $shift, $date) as $log) {
            $owned[(string) $log->id] = $log;
        }

        foreach ($wanted as $id => ['log' => $log, 'slot' => $slot]) {
            if (isset($owned[$id])) {
                continue;
            }
            $what = self::describe($date, $log, $slot);
            if (! PunchSlots::isNextDay($log->loggedAt, $date)) {
                $previous = HrisFormat::date(CalendarDays::previous($date));

                return "{$what} would read as part of the {$previous} record, not this one. "
                    ."If it was after midnight, tick Next day beside it; if it belongs to {$previous}, correct that date instead.";
            }

            $message = "{$what} would be read as part of the ".HrisFormat::date(CalendarDays::next($date))
                .' record, not this date\'s. Check the times and the Next day boxes.';
            $dayShift = ($shift ?? DomainShift::default())->forDate($date);
            $geometry = ShiftResolver::geometry($dayShift);
            if ($log->logType === DomainLog::TIME_IN && ShiftResolver::scheduledBreakWindow($dayShift, $geometry) === null) {
                $message .= ' On this shift only a Time Out can fall on the next day.';
            }

            return $message;
        }

        foreach (self::sorted(array_values($owned)) as $log) {
            if (isset($wanted[(string) $log->id])) {
                continue;
            }
            $what = self::describe($date, $log, null);
            if (! PunchSlots::isNextDay($log->loggedAt, $date)) {
                $previous = HrisFormat::date(CalendarDays::previous($date));

                return "Saving would take {$what} away from the {$previous} record. Correct {$previous} instead, or check the times.";
            }
            $next = HrisFormat::date(CalendarDays::next($date));

            return "Saving would also pull {$what} from the {$next} record into this date. Correct {$next} first.";
        }

        if ($plan['delete'] !== [] || $plan['insert'] !== []) {
            $last = self::sorted(array_values($owned));
            $last = $last === [] ? null : $last[count($last) - 1];
            if ($last !== null && $last->logType === DomainLog::TIME_IN && PunchSlots::isNextDay($last->loggedAt, $date)) {
                return 'Record the Final Time Out too — without it the DTR reads '
                    .self::describe($date, $last, $wanted[(string) $last->id]['slot'] ?? null)
                    .' as the '.HrisFormat::date(CalendarDays::next($date)).' record\'s Time In.';
            }
        }

        return null;
    }

    /**
     * "Final Time Out 6:00 AM next day" for a slot, "the Time Out at 6:00 AM"
     * for a row no slot names — the Manila clock time, never its seconds, and
     * "next day" where it falls after the corrected date.
     */
    private static function describe(string $date, DomainLog $log, ?string $slot): string
    {
        $time = ManilaClock::instant($log->loggedAt)->setTimezone(ManilaClock::TZ)->format('g:i A')
            .(PunchSlots::isNextDay($log->loggedAt, $date) ? ' next day' : '');

        if ($slot !== null && isset(PunchSlots::LABELS[$slot])) {
            return PunchSlots::LABELS[$slot].' '.$time;
        }

        return 'the '.($log->logType === DomainLog::TIME_IN ? 'Time In' : 'Time Out').' at '.$time;
    }

    /**
     * Oldest first, ties broken by id — integer ms compared with `<=>`, never
     * a float comparator, which PHP would cast to int and sort by nothing.
     *
     * @param  list<DomainLog>  $logs
     * @return list<DomainLog>
     */
    private static function sorted(array $logs): array
    {
        usort(
            $logs,
            static fn (DomainLog $a, DomainLog $b): int => [ManilaClock::ms(ManilaClock::instant($a->loggedAt)), (string) $a->id]
                <=> [ManilaClock::ms(ManilaClock::instant($b->loggedAt)), (string) $b->id],
        );

        return $logs;
    }
}
