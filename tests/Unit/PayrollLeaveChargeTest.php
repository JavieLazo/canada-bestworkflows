<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Payroll\PayslipCalculator;

/**
 * DELIBERATE DIVERGENCE 15 in the engine: the covered part of a late day's
 * minutes is not priced, the covered part of an absent day is paid as leave
 * — the same buckets an approved paid leave fills — and the payslip reports
 * the days of credits charged. An empty charge map, and null, are the
 * reference bit for bit, which the golden vectors hold.
 *
 * September 2026 has 22 scheduled Monday-to-Friday days, so CAD 30,000 a
 * month is CAD 1,363.636… a day and CAD 170.4545… an hour. The period is 1
 * to 15 September.
 */
function plcShift(): DomainShift
{
    return DomainShift::fromArray([
        'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'grace_minutes' => 15, 'break_minutes' => 60,
    ]);
}

/**
 * Full days on every scheduled day of 1 to 15 September except the ones
 * named; a late Time In where given (Manila 08:00 is 00:00Z).
 *
 * @param  list<string>  $absent
 * @param  array<string, string>  $lateIn  date => Time In (UTC clock)
 * @return list<DomainLog>
 */
function plcLogs(array $absent = [], array $lateIn = []): array
{
    $logs = [];
    foreach (['01', '02', '03', '04', '07', '08', '09', '10', '11', '14', '15'] as $day) {
        $date = "2026-09-{$day}";
        if (in_array($date, $absent, true)) {
            continue;
        }
        $logs[] = ['log_type' => 'TIME_IN', 'logged_at' => "{$date}T".($lateIn[$date] ?? '00:00').':00.000Z'];
        $logs[] = ['log_type' => 'TIME_OUT', 'logged_at' => "{$date}T09:00:00.000Z"];
    }

    return DomainLog::listFromArrays($logs);
}

/**
 * @param  list<DomainLog>  $logs
 * @param  array{late: array<string, float>, absence: array<string, float>, late_days: float}|null  $charges
 * @return array<string, mixed>
 */
function plcSlip(array $logs, ?array $charges): array
{
    return PayslipCalculator::compute(
        employee: (object) ['id' => 'E1', 'monthly_rate' => 30000, 'hourly_rate' => 0, 'allow_sss' => true, 'allow_philhealth' => true, 'allow_pagibig' => true, 'allow_withholding_tax' => true],
        logs: $logs, shift: plcShift(), leaves: [], periodStart: '2026-09-01', periodEnd: '2026-09-15', bonus: 0.0,
        settings: ['holidays' => []], overtimeApprovals: [], leaveCharges: $charges,
    )->toArray();
}

it('pays the covered part of an absent day as leave and leaves the rest unpaid', function () {
    $logs = plcLogs(absent: ['2026-09-03', '2026-09-10']);
    $reference = plcSlip($logs, null);
    $charged = plcSlip($logs, ['late' => [], 'absence' => ['2026-09-03' => 1.0, '2026-09-10' => 0.25], 'late_days' => 0.0]);

    expect($reference['work_days'])->toBe(9.0)->and($reference['absence_days'])->toBe(2.0)->and($reference['paid_leave_days'])->toBe(0.0)
        ->and($reference['leave_pay'])->toBe(0.0)->and($reference['work_days_pay'])->toBe(12272.73)
        // 1.25 days charged: paid as leave, carved out of the one paid-days total exactly as approved leave is.
        ->and($charged['work_days'])->toBe(9.0)->and($charged['absence_days'])->toBe(2.0)
        ->and($charged['paid_leave_days'])->toBe(1.25)->and($charged['leave_charged_days'])->toBe(1.25)
        ->and($charged['leave_pay'])->toBe(1704.55)
        ->and(round($charged['work_days_pay'] + $charged['leave_pay'], 2))->toBe(13977.27)
        ->and($charged['gross_pay'])->toBeGreaterThan($reference['gross_pay']);
});

it('prices only the uncovered part of a late day\'s minutes', function () {
    // 60 minutes late on the 2nd (09:00 Manila), 30 on the 8th.
    $logs = plcLogs(lateIn: ['2026-09-02' => '01:00', '2026-09-08' => '00:30']);
    $reference = plcSlip($logs, null);
    $charged = plcSlip($logs, ['late' => ['2026-09-02' => 1.0, '2026-09-08' => 0.5], 'absence' => [], 'late_days' => 0.1875]);

    expect($reference['late_minutes'])->toBe(90.0)->and($reference['late_deduction'])->toBe(255.68)
        // The 2nd wholly covered, half of the 8th: 15 minutes priced.
        ->and($charged['late_minutes'])->toBe(15.0)->and($charged['late_deduction'])->toBe(42.61)
        ->and($charged['leave_charged_days'])->toBe(0.1875)
        ->and($charged['work_days_pay'])->toBe($reference['work_days_pay'])
        ->and($charged['net_pay'])->toBeGreaterThan($reference['net_pay']);
});

it('is the reference with an empty charge map or none', function () {
    $logs = plcLogs(absent: ['2026-09-03'], lateIn: ['2026-09-02' => '01:00']);
    $reference = plcSlip($logs, null);
    expect(plcSlip($logs, ['late' => [], 'absence' => [], 'late_days' => 0.0]))->toBe($reference)
        ->and($reference['leave_charged_days'])->toBe(0.0);
});
