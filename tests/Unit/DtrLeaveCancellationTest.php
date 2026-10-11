<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\LeaveRequest as DomainLeave;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Payroll\PayslipCalculator;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;

/** Selected cancellations remove only those dates' excuses and pay, preserving every remaining approved date. */

/** @param list<string> $cancelledDates */
function cancelledDtrLeave(array $cancelledDates, bool $paid = true, string $dayType = 'WHOLE_DAY'): DomainLeave
{
    return DomainLeave::fromArray([
        'request_type' => 'VACATION_LEAVE', 'status' => 'APPROVED', 'is_paid' => $paid,
        'day_type' => $dayType, 'start_date' => '2026-10-12', 'end_date' => '2026-10-14',
        'cancelled_dates' => $cancelledDates,
    ]);
}

function cancelledDtrShift(): DomainShift
{
    return DomainShift::fromArray([
        'work_days' => [1, 2, 3, 4, 5], 'mode' => 'FIXED',
        'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'break_minutes' => 60, 'grace_minutes' => 0,
    ]);
}

it('returns only cancelled calendar-day credits in whole and half-day steps', function () {
    $cancelled = ['2027-01-01', '2027-01-01', '2027-01-05'];
    expect(LeaveCreditPolicy::coveredDays('WHOLE_DAY', '2026-12-31', '2027-01-02', $cancelled))->toBe(2.0)
        ->and(LeaveCreditPolicy::coveredDays('HALF_DAY', '2026-12-31', '2027-01-02', $cancelled))->toBe(1.0)
        ->and(LeaveCreditPolicy::yearOf('2026-12-31'))->toBe(2026)
        ->and(LeaveCreditPolicy::activeDays('2026-12-31', '2027-01-02', $cancelled))->toBe(['2026-12-31', '2027-01-02'])
        ->and(LeaveCreditPolicy::hasActiveDate('2027-01-01', '2027-01-01', $cancelled))->toBeFalse()
        ->and(LeaveCreditPolicy::hasActiveDate('2027-01-01', '9999-12-31', $cancelled))->toBeTrue();
});

it('removes one selected leave day from attendance and regular daily-rate pay', function (bool $paid, string $dayType, float $remainingDays, float $leavePay) {
    $leave = cancelledDtrLeave(['2026-10-13'], $paid, $dayType);
    $days = DtrCalculator::compute([], cancelledDtrShift(), [$leave], '2026-10-12', '2026-10-14');
    $slip = PayslipCalculator::compute(
        employee: (object) ['id' => 'CANCELLED-LEAVE', 'monthly_rate' => 0, 'daily_rate' => 1000, 'hourly_rate' => 0],
        logs: [], shift: cancelledDtrShift(), leaves: [$leave], periodStart: '2026-10-12', periodEnd: '2026-10-14', bonus: 0,
    );

    expect(array_map(static fn ($day): string => $day->status, $days))->toBe(['EXCUSED', 'ABSENT', 'EXCUSED'])
        ->and($days[1]->excused)->toBeFalse()
        ->and($days[1]->excuseType)->toBeNull()
        ->and($slip->paid_leave_days)->toBe($paid ? $remainingDays : 0.0)
        ->and($slip->unpaid_leave_days)->toBe($paid ? 0.0 : $remainingDays)
        ->and($slip->leave_pay)->toBe($leavePay);
})->with([
    'whole paid' => [true, 'WHOLE_DAY', 2.0, 2000.0],
    'half paid' => [true, 'HALF_DAY', 1.0, 1000.0],
    'whole unpaid' => [false, 'WHOLE_DAY', 2.0, 0.0],
]);

it('restores ordinary late and undertime calculations on a cancelled half-day', function () {
    $logs = DomainLog::listFromArrays([
        ['log_type' => 'TIME_IN', 'logged_at' => '2026-10-13T13:00:00+08:00'],
        ['log_type' => 'TIME_OUT', 'logged_at' => '2026-10-13T17:00:00+08:00'],
    ]);
    $approved = DtrCalculator::compute($logs, cancelledDtrShift(), [cancelledDtrLeave([], dayType: 'HALF_DAY')], '2026-10-13', '2026-10-13')[0];
    $cancelled = DtrCalculator::compute($logs, cancelledDtrShift(), [cancelledDtrLeave(['2026-10-13'], dayType: 'HALF_DAY')], '2026-10-13', '2026-10-13')[0];
    $ordinary = DtrCalculator::compute($logs, cancelledDtrShift(), [], '2026-10-13', '2026-10-13')[0];

    expect($approved->excuseDays)->toBe(0.5)
        ->and($cancelled)->toEqual($ordinary)
        ->and($cancelled->excuseDays)->toBe(0.0)
        ->and($cancelled->lateMinutes)->toBeGreaterThan($approved->lateMinutes);
});

it('keeps legacy leave input without cancellations effective over its entire approved range', function () {
    $row = ['request_type' => 'SICK_LEAVE', 'status' => 'APPROVED', 'start_date' => '2026-10-12', 'end_date' => '2026-10-14'];
    $legacy = DomainLeave::fromArray($row);
    expect($legacy->cancelledDates)->toBe([])
        ->and(DtrCalculator::compute([], cancelledDtrShift(), [$legacy], '2026-10-12', '2026-10-14'))
        ->toEqual(DtrCalculator::compute([], cancelledDtrShift(), [DomainLeave::fromArray($row + ['cancelled_dates' => []])], '2026-10-12', '2026-10-14'));
});
