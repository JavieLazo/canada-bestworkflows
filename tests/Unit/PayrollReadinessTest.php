<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\Shift;
use App\Domain\Payroll\PayrollReadiness;

/**
 * The finalize blockers read each punch in exactly ONE period. The
 * reference's carry already gives the last day the next morning's punches up
 * to its first Time In; as a DELIBERATE DIVERGENCE the first day's punches
 * before ITS first Time In are the previous day's tail and are left out, so a
 * night shift's after-midnight Time Out on the period's first morning never
 * reads as an unmatched punch. The walk refuses everything else the reference
 * refuses — a Time Out that closes nothing inside the period, a Time In on top
 * of an open one, a Time In left open at the end.
 */

/**
 * @param  list<array{0: string, 1: string}>  $punches  [log type, 'Y-m-dTH:i:s' Manila wall clock]
 * @return list<AttendanceLog>
 */
function readiLogs(array $punches): array
{
    return array_map(
        static fn (array $punch, int $i): AttendanceLog => new AttendanceLog($punch[0], $punch[1], 'log-'.$i),
        $punches,
        array_keys($punches),
    );
}

/** A DTR that computes no rows: the pairing walk alone decides UNPAIRED_ATTENDANCE. */
function readiNoDtr(): Closure
{
    return static fn (): array => [];
}

/**
 * The blocking codes raised for a period, employed since 2026-08-01.
 *
 * @param  list<array{0: string, 1: string}>  $punches
 * @return list<string>
 */
function readiCodes(array $punches, ?callable $dtr = null, mixed $shift = null, string $start = '2026-09-16', string $end = '2026-09-30'): array
{
    $issues = PayrollReadiness::issues($dtr ?? readiNoDtr(), readiLogs($punches), $shift, [], $start, $end, '2026-08-01');

    return array_column($issues, 'code');
}

/** A Night Shift: 20:00–01:00, Monday to Friday, 15 minutes' grace. */
function readiNightShift(): Shift
{
    return new Shift('20:00:00', '01:00:00', 15, Shift::MODE_FIXED, null, [1, 2, 3, 4, 5]);
}

/* |---| The previous night's tail on the first morning |---| */

it('does not read the previous night\'s Time Out on the first morning as unmatched', function () {
    // The Sep 15 night ended at 01:00 on Sep 16; the period starts on Sep 16.
    $codes = readiCodes([
        ['TIME_OUT', '2026-09-16T01:00:00'],
        ['TIME_IN', '2026-09-16T20:00:00'], ['TIME_OUT', '2026-09-17T01:00:00'],
        ['TIME_IN', '2026-09-17T20:00:00'], ['TIME_OUT', '2026-09-18T01:00:00'],
    ]);

    expect($codes)->not->toContain(PayrollReadiness::CODE_UNPAIRED_ATTENDANCE);
});

it('leaves out every first-day punch before its first Time In', function () {
    // A double-tapped Time Out on the morning after the previous night.
    $codes = readiCodes([
        ['TIME_OUT', '2026-09-16T01:00:00'], ['TIME_OUT', '2026-09-16T01:00:30'],
        ['TIME_IN', '2026-09-16T20:00:00'], ['TIME_OUT', '2026-09-17T01:00:00'],
    ]);

    expect($codes)->not->toContain(PayrollReadiness::CODE_UNPAIRED_ATTENDANCE);
});

it('treats a first day with no Time In as the previous night\'s tail', function () {
    // The period starts on a Saturday: only Friday night's Time Out falls on it.
    $codes = readiCodes([
        ['TIME_OUT', '2026-09-19T01:03:00'],
        ['TIME_IN', '2026-09-21T20:00:00'], ['TIME_OUT', '2026-09-22T01:00:00'],
    ], start: '2026-09-19');

    expect($codes)->not->toContain(PayrollReadiness::CODE_UNPAIRED_ATTENDANCE);
});

it('passes a month of night shifts through the real DTR engine', function () {
    // Every weekday night of Sep 16–30 worked 20:00–01:00, with the Sep 15
    // night's Time Out on the first morning and the Sep 30 night's Time Out
    // on Oct 1 (the end carry).
    // Called exactly as PayrollData::calculateInputs calls the engine.
    $dtr = static fn (array $logs, mixed $shift, array $leaves, string $start, string $end, array $holidays): array => DtrCalculator::compute(array_values($logs), $shift, array_values($leaves), $start, $end, array_values($holidays));

    $punches = [['TIME_OUT', '2026-09-16T01:00:00']];
    foreach ([16, 17, 18, 21, 22, 23, 24, 25, 28, 29] as $day) {
        $punches[] = ['TIME_IN', sprintf('2026-09-%02dT20:00:00', $day)];
        $punches[] = ['TIME_OUT', sprintf('2026-09-%02dT01:00:00', $day + 1)];
    }
    $punches[] = ['TIME_IN', '2026-09-30T20:00:00'];
    $punches[] = ['TIME_OUT', '2026-10-01T01:00:00'];

    expect(readiCodes($punches, $dtr, readiNightShift()))->toBe([]);
});

it('keeps a day-shift first day whole when it opens with its Time In', function () {
    $codes = readiCodes([
        ['TIME_IN', '2026-09-16T08:00:00'], ['TIME_OUT', '2026-09-16T17:00:00'],
    ]);

    expect($codes)->toBe([]);
});

/* |---| What the walk refuses, as the reference does |---| */

it('still flags a Time Out that closes nothing inside the period', function () {
    $codes = readiCodes([
        ['TIME_IN', '2026-09-16T20:00:00'], ['TIME_OUT', '2026-09-17T01:00:00'],
        ['TIME_OUT', '2026-09-17T01:30:00'],
    ]);

    expect($codes)->toContain(PayrollReadiness::CODE_UNPAIRED_ATTENDANCE);
});

it('still flags a second Time In on the first day once its first Time In has opened', function () {
    $codes = readiCodes([
        ['TIME_OUT', '2026-09-16T01:00:00'],
        ['TIME_IN', '2026-09-16T20:00:00'], ['TIME_IN', '2026-09-16T20:05:00'],
        ['TIME_OUT', '2026-09-17T01:00:00'],
    ]);

    expect($codes)->toContain(PayrollReadiness::CODE_UNPAIRED_ATTENDANCE);
});

it('still flags a Time In left open at the end of the period', function () {
    $codes = readiCodes([
        ['TIME_IN', '2026-09-29T20:00:00'], ['TIME_OUT', '2026-09-30T01:00:00'],
        ['TIME_IN', '2026-09-30T20:00:00'],
    ]);

    expect($codes)->toContain(PayrollReadiness::CODE_UNPAIRED_ATTENDANCE);
});

it('still carries the next morning\'s Time Out into the last night and no further', function () {
    $codes = readiCodes([
        ['TIME_IN', '2026-09-30T20:00:00'], ['TIME_OUT', '2026-10-01T01:00:00'],
        // The next period's own night: left out by the carry, so it opens nothing here.
        ['TIME_IN', '2026-10-01T20:00:00'],
    ]);

    expect($codes)->not->toContain(PayrollReadiness::CODE_UNPAIRED_ATTENDANCE);
});
