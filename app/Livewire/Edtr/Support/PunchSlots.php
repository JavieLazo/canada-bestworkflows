<?php

declare(strict_types=1);

namespace App\Livewire\Edtr\Support;

use App\Domain\Attendance\Minutes;
use Carbon\CarbonImmutable;

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
 * 'break_in' => ?string, 'time_out' => ?string]`, each 'HH:MM' or null.
 */
final class PunchSlots
{
    /** TS: `MAX_CORRECTED_SPAN_HOURS` — the longest day a correction may describe. */
    public const MAX_CORRECTED_SPAN_HOURS = 20;

    public const KEYS = ['time_in', 'break_out', 'break_in', 'time_out'];

    private const HHMM = '/^([01]\d|2[0-3]):([0-5]\d)$/';

    private function __construct() {}

    /**
     * TS: `punchSlotsError(slots, options)` — why this day cannot be saved,
     * or null.
     *
     * @param  array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}  $slots
     */
    public static function error(array $slots, bool $overnightShift = false): ?string
    {
        $entries = [
            ['Time in', $slots['time_in']],
            ['Break out', $slots['break_out']],
            ['Break in', $slots['break_in']],
            ['Time out', $slots['time_out']],
        ];

        foreach ($entries as [$label, $value]) {
            if ($value !== null && preg_match(self::HHMM, $value) !== 1) {
                return "{$label} must be a time like 08:00.";
            }
        }

        $hasBreakOut = $slots['break_out'] !== null;
        $hasBreakIn = $slots['break_in'] !== null;
        if ($hasBreakOut !== $hasBreakIn) {
            return 'Record both halves of the break, or neither — a break out with no break in never closes.';
        }
        if ($slots['time_in'] === null && ($hasBreakOut || $slots['time_out'] !== null)) {
            return 'A day needs a time in before anything else can be recorded against it.';
        }

        // A time reading earlier than the one before it means the day crossed
        // midnight. That is how a night shift is written, and a typo on any
        // other shift — the two are identical as times, so the shift decides.
        ['minutes' => $minutes, 'rolled' => $rolled] = self::orderedMinutes($slots);
        if ($rolled && ! $overnightShift) {
            return 'A time here reads earlier than the one before it. That only works on a shift that runs overnight — check the order.';
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
     * becomes, oldest first, as OFFSET-LESS Manila wall-clock timestamps
     * (`2026-01-05` + 1500 minutes → `2026-01-06T01:00:00`).
     *
     * @param  array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}  $slots
     * @return list<array{log_type: string, logged_at: string}>
     */
    public static function punches(string $date, array $slots): array
    {
        $types = [];
        if ($slots['time_in'] !== null) {
            $types[] = 'TIME_IN';
        }
        if ($slots['break_out'] !== null) {
            $types[] = 'TIME_OUT';
        }
        if ($slots['break_in'] !== null) {
            $types[] = 'TIME_IN';
        }
        if ($slots['time_out'] !== null) {
            $types[] = 'TIME_OUT';
        }

        $minutes = self::orderedMinutes($slots)['minutes'];

        // One type per filled slot, in slot order — orderedMinutes() emits its
        // minutes in exactly that order, so the two lists zip index-for-index.
        $out = [];
        foreach ($types as $index => $type) {
            if (! array_key_exists($index, $minutes)) {
                break;
            }

            $out[] = [
                'log_type' => $type,
                'logged_at' => self::stampFrom($date, $minutes[$index]),
            ];
        }

        return $out;
    }

    /**
     * TS: `orderedMinutes(slots)` — the slots as minutes from midnight, each
     * rolled a day forward when it reads earlier than the one before, which is
     * how a 22:00-to-06:00 night is written. `rolled` is what tells a night
     * shift apart from a typo.
     *
     * @param  array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}  $slots
     * @return array{minutes: list<int>, rolled: bool}
     */
    private static function orderedMinutes(array $slots): array
    {
        $present = [];
        foreach ([$slots['time_in'], $slots['break_out'], $slots['break_in'], $slots['time_out']] as $value) {
            if ($value !== null) {
                $present[] = $value;
            }
        }

        $minutes = [];
        $rolled = false;
        $previous = -1;
        foreach ($present as $value) {
            [$h, $m] = explode(':', $value);
            $v = (int) $h * 60 + (int) $m;
            while ($v <= $previous) {
                $v += 24 * 60;
                $rolled = true;
            }
            $minutes[] = $v;
            $previous = $v;
        }

        return ['minutes' => $minutes, 'rolled' => $rolled];
    }

    /** TS: `stampFrom(date, minutes)` — `2026-01-05` + 1500 → `2026-01-06T01:00:00`. */
    private static function stampFrom(string $date, int $minutes): string
    {
        $dayOffset = intdiv($minutes, 24 * 60);
        $within = $minutes % (24 * 60);

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        $day = $day->addDays($dayOffset);

        return sprintf('%sT%02d:%02d:00', $day->format('Y-m-d'), intdiv($within, 60), $within % 60);
    }
}
