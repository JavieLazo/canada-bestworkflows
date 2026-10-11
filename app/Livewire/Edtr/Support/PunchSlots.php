<?php

declare(strict_types=1);

namespace App\Livewire\Edtr\Support;

/** Compatibility adapter; business logic belongs to \App\Modules\Timekeeping\Domain\Attendance\PunchSlots. */
final class PunchSlots
{
    /** TS: `MAX_CORRECTED_SPAN_HOURS` — the longest day a correction may describe. */
    public const MAX_CORRECTED_SPAN_HOURS = \App\Modules\Timekeeping\Domain\Attendance\PunchSlots::MAX_CORRECTED_SPAN_HOURS;

    public const KEYS = \App\Modules\Timekeeping\Domain\Attendance\PunchSlots::KEYS;

    /** No slot ticked "Next day". */
    public const SAME_DAY = \App\Modules\Timekeeping\Domain\Attendance\PunchSlots::SAME_DAY;

    /**
     * TS: `punchSlotsError(slots, options)` — why this day cannot be saved,
     * or null.
     *
     * @param  array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}  $slots
     * @param  array{time_in: bool, break_out: bool, break_in: bool, time_out: bool}  $nextDay
     */
    public static function error(array $slots, array $nextDay, bool $nextDayAllowed): ?string
    {
        return \App\Modules\Timekeeping\Domain\Attendance\PunchSlots::error($slots, $nextDay, $nextDayAllowed);
    }

    /**
     * TS: `punchesFromSlots(date, slots)` — the punches a corrected day
     * becomes, in slot order, as OFFSET-LESS Manila wall-clock timestamps; a
     * slot ticked Next day lands on the following date
     * (`2026-01-05` + `01:00` ticked → `2026-01-06T01:00:00`).
     *
     * @param  array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}  $slots
     * @param  array{time_in: bool, break_out: bool, break_in: bool, time_out: bool}  $nextDay
     * @return list<array{log_type: string, logged_at: string}>
     */
    public static function punches(string $date, array $slots, array $nextDay): array
    {
        return \App\Modules\Timekeeping\Domain\Attendance\PunchSlots::punches($date, $slots, $nextDay);
    }
}
