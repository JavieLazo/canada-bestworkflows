<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog;
use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\Shift;

/**
 * Late, worked and undertime are three slices of one paid day.
 *
 * All three are priced from the same hourly rate, so they have to partition the
 * PAID span exactly -- `late + worked + undertime - overtime == paidShiftMinutes`.
 * They did not. Lateness was moved onto payable minutes in c72beed while worked
 * kept a flat break deduction and undertime stayed raw clock distance, so on an
 * 08:00-17:00 shift with an unpaid 12:00-13:00 the three disagreed on 48.7% of
 * punch pairs -- most often by exactly the 60-minute break, charged as undertime
 * to someone who had gone home before the window ever opened.
 */

/**
 * Named constructor arguments of Shift, each optional, merged over the 08:00-17:00 base.
 *
 * @param  array{startTime?: string, endTime?: string, graceMinutes?: int, mode?: ?string, requiredHours?: ?float, workDays?: list<int>|null, shiftTypeId?: ?string, breakStart?: ?string, breakEnd?: ?string, breakMinutes?: ?int, requiresBreakPunch?: ?bool}  $overrides
 */
function pmShift(array $overrides = []): Shift
{
    // 08:00-17:00 with an unpaid 12:00-13:00 -> 480 PAID minutes.
    return new Shift(...array_merge([
        'startTime' => '08:00:00',
        'endTime' => '17:00:00',
        'graceMinutes' => 0,
        'mode' => Shift::MODE_FIXED,
        'workDays' => [1, 2, 3, 4, 5],
        'breakStart' => '12:00:00',
        'breakEnd' => '13:00:00',
        'breakMinutes' => 60,
    ], $overrides));
}

function pmDay(string $in, string $out, ?Shift $shift = null, string $date = '2026-09-02'): DayRecord
{
    return DtrCalculator::compute(
        [new AttendanceLog('TIME_IN', $date.'T'.$in), new AttendanceLog('TIME_OUT', $date.'T'.$out)],
        $shift ?? pmShift(), [], $date, $date,
    )[0];
}

it('does not charge undertime for a break the employee had already left before', function () {
    // Home at 11:00. The 12:00-13:00 window is unpaid, so of the six hours
    // between 11:00 and 17:00 only five were ever payable.
    $day = pmDay('08:00:00', '11:00:00');

    expect($day->workedMinutes)->toBe(180.0)
        ->and($day->undertimeMinutes)->toBe(300.0)   // was 360 -- the raw clock distance
        ->and($day->lateMinutes)->toBe(0.0);
});

it('charges only the shared minutes when the day ends inside the break window', function () {
    // On the clock 08:00-12:30, so only the first half hour of the window was
    // reached. The old flat rule took the whole hour off a 4.5 hour day.
    $day = pmDay('08:00:00', '12:30:00');

    expect($day->breakMinutes)->toBe(30.0)
        ->and($day->workedMinutes)->toBe(240.0)      // was 210
        ->and($day->undertimeMinutes)->toBe(240.0);
});

it('does not bill the hours before a shift the employee never started', function () {
    // Punched in and straight out at 07:00-07:15, before the 08:00 start. They
    // owe the paid day -- not the paid day plus the time before it began.
    $day = pmDay('07:00:00', '07:15:00');

    expect($day->workedMinutes)->toBe(0.0)
        ->and($day->undertimeMinutes)->toBe(480.0);  // was 525
});

it('partitions the paid span across every punch pair inside the shift', function () {
    $paid = 480.0;
    $offenders = [];

    for ($in = 480; $in <= 1020; $in += 15) {
        for ($out = $in + 15; $out <= 1020; $out += 15) {
            $day = pmDay(
                sprintf('%02d:%02d:00', intdiv($in, 60), $in % 60),
                sprintf('%02d:%02d:00', intdiv($out, 60), $out % 60),
            );
            $sum = $day->lateMinutes + $day->workedMinutes + $day->undertimeMinutes - $day->overtimeMinutes;

            // The one documented exception: a day spent ENTIRELY inside the
            // unpaid window keeps its worked minutes rather than dropping to
            // zero, because zeroing them would cost the employee the work day
            // itself at PayslipCalculator.php:240.
            if ($in >= 720 && $out <= 780) {
                continue;
            }
            if (abs($sum - $paid) > 0.001) {
                $offenders[] = sprintf('in=%d out=%d sum=%.1f', $in, $out, $sum);
            }
        }
    }

    expect($offenders)->toBe([]);
});
