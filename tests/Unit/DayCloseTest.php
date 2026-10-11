<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\LeaveRequest as DomainLeave;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Payroll\PayslipCalculator;
use App\Modules\Timekeeping\Domain\Attendance\DayClose;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;

/**
 * When a DTR day is over, and which of those days the Absences tile counts:
 * a day closes at its shift's end — the next morning for a night shift —
 * plus the Formulas OT max, and a flexible day its owed hours and the OT max
 * after midnight, its latest start. Only an absence day counts — no complete
 * Time In and Time Out on a day the schedule works, DayRecord::isAbsenceDay,
 * the one test the payslip's `absence_days` counts with too — so over a
 * finished period the tile and the payslip agree for an employee employed
 * the whole period (the payslip leaves out days before hiring and after
 * resigning), which the last test holds.
 */

/** @param array<string, mixed> $overrides */
function dayCloseShift(array $overrides = []): DomainShift
{
    return DomainShift::fromArray(array_merge([
        'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'grace_minutes' => 15, 'break_minutes' => 60,
    ], $overrides));
}

function dayCloseNight(): DomainShift
{
    return dayCloseShift(['start_time' => '22:00:00', 'end_time' => '06:00:00']);
}

/** A computed day with nothing on it but its date and status. */
function dayCloseRecord(string $date, string $status): DayRecord
{
    return new DayRecord(
        date: $date, timeIn: null, breakOut: null, breakIn: null, timeOut: null,
        workedMinutes: 0.0, lateMinutes: 0.0, undertimeMinutes: 0.0, overtimeMinutes: 0.0,
        excused: false, excuseType: null, excusePaid: null, breakMinutes: 0.0, excuseDays: 0.0,
        status: $status,
    );
}

/** A Manila wall-clock instant, as a screen reads "now". */
function dayCloseNow(string $manila): CarbonImmutable
{
    return ManilaClock::instant($manila);
}

/** A close instant as Manila wall clock, to the minute. */
function dayCloseWall(CarbonImmutable $at): string
{
    return $at->setTimezone(ManilaClock::TZ)->format('Y-m-d H:i');
}

it('closes a day shift at its end plus the Formulas OT max', function () {
    // 2026-09-02 is a Wednesday.
    expect(dayCloseWall(DayClose::at('2026-09-02', dayCloseShift(), 8.0)))->toBe('2026-09-03 01:00')
        ->and(dayCloseWall(DayClose::at('2026-09-02', dayCloseShift(), 1.5)))->toBe('2026-09-02 18:30')
        ->and(dayCloseWall(DayClose::at('2026-09-02', DomainShift::default(), 8.0)))->toBe('2026-09-03 01:00');
});

it('closes a night shift the next day, never at midnight or at its end alone', function () {
    expect(dayCloseWall(DayClose::at('2026-09-02', dayCloseNight(), 8.0)))->toBe('2026-09-03 14:00')
        ->and(DayClose::isOver('2026-09-02', dayCloseNight(), 8.0, dayCloseNow('2026-09-03T00:30:00')))->toBeFalse()
        ->and(DayClose::isOver('2026-09-02', dayCloseNight(), 8.0, dayCloseNow('2026-09-03T06:30:00')))->toBeFalse()
        ->and(DayClose::isOver('2026-09-02', dayCloseNight(), 8.0, dayCloseNow('2026-09-03T14:00:00')))->toBeTrue();
});

it('closes a flexible day its owed hours and the OT max after midnight', function () {
    expect(dayCloseWall(DayClose::at('2026-09-02', dayCloseShift(['mode' => 'FLEXIBLE', 'required_hours' => 8]), 8.0)))->toBe('2026-09-03 16:00')
        ->and(dayCloseWall(DayClose::at('2026-09-02', dayCloseShift(['mode' => 'FLEXIBLE', 'required_hours' => 6]), 2.0)))->toBe('2026-09-03 08:00');
});

it('reads a negative or unreadable OT max as none, closing at the shift end', function (float $hours) {
    expect(dayCloseWall(DayClose::at('2026-09-02', dayCloseShift(), $hours)))->toBe('2026-09-02 17:00');
})->with([0.0, -3.0, NAN]);

it('is over from the close instant exactly, not a millisecond before', function () {
    $close = DayClose::at('2026-09-02', dayCloseShift(), 8.0);

    expect(DayClose::isOver('2026-09-02', dayCloseShift(), 8.0, $close))->toBeTrue()
        ->and(DayClose::isOver('2026-09-02', dayCloseShift(), 8.0, $close->subMillisecond()))->toBeFalse();
});

it('makes an absence day of no complete Time In and Time Out on a day the schedule works', function () {
    // 2026-09-02 is a Wednesday, 2026-09-05 a Saturday; null is the default Monday–Friday.
    foreach ([dayCloseShift(), null] as $schedule) {
        expect(dayCloseRecord('2026-09-02', DayRecord::ABSENT)->isAbsenceDay($schedule))->toBeTrue()
            ->and(dayCloseRecord('2026-09-02', DayRecord::INCOMPLETE)->isAbsenceDay($schedule))->toBeTrue()
            ->and(dayCloseRecord('2026-09-05', DayRecord::INCOMPLETE)->isAbsenceDay($schedule))->toBeFalse();
    }

    foreach ([DayRecord::PRESENT, DayRecord::LATE, DayRecord::EXCUSED, DayRecord::OFFICIAL_BUSINESS] as $status) {
        expect(dayCloseRecord('2026-09-02', $status)->isAbsenceDay(dayCloseShift()))->toBeFalse();
    }
});

it('leaves today out of the count until it is over, whatever it reads now', function () {
    // Yesterday had no punch; today has none YET — the engine reads both ABSENT.
    $days = [dayCloseRecord('2026-09-01', DayRecord::ABSENT), dayCloseRecord('2026-09-02', DayRecord::ABSENT)];

    expect(DayClose::absences($days, dayCloseShift(), 8.0, dayCloseNow('2026-09-02T07:00:00')))->toBe(1)
        ->and(DayClose::absences($days, dayCloseShift(), 8.0, dayCloseNow('2026-09-02T17:30:00')))->toBe(1)
        ->and(DayClose::absences($days, dayCloseShift(), 8.0, dayCloseNow('2026-09-03T01:00:00')))->toBe(2);
});

it('does not count someone still on overtime without a Time Out', function () {
    $today = [dayCloseRecord('2026-09-02', DayRecord::INCOMPLETE)];

    expect(DayClose::absences($today, dayCloseShift(), 8.0, dayCloseNow('2026-09-02T20:00:00')))->toBe(0)
        ->and(DayClose::absences($today, dayCloseShift(), 8.0, dayCloseNow('2026-09-03T01:00:00')))->toBe(1)
        // A shorter Formulas cap closes the day sooner.
        ->and(DayClose::absences($today, dayCloseShift(), 2.0, dayCloseNow('2026-09-02T19:00:00')))->toBe(1);
});

it('waits for a night shift and its overtime before counting it', function () {
    // In at 22:00 on the 1st, no Time Out yet.
    $night = [dayCloseRecord('2026-09-01', DayRecord::INCOMPLETE)];

    expect(DayClose::absences($night, dayCloseNight(), 8.0, dayCloseNow('2026-09-02T00:30:00')))->toBe(0)
        ->and(DayClose::absences($night, dayCloseNight(), 8.0, dayCloseNow('2026-09-02T09:00:00')))->toBe(0)
        ->and(DayClose::absences($night, dayCloseNight(), 8.0, dayCloseNow('2026-09-02T14:00:00')))->toBe(1);
});

it('closes each date by its own hours on a weekly schedule', function () {
    // Monday–Friday days, but Fridays are a 22:00–06:00 night; 2026-09-04 is a Friday.
    $schedule = dayCloseShift()->withWeekdayShifts([5 => dayCloseNight()]);
    $friday = [dayCloseRecord('2026-09-04', DayRecord::INCOMPLETE)];

    // The standing day shift would have closed at 01:00.
    expect(DayClose::absences($friday, $schedule, 8.0, dayCloseNow('2026-09-05T02:00:00')))->toBe(0)
        ->and(DayClose::absences($friday, $schedule, 8.0, dayCloseNow('2026-09-05T14:00:00')))->toBe(1);
});

it('counts exactly the days the payslip counts as absences once the period is over', function (?DomainShift $shift) {
    // Monday 2026-08-31 to Sunday 2026-09-06, every Time In at 08:00 Manila.
    $in = static fn (string $date): array => ['log_type' => 'TIME_IN', 'logged_at' => $date.'T00:00:00.000Z'];
    $logs = DomainLog::listFromArrays([
        $in('2026-08-31'), ['log_type' => 'TIME_OUT', 'logged_at' => '2026-08-31T09:00:00.000Z'], // present
        // 2026-09-01: no punch at all
        $in('2026-09-02'), // a Time In with no Time Out
        $in('2026-09-03'), // the same, on a holiday in the work week
        $in('2026-09-05'), // the same, on a Saturday rest day
    ]);
    $leaves = DomainLeave::listFromArrays([[
        'request_type' => 'SICK_LEAVE', 'status' => 'APPROVED', 'is_paid' => true,
        'day_type' => 'WHOLE_DAY', 'start_date' => '2026-09-04', 'end_date' => '2026-09-04',
    ]]);
    $holidays = HolidaySetting::listFromArrays([['name' => 'Test Holiday', 'date' => '2026-09-03', 'schedule' => 'ONE_TIME', 'type' => 'SPECIAL', 'percent' => 30]]);

    $slip = PayslipCalculator::compute(
        employee: (object) ['id' => 'E1', 'monthly_rate' => 30000.0, 'hourly_rate' => 0],
        logs: $logs,
        shift: $shift,
        leaves: $leaves,
        periodStart: '2026-08-31',
        periodEnd: '2026-09-06',
        bonus: 0.0,
        settings: ['holidays' => $holidays],
    );
    $days = DtrCalculator::compute($logs, $shift, $leaves, '2026-08-31', '2026-09-06', $holidays);

    expect($slip->absence_days)->toBe(3.0)
        ->and(DayClose::absences($days, $shift, 8.0, dayCloseNow('2026-09-08T00:00:00')))->toBe((int) $slip->absence_days);
})->with([
    'rostered' => [fn (): DomainShift => dayCloseShift()],
    'not rostered' => [null],
]);
