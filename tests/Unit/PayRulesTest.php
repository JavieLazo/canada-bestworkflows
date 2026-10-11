<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Payroll\PayRules;
use App\Domain\Payroll\PayslipCalculator;

/**
 * DIVERGENCE 13: Pay Basis & Rates are a version's figures, not constants.
 *
 * The reference prices every day one way. EMS hands the engine a PayRules
 * value: the pay basis, the daily-rate method, the night window, whether
 * overtime hours earn night pay, the holiday overtime factor and a matrix
 * of explicit rates by day type. With the reference's figures (every
 * matrix cell blank, the DAILY basis, the month's working days) the engine
 * performs the reference's exact operations — the golden guard proves it on
 * the whole corpus — and this file pins what each switch changes, in pesos.
 *
 * September 2026 has 22 scheduled weekdays, so a 22,000 monthly rate is a
 * 1,000 day and a 125 hour on an 8-hour shift: every figure below is exact.
 */

/** Monday to Friday, 08:00–17:00 with a 60-minute break at noon: 8 paid hours. */
function payRulesShift(string $start = '08:00:00', string $end = '17:00:00', string $breakStart = '12:00:00', string $breakEnd = '13:00:00'): DomainShift
{
    return DomainShift::fromArray([
        'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => $start, 'end_time' => $end,
        'grace_minutes' => 15, 'break_minutes' => 60, 'break_start' => $breakStart, 'break_end' => $breakEnd,
    ]);
}

/**
 * One day's punches in Manila wall-clock hours (08:00 Manila is 00:00Z).
 *
 * @return list<array<string, mixed>>
 */
function payRulesPunches(string $date, string $in = '00:00', string $out = '09:00', ?string $outDate = null): array
{
    return [
        ['log_type' => 'TIME_IN', 'logged_at' => "{$date}T{$in}:00.000Z"],
        ['log_type' => 'TIME_OUT', 'logged_at' => ($outDate ?? $date)."T{$out}:00.000Z"],
    ];
}

/**
 * The reference's figures with some of them changed.
 *
 * @param  array<string, mixed>  $changes  dotted keys: 'basis', 'daily_rate.method', 'matrix.REGULAR.worked', …
 */
function payRulesWith(array $changes): PayRules
{
    $figures = PayRules::fromFormulas()->toArray();
    foreach ($changes as $path => $value) {
        $ref = &$figures;
        foreach (explode('.', $path) as $key) {
            $ref = &$ref[$key];
        }
        $ref = $value;
        unset($ref);
    }

    return PayRules::fromArray($figures);
}

/**
 * @param  list<array<string, mixed>>  $logs
 * @param  list<array<string, mixed>>  $holidays
 * @param  array<string, int>|null  $approvals
 * @return array<string, mixed>
 */
function payRulesSlip(?PayRules $rules, array $logs, array $holidays = [], ?array $approvals = null, string $start = '2026-09-01', string $end = '2026-09-15', ?DomainShift $shift = null): array
{
    return PayslipCalculator::compute(
        employee: (object) [
            'id' => 'E1', 'monthly_rate' => 22000, 'daily_rate' => 0, 'hourly_rate' => 0,
            'allow_sss' => false, 'allow_philhealth' => false, 'allow_pagibig' => false, 'allow_withholding_tax' => false,
        ],
        logs: DomainLog::listFromArrays($logs),
        shift: $shift ?? payRulesShift(),
        leaves: [],
        periodStart: $start,
        periodEnd: $end,
        bonus: 0.0,
        settings: ['formulas' => [], 'holidays' => HolidaySetting::listFromArrays($holidays)],
        overtimeApprovals: $approvals,
        rules: $rules,
    )->toArray();
}

/** @return array<string, mixed> */
function payRulesHoliday(string $date, string $type = 'REGULAR', float $percent = 200.0, string $name = 'Synthetic holiday'): array
{
    return ['name' => $name, 'date' => $date, 'schedule' => 'ONE_TIME', 'type' => $type, 'percent' => $percent];
}

it('performs the reference path when handed the reference figures as a saved version', function () {
    $logs = payRulesPunches('2026-09-01');
    $reference = payRulesSlip(null, $logs);
    $explicit = payRulesSlip(PayRules::fromArray(PayRules::fromFormulas()->toArray()), $logs);

    expect($reference['work_days'])->toBe(1.0)
        ->and($reference['work_days_pay'])->toBe(1000.0)
        ->and($reference['daily_rate'])->toBe(1000.0)
        ->and($reference['gross_pay'])->toBe(1000.0)
        ->and($explicit)->toBe($reference);
});

it('derives the day from a fixed divisor or a yearly factor instead of the month\'s working days', function () {
    $logs = payRulesPunches('2026-09-01');
    $divisor = payRulesSlip(payRulesWith(['daily_rate.method' => PayRules::METHOD_DIVISOR, 'daily_rate.value' => 20.0]), $logs);
    $factor = payRulesSlip(payRulesWith(['daily_rate.method' => PayRules::METHOD_FACTOR, 'daily_rate.value' => 240.0]), $logs);

    expect($divisor['daily_rate'])->toBe(1100.0)
        ->and($divisor['work_days_pay'])->toBe(1100.0)
        ->and($factor['daily_rate'])->toBe(1100.0)
        ->and($factor['work_days_pay'])->toBe(1100.0);
});

it('pays the salary pro rata under the MONTHLY basis, an absence costing one day at the divisor', function () {
    $scheduled = ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-14', '2026-09-15'];
    $all = array_merge(...array_map(static fn (string $d): array => payRulesPunches($d), $scheduled));
    $oneAbsent = array_merge(...array_map(static fn (string $d): array => payRulesPunches($d), array_slice($scheduled, 0, 10)));
    $monthly = payRulesWith(['basis' => PayRules::BASIS_MONTHLY, 'daily_rate.method' => PayRules::METHOD_DIVISOR, 'daily_rate.value' => 20.0]);

    // 11 × 1,100 plus the residual 22,000 × 11/22 − 11 × 1,100: half the salary.
    expect(payRulesSlip($monthly, $all)['work_days_pay'])->toBe(11000.0)
        ->and(payRulesSlip($monthly, $oneAbsent)['work_days_pay'])->toBe(9900.0)
        // The DAILY basis at the same divisor pays the days as they stand.
        ->and(payRulesSlip(payRulesWith(['daily_rate.method' => PayRules::METHOD_DIVISOR, 'daily_rate.value' => 20.0]), $all)['work_days_pay'])->toBe(12100.0)
        // Under the month's working days the residual is zero and nothing is added.
        ->and(payRulesSlip(payRulesWith(['basis' => PayRules::BASIS_MONTHLY]), $oneAbsent)['work_days_pay'])->toBe(10000.0);
});

it('counts an unworked holiday on a scheduled day as paid under the MONTHLY basis and prices it by its cell under DAILY', function () {
    $logs = payRulesPunches('2026-09-01');
    $holidays = [payRulesHoliday('2026-09-02')];

    $reference = payRulesSlip(null, $logs, $holidays, start: '2026-09-01', end: '2026-09-02');
    $monthly = payRulesSlip(payRulesWith(['basis' => PayRules::BASIS_MONTHLY]), $logs, $holidays, start: '2026-09-01', end: '2026-09-02');
    $daily = payRulesSlip(payRulesWith(['matrix.REGULAR.unworked' => 100.0]), $logs, $holidays, start: '2026-09-01', end: '2026-09-02');

    expect($reference['work_days_pay'])->toBe(1000.0)
        ->and($reference['holiday_pay'])->toBe(0.0)
        ->and($monthly['work_days'])->toBe(1.0)
        ->and($monthly['work_days_pay'])->toBe(2000.0)
        ->and($monthly['holiday_pay'])->toBe(0.0)
        ->and($daily['work_days_pay'])->toBe(1000.0)
        ->and($daily['holiday_pay'])->toBe(1000.0)
        ->and($daily['gross_pay'])->toBe(2000.0);
});

it('pays an unworked holiday only after a worked previous scheduled day when the attendance rule is on', function () {
    $rules = payRulesWith(['matrix.REGULAR.unworked' => 100.0, 'holiday_needs_attendance' => true]);
    $holidays = [payRulesHoliday('2026-09-02')];

    $worked = payRulesSlip($rules, payRulesPunches('2026-09-01'), $holidays, start: '2026-09-01', end: '2026-09-02');
    $absent = payRulesSlip($rules, [], $holidays, start: '2026-09-01', end: '2026-09-02');
    // A holiday on the period's first day has no previous day to check.
    $first = payRulesSlip($rules, [], [payRulesHoliday('2026-09-01')], start: '2026-09-01', end: '2026-09-02');

    expect($worked['holiday_pay'])->toBe(1000.0)
        ->and($absent['holiday_pay'])->toBe(0.0)
        ->and($first['holiday_pay'])->toBe(1000.0);
});

it('prices a worked holiday by its explicit cell instead of the holiday\'s own percent', function () {
    $logs = payRulesPunches('2026-09-02');
    $holidays = [payRulesHoliday('2026-09-02')];

    expect(payRulesSlip(null, $logs, $holidays)['holiday_pay'])->toBe(2000.0)
        ->and(payRulesSlip(payRulesWith(['matrix.REGULAR.worked' => 250.0]), $logs, $holidays)['holiday_pay'])->toBe(2500.0);
});

it('prices two holidays on one date as a double only while the version has a rate for it', function () {
    $logs = payRulesPunches('2026-09-02');
    $holidays = [payRulesHoliday('2026-09-02'), payRulesHoliday('2026-09-02', name: 'Second holiday')];

    expect(payRulesSlip(null, $logs, $holidays)['holiday_pay'])->toBe(2000.0)
        ->and(payRulesSlip(payRulesWith(['matrix.DOUBLE.worked' => 300.0]), $logs, $holidays)['holiday_pay'])->toBe(3000.0);
});

it('prices an overtime hour by the explicit cell, else by the holiday percent times the version\'s holiday overtime factor', function () {
    $logs = payRulesPunches('2026-09-02', '00:00', '11:00');
    $holidays = [payRulesHoliday('2026-09-02')];
    $approvals = ['2026-09-02' => 120];

    expect(payRulesSlip(null, $logs, $holidays, $approvals)['overtime_pay'])->toBe(650.0)
        ->and(payRulesSlip(payRulesWith(['holiday_overtime_factor' => 1.0]), $logs, $holidays, $approvals)['overtime_pay'])->toBe(500.0)
        ->and(payRulesSlip(payRulesWith(['matrix.REGULAR.overtime' => 150.0]), $logs, $holidays, $approvals)['overtime_pay'])->toBe(375.0)
        // An ordinary day's overtime is the overtime rate on the base hourly.
        ->and(payRulesSlip(null, payRulesPunches('2026-09-01', '00:00', '11:00'), [], ['2026-09-01' => 120])['overtime_pay'])->toBe(312.5);
});

it('measures night hours inside the version\'s window', function () {
    // 14:00–23:00 with the break at 17:00–18:00: one scheduled hour inside 22:00–06:00, five inside 18:00–06:00.
    $shift = payRulesShift('14:00:00', '23:00:00', '17:00:00', '18:00:00');
    $logs = payRulesPunches('2026-09-01', '06:00', '15:00');

    expect(payRulesSlip(null, $logs, shift: $shift)['night_differential'])->toBe(12.5)
        ->and(payRulesSlip(payRulesWith(['night.start' => 18 * 60, 'night.end' => 30 * 60]), $logs, shift: $shift)['night_differential'])->toBe(62.5);
});

it('adds the night add-on to overtime hours inside the window only when the version says so', function () {
    // 16:00–01:00 with the break at 20:00–21:00: three scheduled night hours, and
    // out at 03:00 — two approved overtime hours, both inside the window.
    $shift = payRulesShift('16:00:00', '01:00:00', '20:00:00', '21:00:00');
    $logs = payRulesPunches('2026-09-01', '08:00', '19:00');
    $approvals = ['2026-09-01' => 120];

    $reference = payRulesSlip(null, $logs, approvals: $approvals, shift: $shift);
    $onOvertime = payRulesSlip(payRulesWith(['night.on_overtime' => true]), $logs, approvals: $approvals, shift: $shift);

    expect($reference['overtime_pay'])->toBe(312.5)
        ->and($reference['night_differential'])->toBe(37.5)
        ->and($onOvertime['overtime_pay'])->toBe(312.5)
        ->and($onOvertime['night_differential'])->toBe(62.5);
});

it('prices a night hour by the explicit cell as that rate less the worked rate', function () {
    $shift = payRulesShift('14:00:00', '23:00:00', '17:00:00', '18:00:00');
    $logs = payRulesPunches('2026-09-01', '06:00', '15:00');

    // 120 on an ordinary day: a 20% add-on on the base hourly for the one night hour.
    expect(payRulesSlip(payRulesWith(['matrix.ORDINARY.night' => 120.0]), $logs, shift: $shift)['night_differential'])->toBe(25.0);
});

it('reads a version strictly, round-trips it and refuses figures the engine cannot price', function () {
    $figures = PayRules::fromFormulas()->toArray();
    expect(PayRules::fromArray($figures)->toArray())->toBe($figures)
        ->and(PayRules::error($figures))->toBeNull()
        ->and(PayRules::error(PayRules::preset(PayRules::PRESET_STANDARD)))->toBeNull()
        ->and(PayRules::error(PayRules::preset(PayRules::PRESET_GOVERNMENT)))->toBeNull()
        ->and(PayRules::error('nope'))->toBe('The pay rules are missing.')
        ->and(PayRules::error(['basis' => 'WEEKLY'] + $figures))->toBe('Choose the pay basis.')
        ->and(PayRules::error(['daily_rate' => ['method' => PayRules::METHOD_DIVISOR, 'value' => 0.0]] + $figures))->toBe('The divisor must be between 1 and 31 days.')
        ->and(PayRules::error(['daily_rate' => ['method' => PayRules::METHOD_FACTOR, 'value' => 400.0]] + $figures))->toBe('The yearly factor must be between 1 and 366 days.')
        ->and(PayRules::error(['overtime' => ['rate' => 11.0, 'max_hours' => 8.0]] + $figures))->toBe('Overtime: enter a rate between 0% and 1,000%.')
        ->and(PayRules::error(['night' => ['rate' => 0.1, 'max_hours' => 8.0, 'start' => 1320, 'end' => 1300, 'on_overtime' => false]] + $figures))->toBe('Night work: enter when it starts and ends.')
        ->and(PayRules::error(['matrix' => ['REGULAR' => ['worked' => 200.0, 'overtime' => null, 'night' => 150.0, 'unworked' => 0.0]] + $figures['matrix']] + $figures))->toBe('The REGULAR night rate cannot be below its worked rate.')
        ->and(fn () => PayRules::preset('OTHER'))->toThrow(InvalidArgumentException::class);

    $government = PayRules::fromArray(PayRules::preset(PayRules::PRESET_GOVERNMENT));
    expect($government->basis)->toBe(PayRules::BASIS_MONTHLY)
        ->and($government->dailyRateMethod)->toBe(PayRules::METHOD_DIVISOR)
        ->and($government->dailyRateValue)->toBe(22.0)
        ->and($government->nightOnOvertime)->toBeTrue()
        ->and($government->prices('DOUBLE'))->toBeTrue();
    $standard = PayRules::fromArray(PayRules::preset(PayRules::PRESET_STANDARD));
    expect($standard->basis)->toBe(PayRules::BASIS_DAILY)
        ->and($standard->holidayNeedsAttendance)->toBeTrue()
        ->and($standard->matrix['REGULAR']['unworked'])->toBe(100.0)
        ->and($standard->matrix['DOUBLE']['unworked'])->toBe(200.0)
        ->and($standard->prices('DOUBLE'))->toBeFalse();
});

it('names the cells below their usual minimum as the engine would price them', function () {
    $reference = PayRules::fromFormulas();
    $short = array_map(static fn (array $s): string => $s['type'].'.'.$s['cell'].'='.$s['value'].'<'.$s['minimum'], $reference->shortfalls());
    expect($short)->toBe(['REST.overtime=162.5<169', 'REST.night=140<143', 'REGULAR.unworked=0<100', 'DOUBLE.unworked=0<200'])
        ->and($reference->effectivePercent('ORDINARY', 'overtime'))->toBe(125.0)
        ->and($reference->effectivePercent('ORDINARY', 'night'))->toBe(110.0)
        ->and($reference->effectivePercent('REGULAR', 'worked'))->toBeNull();

    // An explicit cell counts as it stands; the MONTHLY basis never pays an unworked cell.
    $fixed = payRulesWith(['matrix.REST.overtime' => 169.0, 'matrix.REST.night' => 143.0, 'matrix.REGULAR.unworked' => 100.0, 'matrix.DOUBLE.unworked' => 200.0]);
    expect($fixed->shortfalls())->toBe([])
        ->and(payRulesWith(['basis' => PayRules::BASIS_MONTHLY])->shortfalls())->toHaveCount(2)
        ->and(payRulesWith(['matrix.SPECIAL.worked' => 120.0])->shortfalls()[0])->toBe(['type' => 'REST', 'cell' => 'overtime', 'value' => 162.5, 'minimum' => 169.0])
        ->and(array_column(payRulesWith(['matrix.SPECIAL.worked' => 120.0])->shortfalls(), 'type'))->toContain('SPECIAL');
});
