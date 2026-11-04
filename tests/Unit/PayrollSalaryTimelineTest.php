<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Payroll\PayRules;
use App\Domain\Payroll\PayRulesTimeline;
use App\Domain\Payroll\PayslipCalculator;

/**
 * DELIBERATE DIVERGENCE 18: a salary version taking over inside the period
 * prices the days from its date at its own rates — a Rate Per Month over
 * the month's working days, or a Rate Per Day as it stands — while the
 * days before it keep the first day's salary; the reported daily rate and
 * the contribution base stay the first day's. No segment, or one in force
 * since always, is the reference bit for bit.
 *
 * September 2026 has 22 scheduled weekdays, so 22,000 a month is 1,000 a
 * day and 24,200 is 1,100.
 */
const SALARY_TL_SCHEDULED = ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-14', '2026-09-15'];

function salaryTlShift(): DomainShift
{
    return DomainShift::fromArray([
        'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'break_start' => '12:00:00', 'break_end' => '13:00:00',
    ]);
}

/**
 * @param  list<array{from: string, monthly_rate: ?float, daily_rate: ?float}>  $segments
 * @param  list<string>  $dates
 * @return array<string, mixed>
 */
function salaryTlSlip(array $segments, ?float $monthly = 22000.0, ?float $daily = null, array $dates = SALARY_TL_SCHEDULED): array
{
    $logs = [];
    foreach ($dates as $date) {
        $logs[] = ['log_type' => 'TIME_IN', 'logged_at' => "{$date}T00:00:00.000Z"];
        $logs[] = ['log_type' => 'TIME_OUT', 'logged_at' => "{$date}T09:00:00.000Z"];
    }

    return PayslipCalculator::compute(
        employee: (object) [
            'id' => 'E1', 'monthly_rate' => $monthly, 'daily_rate' => $daily, 'hourly_rate' => 0,
            'allow_sss' => true, 'allow_philhealth' => false, 'allow_pagibig' => false, 'allow_withholding_tax' => false,
            'salary_rates' => $segments,
        ],
        logs: DomainLog::listFromArrays($logs),
        shift: salaryTlShift(),
        leaves: [],
        periodStart: '2026-09-01',
        periodEnd: '2026-09-15',
        bonus: 0.0,
        settings: ['formulas' => [], 'holidays' => []],
    )->toArray();
}

it('prices no segment, and a segment in force since always, exactly as the reference', function () {
    $reference = salaryTlSlip([]);
    expect($reference['work_days_pay'])->toBe(11000.0)
        ->and(salaryTlSlip([['from' => PayRulesTimeline::ALWAYS, 'monthly_rate' => 22000.0, 'daily_rate' => null]]))->toBe($reference);
});

it('prices the days from a raise at the new monthly rate and the days before it at the old', function () {
    $raised = salaryTlSlip([['from' => '2026-09-10', 'monthly_rate' => 24200.0, 'daily_rate' => null]]);
    $reference = salaryTlSlip([]);

    expect($raised['work_days'])->toBe(11.0)
        ->and($raised['work_days_pay'])->toBe(7 * 1000.0 + 4 * 1100.0)
        // The payslip reports the first day's rate, and contributions read the first day's salary.
        ->and($raised['daily_rate'])->toBe(1000.0)
        ->and($raised['sss'])->toBe($reference['sss'])
        // The cash-out's daily rate follows the date.
        ->and(PayslipCalculator::dailyRateOn((object) ['monthly_rate' => 22000.0, 'daily_rate' => null, 'salary_rates' => [['from' => '2026-09-10', 'monthly_rate' => 24200.0, 'daily_rate' => null]]], '2026-09-09', salaryTlShift(), PayRules::fromFormulas()))->toBe(1000.0)
        ->and(PayslipCalculator::dailyRateOn((object) ['monthly_rate' => 22000.0, 'daily_rate' => null, 'salary_rates' => [['from' => '2026-09-10', 'monthly_rate' => 24200.0, 'daily_rate' => null]]], '2026-09-10', salaryTlShift(), PayRules::fromFormulas()))->toBe(1100.0);
});

it('switches to a Rate Per Day, or back from one, on its date', function () {
    $perDay = salaryTlSlip([['from' => '2026-09-10', 'monthly_rate' => null, 'daily_rate' => 1200.0]]);
    expect($perDay['work_days_pay'])->toBe(7 * 1000.0 + 4 * 1200.0);

    $monthlyAgain = salaryTlSlip([['from' => '2026-09-10', 'monthly_rate' => 22000.0, 'daily_rate' => null]], monthly: 0.0, daily: 900.0);
    expect($monthlyAgain['work_days_pay'])->toBe(7 * 900.0 + 4 * 1000.0)
        ->and($monthlyAgain['daily_rate'])->toBe(900.0);
});

it('prices the late and undertime minutes of each side at its own hour', function () {
    // The 9th and the 10th: in at 08:00, out at 16:00 — an hour of undertime each.
    $logs = [];
    foreach (['2026-09-09', '2026-09-10'] as $date) {
        $logs[] = ['log_type' => 'TIME_IN', 'logged_at' => "{$date}T00:00:00.000Z"];
        $logs[] = ['log_type' => 'TIME_OUT', 'logged_at' => "{$date}T08:00:00.000Z"];
    }
    $slip = PayslipCalculator::compute(
        employee: (object) ['id' => 'E1', 'monthly_rate' => 22000.0, 'daily_rate' => null, 'hourly_rate' => 0,
            'allow_sss' => false, 'allow_philhealth' => false, 'allow_pagibig' => false, 'allow_withholding_tax' => false,
            'salary_rates' => [['from' => '2026-09-10', 'monthly_rate' => 24200.0, 'daily_rate' => null]]],
        logs: DomainLog::listFromArrays($logs), shift: salaryTlShift(), leaves: [],
        periodStart: '2026-09-01', periodEnd: '2026-09-15', bonus: 0.0, settings: ['formulas' => [], 'holidays' => []],
    )->toArray();
    // 125 an hour on the 9th, 137.5 on the 10th.
    expect($slip['undertime_minutes'])->toBe(120.0)
        ->and($slip['undertime_deduction'])->toBe(262.5);
});
