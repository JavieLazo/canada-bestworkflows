<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog;
use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\Shift;

/**
 * A FLEXIBLE day never computes overtime (DELIBERATE DIVERGENCE 11). It
 * owes its required hours: below them it is short by the difference, above
 * them the extra minutes stay worked minutes and never become overtime,
 * whether they came from an early arrival or a late departure. A FIXED
 * shift earns overtime for every minute on the clock past its paid span.
 */

/**
 * Named Shift arguments merged over an 8-hour Flexi with a 60-minute break allowance.
 *
 * @param  array{startTime?: string, endTime?: string, graceMinutes?: int, mode?: ?string, requiredHours?: ?float, workDays?: list<int>|null, breakMinutes?: ?int}  $overrides
 */
function fotFlexible(array $overrides = []): Shift
{
    return new Shift(...array_merge([
        // The filler clock times a FLEXIBLE row stores (ShiftResolver's header).
        'startTime' => '08:00:00',
        'endTime' => '17:00:00',
        'graceMinutes' => 15,
        'mode' => Shift::MODE_FLEXIBLE,
        'requiredHours' => 8.0,
        'workDays' => [1, 2, 3, 4, 5],
        'breakMinutes' => 60,
    ], $overrides));
}

function fotDay(string $in, string $out, Shift $shift, string $date = '2026-09-02'): DayRecord
{
    return DtrCalculator::compute(
        [new AttendanceLog('TIME_IN', $date.'T'.$in), new AttendanceLog('TIME_OUT', $date.'T'.$out)],
        $shift, [], $date, $date,
    )[0];
}

it('keeps an early arrival on a flexible day as worked minutes, never overtime', function () {
    // The demo's Maria Santos: in 06:55, out 16:05, 8 hours owed, an hour's break.
    $day = fotDay('06:55:00', '16:05:00', fotFlexible());

    expect($day->workedMinutes)->toBe(490.0)
        ->and($day->overtimeMinutes)->toBe(0.0)
        ->and($day->regularMinutes())->toBe(490.0)
        ->and($day->undertimeMinutes)->toBe(0.0)
        ->and($day->lateMinutes)->toBe(0.0)
        ->and($day->status)->toBe(DayRecord::PRESENT);
});

it('computes no overtime on a flexible day however long it runs', function () {
    $day = fotDay('08:00:00', '19:30:00', fotFlexible());

    expect($day->workedMinutes)->toBe(630.0)
        ->and($day->overtimeMinutes)->toBe(0.0)
        ->and(DtrCalculator::summarize([$day])->overtimeMinutes)->toBe(0.0);
});

it('still charges a flexible day undertime below its required hours', function () {
    $day = fotDay('09:00:00', '16:00:00', fotFlexible());

    expect($day->workedMinutes)->toBe(360.0)
        ->and($day->undertimeMinutes)->toBe(120.0)
        ->and($day->overtimeMinutes)->toBe(0.0);
});

it('leaves a fixed shift earning overtime past its paid span', function () {
    $fixed = new Shift(startTime: '08:00:00', endTime: '17:00:00', graceMinutes: 0, mode: Shift::MODE_FIXED,
        workDays: [1, 2, 3, 4, 5], breakStart: '12:00:00', breakEnd: '13:00:00', breakMinutes: 60);
    $day = fotDay('06:55:00', '19:30:00', $fixed);

    // On the clock from 08:00 (the early-arrival clamp) with the unpaid hour
    // out: 630 worked against 480 paid.
    expect($day->workedMinutes)->toBe(630.0)
        ->and($day->overtimeMinutes)->toBe(150.0);
});
