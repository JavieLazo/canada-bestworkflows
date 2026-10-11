<?php

declare(strict_types=1);

use App\Domain\Attendance\Shift;
use App\Modules\Timekeeping\Domain\Roster\EffectiveDatePolicy;

/**
 * A schedule change starts today or later; today only until today's shift
 * (the one scheduled, and the one proposed) has begun and before its first
 * punch; and never so that one shift is still scheduled to be running when
 * the next date's starts.
 */
/** @param list<int>|null $days */
function edpShift(string $start, string $end, ?array $days = null, string $mode = 'FIXED'): Shift
{
    return Shift::fromArray(['start_time' => $start, 'end_time' => $end, 'grace_minutes' => 0, 'mode' => $mode, 'required_hours' => $mode === 'FLEXIBLE' ? 8 : null, 'work_days' => $days ?? [1, 2, 3, 4, 5]]);
}

it('refuses a date that is not real or is before today, and accepts today and later', function () {
    expect(EffectiveDatePolicy::dateError('2026-10-04', '2026-10-05'))->toBe('A schedule change cannot start in the past. Choose today or a later date.')
        ->and(EffectiveDatePolicy::dateError('2026-02-30', '2026-10-05'))->toBe('Choose a valid calendar date.')
        ->and(EffectiveDatePolicy::dateError('tomorrow', '2026-10-05'))->toBe('Choose a valid calendar date.')
        ->and(EffectiveDatePolicy::dateError('2026-10-05', '2026-10-05'))->toBeNull()
        ->and(EffectiveDatePolicy::dateError('2027-01-01', '2026-10-05'))->toBeNull();
});

it('allows today only before the scheduled shift, the proposed shift and the first punch', function () {
    // Monday 2026-10-05. Scheduled 08:00–17:00; proposed 13:00–22:00.
    $day = edpShift('08:00:00', '17:00:00');
    $late = edpShift('13:00:00', '22:00:00');

    expect(EffectiveDatePolicy::todayCutoff($day, $late, '2026-10-05', 7 * 60 + 59, false))->toBeNull()
        ->and(EffectiveDatePolicy::todayCutoff($day, $late, '2026-10-05', 8 * 60, false))->toBe('the shift scheduled for today has already started')
        ->and(EffectiveDatePolicy::todayCutoff($late, $day, '2026-10-05', 9 * 60, false))->toBe('the new shift would already have started today')
        ->and(EffectiveDatePolicy::todayCutoff($late, $late, '2026-10-05', 12 * 60, true))->toBe('a punch is already recorded for today’s shift');
});

it('has no shift to have started on a rest day, and only the first punch counts for a flexible shift', function () {
    $day = edpShift('08:00:00', '17:00:00');
    $flexible = edpShift('08:00:00', '17:00:00', null, 'FLEXIBLE');

    // Sunday 2026-10-04 rests on both schedules, at any hour.
    expect(EffectiveDatePolicy::todayCutoff($day, $day, '2026-10-04', 15 * 60, false))->toBeNull()
        // A flexible shift's clock is filler: 15:00 has not "started" it.
        ->and(EffectiveDatePolicy::todayCutoff($flexible, $flexible, '2026-10-05', 15 * 60, false))->toBeNull()
        ->and(EffectiveDatePolicy::todayCutoff($flexible, $flexible, '2026-10-05', 6 * 60, true))->toBe('a punch is already recorded for today’s shift')
        // No assigned schedule is no shift: the engine default cannot have started.
        ->and(EffectiveDatePolicy::todayCutoff(null, null, '2026-10-05', 8 * 60 + 1, false))->toBeNull()
        ->and(EffectiveDatePolicy::todayCutoff(null, $day, '2026-10-05', 8 * 60 + 1, false))->toBe('the new shift would already have started today');
});

it('finds a night still scheduled to be running when the next date starts, and nothing when it ends first', function () {
    // Nights 22:00–07:00 Mon–Fri, with Friday 2026-10-09 changed to a 06:00 day shift.
    $nights = edpShift('22:00:00', '07:00:00');
    $clash = $nights->withDateShifts(['2026-10-09' => edpShift('06:00:00', '15:00:00', [5])]);
    $clear = $nights->withDateShifts(['2026-10-09' => edpShift('07:00:00', '16:00:00', [5])]);

    expect(EffectiveDatePolicy::overlaps($clash, '2026-10-07', '2026-10-09'))->toBe([['date' => '2026-10-08', 'ends' => '07:00', 'next' => '2026-10-09', 'starts' => '06:00']])
        // Ending exactly when the next one starts is not an overlap.
        ->and(EffectiveDatePolicy::overlaps($clear, '2026-10-07', '2026-10-09'))->toBe([])
        // Night to night never overlaps itself; a rest day after a night has no shift to clash with.
        ->and(EffectiveDatePolicy::overlaps($nights, '2026-10-05', '2026-10-11'))->toBe([])
        ->and(EffectiveDatePolicy::overlaps($clash, '2026-10-10', '2026-10-09'))->toBe([]);
});

it('ignores flexible shifts and the default schedule when looking for overlaps', function () {
    $flexible = edpShift('08:00:00', '17:00:00', null, 'FLEXIBLE');
    $mixed = edpShift('22:00:00', '07:00:00')->withDateShifts(['2026-10-09' => $flexible]);

    expect(EffectiveDatePolicy::overlaps($mixed, '2026-10-07', '2026-10-10'))->toBe([])
        ->and(EffectiveDatePolicy::overlaps(null, '2026-10-05', '2026-10-11'))->toBe([]);
});
