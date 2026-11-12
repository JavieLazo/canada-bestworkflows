<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Payroll\PayRules;
use App\Domain\Payroll\PayRulesTimeline;
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
 * @param  array<string, string>|null  $categories  date => PayRules::OVERTIME_SEAPORT
 * @return array<string, mixed>
 */
function payRulesSlip(?PayRules $rules, array $logs, array $holidays = [], ?array $approvals = null, string $start = '2026-09-01', string $end = '2026-09-15', ?DomainShift $shift = null, ?array $categories = null, ?PayRulesTimeline $timeline = null): array
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
        overtimeCategories: $categories,
        timeline: $timeline,
    )->toArray();
}

/** The scheduled working days of 1–15 September 2026, Monday to Friday. */
const PAY_RULES_SCHEDULED = ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-14', '2026-09-15'];

/**
 * Two versions: the first from the period's start, the second from a date.
 *
 * @param  array<string, mixed>  $first  dotted changes to the reference figures
 * @param  array<string, mixed>  $second
 */
function payRulesSplit(array $first, string $from, array $second): PayRulesTimeline
{
    return PayRulesTimeline::of([
        ['from' => '2026-09-01', 'rules' => payRulesWith($first)],
        ['from' => $from, 'rules' => payRulesWith($second)],
    ]);
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

/*
|--------------------------------------------------------------------------
| Divergence 16: hours for the hourly rate, rounding per step, seaport
|--------------------------------------------------------------------------
*/

it('divides the day by the version\'s fixed hours instead of the shift\'s paid hours', function () {
    // 08:00–15:00 with the break at noon: six paid hours, so the reference hour is 1,000 ÷ 6.
    $shift = payRulesShift('08:00:00', '15:00:00');
    $logs = payRulesPunches('2026-09-01', '00:00', '09:00');
    $approvals = ['2026-09-01' => 120];

    $reference = payRulesSlip(null, $logs, approvals: $approvals, shift: $shift);
    $eight = payRulesSlip(payRulesWith(['hourly_rate.hours' => 8.0]), $logs, approvals: $approvals, shift: $shift);
    expect($reference['overtime_pay'])->toBe(416.67)
        ->and($eight['overtime_pay'])->toBe(312.5)
        // A stored hourly override still wins over the derivation.
        ->and(PayRules::fromFormulas()->hourlyHours)->toBeNull();
});

it('rounds the day and the hour to the version\'s decimals and each premium amount once to centavos', function () {
    $logs = payRulesPunches('2026-09-01', '00:00', '11:00');
    $approvals = ['2026-09-01' => 120];
    $divisor = ['daily_rate.method' => PayRules::METHOD_DIVISOR, 'daily_rate.value' => 26.1669];

    // 22,000 ÷ 26.1669 = 840.7568…: unrounded, the overtime hour is 105.0946 × 125%.
    $unrounded = payRulesSlip(payRulesWith($divisor), $logs, approvals: $approvals);
    $centavos = payRulesSlip(payRulesWith($divisor + ['rounding.decimals' => 2]), $logs, approvals: $approvals);
    $pesos = payRulesSlip(payRulesWith($divisor + ['rounding.decimals' => 0]), $logs, approvals: $approvals);

    expect($unrounded['daily_rate'])->toBe(840.76)
        ->and($unrounded['overtime_pay'])->toBe(262.74)
        // ROUND(22,000 ÷ 26.1669, 2) = 840.76; ROUND(840.76 ÷ 8, 2) = 105.10; ROUND(2 × 105.10 × 1.25, 2) = 262.75.
        ->and($centavos['daily_rate'])->toBe(840.76)
        ->and($centavos['work_days_pay'])->toBe(840.76)
        ->and($centavos['overtime_pay'])->toBe(262.75)
        // Whole-peso rates: 841 a day, 105 an hour (105.125 rounds down); the amount is still centavos, 2 × 105 × 1.25.
        ->and($pesos['daily_rate'])->toBe(841.0)
        ->and($pesos['work_days_pay'])->toBe(841.0)
        ->and($pesos['overtime_pay'])->toBe(262.5)
        // The cash-out's daily rate rounds the same way.
        ->and(PayslipCalculator::dailyRateOn((object) ['monthly_rate' => 22000, 'daily_rate' => 0], '2026-09-01', payRulesShift(), payRulesWith($divisor + ['rounding.decimals' => 2])))->toBe(840.76)
        ->and(PayslipCalculator::dailyRateOn((object) ['monthly_rate' => 22000, 'daily_rate' => 0], '2026-09-01', payRulesShift(), payRulesWith($divisor)))->toBe(22000 / 26.1669);
});

it('prices each premium as a payroll register does: the hours at one rate added up, then one rounding', function () {
    // 32,000 ÷ 22 ÷ 8 is the register's 181.82 an hour; 42,000 ÷ 26.1669 ÷ 8 its 200.64.
    $register = fn (float $divisor, array $more = []): PayRules => payRulesWith(['daily_rate.method' => PayRules::METHOD_DIVISOR, 'daily_rate.value' => $divisor, 'hourly_rate.hours' => 8.0, 'rounding.decimals' => 2, 'overtime.max_hours' => 12.0] + $more);
    $slip = function (PayRules $rules, float $monthly, array $outs, array $approvals, ?array $categories = null): array {
        $logs = [];
        foreach ($outs as $date => $out) {
            array_push($logs, ...payRulesPunches($date, '00:00', $out));
        }

        return PayslipCalculator::compute(
            employee: (object) ['id' => 'E1', 'monthly_rate' => $monthly, 'daily_rate' => 0, 'hourly_rate' => 0, 'allow_sss' => false, 'allow_philhealth' => false, 'allow_pagibig' => false, 'allow_withholding_tax' => false],
            logs: DomainLog::listFromArrays($logs), shift: payRulesShift(), leaves: [], periodStart: '2026-09-01', periodEnd: '2026-09-15', bonus: 0.0,
            settings: ['formulas' => [], 'holidays' => []], overtimeApprovals: $approvals, rules: $rules, overtimeCategories: $categories,
        )->toArray();
    };

    // 2 × 181.82 × 1.25 = 454.55. Rounding 227.275 first would give 454.54 or 454.56.
    expect($slip($register(22.0), 32000, ['2026-09-01' => '11:00'], ['2026-09-01' => 120])['overtime_pay'])->toBe(454.55)
        // An exact half-centavo rounds up, as ROUND does: 3 × 181.82 × 1.25 = 681.825.
        ->and($slip($register(22.0), 32000, ['2026-09-01' => '12:00'], ['2026-09-01' => 180])['overtime_pay'])->toBe(681.83)
        // Rounded once over the cut-off, not day by day: 1 + 1 + 1 hours make the same 681.83, not 3 × 227.28.
        ->and($slip($register(22.0), 32000, ['2026-09-01' => '10:00', '2026-09-02' => '10:00', '2026-09-03' => '10:00'], ['2026-09-01' => 60, '2026-09-02' => 60, '2026-09-03' => 60])['overtime_pay'])->toBe(681.83);

    // Each rate is its own bucket: 3 × 200.64 × 1.25 = 752.40 and 2 × 200.64 × 1.5 = 601.92.
    $seaport = $slip($register(26.1669, ['overtime.seaport_rate' => 1.5]), 42000, ['2026-09-01' => '12:00', '2026-09-02' => '11:00'], ['2026-09-01' => 180, '2026-09-02' => 120], ['2026-09-02' => PayRules::OVERTIME_SEAPORT]);
    expect($seaport['overtime_pay'])->toBe(1354.32);

    // Night hours on overtime are one bucket at 20%: 8 × 200.64 × 0.2 = 321.02 and 4 × 181.82 × 0.2 = 145.46.
    $night = ['night.rate' => 0.2, 'night.start' => 17 * 60, 'night.end' => 30 * 60, 'night.on_overtime' => true];
    $four = ['2026-09-01' => '11:00', '2026-09-02' => '11:00', '2026-09-03' => '11:00', '2026-09-04' => '11:00'];
    $eight = $slip($register(26.1669, $night), 42000, $four, array_fill_keys(array_keys($four), 120));
    $two = array_slice($four, 0, 2, true);
    expect($eight['night_differential'])->toBe(321.02)
        ->and($slip($register(22.0, $night), 32000, $two, array_fill_keys(array_keys($two), 120))['night_differential'])->toBe(145.46);
});

it('never caps a seaport overtime day and prices it at the seaport rate when the version has one', function () {
    $logs = payRulesPunches('2026-09-01', '00:00', '11:00');
    $approvals = ['2026-09-01' => 120];
    $seaport = ['2026-09-01' => PayRules::OVERTIME_SEAPORT];
    $capped = ['overtime.max_hours' => 1.0];

    // Ordinary: the cap leaves one hour at 125 × 125%.
    expect(payRulesSlip(payRulesWith($capped), $logs, approvals: $approvals)['overtime_pay'])->toBe(156.25)
        // Seaport with no seaport rate: both hours, at the day's own rate.
        ->and(payRulesSlip(payRulesWith($capped), $logs, approvals: $approvals, categories: $seaport)['overtime_pay'])->toBe(312.5)
        // Seaport at 150%: both hours at 125 × 150%, whatever the day type.
        ->and(payRulesSlip(payRulesWith($capped + ['overtime.seaport_rate' => 1.5]), $logs, approvals: $approvals, categories: $seaport)['overtime_pay'])->toBe(375.0)
        ->and(payRulesSlip(payRulesWith($capped + ['overtime.seaport_rate' => 1.5]), $logs, [payRulesHoliday('2026-09-01')], $approvals, categories: $seaport)['overtime_pay'])->toBe(375.0)
        // A category on a day with no approval still pays nothing under per-day approval.
        ->and(payRulesSlip(payRulesWith($capped + ['overtime.seaport_rate' => 1.5]), $logs, approvals: [], categories: $seaport)['overtime_pay'])->toBe(0.0)
        // An empty map is the reference path.
        ->and(payRulesSlip(null, $logs, approvals: $approvals, categories: []))->toBe(payRulesSlip(null, $logs, approvals: $approvals));
});

it('reads the three figures leniently when absent and refuses them out of bounds', function () {
    $figures = PayRules::fromFormulas()->toArray();
    expect($figures['hourly_rate'])->toBe(['hours' => null])
        ->and($figures['rounding'])->toBe(['decimals' => null])
        ->and($figures['overtime']['seaport_rate'])->toBeNull();

    // A version captured before the figures existed has no key: blank.
    $older = $figures;
    unset($older['hourly_rate'], $older['rounding'], $older['overtime']['seaport_rate']);
    $read = PayRules::fromArray($older);
    expect($read->hourlyHours)->toBeNull()
        ->and($read->roundingDecimals)->toBeNull()
        ->and($read->seaportRate)->toBeNull()
        ->and($read->toArray())->toBe($figures)
        ->and(PayRules::error(['hourly_rate' => ['hours' => 0.5]] + $figures))->toBe('Hours for the hourly rate: enter between 1 and 24, or leave it blank.')
        ->and(PayRules::error(['rounding' => ['decimals' => 5]] + $figures))->toBe('Rounding: choose 0 to 4 decimals, or leave it blank.')
        ->and(PayRules::error(['rounding' => ['decimals' => '2']] + $figures))->toBe('Rounding: choose 0 to 4 decimals, or leave it blank.')
        ->and(PayRules::error(['overtime' => ['rate' => 1.25, 'max_hours' => 8.0, 'seaport_rate' => 11.0]] + $figures))->toBe('Seaport overtime: enter a rate between 0% and 1,000%, or leave it blank.')
        ->and(PayRules::fromArray(['hourly_rate' => ['hours' => 8], 'rounding' => ['decimals' => 2]] + $figures)->roundRate(105.09375))->toBe(105.09)
        ->and(PayRules::fromFormulas()->roundRate(105.09375))->toBe(105.09375);
});

/*
|--------------------------------------------------------------------------
| Divergence 17: each day priced by the version in force on it
|--------------------------------------------------------------------------
*/

it('answers a one-version timeline with the reference path, and reads segments by date', function () {
    $logs = array_merge(...array_map(static fn (string $d): array => payRulesPunches($d), PAY_RULES_SCHEDULED));
    $reference = payRulesSlip(null, $logs);
    $single = payRulesSlip(null, $logs, timeline: PayRulesTimeline::single(PayRules::fromArray(PayRules::fromFormulas()->toArray())));
    expect($single)->toBe($reference)
        ->and($reference['work_days_pay'])->toBe(11000.0);

    $a = PayRules::fromFormulas();
    $b = payRulesWith(['daily_rate.method' => PayRules::METHOD_DIVISOR, 'daily_rate.value' => 20.0]);
    $timeline = PayRulesTimeline::of([['from' => '2026-09-01', 'rules' => $a], ['from' => '2026-09-10', 'rules' => $b]]);
    expect($timeline->isSingle())->toBeFalse()
        ->and($timeline->on('2026-08-31'))->toBe($a)
        ->and($timeline->on('2026-09-09'))->toBe($a)
        ->and($timeline->on('2026-09-10'))->toBe($b)
        ->and($timeline->on('2026-12-31'))->toBe($b)
        ->and($timeline->segmentStart('2026-09-09'))->toBe('2026-09-01')
        ->and($timeline->segmentStart('2026-09-10'))->toBe('2026-09-10')
        ->and(PayRulesTimeline::single($a)->segmentStart('2026-09-10'))->toBe(PayRulesTimeline::ALWAYS)
        ->and(fn () => PayRulesTimeline::of([]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PayRulesTimeline::of([['from' => '2026-09-10', 'rules' => $a], ['from' => '2026-09-01', 'rules' => $b]]))->toThrow(InvalidArgumentException::class);
});

it('prices the days before and from a split by their own version, the method included', function () {
    $logs = array_merge(...array_map(static fn (string $d): array => payRulesPunches($d), PAY_RULES_SCHEDULED));
    // 1–9 September at the month's 22 working days (1,000 a day), 10–15 at a divisor of 20 (1,100).
    $split = payRulesSlip(null, $logs, timeline: payRulesSplit([], '2026-09-10', ['daily_rate.method' => PayRules::METHOD_DIVISOR, 'daily_rate.value' => 20.0]));
    expect($split['work_days'])->toBe(11.0)
        ->and($split['work_days_pay'])->toBe(7 * 1000.0 + 4 * 1100.0)
        // The payslip reports the first day's rate.
        ->and($split['daily_rate'])->toBe(1000.0)
        ->and($split['gross_pay'])->toBe(11400.0);

    // Overtime on each side at its own hour: two hours on the 9th (125 × 125%) and on the 10th (137.5 × 125%).
    $overtime = array_merge(payRulesPunches('2026-09-09', '00:00', '11:00'), payRulesPunches('2026-09-10', '00:00', '11:00'));
    $approvals = ['2026-09-09' => 120, '2026-09-10' => 120];
    expect(payRulesSlip(null, $overtime, approvals: $approvals, timeline: payRulesSplit([], '2026-09-10', ['daily_rate.method' => PayRules::METHOD_DIVISOR, 'daily_rate.value' => 20.0]))['overtime_pay'])->toBe(312.5 + 343.75);
});

it('carries the monthly residual per version when a split changes the basis', function () {
    // The 10th is absent; everything else is worked.
    $worked = array_values(array_diff(PAY_RULES_SCHEDULED, ['2026-09-10']));
    $logs = array_merge(...array_map(static fn (string $d): array => payRulesPunches($d), $worked));
    // Daily-paid at a divisor of 25 (880 a day) until the 9th, then monthly-paid at a divisor of 20:
    // 7 × 880, 3 × 1,100, and the monthly residual over the second version's 4 scheduled days
    // (22,000 × 4 ÷ 22 − 4 × 1,100 = −400).
    $split = payRulesSlip(null, $logs, timeline: payRulesSplit(
        ['daily_rate.method' => PayRules::METHOD_DIVISOR, 'daily_rate.value' => 25.0],
        '2026-09-10',
        ['basis' => PayRules::BASIS_MONTHLY, 'daily_rate.method' => PayRules::METHOD_DIVISOR, 'daily_rate.value' => 20.0],
    ));
    expect($split['work_days'])->toBe(10.0)
        ->and($split['absence_days'])->toBe(1.0)
        ->and($split['work_days_pay'])->toBe(7 * 880.0 + 3 * 1100.0 - 400.0);
});

it('prices a holiday by the version in force on its date across a split', function () {
    $logs = payRulesPunches('2026-09-10');
    $holidays = [payRulesHoliday('2026-09-10')];
    $richer = ['matrix.REGULAR.worked' => 250.0];

    expect(payRulesSlip(null, $logs, $holidays)['holiday_pay'])->toBe(2000.0)
        ->and(payRulesSlip(null, $logs, $holidays, timeline: payRulesSplit([], '2026-09-10', $richer))['holiday_pay'])->toBe(2500.0)
        ->and(payRulesSlip(null, $logs, $holidays, timeline: payRulesSplit([], '2026-09-11', $richer))['holiday_pay'])->toBe(2000.0);
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
