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

it('treats a first punch during or after lunch as the afternoon time in', function () {
    // Missed the morning, clocked in at 13:00, out at 17:00. That is not the
    // 10:00 time in; it is the afternoon segment. The missed 10:00-12:00
    // segment is undertime, and lunch is skipped because they were not yet on
    // the clock when it happened.
    $day = dtrDay([['TIME_IN', '13:00:00'], ['TIME_OUT', '17:00:00']]);

    expect($day->workedMinutes)->toBe(240.0)
        ->and($day->breakMinutes)->toBe(0.0)
        ->and($day->lateMinutes)->toBe(0.0)
        ->and($day->undertimeMinutes)->toBe(120.0)
        ->and($day->timeIn)->toBeNull()
        ->and($day->breakOut)->toBeNull()
        ->and($day->breakIn)->toBe('2026-09-02T13:00:00')
        ->and($day->timeOut)->toBe('2026-09-02T17:00:00')
        ->and($day->flags)->toContain('First work segment was missed.');
});

it('still deducts the break when the window was worked through', function () {
    // On the clock 10:00-17:00 across the whole window, break never punched:
    // the allowance is exactly what stops a forgotten lunch being paid.
    $day = dtrDay([['TIME_IN', '10:00:00'], ['TIME_OUT', '17:00:00']]);

    expect($day->workedMinutes)->toBe(360.0)
        ->and($day->breakMinutes)->toBe(60.0)
        ->and($day->lateMinutes)->toBe(0.0)
        ->and($day->flags)->toContain('Missing middle punch: First Time Out and Second Time In.');
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

it('counts an early first time out as undertime before the scheduled break', function () {
    // Time out at 11:00 is inside the one-hour early window for the noon slot,
    // but the 11:00-12:00 gap is not lunch. It is one paid hour missed before
    // the 12:00-13:00 unpaid break.
    $day = dtrDay([
        ['TIME_IN', '10:00:00'], ['TIME_OUT', '11:00:00'],
        ['TIME_IN', '13:00:00'], ['TIME_OUT', '17:00:00'],
    ]);

    expect($day->workedMinutes)->toBe(300.0)
        ->and($day->breakMinutes)->toBe(60.0)
        ->and($day->lateMinutes)->toBe(0.0)
        ->and($day->undertimeMinutes)->toBe(60.0)
        ->and($day->breakOut)->toBe('2026-09-02T11:00:00')
        ->and($day->breakIn)->toBe('2026-09-02T13:00:00')
        ->and($day->flags)->toContain('First Time Out was 60 min before the scheduled break.');
});

it('flags an imported first punch before the second time in window', function () {
    // The live station blocks this, but imported legacy data may already exist.
    // It is kept visible as the second slot, marked as outside the allowed
    // window, and never treated as the 10:00 First Time In.
    $day = dtrDay([['TIME_IN', '12:00:00'], ['TIME_OUT', '17:00:00']]);

    expect($day->workedMinutes)->toBe(240.0)
        ->and($day->breakMinutes)->toBe(0.0)
        ->and($day->lateMinutes)->toBe(0.0)
        ->and($day->undertimeMinutes)->toBe(120.0)
        ->and($day->timeIn)->toBeNull()
        ->and($day->breakIn)->toBe('2026-09-02T12:00:00')
        ->and($day->timeOut)->toBe('2026-09-02T17:00:00')
        ->and($day->flags)->toContain('Second Time In was recorded before its allowed window.');
});

it('applies grace to the second time in slot', function () {
    $shift = dtrShift(['graceMinutes' => 10]);
    $insideGrace = dtrDay([
        ['TIME_IN', '10:00:00'], ['TIME_OUT', '12:00:00'],
        ['TIME_IN', '13:05:00'], ['TIME_OUT', '17:00:00'],
    ], $shift);
    $beyondGrace = dtrDay([
        ['TIME_IN', '10:00:00'], ['TIME_OUT', '12:00:00'],
        ['TIME_IN', '13:11:00'], ['TIME_OUT', '17:00:00'],
    ], $shift);

    expect($insideGrace->workedMinutes)->toBe(355.0)
        ->and($insideGrace->lateMinutes)->toBe(0.0)
        ->and($insideGrace->undertimeMinutes)->toBe(0.0)
        ->and($beyondGrace->workedMinutes)->toBe(349.0)
        ->and($beyondGrace->lateMinutes)->toBe(11.0)
        ->and($beyondGrace->undertimeMinutes)->toBe(0.0)
        ->and($beyondGrace->flags)->toContain('Second Time In was 11 min late.');
});

it('uses only the first and final slots when a shift has no break', function () {
    $shift = dtrShift([
        'endTime' => '14:00:00',
        'breakStart' => null,
        'breakEnd' => null,
        'breakMinutes' => 0,
        'requiresBreakPunch' => false,
    ]);
    $day = dtrDay([['TIME_IN', '10:00:00'], ['TIME_OUT', '14:00:00']], $shift);

    expect($day->workedMinutes)->toBe(240.0)
        ->and($day->breakMinutes)->toBe(0.0)
        ->and($day->breakOut)->toBeNull()
        ->and($day->breakIn)->toBeNull()
        ->and($day->flags)->toBe([]);
});

it('places an overnight break on the shift-start work date timeline', function () {
    $shift = dtrShift([
        'startTime' => '20:00:00',
        'endTime' => '01:00:00',
        'breakStart' => '23:30:00',
        'breakEnd' => '00:30:00',
        'breakMinutes' => 60,
    ]);

    $logs = [
        new AttendanceLog('TIME_IN', '2026-09-02T20:00:00'),
        new AttendanceLog('TIME_OUT', '2026-09-02T23:30:00'),
        new AttendanceLog('TIME_IN', '2026-09-03T00:30:00'),
        new AttendanceLog('TIME_OUT', '2026-09-03T01:00:00'),
    ];

    $day = DtrCalculator::compute($logs, $shift, [], '2026-09-02', '2026-09-02')[0];

    expect($day->workedMinutes)->toBe(240.0)
        ->and($day->breakMinutes)->toBe(60.0)
        ->and($day->timeIn)->toBe('2026-09-02T20:00:00')
        ->and($day->breakOut)->toBe('2026-09-02T23:30:00')
        ->and($day->breakIn)->toBe('2026-09-03T00:30:00')
        ->and($day->timeOut)->toBe('2026-09-03T01:00:00')
        ->and($day->flags)->toBe([]);
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
