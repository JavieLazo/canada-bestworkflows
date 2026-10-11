<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Payroll\PayslipCalculator;

/**
 * DIVERGENCE 10: Rate Per Day is the pay per day whenever it is above zero.
 *
 * The reference derives every day from `monthly_rate` over the month's
 * scheduled working days. EMS's record also carries `daily_rate`; entered, it
 * prices every day as it stands (the same in every month), with or without a
 * monthly rate, and everything priced from a day or an hour follows it. Blank
 * or zero, the monthly derivation is untouched.
 *
 * The contribution tables keep the monthly rate whenever it has a value; only
 * with a blank monthly rate do they read the day rate times the month's
 * working days, instead of posting nothing.
 *
 * The golden-vector guard proves a Rate Per Day equal to the derived day
 * changes nothing; this file pins the rates that differ from it.
 */
/** Monday to Friday, 08:00-17:00 with a 60-minute unpaid break: 8 paid hours. */
function dailyRateShift(): DomainShift
{
    return DomainShift::fromArray([
        'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'grace_minutes' => 15, 'break_minutes' => 60,
    ]);
}

/**
 * @param  array<string, mixed>  $employee  merged over an employee with every contribution switched on
 * @param  list<array<string, mixed>>  $logs
 * @param  array<string, int>|null  $overtimeApprovals
 * @return array<string, mixed>
 */
function dailyRateSlip(
    array $employee,
    string $start = '2026-09-01',
    string $end = '2026-09-15',
    array $logs = [],
    ?DomainShift $shift = null,
    ?array $overtimeApprovals = null,
): array {
    return PayslipCalculator::compute(
        employee: (object) ($employee + [
            'id' => 'E1', 'monthly_rate' => 0, 'hourly_rate' => 0,
            'allow_sss' => true, 'allow_philhealth' => true, 'allow_pagibig' => true,
            'allow_withholding_tax' => true,
        ]),
        logs: DomainLog::listFromArrays($logs),
        shift: $shift ?? dailyRateShift(),
        leaves: [],
        periodStart: $start,
        periodEnd: $end,
        bonus: 0.0,
        overtimeApprovals: $overtimeApprovals,
    )->toArray();
}

/**
 * One day's punches in Manila wall-clock hours. The offsets are explicit:
 * stored instants are UTC, so 08:00 Manila is 00:00Z.
 *
 * @return list<array<string, mixed>>
 */
function dailyRatePunches(string $date, string $in = '00:00', string $out = '09:00'): array
{
    return [
        ['log_type' => 'TIME_IN', 'logged_at' => "{$date}T{$in}:00.000Z"],
        ['log_type' => 'TIME_OUT', 'logged_at' => "{$date}T{$out}:00.000Z"],
    ];
}

/**
 * Full days on each date.
 *
 * @param  list<string>  $dates
 * @return list<array<string, mixed>>
 */
function dailyRateDays(array $dates): array
{
    return array_merge(...array_map(static fn (string $d): array => dailyRatePunches($d), $dates));
}

/** September 1-15, 2026: the eleven Monday-to-Friday dates. */
const DAILY_RATE_FIRST_HALF = [
    '2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-07', '2026-09-08',
    '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-14', '2026-09-15',
];

it('pays each worked day at the Rate Per Day even when a monthly rate is also entered', function () {
    // 30000 / 22 September weekdays would be 1363.64 a day; the entered rate wins.
    $slip = dailyRateSlip(['monthly_rate' => 30000.0, 'daily_rate' => 1000.0], logs: dailyRateDays(DAILY_RATE_FIRST_HALF));

    expect($slip['daily_rate'])->toBe(1000.0)
        ->and($slip['work_days'])->toBe(11.0)
        ->and($slip['work_days_pay'])->toBe(11000.0)
        ->and($slip['basic_pay'])->toBe(11000.0)
        ->and($slip['gross_pay'])->toBe(11000.0);
});

it('keeps the monthly rate as the contribution base when both rates are entered', function () {
    $logs = dailyRateDays(DAILY_RATE_FIRST_HALF);
    $both = dailyRateSlip(['monthly_rate' => 30000.0, 'daily_rate' => 1000.0], logs: $logs);
    $monthlyOnly = dailyRateSlip(['monthly_rate' => 30000.0], logs: $logs);

    // Half of each monthly table figure for 30,000 (a null mode halves).
    expect($both['sss'])->toBe(750.0)
        ->and($both['philhealth'])->toBe(375.0)
        ->and($both['pagibig'])->toBe(100.0);
    foreach (['sss', 'sss_er', 'philhealth', 'philhealth_er', 'pagibig', 'pagibig_er'] as $line) {
        expect($both[$line])->toBe($monthlyOnly[$line], $line);
    }
});

it('derives the day from the monthly rate when the Rate Per Day is blank or zero', function () {
    $logs = dailyRateDays(DAILY_RATE_FIRST_HALF);
    $absent = dailyRateSlip(['monthly_rate' => 30000.0], logs: $logs);

    expect($absent['daily_rate'])->toBe(1363.64)
        ->and($absent['work_days_pay'])->toBe(15000.0)
        ->and(dailyRateSlip(['monthly_rate' => 30000.0, 'daily_rate' => null], logs: $logs))->toBe($absent)
        ->and(dailyRateSlip(['monthly_rate' => 30000.0, 'daily_rate' => 0.0], logs: $logs))->toBe($absent)
        ->and(dailyRateSlip(['monthly_rate' => 30000.0, 'daily_rate' => '0.00'], logs: $logs))->toBe($absent);
});

it('reads a Rate Per Day stored as a decimal string, as the database returns it', function () {
    $slip = dailyRateSlip(['daily_rate' => '1000.00'], logs: dailyRateDays(DAILY_RATE_FIRST_HALF));

    expect($slip['work_days_pay'])->toBe(11000.0);
});

it('keeps the Rate Per Day the same in both months of a period that crosses one', function () {
    // January 2026 has 22 weekdays and February 20, so a monthly rate prices
    // the two halves differently: 2 x 30000/22 + 2 x 30000/20 = 5727.27.
    $logs = dailyRateDays(['2026-01-29', '2026-01-30', '2026-02-02', '2026-02-03']);

    $daily = dailyRateSlip(['monthly_rate' => 30000.0, 'daily_rate' => 1000.0], '2026-01-29', '2026-02-03', $logs);
    $monthly = dailyRateSlip(['monthly_rate' => 30000.0], '2026-01-29', '2026-02-03', $logs);

    expect($daily['work_days_pay'])->toBe(4000.0)
        ->and($monthly['work_days_pay'])->toBe(5727.27);
});

it('prices overtime and undertime from the Rate Per Day over the paid hours', function () {
    // 800 a day over the 8 paid hours of 08:00-17:00 less the break: 100 an hour.
    $logs = [
        ...dailyRatePunches('2026-09-01', '00:00', '10:00'), // 08:00-18:00: one hour over
        ...dailyRatePunches('2026-09-02', '00:00', '08:00'), // 08:00-16:00: one hour short
    ];

    $slip = dailyRateSlip(
        ['monthly_rate' => 30000.0, 'daily_rate' => 800.0],
        logs: $logs,
        overtimeApprovals: ['2026-09-01' => 60],
    );

    expect($slip['overtime_hours'])->toBe(1.0)
        ->and($slip['overtime_pay'])->toBe(125.0)
        ->and($slip['undertime_minutes'])->toBe(60.0)
        ->and($slip['undertime_deduction'])->toBe(100.0);
});

it('lets a stored hourly rate override the hourly figures of a Rate Per Day', function () {
    $slip = dailyRateSlip(
        ['daily_rate' => 800.0, 'hourly_rate' => 150.0],
        logs: dailyRatePunches('2026-09-02', '00:00', '08:00'),
    );

    expect($slip['work_days_pay'])->toBe(800.0)
        ->and($slip['undertime_deduction'])->toBe(150.0);
});

it('bases contributions on the Rate Per Day times the month\'s working days when no monthly rate is entered', function () {
    $logs = dailyRateDays(DAILY_RATE_FIRST_HALF);
    $slip = dailyRateSlip(['daily_rate' => 1000.0], logs: $logs);

    // 1000 x 22 September weekdays = 22,000 a month, halved on this payslip.
    expect($slip['sss'])->toBe(550.0)
        ->and($slip['philhealth'])->toBe(275.0)
        ->and($slip['pagibig'])->toBe(100.0)
        // The whole payslip is the one a 22,000 monthly rate produces, since
        // 22000 / 22 is the same day.
        ->and($slip)->toBe(dailyRateSlip(['monthly_rate' => 22000.0], logs: $logs));
});

it('counts the working days of the period\'s first month for that base', function () {
    // February 2026 has 20 weekdays: 1000 x 20 = 20,000.
    $slip = dailyRateSlip(['daily_rate' => 1000.0], '2026-02-02', '2026-02-13', dailyRateDays(['2026-02-02']));

    expect($slip['philhealth'])->toBe(250.0);
});

it('still posts no contributions when neither rate is entered', function () {
    $slip = dailyRateSlip(['monthly_rate' => 0.0, 'daily_rate' => null], logs: dailyRateDays(DAILY_RATE_FIRST_HALF));

    expect($slip['gross_pay'])->toBe(0.0)
        ->and($slip['sss'])->toBe(0.0)
        ->and($slip['philhealth'])->toBe(0.0)
        ->and($slip['pagibig'])->toBe(0.0);
});

it('refuses a week with no working day even when a Rate Per Day makes the division unnecessary', function () {
    $allRest = dailyRateShift()->withWeekdayShifts(array_fill(0, 7, DomainShift::restDay()));

    expect(fn () => dailyRateSlip(['daily_rate' => 1000.0], shift: $allRest))
        ->toThrow(RuntimeException::class, 'The weekly shift roster needs at least one working day.');
});
