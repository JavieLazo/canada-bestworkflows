<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Domain\Credits\AttendanceChargePlan;
use App\Modules\Timekeeping\Domain\Credits\MinuteConversion;

/**
 * What a payroll charges to leave credits (AttendanceChargePlan): absences
 * first in date order, then lateness and undertime by the conversion table,
 * each taken from the policy's leave types in order down to each type's
 * floor, with what no type covers left for pay. Nothing is charged under a
 * policy that charges to pay, without a table for lateness, or on a day an
 * earlier payslip already charged.
 */

/**
 * @param  list<string>  $late
 * @param  list<string>  $absence
 * @return array{enabled: bool, combine: string, late: list<string>, absence: list<string>}
 */
function acpCharges(string $combine = 'DAY', array $late = ['VACATION_LEAVE'], array $absence = ['VACATION_LEAVE', 'SICK_LEAVE'], bool $enabled = true): array
{
    return ['enabled' => $enabled, 'combine' => $combine, 'late' => $late, 'absence' => $absence];
}

/** @return array{rows: array{minutes: array<int|string, string|float>, hours: array<int|string, string|float>}, hours_per_day: float, decimals: int} */
function acpConversion(): array
{
    return ['rows' => MinuteConversion::rows(8, 3, 'HALF_DOWN'), 'hours_per_day' => 8.0, 'decimals' => 3];
}

/** @return array<string, array{available: float, below_zero_limit: float}> */
function acpBalances(float $vacation = 2.0, float $sick = 1.0, float $sickFloor = 0.0): array
{
    return ['VACATION_LEAVE' => ['available' => $vacation, 'below_zero_limit' => 0.0], 'SICK_LEAVE' => ['available' => $sick, 'below_zero_limit' => $sickFloor]];
}

/** @return array{date: string, late_minutes: float, undertime_minutes: float, absence_days: float} */
function acpDay(string $date, float $late = 0.0, float $undertime = 0.0, float $absent = 0.0): array
{
    return ['date' => $date, 'late_minutes' => $late, 'undertime_minutes' => $undertime, 'absence_days' => $absent];
}

it('charges absences in date order from the types in order, then leaves the rest for pay', function () {
    $plan = AttendanceChargePlan::plan(acpCharges(), acpConversion(), acpBalances(2.0, 1.0), [
        acpDay('2026-11-04', absent: 1.0), acpDay('2026-11-02', absent: 1.0), acpDay('2026-11-06', absent: 1.0), acpDay('2026-11-09', absent: 1.0),
    ]);
    $lines = $plan['lines'];
    expect(array_column($lines, 'date'))->toBe(['2026-11-02', '2026-11-04', '2026-11-06', '2026-11-09'])
        ->and($lines[0]['covered'])->toBe(['VACATION_LEAVE' => 1.0])->and($lines[0]['uncovered_days'])->toBe(0.0)->and($lines[0]['fraction'])->toBe(1.0)
        ->and($lines[1]['covered'])->toBe(['VACATION_LEAVE' => 1.0])
        ->and($lines[2]['covered'])->toBe(['SICK_LEAVE' => 1.0])
        ->and($lines[3]['covered'])->toBe([])->and($lines[3]['uncovered_days'])->toBe(1.0)->and($lines[3]['fraction'])->toBe(0.0)
        ->and($plan['balances'])->toBe(['VACATION_LEAVE' => 0.0, 'SICK_LEAVE' => 0.0]);
});

it('splits one day across two types and lets a type go below zero only to its floor', function () {
    $plan = AttendanceChargePlan::plan(acpCharges(), acpConversion(), acpBalances(0.25, 0.0, sickFloor: 0.5), [acpDay('2026-11-02', absent: 1.0)]);
    expect($plan['lines'][0]['covered'])->toBe(['VACATION_LEAVE' => 0.25, 'SICK_LEAVE' => 0.5])
        ->and($plan['lines'][0]['uncovered_days'])->toBe(0.25)
        ->and($plan['lines'][0]['fraction'])->toBe(0.75)
        ->and($plan['balances'])->toBe(['VACATION_LEAVE' => 0.0, 'SICK_LEAVE' => -0.5]);
});

it('converts each late day on its own by the table, lateness and undertime together', function () {
    // 45 minutes late + 15 minutes undertime = 60 minutes = 0.125 of an 8-hour day; 30 minutes = 0.062 (half down).
    $plan = AttendanceChargePlan::plan(acpCharges(), acpConversion(), acpBalances(0.15, 5.0), [acpDay('2026-11-03', late: 45.0, undertime: 15.0), acpDay('2026-11-05', late: 30.0)]);
    $lines = $plan['lines'];
    expect($lines[0])->toMatchArray(['date' => '2026-11-03', 'kind' => 'LATE', 'minutes' => 60, 'days' => 0.125, 'covered' => ['VACATION_LEAVE' => 0.125], 'uncovered_days' => 0.0, 'fraction' => 1.0])
        // Only 0.025 of Vacation Leave is left, and Sick Leave is not charged for lateness.
        ->and($lines[1])->toMatchArray(['date' => '2026-11-05', 'minutes' => 30, 'days' => 0.062, 'covered' => ['VACATION_LEAVE' => 0.025], 'uncovered_days' => 0.037])
        ->and($lines[1]['fraction'])->toBe(0.4032);
});

it('pools the payroll\'s minutes under PAYROLL, converts once and spreads the covered part over the days', function () {
    // 40 + 20 = 60 minutes = 0.125 days; Vacation Leave covers 0.1 of it (80%).
    $plan = AttendanceChargePlan::plan(acpCharges('PAYROLL'), acpConversion(), acpBalances(0.1, 5.0), [acpDay('2026-11-03', late: 40.0), acpDay('2026-11-05', late: 20.0)]);
    $lines = $plan['lines'];
    expect($lines[0]['covered'])->toBe(['VACATION_LEAVE' => 0.1])->and($lines[1]['covered'])->toBe([])
        ->and($lines[0]['fraction'])->toBe(0.8)->and($lines[1]['fraction'])->toBe(0.8)
        ->and($lines[0]['days'])->toBe(0.0833)->and($lines[1]['days'])->toBe(0.0417)
        ->and($lines[0]['uncovered_days'])->toBe(0.0167)->and($lines[1]['uncovered_days'])->toBe(0.0083);
});

it('charges nothing under a policy that charges to pay, says so without a conversion table, and skips days already charged', function () {
    $days = [acpDay('2026-11-03', late: 45.0), acpDay('2026-11-04', absent: 1.0)];
    expect(AttendanceChargePlan::plan(acpCharges(enabled: false), acpConversion(), acpBalances(), $days)['lines'])->toBe([]);

    $noTable = AttendanceChargePlan::plan(acpCharges(), null, acpBalances(), $days);
    expect(array_column($noTable['lines'], 'kind'))->toBe(['ABSENCE'])
        ->and($noTable['notes'])->toHaveCount(1)->and($noTable['notes'][0])->toContain('no minutes-to-days conversion table');

    $charged = AttendanceChargePlan::plan(acpCharges(), acpConversion(), acpBalances(), $days, ['late' => ['2026-11-03'], 'absence' => ['2026-11-04']]);
    expect($charged['lines'])->toBe([]);

    // A type the policy names but the balances do not know is skipped, never invented.
    $unknown = AttendanceChargePlan::plan(acpCharges(absence: ['SPECIAL', 'SICK_LEAVE']), acpConversion(), acpBalances(2.0, 1.0), [acpDay('2026-11-04', absent: 1.0)]);
    expect($unknown['lines'][0]['covered'])->toBe(['SICK_LEAVE' => 1.0]);
});
