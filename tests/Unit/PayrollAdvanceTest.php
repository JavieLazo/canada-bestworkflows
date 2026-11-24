<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\LeaveRequest as DomainLeave;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Payroll\PayslipCalculator;
use App\Modules\Payroll\Domain\PayrollAdvance;

/**
 * DELIBERATE DIVERGENCE 14: the advance payroll. A payroll processed before
 * its period ends reads attendance only through its "attendance counted
 * through" date; every scheduled working day after it is PRESUMED — a full
 * day at its own rate, no lateness, undertime, overtime or night pay,
 * whatever punches it carries — and counted in `presumed_days`. A rest day
 * owes nothing, a holiday keeps its own rule and a whole day of approved
 * leave is priced as leave. The settlement of earlier presumed days joins
 * gross pay before the tax. With no counted date, and with a settlement of
 * zero, the engine is the reference's, which the golden vectors hold.
 *
 * September 2026 starts on a Tuesday: 22 scheduled Monday-to-Friday days, so
 * a CAD 30,000 monthly rate is CAD 1,363.636… a day. The period is 1 to 15
 * September; the 11th is a Friday, the 14th a Monday.
 */
/** Monday to Friday, 08:00-17:00 with a 60-minute unpaid break: 8 paid hours. */
function advShift(): DomainShift
{
    return DomainShift::fromArray([
        'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'grace_minutes' => 15, 'break_minutes' => 60,
    ]);
}

/**
 * One day's punches, Manila wall clock written as UTC instants (08:00 Manila is 00:00Z).
 *
 * @return list<array<string, mixed>>
 */
function advPunches(string $date, string $in = '00:00', string $out = '09:00'): array
{
    return [
        ['log_type' => 'TIME_IN', 'logged_at' => "{$date}T{$in}:00.000Z"],
        ['log_type' => 'TIME_OUT', 'logged_at' => "{$date}T{$out}:00.000Z"],
    ];
}

/**
 * Full days on each of the first nine working days, 1 to 11 September.
 *
 * @return list<array<string, mixed>>
 */
function advWorkedThroughEleventh(): array
{
    $logs = [];
    foreach (['01', '02', '03', '04', '07', '08', '09', '10', '11'] as $day) {
        $logs = [...$logs, ...advPunches("2026-09-{$day}")];
    }

    return $logs;
}

/**
 * @param  list<array<string, mixed>>  $logs
 * @param  list<DomainLeave>  $leaves
 * @param  list<HolidaySetting>  $holidays
 * @param  array<string, int>|null  $overtimeApprovals
 * @return array<string, mixed>
 */
function advSlip(array $logs, ?string $through, array $leaves = [], array $holidays = [], ?array $overtimeApprovals = [], float $settlement = 0.0): array
{
    return PayslipCalculator::compute(
        employee: (object) [
            'id' => 'E1', 'monthly_rate' => 30000, 'hourly_rate' => 0,
            'allow_sss' => true, 'allow_philhealth' => true, 'allow_pagibig' => true, 'allow_withholding_tax' => true,
        ],
        logs: DomainLog::listFromArrays($logs),
        shift: advShift(),
        leaves: $leaves,
        periodStart: '2026-09-01',
        periodEnd: '2026-09-15',
        bonus: 0.0,
        settings: ['holidays' => $holidays],
        overtimeApprovals: $overtimeApprovals,
        attendanceThrough: $through,
        settlement: $settlement,
    )->toArray();
}

/*
|--------------------------------------------------------------------------
| The rule
|--------------------------------------------------------------------------
*/

it('normalizes a blank or period-end date to no advance', function () {
    expect(PayrollAdvance::normalize(null, '2026-09-15'))->toBeNull()
        ->and(PayrollAdvance::normalize('', '2026-09-15'))->toBeNull()
        ->and(PayrollAdvance::normalize('  ', '2026-09-15'))->toBeNull()
        ->and(PayrollAdvance::normalize('2026-09-15', '2026-09-15'))->toBeNull()
        ->and(PayrollAdvance::normalize('2026-09-11', '2026-09-15'))->toBe('2026-09-11');
});

it('accepts a counted date inside the period that is already over, and refuses the rest', function (?string $through, string $today, ?string $error) {
    $message = PayrollAdvance::error($through, '2026-09-01', '2026-09-15', $today);
    if ($error === null) {
        expect($message)->toBeNull();
    } else {
        expect($message)->toContain($error);
    }
})->with([
    'no advance' => [null, '2026-09-12', null],
    'yesterday' => ['2026-09-11', '2026-09-12', null],
    'a week ago' => ['2026-09-05', '2026-09-12', null],
    'today' => ['2026-09-12', '2026-09-12', 'yesterday at the latest'],
    'tomorrow' => ['2026-09-13', '2026-09-12', 'yesterday at the latest'],
    'before the period' => ['2026-08-31', '2026-09-12', 'inside the payroll period'],
    'after the period' => ['2026-09-16', '2026-09-20', 'inside the payroll period'],
    'not a date' => ['2026-09-31', '2026-10-05', 'valid date'],
]);

it('defaults a running period to yesterday and an ended one to nothing', function () {
    expect(PayrollAdvance::defaultThrough('2026-09-01', '2026-09-15', '2026-09-12'))->toBe('2026-09-11')
        ->and(PayrollAdvance::defaultThrough('2026-09-01', '2026-09-15', '2026-09-15'))->toBe('2026-09-14')
        ->and(PayrollAdvance::defaultThrough('2026-09-01', '2026-09-15', '2026-09-16'))->toBeNull()
        ->and(PayrollAdvance::defaultThrough('2026-09-01', '2026-09-15', '2026-09-01'))->toBeNull()
        ->and(PayrollAdvance::defaultThrough('2026-09-01', '2026-09-15', '2026-09-02'))->toBe('2026-09-01')
        ->and(PayrollAdvance::presumedRange('2026-09-11', '2026-09-15'))->toBe(['from' => '2026-09-12', 'to' => '2026-09-15'])
        ->and(PayrollAdvance::presumedRange(null, '2026-09-15'))->toBeNull()
        ->and(PayrollAdvance::presumedRange('2026-09-15', '2026-09-15'))->toBeNull();
});

it('pulls a member\'s counted date back before their first unfinished day, and leaves it alone otherwise', function () {
    $row = static fn (string $date, string $status): object => (object) ['date' => $date, 'status' => $status];
    $finished = [$row('2026-09-10', 'PRESENT'), $row('2026-09-11', 'LATE'), $row('2026-09-14', 'PRESENT'), $row('2026-09-15', 'INCOMPLETE')];
    $openOnTheCountedDay = [$row('2026-09-10', 'PRESENT'), $row('2026-09-11', 'PRESENT'), $row('2026-09-14', 'INCOMPLETE')];
    $openEarlier = [$row('2026-09-14', 'PRESENT'), $row('2026-09-11', 'INCOMPLETE'), $row('2026-09-10', 'PRESENT'), $row('2026-09-15', 'INCOMPLETE')];
    $openOnTheFirstDay = [$row('2026-09-01', 'INCOMPLETE'), $row('2026-09-02', 'PRESENT')];

    // An unfinished day after the counted date is already presumed: it moves nothing.
    expect(PayrollAdvance::countedThrough('2026-09-14', $finished))->toBe('2026-09-14')
        // The counted day itself unfinished: counted through the day before.
        ->and(PayrollAdvance::countedThrough('2026-09-14', $openOnTheCountedDay))->toBe('2026-09-13')
        // The earliest unfinished counted day decides, whatever order the rows come in.
        ->and(PayrollAdvance::countedThrough('2026-09-14', $openEarlier))->toBe('2026-09-10')
        // The first day unfinished: the day before the period, so every day is presumed.
        ->and(PayrollAdvance::countedThrough('2026-09-14', $openOnTheFirstDay))->toBe('2026-08-31')
        // Absent, excused or official business are finished days.
        ->and(PayrollAdvance::countedThrough('2026-09-14', [$row('2026-09-14', 'ABSENT'), $row('2026-09-11', 'EXCUSED'), $row('2026-09-10', 'OFFICIAL_BUSINESS')]))->toBe('2026-09-14')
        // No advance: nothing is presumed, so nothing moves.
        ->and(PayrollAdvance::countedThrough(null, $openOnTheCountedDay))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Presumed days
|--------------------------------------------------------------------------
*/

it('pays the scheduled working days after the counted date as full days, whatever they carry', function () {
    $logs = advWorkedThroughEleventh();
    // The 14th: in late and out late — lateness and overtime the engine would read.
    $late = [...$logs, ...advPunches('2026-09-14', '01:30', '12:00')];

    $plain = advSlip($logs, '2026-09-11');
    $withPunches = advSlip($late, '2026-09-11');
    $reference = advSlip($late, null);

    // 9 worked days plus the presumed 14th and 15th; the 12th and 13th are rest days.
    expect($plain['work_days'])->toBe(11.0)
        ->and($plain['presumed_days'])->toBe(2.0)
        ->and($plain['absence_days'])->toBe(0.0)
        ->and($plain['work_days_pay'])->toBe(15000.0)
        ->and($plain['basic_pay'])->toBe(15000.0)
        ->and($plain['late_deduction'])->toBe(0.0)
        ->and($plain['overtime_pay'])->toBe(0.0)
        ->and($plain['night_differential'])->toBe(0.0)
        ->and($plain['settlement'])->toBe(0.0)
        // The punches on a presumed day change nothing: the day is decided, not read.
        ->and($withPunches)->toBe($plain)
        // Without a counted date the same punches are read: a late day with overtime, and the 15th absent.
        ->and($reference['work_days'])->toBe(10.0)
        ->and($reference['presumed_days'])->toBe(0.0)
        ->and($reference['absence_days'])->toBe(1.0)
        ->and($reference['late_deduction'])->toBeGreaterThan(0.0);
});

it('leaves rest days, holidays and whole days of approved leave to their own rules', function () {
    $logs = advWorkedThroughEleventh();
    $holiday = HolidaySetting::listFromArrays([['date' => '2026-09-15', 'name' => 'Synthetic', 'schedule' => 'ONE_TIME', 'type' => 'REGULAR', 'percent' => 100]]);
    $leave = DomainLeave::listFromArrays([[
        'id' => 'L1', 'employee_id' => 'E1', 'request_type' => 'SICK', 'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-09-14', 'end_date' => '2026-09-14', 'status' => 'APPROVED', 'is_paid' => true,
    ]]);

    $withHoliday = advSlip($logs, '2026-09-11', holidays: $holiday);
    $withLeave = advSlip($logs, '2026-09-11', leaves: $leave);
    $referenceLeave = advSlip([...$logs, ...advPunches('2026-09-15')], null, leaves: $leave);

    // The holiday on the 15th keeps its own row: only the 14th is presumed, and the
    // unworked holiday pays the reference's rule (nothing, the matrix's default).
    expect($withHoliday['presumed_days'])->toBe(1.0)
        ->and($withHoliday['work_days'])->toBe(10.0)
        ->and($withHoliday['holiday_days'])->toBe(0.0)
        // The approved leave on the 14th is priced as leave; only the 15th is presumed.
        ->and($withLeave['presumed_days'])->toBe(1.0)
        ->and($withLeave['work_days'])->toBe(10.0)
        ->and($withLeave['paid_leave_days'])->toBe(1.0)
        ->and($withLeave['leave_pay'])->toBe($referenceLeave['leave_pay'])
        // A presumed day and a worked day are worth the same, so the presumed
        // payslip equals the reference where the 15th was in fact worked.
        ->and($withLeave['gross_pay'])->toBe($referenceLeave['gross_pay']);
});

it('is the reference when the counted date is the period end, is later, or is null', function () {
    $logs = [...advWorkedThroughEleventh(), ...advPunches('2026-09-14'), ...advPunches('2026-09-15', '00:00', '11:00')];
    $reference = advSlip($logs, null, overtimeApprovals: ['2026-09-15' => 120]);

    expect(advSlip($logs, '2026-09-15', overtimeApprovals: ['2026-09-15' => 120]))->toBe($reference)
        ->and(advSlip($logs, '2026-09-30', overtimeApprovals: ['2026-09-15' => 120]))->toBe($reference)
        ->and($reference['overtime_pay'])->toBeGreaterThan(0.0);
});

/*
|--------------------------------------------------------------------------
| The settlement
|--------------------------------------------------------------------------
*/

it('adds a settlement to gross pay before the tax, in either direction, and adds nothing at zero', function () {
    $logs = advWorkedThroughEleventh();
    $none = advSlip($logs, '2026-09-11');
    $owed = advSlip($logs, '2026-09-11', settlement: 1363.64);
    $charged = advSlip($logs, '2026-09-11', settlement: -1363.64);

    expect($owed['settlement'])->toBe(1363.64)
        ->and($owed['gross_pay'])->toBe(round($none['gross_pay'] + 1363.64, 2))
        ->and($owed['basic_pay'])->toBe($none['basic_pay'])
        ->and($owed['withholding_tax'])->toBeGreaterThanOrEqual($none['withholding_tax'])
        ->and($charged['settlement'])->toBe(-1363.64)
        ->and($charged['gross_pay'])->toBe(round($none['gross_pay'] - 1363.64, 2))
        ->and($charged['net_pay'])->toBeLessThan($none['net_pay'])
        ->and(advSlip($logs, '2026-09-11', settlement: 0.0))->toBe($none);
});
