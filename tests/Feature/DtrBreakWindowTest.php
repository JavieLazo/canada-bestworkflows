<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\Shift;

/**
 * The unpaid break window is a REAL window, not just a number of minutes.
 *
 * Both rules here concern a shift that says WHEN its break is — 10:00-17:00
 * with an unpaid 12:00-13:00, so six paid hours. Before these rules existed
 * the engine read `break_minutes` as a flat quantity and never looked at
 * `break_start` / `break_end` at all, which produced two wrong answers for an
 * employee who missed the morning and clocked in at 13:00:
 *
 *   - 60 minutes of break were deducted from a day whose every worked minute
 *     fell AFTER the break window had closed, so four hours on the clock were
 *     recorded as three; and
 *   - lateness was measured as the raw 180 minutes from 10:00 to 13:00, which
 *     charges the employee for the unpaid hour nobody was ever paying for.
 *     `late_deduction` is `(lateMinutes / 60) * hourly`, so that is an hour of
 *     real money.
 *
 * A shift that gives `break_minutes` WITHOUT a window keeps the old flat
 * behaviour: with no window the engine cannot know when the break was, and
 * guessing would be worse than the status quo.
 */
function dtrShift(array $overrides = []): Shift
{
    return new Shift(...array_merge([
        'startTime' => '10:00:00',
        'endTime' => '17:00:00',
        'graceMinutes' => 15,
        'mode' => Shift::MODE_FIXED,
        'workDays' => [1, 2, 3, 4, 5],
        'breakStart' => '12:00:00',
        'breakEnd' => '13:00:00',
        'breakMinutes' => 60,
    ], $overrides));
}

/** @param  list<array{0: string, 1: string}>  $punches */
function dtrDay(array $punches, ?Shift $shift = null, string $date = '2026-09-02')
{
    $logs = array_map(
        static fn (array $p) => new AttendanceLog($p[0], $date.'T'.$p[1]),
        $punches,
    );

    return DtrCalculator::compute($logs, $shift ?? dtrShift(), [], $date, $date)[0];
}

it('does not deduct the break when the window closed before the first punch', function () {
    // Missed the morning, clocked in at 13:00, out at 17:00. Four hours on the
    // clock, and the 12:00-13:00 window was over before any of them.
    $day = dtrDay([['TIME_IN', '13:00:00'], ['TIME_OUT', '17:00:00']]);

    expect($day->workedMinutes)->toBe(240.0)
        ->and($day->breakMinutes)->toBe(0.0);
});

it('charges lateness in payable minutes, not raw clock minutes', function () {
    // 10:00 to 13:00 is 180 raw minutes, but 60 of them are the unpaid break.
    // Only the 10:00-12:00 stretch was ever payable.
    $day = dtrDay([['TIME_IN', '13:00:00'], ['TIME_OUT', '17:00:00']]);

    expect($day->lateMinutes)->toBe(120.0);
});

it('still deducts the break when the window was worked through', function () {
    // On the clock 10:00-17:00 across the whole window, break never punched:
    // the allowance is exactly what stops a forgotten lunch being paid.
    $day = dtrDay([['TIME_IN', '10:00:00'], ['TIME_OUT', '17:00:00']]);

    expect($day->workedMinutes)->toBe(360.0)
        ->and($day->breakMinutes)->toBe(60.0)
        ->and($day->lateMinutes)->toBe(0.0);
});

it('leaves a punched break exactly as it was', function () {
    $day = dtrDay([
        ['TIME_IN', '10:00:00'], ['TIME_OUT', '12:00:00'],
        ['TIME_IN', '13:00:00'], ['TIME_OUT', '17:00:00'],
    ]);

    expect($day->workedMinutes)->toBe(360.0)
        ->and($day->breakMinutes)->toBe(60.0)
        ->and($day->lateMinutes)->toBe(0.0)
        ->and($day->undertimeMinutes)->toBe(0.0);
});

it('keeps the flat deduction when the shift gives no break window', function () {
    // 5,290 of the golden payslip vectors are this shape. With no window the
    // engine cannot know when the break was, so nothing changes for them.
    $shift = dtrShift(['breakStart' => null, 'breakEnd' => null]);
    $day = dtrDay([['TIME_IN', '13:00:00'], ['TIME_OUT', '17:00:00']], $shift);

    expect($day->workedMinutes)->toBe(180.0)
        ->and($day->breakMinutes)->toBe(60.0)
        ->and($day->lateMinutes)->toBe(180.0);
});

it('does not let the break window forgive a late arrival before it', function () {
    // In at 11:00: an hour late, and the whole break still lies ahead, so the
    // window takes nothing off the lateness.
    $day = dtrDay([['TIME_IN', '11:00:00'], ['TIME_OUT', '17:00:00']]);

    expect($day->lateMinutes)->toBe(60.0)
        ->and($day->breakMinutes)->toBe(60.0);
});
