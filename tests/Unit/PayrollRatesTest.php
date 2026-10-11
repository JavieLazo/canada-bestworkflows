<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Payroll\PayrollRates;
use App\Domain\Payroll\PayslipCalculator;

/** Captured formula rows outside the active engine contract cannot alter payroll. */
it('ignores historical SIL parameters while preserving all active rates', function () {
    $active = [
        ['key' => 'OT', 'params' => [['key' => 'percentage', 'value' => 1.5], ['key' => 'max_ot', 'value' => 4]]],
        ['key' => 'NSD', 'params' => [['key' => 'percentage', 'value' => 0.2], ['key' => 'max_nsd', 'value' => 6]]],
        ['key' => 'REST_DAY', 'params' => [['key' => 'percentage', 'value' => 1.4], ['key' => 'max_hours', 'value' => 7.5]]],
    ];
    $rates = PayrollRates::fromFormulas($active);
    $historical = [...$active, ['key' => 'SIL', 'params' => [
        ['key' => 'max_leave', 'value' => 6], ['key' => 'months', 'value' => 12], ['key' => 'days_per_month', 'value' => 31],
    ]]];

    expect(get_object_vars($rates))->toBe([
        'otRate' => 1.5, 'maxOtHours' => 4.0, 'nsdRate' => 0.2,
        'maxNsdHours' => 6.0, 'restDayRate' => 1.4, 'maxRestDayHours' => 7.5,
    ])->and(PayrollRates::fromFormulas($historical))->toEqual($rates);
});

it('replays payroll containing historical SIL rows without changing any payslip field', function () {
    $employee = (object) ['id' => 'E1', 'monthly_rate' => 22000.0, 'hourly_rate' => 0];
    $logs = DomainLog::listFromArrays([
        ['log_type' => 'TIME_IN', 'logged_at' => '2026-09-01T00:00:00.000Z'],
        ['log_type' => 'TIME_OUT', 'logged_at' => '2026-09-01T10:00:00.000Z'],
    ]);
    $shift = DomainShift::fromArray([
        'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'grace_minutes' => 15, 'break_minutes' => 60,
    ]);
    $active = [['key' => 'OT', 'params' => [['key' => 'percentage', 'value' => 1.5], ['key' => 'max_ot', 'value' => 4]]]];
    $current = PayslipCalculator::compute(
        $employee, $logs, $shift, [], '2026-09-01', '2026-09-15', 0,
        settings: ['formulas' => $active], overtimeApprovals: ['2026-09-01' => 60],
    )->toArray();
    $historical = PayslipCalculator::compute(
        $employee, $logs, $shift, [], '2026-09-01', '2026-09-15', 0,
        settings: ['formulas' => [...$active, ['key' => 'SIL', 'params' => [
            ['key' => 'max_leave', 'value' => 366], ['key' => 'months', 'value' => 1], ['key' => 'days_per_month', 'value' => 1],
        ]]]], overtimeApprovals: ['2026-09-01' => 60],
    )->toArray();

    expect($current['daily_rate'])->toBe(1000.0)
        ->and($current['overtime_pay'])->toBe(187.5)
        ->and($historical)->toBe($current);
});
