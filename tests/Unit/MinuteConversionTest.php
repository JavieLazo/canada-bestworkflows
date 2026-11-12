<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
use App\Modules\Timekeeping\Domain\Credits\MinuteConversion;

/**
 * Minutes become fractions of a day exactly, in whole-number arithmetic:
 * the formula at the table's decimals and rounding reproduces the
 * government's 8-hour table (1 minute .002, 30 minutes .062, 60 minutes
 * .125, each hour .125), a duration is read as whole days plus the hour
 * row plus the minute row, and a loaded table that differs from the
 * formula is reported row by row. Credits themselves hold four decimals
 * and never go through money rounding.
 */
it('reproduces the government table for an 8-hour day at 3 decimals rounding half down', function () {
    $rows = MinuteConversion::rows(8, 3, 'HALF_DOWN');

    expect($rows['minutes'][1])->toBe('0.002')
        ->and($rows['minutes'][2])->toBe('0.004')
        ->and($rows['minutes'][3])->toBe('0.006')
        ->and($rows['minutes'][7])->toBe('0.015')
        ->and($rows['minutes'][13])->toBe('0.027')
        ->and($rows['minutes'][30])->toBe('0.062')
        ->and($rows['minutes'][43])->toBe('0.090')
        ->and($rows['minutes'][59])->toBe('0.123')
        ->and($rows['minutes'][60])->toBe('0.125')
        ->and($rows['hours'])->toBe([1 => '0.125', 2 => '0.250', 3 => '0.375', 4 => '0.500', 5 => '0.625', 6 => '0.750', 7 => '0.875', 8 => '1.000']);
});

it('rounds a tie by the rule asked for', function (string $rounding, string $thirty, string $one) {
    expect(MinuteConversion::fraction(30, 480, 3, $rounding))->toBe($thirty)
        ->and(MinuteConversion::fraction(1, 480, 3, $rounding))->toBe($one);
})->with([
    'half down' => ['HALF_DOWN', '0.062', '0.002'],
    'half up' => ['HALF_UP', '0.063', '0.002'],
    'always up' => ['UP', '0.063', '0.003'],
    'always down' => ['DOWN', '0.062', '0.002'],
]);

it('reads a duration as whole days, the hours row and the minutes row from the table', function () {
    $rows = MinuteConversion::rows(8, 3, 'HALF_DOWN');

    expect(MinuteConversion::days(0, $rows, 8, 3))->toBe(0.0)
        ->and(MinuteConversion::days(30, $rows, 8, 3))->toBe(0.062)
        ->and(MinuteConversion::days(90, $rows, 8, 3))->toBe(0.187)
        ->and(MinuteConversion::days(60, $rows, 8, 3))->toBe(0.125)
        ->and(MinuteConversion::days(480, $rows, 8, 3))->toBe(1.0)
        ->and(MinuteConversion::days(480 + 125, $rows, 8, 3))->toBe(1.26);
});

it('refuses a day whose minutes are not whole and lists the rows a loaded table changed', function () {
    expect(fn () => MinuteConversion::dayMinutes(7.333))->toThrow(InvalidArgumentException::class)
        ->and(MinuteConversion::dayMinutes(7.5))->toBe(450)
        ->and(MinuteConversion::hoursInDay(7.5))->toBe(8);

    $rows = MinuteConversion::rows(8, 3, 'HALF_DOWN');
    $rows['minutes'][30] = '0.063';
    $rows['hours'][8] = '0.999';
    $differences = MinuteConversion::differences($rows, 8, 3, 'HALF_DOWN');

    expect($differences)->toBe([
        ['unit' => 'minutes', 'value' => 30, 'table' => '0.063', 'formula' => '0.062'],
        ['unit' => 'hours', 'value' => 8, 'table' => '0.999', 'formula' => '1.000'],
    ])
        ->and(MinuteConversion::rowsError($rows, 8, 3))->toBeNull()
        ->and(MinuteConversion::rowsError(['minutes' => [], 'hours' => []], 8, 3))->toBe('Minutes row 1 is missing.')
        ->and(MinuteConversion::rowsError(['minutes' => array_fill(1, 60, '0.0625'), 'hours' => array_fill(1, 8, '0.125')], 8, 3))->toContain('up to 3 decimals')
        ->and(MinuteConversion::rowsError(['minutes' => array_fill(1, 60, '2'), 'hours' => array_fill(1, 8, '0.125')], 8, 3))->toContain('cannot exceed one day');
});

it('keeps credits to four decimals, shows them without trailing zeros and refuses what is not a credit', function () {
    expect(LeaveQuantity::format(13.75))->toBe('13.75')
        ->and(LeaveQuantity::format(1.167))->toBe('1.167')
        ->and(LeaveQuantity::format(43.3025))->toBe('43.3025')
        ->and(LeaveQuantity::format(0.0))->toBe('0')
        ->and(LeaveQuantity::format(-0.00004))->toBe('0')
        ->and(LeaveQuantity::format(-1.5))->toBe('-1.5')
        ->and(LeaveQuantity::normalize(1.23456))->toBe(1.2346)
        ->and(LeaveQuantity::sum(0.1, 0.2))->toBe(0.3)
        ->and(LeaveQuantity::parse(' 2.5 '))->toBe(2.5)
        ->and(LeaveQuantity::parse(''))->toBeNull()
        ->and(LeaveQuantity::error('1.1667'))->toBeNull()
        ->and(LeaveQuantity::error('1.16675'))->toBe(LeaveQuantity::FORMAT_ERROR)
        ->and(LeaveQuantity::error('12,5'))->toBe(LeaveQuantity::FORMAT_ERROR)
        ->and(LeaveQuantity::error('-1'))->toBe(LeaveQuantity::RANGE_ERROR)
        ->and(LeaveQuantity::error('-1', allowNegative: true))->toBeNull()
        ->and(LeaveQuantity::error('1000000'))->toBe(LeaveQuantity::RANGE_ERROR)
        ->and(LeaveQuantity::error(true))->toBe(LeaveQuantity::FORMAT_ERROR);
});

it('validates a leave type\'s credit rules and describes them for the audit trail', function () {
    $input = ['earns_credits' => true, 'unit' => 'DAYS', 'charge_late' => true, 'charge_absence' => true, 'charge_order' => '1', 'below_zero_limit' => '2.5'];

    expect(LeaveTypeCredits::error($input))->toBeNull()
        ->and(LeaveTypeCredits::fromInput($input)->describe())->toBe('earns credits · days · charged for lateness and absences, order 1 · below zero to 2.5')
        ->and(LeaveTypeCredits::none()->describe())->toBe('does not earn credits · days · never charged · below zero to 0')
        ->and(LeaveTypeCredits::error([...$input, 'unit' => 'WEEKS']))->toContain('days or hours')
        ->and(LeaveTypeCredits::error([...$input, 'charge_order' => '1000']))->toContain('0 to 999')
        ->and(LeaveTypeCredits::error([...$input, 'below_zero_limit' => '-1']))->toContain('Maximum negative balance')
        ->and(LeaveTypeCredits::error([...$input, 'earns_credits' => 'yes']))->toContain('yes or no');
});

it('finds the charged leave types that share a charge order, and ignores the types charged for nothing', function () {
    $type = static fn (bool $late, bool $absence, int $order): LeaveTypeCredits => new LeaveTypeCredits(true, 'DAYS', $late, $absence, $order, 0.0);
    $types = [
        'VACATION_LEAVE' => ['name' => 'Vacation Leave', 'rules' => $type(true, true, 1)],
        'SICK_LEAVE' => ['name' => 'Sick Leave', 'rules' => $type(false, true, 1)],
        'SOLO_PARENT_LEAVE' => ['name' => 'Solo Parent Leave', 'rules' => $type(true, false, 1)],
        'FORCED_LEAVE' => ['name' => 'Forced Leave', 'rules' => $type(false, true, 2)],
        // Charged for nothing: in no order, so its 1 and 2 clash with nobody.
        'BIRTHDAY_LEAVE' => ['name' => 'Birthday Leave', 'rules' => $type(false, false, 1)],
        'SPECIAL_LEAVE' => ['name' => 'Special Leave', 'rules' => $type(false, false, 2)],
    ];

    expect(LeaveTypeCredits::orderClashes($types))->toBe([
        'VACATION_LEAVE' => ['Sick Leave', 'Solo Parent Leave'],
        'SICK_LEAVE' => ['Vacation Leave', 'Solo Parent Leave'],
        'SOLO_PARENT_LEAVE' => ['Vacation Leave', 'Sick Leave'],
    ])
        ->and(LeaveTypeCredits::orderClashes(['FORCED_LEAVE' => $types['FORCED_LEAVE'], 'SPECIAL_LEAVE' => $types['SPECIAL_LEAVE']]))->toBe([])
        ->and(LeaveTypeCredits::orderClashText(1, ['Sick Leave']))->toBe('Deduction priority 1 is already used by Sick Leave. Each leave type with credit deductions needs its own priority.')
        ->and(LeaveTypeCredits::orderClashText(3, ['Sick Leave', 'Forced Leave', 'Solo Parent Leave']))->toBe('Deduction priority 3 is already used by Sick Leave, Forced Leave and Solo Parent Leave. Each leave type with credit deductions needs its own priority.');
});
