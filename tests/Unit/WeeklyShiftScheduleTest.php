<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\Shift;
use App\Domain\Attendance\ShiftDayBoundary;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Payroll\PayslipCalculator;

/** Weekday hours follow the shift-start date through carry-over, rest days and every per-hour pay line. */
function weeklyShiftBase(): Shift
{
    return new Shift(workDays: [1, 2, 3, 4, 5], breakMinutes: 60);
}

function weeklyShiftNight(bool $scheduledBreak = false): Shift
{
    return new Shift(
        startTime: '22:00:00', endTime: '06:00:00', workDays: [1],
        breakStart: $scheduledBreak ? '02:00:00' : null,
        breakEnd: $scheduledBreak ? '03:00:00' : null,
        breakMinutes: $scheduledBreak ? 60 : 0,
    );
}

/** @param list<array{0: string, 1: string}> $stamps
 * @return list<AttendanceLog>
 */
function weeklyShiftLogs(array $stamps): array
{
    return array_map(static fn (array $row): AttendanceLog => new AttendanceLog($row[0], $row[1]), $stamps);
}

/** @param list<AttendanceLog> $logs
 * @param  array<string, int>  $approvals
 * @return array<string, mixed>
 */
function weeklyShiftPay(Shift $shift, array $logs, string $end = '2026-09-08', float $monthly = 22000, array $approvals = []): array
{
    return PayslipCalculator::compute(
        employee: (object) ['id' => 'WEEKLY', 'monthly_rate' => $monthly, 'hourly_rate' => 0],
        logs: $logs, shift: $shift, leaves: [], periodStart: '2026-09-07', periodEnd: $end,
        bonus: 0, overtimeApprovals: $approvals,
    )->toArray();
}

it('retains Monday night carry-over and resolves Tuesday regular hours independently', function () {
    $shift = weeklyShiftBase()->withWeekdayShifts([1 => weeklyShiftNight()]);
    $logs = weeklyShiftLogs([
        ['TIME_IN', '2026-09-07T22:00:00'], ['TIME_OUT', '2026-09-08T06:00:00'],
        ['TIME_IN', '2026-09-08T08:00:00'], ['TIME_OUT', '2026-09-08T17:00:00'],
    ]);
    $days = DtrCalculator::compute($logs, $shift, [], '2026-09-07', '2026-09-08');
    expect($days[0]->timeOut)->toBe('2026-09-08T06:00:00')
        ->and($days[0]->workedMinutes)->toBe(480.0)
        ->and($days[1]->timeIn)->toBe('2026-09-08T08:00:00')
        ->and($days[1]->workedMinutes)->toBe(480.0)
        ->and($days[1]->lateMinutes)->toBe(0.0)
        ->and(DtrCalculator::rowPunches($logs, $shift, '2026-09-08'))->toBe([$logs[2], $logs[3]]);
    $oneDay = DtrCalculator::compute($logs, $shift, [], '2026-09-08', '2026-09-08');
    expect($oneDay[0])->toEqual($days[1]);
    $pay = weeklyShiftPay($shift, $logs);
    expect($pay['work_days_pay'])->toBe(2000.0)
        ->and($pay['night_differential'])->toBe(100.0)
        ->and($pay['gross_pay'])->toBe(2100.0);
});

it('keeps an overnight scheduled break out of the following regular shifts slots', function () {
    $shift = weeklyShiftBase()->withWeekdayShifts([1 => weeklyShiftNight(true)]);
    $logs = weeklyShiftLogs([
        ['TIME_IN', '2026-09-07T22:00:00'], ['TIME_OUT', '2026-09-08T02:00:00'],
        ['TIME_IN', '2026-09-08T03:00:00'], ['TIME_OUT', '2026-09-08T06:00:00'],
        ['TIME_IN', '2026-09-08T08:00:00'], ['TIME_OUT', '2026-09-08T17:00:00'],
    ]);
    $days = DtrCalculator::compute($logs, $shift, [], '2026-09-07', '2026-09-08');
    expect($days[0]->breakIn)->toBe('2026-09-08T03:00:00')
        ->and($days[0]->workedMinutes)->toBe(420.0)
        ->and($days[1]->timeIn)->toBe('2026-09-08T08:00:00')
        ->and($days[1]->breakOut)->toBeNull()
        ->and($days[1]->breakIn)->toBeNull()
        ->and($days[1]->workedMinutes)->toBe(480.0)
        ->and(DtrCalculator::rowPunches($logs, $shift, '2026-09-08'))->toBe([$logs[4], $logs[5]])
        ->and(DtrCalculator::compute($logs, $shift, [], '2026-09-08', '2026-09-08')[0])->toEqual($days[1]);
});

it('prices late and undertime minutes using each weekdays own paid hours', function () {
    $shift = weeklyShiftBase()->withWeekdayShifts([
        1 => weeklyShiftNight(),
        2 => new Shift(endTime: '14:00:00', workDays: [2]),
    ]);
    $pay = weeklyShiftPay($shift, weeklyShiftLogs([
        ['TIME_IN', '2026-09-07T22:30:00'], ['TIME_OUT', '2026-09-08T05:30:00'],
        ['TIME_IN', '2026-09-08T08:30:00'], ['TIME_OUT', '2026-09-08T13:30:00'],
    ]));
    // 30 minutes at 1000/8 and 30 at 1000/6, rounded only on the final line.
    expect($pay['late_minutes'])->toBe(60.0)
        ->and($pay['undertime_minutes'])->toBe(60.0)
        ->and($pay['late_deduction'])->toBe(145.83)
        ->and($pay['undertime_deduction'])->toBe(145.83)
        ->and($pay['night_differential'])->toBe(100.0);
});

it('prices approved overtime at the hourly rate of the date that earned it', function () {
    $shift = weeklyShiftBase()->withWeekdayShifts([
        1 => weeklyShiftNight(),
        2 => new Shift(endTime: '14:00:00', workDays: [2]),
    ]);
    $pay = weeklyShiftPay($shift, weeklyShiftLogs([
        ['TIME_IN', '2026-09-07T22:00:00'], ['TIME_OUT', '2026-09-08T07:00:00'],
        ['TIME_IN', '2026-09-08T08:00:00'], ['TIME_OUT', '2026-09-08T15:00:00'],
    ]), approvals: ['2026-09-07' => 60, '2026-09-08' => 60]);
    expect($pay['overtime_hours'])->toBe(2.0)
        ->and($pay['overtime_pay'])->toBe(364.58)
        ->and($pay['night_differential'])->toBe(100.0);
});

it('uses explicit weekday rest decisions in the monthly divisor and rest day pay', function () {
    $shift = weeklyShiftBase()->withWeekdayShifts([3 => Shift::restDay(new Shift(endTime: '16:00:00'))]);
    expect(ShiftResolver::workWeek($shift))->toBe([1, 2, 4, 5]);
    $pay = weeklyShiftPay($shift, weeklyShiftLogs([
        ['TIME_IN', '2026-09-09T08:00:00'], ['TIME_OUT', '2026-09-09T12:00:00'],
    ]), end: '2026-09-09', monthly: 17000);
    expect($pay['daily_rate'])->toBe(1000.0)
        ->and($pay['rest_days'])->toBe(1.0)
        ->and($pay['rest_day_pay'])->toBe(650.0)
        ->and($pay['work_days_pay'])->toBe(0.0);
});

it('carries a flexible Monday into Tuesday without applying Tuesday clocks to Monday', function () {
    $shift = weeklyShiftBase()->withWeekdayShifts([
        1 => new Shift(mode: Shift::MODE_FLEXIBLE, requiredHours: 8, workDays: [1]),
    ]);
    $logs = weeklyShiftLogs([
        ['TIME_IN', '2026-09-07T21:00:00'], ['TIME_OUT', '2026-09-08T05:00:00'],
        ['TIME_IN', '2026-09-08T08:00:00'], ['TIME_OUT', '2026-09-08T17:00:00'],
    ]);
    $days = DtrCalculator::compute($logs, $shift, [], '2026-09-07', '2026-09-08');
    expect($days[0]->workedMinutes)->toBe(480.0)
        ->and($days[0]->lateMinutes)->toBe(0.0)
        ->and($days[1]->workedMinutes)->toBe(480.0)
        ->and($days[1]->timeIn)->toBe('2026-09-08T08:00:00');
});

it('keeps a later punch owned consistently after a flexible date has only borrowed night slots', function (bool $dated) {
    $night = weeklyShiftNight(true);
    $flexible = new Shift(mode: Shift::MODE_FLEXIBLE, requiredHours: 8, workDays: [2]);
    $shift = $dated
        ? weeklyShiftBase()->withDateShifts(['2026-09-07' => $night, '2026-09-08' => $flexible])
        : weeklyShiftBase()->withWeekdayShifts([1 => $night, 2 => $flexible]);
    $logs = weeklyShiftLogs([
        ['TIME_IN', '2026-09-07T22:00:00'], ['TIME_OUT', '2026-09-08T02:00:00'],
        ['TIME_IN', '2026-09-08T03:00:00'], ['TIME_OUT', '2026-09-09T09:00:00'],
    ]);
    $full = DtrCalculator::compute($logs, $shift, [], '2026-09-07', '2026-09-09');
    $partial = DtrCalculator::compute($logs, $shift, [], '2026-09-08', '2026-09-09');
    expect(DtrCalculator::rowPunches($logs, $shift, '2026-09-07'))->toBe(array_slice($logs, 0, 3))
        ->and(DtrCalculator::rowPunches($logs, $shift, '2026-09-08'))->toBe([])
        ->and(DtrCalculator::rowPunches($logs, $shift, '2026-09-09'))->toBe([$logs[3]])
        ->and($full[1]->timeOut)->toBeNull()
        ->and($full[2]->timeOut)->toBe('2026-09-09T09:00:00')
        ->and($partial)->toEqual(array_slice($full, 1))
        ->and(DtrCalculator::compute($logs, $shift, [], '2026-09-08', '2026-09-08')[0])->toEqual($full[1])
        ->and(DtrCalculator::compute($logs, $shift, [], '2026-09-09', '2026-09-09')[0])->toEqual($full[2]);
})->with(['weekday schedule' => false, 'date exceptions' => true]);

it('retains a flexible dates own early Time In after the preceding night is complete', function () {
    $shift = weeklyShiftBase()->withDateShifts([
        '2026-09-07' => weeklyShiftNight(true),
        '2026-09-08' => new Shift(mode: Shift::MODE_FLEXIBLE, requiredHours: 8, workDays: [2]),
    ]);
    $logs = weeklyShiftLogs([
        ['TIME_IN', '2026-09-07T22:00:00'], ['TIME_OUT', '2026-09-08T02:00:00'],
        ['TIME_IN', '2026-09-08T03:00:00'], ['TIME_OUT', '2026-09-08T05:00:00'],
        ['TIME_IN', '2026-09-08T05:30:00'], ['TIME_OUT', '2026-09-09T05:00:00'],
    ]);
    $full = DtrCalculator::compute($logs, $shift, [], '2026-09-07', '2026-09-09');
    expect(DtrCalculator::rowPunches($logs, $shift, '2026-09-08'))->toBe(array_slice($logs, 4))
        ->and(DtrCalculator::rowPunches($logs, $shift, '2026-09-09'))->toBe([])
        ->and($full[1]->timeIn)->toBe('2026-09-08T05:30:00')
        ->and($full[1]->timeOut)->toBe('2026-09-09T05:00:00')
        ->and($full[2]->timeOut)->toBeNull()
        ->and(DtrCalculator::compute($logs, $shift, [], '2026-09-09', '2026-09-09')[0])->toEqual($full[2]);
});

it('hands completed scheduled night slots to the different next shifts early Time In', function () {
    $night = new Shift(startTime: '22:00:00', endTime: '08:00:00', workDays: [1],
        breakStart: '02:00:00', breakEnd: '03:00:00', breakMinutes: 60);
    $shift = weeklyShiftBase()->withWeekdayShifts([1 => $night]);
    $logs = weeklyShiftLogs([
        ['TIME_IN', '2026-09-07T22:00:00'], ['TIME_OUT', '2026-09-08T02:00:00'],
        ['TIME_IN', '2026-09-08T03:00:00'], ['TIME_OUT', '2026-09-08T07:00:00'],
        ['TIME_IN', '2026-09-08T07:30:00'], ['TIME_OUT', '2026-09-08T17:00:00'],
    ]);
    expect(ShiftDayBoundary::belongsToFollowingShift($logs, $logs[4], $night, weeklyShiftBase(), '2026-09-08'))->toBeTrue()
        ->and(DtrCalculator::rowPunches($logs, $shift, '2026-09-07'))->toBe(array_slice($logs, 0, 4))
        ->and(DtrCalculator::rowPunches($logs, $shift, '2026-09-08'))->toBe(array_slice($logs, 4));
    $days = DtrCalculator::compute($logs, $shift, [], '2026-09-07', '2026-09-08');
    expect($days[0]->timeOut)->toBe('2026-09-08T07:00:00')
        ->and($days[0]->workedMinutes)->toBe(480.0)
        ->and($days[1]->timeIn)->toBe('2026-09-08T07:30:00')
        ->and($days[1]->workedMinutes)->toBe(480.0)
        ->and(DtrCalculator::compute($logs, $shift, [], '2026-09-08', '2026-09-08')[0])->toEqual($days[1]);
});

it('does not hand off an unfinished overnight sequence or a Time In before the next opening', function () {
    $night = new Shift(startTime: '22:00:00', endTime: '08:00:00', workDays: [1],
        breakStart: '02:00:00', breakEnd: '03:00:00', breakMinutes: 60);
    $unfinished = weeklyShiftLogs([
        ['TIME_IN', '2026-09-07T22:00:00'], ['TIME_OUT', '2026-09-08T02:00:00'],
        ['TIME_IN', '2026-09-08T07:30:00'],
    ]);
    expect(ShiftDayBoundary::belongsToFollowingShift($unfinished, $unfinished[2], $night, weeklyShiftBase(), '2026-09-08'))->toBeFalse();
    $complete = weeklyShiftLogs([
        ['TIME_IN', '2026-09-07T22:00:00'], ['TIME_OUT', '2026-09-08T02:00:00'],
        ['TIME_IN', '2026-09-08T03:00:00'], ['TIME_OUT', '2026-09-08T06:00:00'],
        ['TIME_IN', '2026-09-08T06:30:00'],
    ]);
    expect(ShiftDayBoundary::belongsToFollowingShift($complete, $complete[4], $night, weeklyShiftBase(), '2026-09-08'))->toBeFalse();
});

it('round trips copied weekday facts including rest days without consulting patterns', function () {
    $shift = weeklyShiftBase()->withWeekdayShifts([3 => Shift::restDay(), 1 => weeklyShiftNight()])
        ->withDateShifts(['2026-09-08' => new Shift(endTime: '14:00:00', workDays: [2])]);
    $restored = Shift::fromArray(json_decode(json_encode($shift->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    expect($restored)->toEqual($shift)
        ->and($restored->forDate('2026-09-07')->startTime)->toBe('22:00:00')
        ->and($restored->forDate('2026-09-08')->startTime)->toBe('08:00:00')
        ->and($restored->forDate('2026-09-08')->endTime)->toBe('14:00:00')
        ->and(ShiftResolver::workWeek($restored))->toBe([1, 2, 4, 5]);
});

it('lets tomorrows date override replace weekly hours without changing the next Tuesday', function () {
    $regular = new Shift(endTime: '14:00:00', workDays: [2]);
    $night = new Shift(startTime: '22:00:00', endTime: '06:00:00', workDays: [2]);
    $shift = weeklyShiftBase()->withWeekdayShifts([2 => $regular])
        ->withDateShifts(['2026-09-08' => $night]);
    expect($shift->forDate('2026-09-08'))->toBe($night)
        ->and($shift->forDate('2026-09-15'))->toBe($regular);
    $logs = weeklyShiftLogs([
        ['TIME_IN', '2026-09-08T22:00:00'], ['TIME_OUT', '2026-09-09T06:00:00'],
    ]);
    $days = DtrCalculator::compute($logs, $shift, [], '2026-09-08', '2026-09-09');
    expect($days[0]->workedMinutes)->toBe(480.0)
        ->and($days[0]->timeOut)->toBe('2026-09-09T06:00:00')
        ->and($days[1]->timeOut)->toBeNull();
    $pay = weeklyShiftPay($shift, $logs);
    expect($pay['work_days_pay'])->toBe(1000.0)
        ->and($pay['night_differential'])->toBe(100.0);
});

it('counts one-date rest exceptions across the full month when deriving a cutoff daily rate', function () {
    // The Sep 30 exception is outside this Sep 7–8 cutoff, but reduces the
    // calendar month's divisor from 22 to 21 working dates.
    $shift = weeklyShiftBase()->withDateShifts(['2026-09-30' => Shift::restDay(weeklyShiftBase())]);
    $pay = weeklyShiftPay($shift, weeklyShiftLogs([
        ['TIME_IN', '2026-09-07T08:00:00'], ['TIME_OUT', '2026-09-07T17:00:00'],
        ['TIME_IN', '2026-09-08T08:00:00'], ['TIME_OUT', '2026-09-08T17:00:00'],
    ]), monthly: 21000);
    expect(ShiftResolver::scheduledWorkDaysForShift('2026-09-01', '2026-09-30', $shift))->toBe(21)
        ->and($pay['daily_rate'])->toBe(1000.0)
        ->and($pay['work_days_pay'])->toBe(2000.0)
        ->and(ShiftResolver::worksOnDate('2026-09-30', $shift))->toBeFalse()
        ->and(ShiftResolver::worksOnDate('2026-09-23', $shift))->toBeTrue()
        ->and(DtrCalculator::compute([], $shift, [], '2026-09-30', '2026-09-30')[0]->status)->toBe('EXCUSED');
});

it('refuses a weekly schedule with no working day before deriving payroll rates', function () {
    $shift = weeklyShiftBase()->withWeekdayShifts(array_fill(0, 7, Shift::restDay()));
    expect(fn () => weeklyShiftPay($shift, []))
        ->toThrow(RuntimeException::class, 'The weekly shift roster needs at least one working day.');
});
