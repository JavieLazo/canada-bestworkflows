<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\LeaveRequest as DomainLeave;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Payroll\PayslipCalculator;

/**
 * DIVERGENCE 6: approved paid leave is its own payslip line, and the
 * reclassification does not move a single peso.
 *
 * The reference engine folds an approved paid-leave day into `workedDays`
 * (payroll.ts:492), so it is paid as work-day pay and the Leave column means
 * something else entirely. EMS has a leave module that already records whether
 * an absence is with pay or without pay, so `leave_pay` is computed from it and
 * `work_days` counts only days the employee punched.
 *
 * THE INVARIANT THIS FILE EXISTS TO HOLD: `work_days_pay + leave_pay` is always
 * exactly the single rounded figure payroll.ts:497 produced, because the leave
 * line is CARVED OUT of that total rather than priced independently. Rounding
 * the two buckets separately moves `gross_pay` by a centavo on roughly one
 * payslip in five — the half-centavo case below is one of them.
 *
 * What is deliberately NOT changed is pinned here too: leave on an unpunched
 * rest day, leave the employee worked anyway, and official business.
 */
const LEAVE_PAY_WEEK = [1, 2, 3, 4, 5];

/**
 * @param  list<array<string, mixed>>  $logs
 * @param  list<array<string, mixed>>  $leaves
 * @param  array<string, mixed>|null  $earningsOverride
 * @param  list<array<string, mixed>>  $holidays
 * @param  list<int>|null  $week
 * @return array<string, mixed>
 */
function leavePaySlip(
    string $start,
    string $end,
    array $logs = [],
    array $leaves = [],
    float $monthly = 30000.0,
    ?array $earningsOverride = null,
    array $holidays = [],
    ?array $week = null,
): array {
    return PayslipCalculator::compute(
        employee: (object) ['id' => 'E1', 'monthly_rate' => $monthly, 'hourly_rate' => 0],
        logs: DomainLog::listFromArrays($logs),
        shift: DomainShift::fromArray([
            'mode' => 'FIXED', 'work_days' => $week ?? LEAVE_PAY_WEEK,
            'start_time' => '08:00:00', 'end_time' => '17:00:00',
            'grace_minutes' => 15, 'break_minutes' => 60,
        ]),
        leaves: DomainLeave::listFromArrays($leaves),
        periodStart: $start,
        periodEnd: $end,
        bonus: 0.0,
        earningsOverride: $earningsOverride,
        settings: ['holidays' => HolidaySetting::listFromArrays($holidays)],
    )->toArray();
}

/**
 * A full 08:00-17:00 Manila day. The offsets are explicit: the stored instants
 * are UTC, so 00:00Z is 08:00 in Manila.
 *
 * @return list<array<string, mixed>>
 */
function leavePayDay(string $date): array
{
    return [
        ['log_type' => 'TIME_IN', 'logged_at' => $date.'T00:00:00.000Z'],
        ['log_type' => 'TIME_OUT', 'logged_at' => $date.'T09:00:00.000Z'],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function leavePayApproved(string $start, string $end, ?bool $paid = true, ?string $dayType = null): array
{
    return [[
        'request_type' => 'LEAVE', 'status' => 'APPROVED', 'is_paid' => $paid,
        'day_type' => $dayType ?? 'WHOLE_DAY', 'start_date' => $start, 'end_date' => $end,
    ]];
}

it('pays a whole day of approved leave on the Leave line and not as a work day', function () {
    // Sept 2026 has 22 Mon-Fri days, so daily is 30000/22 = 1363.6363...
    // Sept 1-15 holds 11 of them: ten punched, one taken as leave.
    $logs = [];
    foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-07',
        '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-14'] as $d) {
        $logs = array_merge($logs, leavePayDay($d));
    }

    $slip = leavePaySlip('2026-09-01', '2026-09-15', $logs, leavePayApproved('2026-09-15', '2026-09-15'));

    expect($slip['work_days'])->toBe(10.0)
        ->and($slip['paid_leave_days'])->toBe(1.0)
        ->and($slip['leave_pay'])->toBe(1363.64)
        ->and($slip['work_days_pay'])->toBe(13636.36)
        ->and($slip['basic_pay'])->toBe(13636.36)
        // The whole point: 11 days at 30000/22 is 15000.00 either way.
        ->and($slip['work_days_pay'] + $slip['leave_pay'])->toBe(15000.0)
        ->and($slip['gross_pay'])->toBe(15000.0);
});

it('carves the leave line out of the rounded total rather than rounding it twice', function () {
    // February 2026 has 20 Mon-Fri days. 20000.12 / 20 is exactly 1000.006, so
    // four and a half days price to 4500.027 — a half-centavo boundary.
    //
    // Rounding each bucket on its own would give 4000.02 + 500.00 = 4500.02.
    // Carving gives 4000.03 + 500.00 = 4500.03, which is what the reference
    // produced for the same 4.5 days and what this engine must keep producing.
    $logs = [];
    foreach (['2026-02-02', '2026-02-03', '2026-02-04', '2026-02-05'] as $d) {
        $logs = array_merge($logs, leavePayDay($d));
    }

    $slip = leavePaySlip(
        '2026-02-02', '2026-02-06', $logs,
        leavePayApproved('2026-02-06', '2026-02-06', true, 'HALF_DAY'),
        20000.12,
    );

    expect($slip['work_days'])->toBe(4.0)
        ->and($slip['paid_leave_days'])->toBe(0.5)
        ->and($slip['leave_pay'])->toBe(500.0)
        ->and($slip['work_days_pay'])->toBe(4000.03)
        ->and($slip['gross_pay'])->toBe(4500.03);
});

it('prices each leave day at its own month rate across a period boundary', function () {
    // Aug 2026 has 21 Mon-Fri days, Sept has 22. Mon 31 Aug and Tue 1 Sept are
    // both scheduled days, so the two leave days are priced 30000/21 and
    // 30000/22 and summed BEFORE the single round: 1428.5714 + 1363.6363.
    $slip = leavePaySlip('2026-08-31', '2026-09-01', [], leavePayApproved('2026-08-31', '2026-09-01'));

    expect($slip['paid_leave_days'])->toBe(2.0)
        ->and($slip['work_days'])->toBe(0.0)
        ->and($slip['leave_pay'])->toBe(2792.21)
        ->and($slip['work_days_pay'])->toBe(0.0)
        ->and($slip['gross_pay'])->toBe(2792.21);
});

it('pays a whole work day and no leave when the employee worked through the leave', function () {
    // DtrCalculator::532 — someone on whole-day leave who came in anyway is a
    // worked day carrying excuseDays 0. The two can never stack.
    $slip = leavePaySlip(
        '2026-09-01', '2026-09-01', leavePayDay('2026-09-01'),
        leavePayApproved('2026-09-01', '2026-09-01'),
    );

    expect($slip['work_days'])->toBe(1.0)
        ->and($slip['paid_leave_days'])->toBe(0.0)
        ->and($slip['leave_pay'])->toBe(0.0)
        ->and($slip['work_days_pay'])->toBe(1363.64);
});

it('pays nothing for leave on an unpunched rest day', function () {
    // Saturday 5 Sept 2026 is not in the Mon-Fri week, so the day is a rest
    // day and the leave neither pays nor deducts. UNCHANGED by divergence 6.
    $slip = leavePaySlip('2026-09-05', '2026-09-05', [], leavePayApproved('2026-09-05', '2026-09-05'));

    expect($slip['paid_leave_days'])->toBe(0.0)
        ->and($slip['leave_pay'])->toBe(0.0)
        ->and($slip['unpaid_leave_days'])->toBe(0.0)
        ->and($slip['gross_pay'])->toBe(0.0);
});

it('pays nothing for leave that falls on a declared holiday', function () {
    // HolidaySetting::on makes the day a rest day at DtrCalculator::249, so an
    // unpunched holiday pays nothing whether or not leave was filed. This is a
    // SEPARATE pre-existing decision, deliberately untouched here.
    $slip = leavePaySlip(
        '2026-09-01', '2026-09-01', [], leavePayApproved('2026-09-01', '2026-09-01'),
        30000.0, null,
        [['name' => 'Test Holiday', 'date' => '2026-09-01', 'schedule' => 'ONE_TIME', 'percent' => 200]],
    );

    expect($slip['paid_leave_days'])->toBe(0.0)
        ->and($slip['leave_pay'])->toBe(0.0)
        ->and($slip['gross_pay'])->toBe(0.0);
});

it('counts only the leave days inside the period', function () {
    // The leave runs Fri 11 Sept to Mon 14 Sept, but the period ends on the
    // 11th. Only that one day is priced.
    $slip = leavePaySlip('2026-09-10', '2026-09-11', [], leavePayApproved('2026-09-11', '2026-09-14'));

    expect($slip['paid_leave_days'])->toBe(1.0)
        ->and($slip['leave_pay'])->toBe(1363.64);
});

it('skips the weekend inside a leave that spans it', function () {
    // Fri 11 Sept to Mon 14 Sept is four calendar days but only two scheduled
    // ones: the Saturday and Sunday are rest days and pay nothing.
    $slip = leavePaySlip('2026-09-11', '2026-09-14', [], leavePayApproved('2026-09-11', '2026-09-14'));

    expect($slip['paid_leave_days'])->toBe(2.0)
        ->and($slip['leave_pay'])->toBe(2727.27);
});

it('leaves unpaid leave out of the Leave line entirely', function () {
    $slip = leavePaySlip(
        '2026-09-01', '2026-09-01', [],
        leavePayApproved('2026-09-01', '2026-09-01', false),
    );

    expect($slip['paid_leave_days'])->toBe(0.0)
        ->and($slip['leave_pay'])->toBe(0.0)
        ->and($slip['unpaid_leave_days'])->toBe(1.0)
        ->and($slip['gross_pay'])->toBe(0.0);
});

it('reads an approved leave with no is_paid flag as paid', function () {
    // DtrCalculator::527 — `is_paid ?? true`. The application cannot create
    // such a row (ReviewAbsence forces the choice), but imported data can.
    $slip = leavePaySlip(
        '2026-09-01', '2026-09-01', [],
        leavePayApproved('2026-09-01', '2026-09-01', null),
    );

    expect($slip['paid_leave_days'])->toBe(1.0)
        ->and($slip['leave_pay'])->toBe(1363.64);
});

it('never treats approved business travel as paid leave', function () {
    // OB is time worked elsewhere: credited the shift's hours, never the
    // Leave line, even carrying is_paid true.
    $slip = leavePaySlip('2026-09-01', '2026-09-01', [], [[
        'request_type' => 'BUSINESS_TRAVEL', 'status' => 'APPROVED', 'is_paid' => true,
        'day_type' => 'WHOLE_DAY', 'start_date' => '2026-09-01', 'end_date' => '2026-09-01',
    ]]);

    expect($slip['paid_leave_days'])->toBe(0.0)
        ->and($slip['leave_pay'])->toBe(0.0);
});

it('ignores a leave that is not approved', function () {
    $slip = leavePaySlip('2026-09-01', '2026-09-01', [], [[
        'request_type' => 'LEAVE', 'status' => 'PENDING', 'is_paid' => true,
        'day_type' => 'WHOLE_DAY', 'start_date' => '2026-09-01', 'end_date' => '2026-09-01',
    ]]);

    expect($slip['paid_leave_days'])->toBe(0.0)
        ->and($slip['leave_pay'])->toBe(0.0)
        ->and($slip['absence_days'])->toBe(1.0);
});

it('lets a typed Leave figure override the computed one', function () {
    // The night_differential precedent. The residual work_days_pay still uses
    // the COMPUTED figure, so only the Leave line and gross move.
    $slip = leavePaySlip(
        '2026-09-01', '2026-09-01', [], leavePayApproved('2026-09-01', '2026-09-01'),
        30000.0, ['leave_pay' => 500.0],
    );

    expect($slip['leave_pay'])->toBe(500.0)
        ->and($slip['paid_leave_days'])->toBe(1.0)
        ->and($slip['work_days_pay'])->toBe(0.0)
        ->and($slip['gross_pay'])->toBe(500.0);
});

it('keeps the computed Leave figure when no override was typed', function () {
    // An earnings override that carries OTHER keys must not blank the leave.
    $slip = leavePaySlip(
        '2026-09-01', '2026-09-01', [], leavePayApproved('2026-09-01', '2026-09-01'),
        30000.0, ['other_earnings' => 250.0],
    );

    expect($slip['leave_pay'])->toBe(1363.64)
        ->and($slip['other_earnings'])->toBe(250.0)
        ->and($slip['gross_pay'])->toBe(1613.64);
});

it('treats a typed zero as an override that pays no leave', function () {
    // A STORED zero is an override, and it wins. This is exactly why an
    // emptied grid cell must clear the key instead of saving 0.0 —
    // RunWorkspace::updateEarning.
    $slip = leavePaySlip(
        '2026-09-01', '2026-09-01', [], leavePayApproved('2026-09-01', '2026-09-01'),
        30000.0, ['leave_pay' => 0.0],
    );

    expect($slip['leave_pay'])->toBe(0.0)
        ->and($slip['paid_leave_days'])->toBe(1.0)
        ->and($slip['gross_pay'])->toBe(0.0);
});
