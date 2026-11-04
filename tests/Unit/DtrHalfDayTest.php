<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\LeaveRequest as DomainLeave;
use App\Domain\Attendance\Shift as DomainShift;

/**
 * DELIBERATE DIVERGENCE 21: a half day that says WHICH half is off
 * forgives only that half — the morning off (AM) forgives lateness and
 * not undertime, the afternoon off (PM) the other way round — while a
 * half day that says neither keeps the reference's reading, both halves
 * forgiven. The excused half day still counts half a day of leave.
 */
function halfDayLeave(?string $half): DomainLeave
{
    return DomainLeave::fromArray([
        'request_type' => 'VACATION_LEAVE', 'status' => 'APPROVED', 'is_paid' => true,
        'day_type' => 'HALF_DAY', 'start_date' => '2026-10-13', 'end_date' => '2026-10-13', 'half' => $half,
    ]);
}

function halfDayShift(): DomainShift
{
    return DomainShift::fromArray([
        'work_days' => [1, 2, 3, 4, 5], 'mode' => 'FIXED',
        'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'break_minutes' => 60, 'grace_minutes' => 0,
    ]);
}

/** @param list<DomainLeave> $leaves */
function halfDayRecord(string $in, string $out, array $leaves): DayRecord
{
    $logs = DomainLog::listFromArrays([
        ['log_type' => 'TIME_IN', 'logged_at' => "2026-10-13T{$in}:00+08:00"],
        ['log_type' => 'TIME_OUT', 'logged_at' => "2026-10-13T{$out}:00+08:00"],
    ]);

    return DtrCalculator::compute($logs, halfDayShift(), $leaves, '2026-10-13', '2026-10-13')[0];
}

it('forgives only the half that is off, and both when the request says neither', function (string $in, string $out, string $forgivenHalf, string $wrongHalf) {
    // The reference reading forgives both halves; with no leave nothing is forgiven.
    $either = halfDayRecord($in, $out, [halfDayLeave(null)]);
    $plain = halfDayRecord($in, $out, []);
    $forgiven = halfDayRecord($in, $out, [halfDayLeave($forgivenHalf)]);
    $wrong = halfDayRecord($in, $out, [halfDayLeave($wrongHalf)]);

    expect($either->excuseDays)->toBe(0.5)->and($forgiven->excuseDays)->toBe(0.5)->and($wrong->excuseDays)->toBe(0.5)
        // The half that was off forgives exactly what the reference forgave on that side.
        ->and([$forgiven->lateMinutes, $forgiven->undertimeMinutes])->toBe([$either->lateMinutes, $either->undertimeMinutes])
        // The wrong half forgives the OTHER side only, so the side worked stays as a plain day reads it.
        ->and($forgivenHalf === 'AM' ? $wrong->lateMinutes : $wrong->undertimeMinutes)
        ->toBe($forgivenHalf === 'AM' ? $plain->lateMinutes : $plain->undertimeMinutes)
        ->and($forgivenHalf === 'AM' ? $plain->lateMinutes : $plain->undertimeMinutes)->toBeGreaterThan(0.0);
})->with([
    // Came in at 13:00 and stayed to the end: the morning was the half off.
    'afternoon worked' => ['13:00', '17:00', 'AM', 'PM'],
    // Came in at 08:00 and left at 12:00: the afternoon was the half off.
    'morning worked' => ['08:00', '12:00', 'PM', 'AM'],
    // Late inside the worked half, or leaving its end early, is still that much late or short.
    'afternoon worked, 15 minutes late' => ['13:15', '17:00', 'AM', 'PM'],
    'morning worked, left 15 minutes early' => ['08:00', '11:45', 'PM', 'AM'],
]);

it('keeps the extra minutes inside the worked half as lateness or undertime', function () {
    $onTime = halfDayRecord('13:00', '17:00', [halfDayLeave('AM')]);
    $late = halfDayRecord('13:15', '17:00', [halfDayLeave('AM')]);
    $early = halfDayRecord('08:00', '11:45', [halfDayLeave('PM')]);
    $full = halfDayRecord('08:00', '12:00', [halfDayLeave('PM')]);
    expect($late->lateMinutes - $onTime->lateMinutes)->toBe(15.0)
        ->and($early->undertimeMinutes - $full->undertimeMinutes)->toBe(15.0);
});

it('reads a half the evidence or a row does not name as neither, and an unknown value the same', function () {
    expect(DomainLeave::fromArray(['request_type' => 'VACATION_LEAVE', 'status' => 'APPROVED', 'day_type' => 'HALF_DAY', 'start_date' => '2026-10-13', 'end_date' => '2026-10-13'])->half)->toBeNull()
        ->and(DomainLeave::fromArray(['request_type' => 'VACATION_LEAVE', 'status' => 'APPROVED', 'day_type' => 'HALF_DAY', 'start_date' => '2026-10-13', 'end_date' => '2026-10-13', 'half' => 'NOON'])->half)->toBeNull()
        ->and(halfDayLeave('PM')->half)->toBe('PM');
});
