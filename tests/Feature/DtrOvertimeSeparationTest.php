<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\DtrSummary;
use App\Domain\Attendance\Shift;

/**
 * The DTR shows worked and overtime side by side, so they must not overlap.
 *
 * `workedMinutes` counts every minute on the clock, overtime included, and the
 * payroll engine DEPENDS on that: the rest-day cap at PayslipCalculator.php:257
 * exists because "hours beyond it are already overtime". Left as-is on screen,
 * a nine-hour day against a six-hour paid shift printed `Worked 8:00` next to
 * `Overtime 2:00` and read as ten hours.
 *
 * So the engine keeps its meaning and the DTR derives the regular half.
 */
function otShift(array $overrides = []): Shift
{
    // 10:00-17:00 with an unpaid 12:00-13:00 -> six PAID hours.
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
function otDay(array $punches, ?Shift $shift = null, string $date = '2026-09-02')
{
    $logs = array_map(
        static fn (array $p) => new AttendanceLog($p[0], $date.'T'.$p[1]),
        $punches,
    );

    return DtrCalculator::compute($logs, $shift ?? otShift(), [], $date, $date)[0];
}

it('takes overtime back out of the regular minutes the DTR prints', function () {
    // 10:00-19:00 is nine hours on the clock; one is the unpaid break, leaving
    // eight worked against a six-hour paid shift -> two hours of overtime.
    $day = otDay([['TIME_IN', '10:00:00'], ['TIME_OUT', '19:00:00']]);

    expect($day->workedMinutes)->toBe(480.0)
        ->and($day->overtimeMinutes)->toBe(120.0)
        // The regular half is exactly the paid shift, NOT the 480 on the clock.
        ->and($day->regularMinutes())->toBe(360.0);
});

it('leaves a day without overtime untouched', function () {
    $day = otDay([['TIME_IN', '10:00:00'], ['TIME_OUT', '17:00:00']]);

    expect($day->overtimeMinutes)->toBe(0.0)
        ->and($day->regularMinutes())->toBe($day->workedMinutes);
});

it('never lets the two columns double-count, on any day', function () {
    foreach ([
        [['TIME_IN', '10:00:00'], ['TIME_OUT', '19:00:00']],  // overtime
        [['TIME_IN', '10:00:00'], ['TIME_OUT', '17:00:00']],  // exact
        [['TIME_IN', '13:00:00'], ['TIME_OUT', '17:00:00']],  // late, short
        [['TIME_IN', '10:00:00'], ['TIME_OUT', '14:00:00']],  // undertime
    ] as $punches) {
        $day = otDay($punches);

        expect($day->regularMinutes() + $day->overtimeMinutes)->toBe($day->workedMinutes)
            ->and($day->regularMinutes())->toBeGreaterThanOrEqual(0.0);
    }
});

it('subtracts overtime from the period total too', function () {
    $days = DtrCalculator::compute(
        [
            new AttendanceLog('TIME_IN', '2026-09-02T10:00:00'),
            new AttendanceLog('TIME_OUT', '2026-09-02T19:00:00'),
            new AttendanceLog('TIME_IN', '2026-09-03T10:00:00'),
            new AttendanceLog('TIME_OUT', '2026-09-03T17:00:00'),
        ],
        otShift(), [], '2026-09-02', '2026-09-03',
    );

    $totals = DtrSummary::of($days);

    expect($totals->workedMinutes)->toBe(840.0)      // 480 + 360 on the clock
        ->and($totals->overtimeMinutes)->toBe(120.0)
        ->and($totals->regularMinutes())->toBe(720.0); // two full paid shifts
});
