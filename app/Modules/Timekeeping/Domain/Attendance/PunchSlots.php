<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Attendance;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\Minutes;
use App\Support\LocalClock;

/**
 * Port of `copems/src/lib/attendance-correction.ts` — turning a corrected day
 * back into punches.
 *
 * The eDTR shows a day as four slots, but the record underneath is a stream of
 * punches — so a correction has to become punches again, in order, before it
 * means anything. A correction supersedes the day rather than adding to it:
 * two Time Ins would be counted twice. Clearing every slot is allowed — that
 * is how a day punched by mistake is taken back off the record.
 *
 * A slots array is `['time_in' => ?string, 'break_out' => ?string,
 * 'break_in' => ?string, 'time_out' => ?string]`, each 'HH:MM' or null. A
 * next-day array has the same four keys, each a bool: the slot's "Next day"
 * tick.
 *
 * DELIBERATE DIVERGENCE: the reference infers the day from the ORDER of the
 * times — a slot reading earlier than the one before it is rolled a day
 * forward (`orderedMinutes`), and only an overnight shift can roll. That
 * guesses wrong in both directions: a night whose Time In falls after
 * midnight is written back onto the wrong morning, and a FLEXIBLE night is
 * refused because its stored 08:00–17:00 filler times do not cross
 * midnight. Here each slot says for itself whether it is on the
 * next calendar day — the dialog's "Next day" tick, prefilled from the saved
 * punch — and nothing is inferred. Whether the shift may hold a next-day time
 * at all is CorrectionWindow's question, not this class's.
 */
final class PunchSlots
{
    /** TS: `MAX_CORRECTED_SPAN_HOURS` — the longest day a correction may describe. */
    public const MAX_CORRECTED_SPAN_HOURS = 20;

    public const KEYS = ['time_in', 'break_out', 'break_in', 'time_out'];

    /** No slot ticked "Next day": every punch on the corrected date. */
    public const SAME_DAY = ['time_in' => false, 'break_out' => false, 'break_in' => false, 'time_out' => false];

    /** What the DTR and the correction dialog call each slot. */
    public const LABELS = [
        'time_in' => 'First Time In',
        'break_out' => 'First Time Out',
        'break_in' => 'Second Time In',
        'time_out' => 'Final Time Out',
    ];

    /** The raw log type each slot is stored as — the four slots are a reading of TIME_IN/TIME_OUT pairs. */
    public const LOG_TYPES = [
        'time_in' => 'TIME_IN',
        'break_out' => 'TIME_OUT',
        'break_in' => 'TIME_IN',
        'time_out' => 'TIME_OUT',
    ];

    private const HHMM = '/^([01]\d|2[0-3]):([0-5]\d)$/';

    private function __construct() {}

    /**
     * The "Next day" ticks as every rule below reads them. Client-supplied,
     * so its shape is untrusted: a slot counts as next day only for a literal
     * `true`, and only beside a FILLED slot — a tick left beside a cleared box
     * says nothing about a punch that is not there.
     *
     * @param  array<array-key, mixed>  $raw
     * @param  array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}  $slots
     * @return array{time_in: bool, break_out: bool, break_in: bool, time_out: bool}
     */
    public static function nextDayFlags(array $raw, array $slots): array
    {
        $flags = self::SAME_DAY;
        foreach (self::KEYS as $key) {
            $flags[$key] = ($raw[$key] ?? false) === true && $slots[$key] !== null;
        }

        return $flags;
    }

    /**
     * The filled slots, in slot order — the order punches() emits them in.
     *
     * @param  array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}  $slots
     * @return list<string>
     */
    public static function filledKeys(array $slots): array
    {
        return array_values(array_filter(self::KEYS, static fn (string $key): bool => $slots[$key] !== null));
    }

    /**
     * TS: `punchSlotsError(slots, options)` — why this day cannot be saved,
     * or null.
     *
     * Once the ticks apply, every filled slot must be STRICTLY later than the
     * one before it (the reference rolls an equal time a day forward; with
     * explicit ticks an equal or earlier time is a typo or a missing tick, and
     * the message says which to check). `$nextDayAllowed` only words that
     * message: on a shift that ends the same day there is no tick to suggest.
     *
     * @param  array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}  $slots
     * @param  array{time_in: bool, break_out: bool, break_in: bool, time_out: bool}  $nextDay  nextDayFlags()
     */
    public static function error(array $slots, array $nextDay, bool $nextDayAllowed): ?string
    {
        foreach (self::KEYS as $key) {
            $value = $slots[$key];
            if ($value !== null && preg_match(self::HHMM, $value) !== 1) {
                return self::LABELS[$key].' must be a time like 08:00.';
            }
        }

        $hasBreakOut = $slots['break_out'] !== null;
        $hasBreakIn = $slots['break_in'] !== null;
        if ($hasBreakOut !== $hasBreakIn) {
            return 'Record both middle punches, or neither - First Time Out with no Second Time In never closes.';
        }
        if ($slots['time_in'] === null && ($hasBreakOut || $slots['time_out'] !== null)) {
            return 'A day needs a time in before anything else can be recorded against it.';
        }

        /** @var list<int> $minutes */
        $minutes = [];
        $previousKey = '';
        foreach (self::filledKeys($slots) as $key) {
            $ticked = ($nextDay[$key] ?? false) === true;
            $minute = self::minuteOfDay((string) $slots[$key]) + ($ticked ? 24 * 60 : 0);

            if ($minutes !== [] && $minute <= $minutes[count($minutes) - 1]) {
                $message = self::LABELS[$key].' must be later than '.self::LABELS[$previousKey].'.';
                if (! $ticked) {
                    $message .= $nextDayAllowed
                        ? ' If it was after midnight, tick Next day beside it.'
                        : ' This shift ends the same day — a time after midnight needs an overnight shift in Shift Roster first.';
                }

                return $message;
            }

            $minutes[] = $minute;
            $previousKey = $key;
        }

        $span = count($minutes) > 1 ? ($minutes[count($minutes) - 1] - $minutes[0]) / 60 : 0;
        if ($span > self::MAX_CORRECTED_SPAN_HOURS) {
            $hours = (int) Minutes::round((float) $span);

            return "That day spans {$hours} hours, which is longer than any shift. Check the times.";
        }

        return null;
    }

    /**
     * TS: `punchesFromSlots(date, slots)` — the punches a corrected day
     * becomes, in slot order, as OFFSET-LESS local wall-clock timestamps:
     * a slot on `$date`, or — ticked Next day — on the calendar day after it
     * (`2026-01-05` + `01:00` ticked → `2026-01-06T01:00:00`). Call it on
     * slots error() accepted; that is what makes slot order time order.
     *
     * @param  array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}  $slots
     * @param  array{time_in: bool, break_out: bool, break_in: bool, time_out: bool}  $nextDay  nextDayFlags()
     * @return list<array{log_type: string, logged_at: string}>
     */
    public static function punches(string $date, array $slots, array $nextDay): array
    {
        $out = [];
        foreach (self::filledKeys($slots) as $key) {
            [$h, $m] = array_map(intval(...), explode(':', (string) $slots[$key]) + [1 => '0']);
            $day = ($nextDay[$key] ?? false) === true ? CalendarDays::next($date) : $date;

            $out[] = [
                'log_type' => self::LOG_TYPES[$key],
                'logged_at' => sprintf('%sT%02d:%02d:00', $day, $h, $m),
            ];
        }

        return $out;
    }

    /**
     * Whether a punch reads as the NEXT day of the DTR row keyed on `$date` —
     * its local calendar day is after the row's. THE one definition of "next
     * day": the DTR table's tag, the dialog's prefilled ticks and the audit
     * sentence all ask here. Never compare the first ten characters of a
     * stored `Z` stamp: a 06:00 local punch is 22:00 the previous UTC day.
     */
    public static function isNextDay(?string $iso, string $date): bool
    {
        if ($iso === null || $iso === '') {
            return false;
        }

        return strcmp(LocalClock::dayKey(LocalClock::instant($iso)), $date) > 0;
    }

    /**
     * The local calendar minute a punch reads as — `Y-m-d\TH:i`, seconds
     * and sub-seconds floored, never rounded.
     *
     * ONE definition, used on both sides of a correction: the dialog prefills
     * a slot from it (slotOf), and CorrectionWindow::reconcile decides from it
     * whether a stored row still IS that slot and may be kept. Accepts the
     * offset-less local stamps punches() emits (instant() reads them as
     * local) and the `Z` stamps DomainLog::isoFromUtc emits (converted).
     */
    public static function minuteKey(string $iso): string
    {
        return LocalClock::instant($iso)->setTimezone(LocalClock::zone())->format('Y-m-d\TH:i');
    }

    /**
     * TS: `slotOf(iso)` — `2026-01-05T07:47:31` → `07:47`, the shape the time
     * fields speak. Seconds are dropped rather than rounded, so a 07:47:31
     * punch never reads back as 07:48 — and so a slot left as prefilled still
     * matches the row it came from. The day is not part of it: isNextDay()
     * answers that, beside it.
     */
    public static function slotOf(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        return substr(self::minuteKey($iso), 11);
    }

    /** 'HH:MM' → minutes from midnight. */
    private static function minuteOfDay(string $hhmm): int
    {
        [$h, $m] = explode(':', $hhmm);

        return (int) $h * 60 + (int) $m;
    }
}
