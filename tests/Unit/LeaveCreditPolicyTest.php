<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Support\HrisFormat;

/**
 * LeaveCreditPolicy is the ONE statement of what a leave credit is: which
 * Leave Settings types carry an entitlement, what a request costs against it
 * (every covered calendar day, a half day at 0.5, charged whole to the year
 * it starts in), which requests count (APPROVED, leave, not without pay —
 * null pay reads as paid, as the DTR reads it), the ONE key a request is
 * charged to (exact before the `_LEAVE` spelling, so a stale `SICK` row and a
 * current `SICK_LEAVE` row never both count one request), and what a typed
 * credit may be (whole or half days, 0 to 9999.99; blank means no row).
 *
 * The `LEAVE` token that PayrollLeavePayTest and PaidLeaveDaysTest file under
 * matches NO seeded credit key — pinned here so that gap stays deliberate:
 * a payroll fixture's bare token is not a credit type. Filing validates
 * available credits separately from payroll's reading of approved rows.
 *
 * Pure PHP; no container and no clock, so this runs under tests/Unit.
 */

/**
 * The seven Leave Settings names ReferenceListSeeder ships, as the credit
 * keys the leave_credits table stores (`HrisFormat::normalizeRequestType`).
 *
 * @return list<string>
 */
function lcpolSeededKeys(): array
{
    return array_map(
        static fn (string $name): string => HrisFormat::normalizeRequestType($name),
        lcpolSeededNames(),
    );
}

/** @return list<string> */
function lcpolSeededNames(): array
{
    return [
        'Vacation Leave',
        'Sick Leave',
        'Business Travel / OB',
        'Emergency Leave',
        'Maternity Leave',
        'Paternity Leave',
        'Leave Without Pay',
    ];
}

/* |---| What a request costs |---| */

it('charges every covered calendar day, a half day at 0.5 per day', function (?string $dayType, string $start, string $end, float $days) {
    expect(LeaveCreditPolicy::coveredDays($dayType, $start, $end))->toBe($days);
})->with([
    'one whole day' => ['WHOLE_DAY', '2026-08-10', '2026-08-10', 1.0],
    'one half day' => ['HALF_DAY', '2026-08-10', '2026-08-10', 0.5],
    'three whole days' => ['WHOLE_DAY', '2026-08-10', '2026-08-12', 3.0],
    'two half days are one day, not half a day' => ['HALF_DAY', '2026-08-10', '2026-08-11', 1.0],
    'a null day type is a whole day' => [null, '2026-08-10', '2026-08-11', 2.0],
    'an unknown day type is a whole day' => ['SOMETHING_ELSE', '2026-08-10', '2026-08-10', 1.0],
    'rest days and holidays inside the range count' => ['WHOLE_DAY', '2026-12-24', '2026-12-27', 4.0],
    'start after end costs nothing' => ['WHOLE_DAY', '2026-08-12', '2026-08-10', 0.0],
    'across New Year, all four days' => ['WHOLE_DAY', '2026-12-30', '2027-01-02', 4.0],
]);

it('charges a request to the year its start date falls in, even across New Year', function () {
    expect(LeaveCreditPolicy::yearOf('2026-12-30'))->toBe(2026)
        ->and(LeaveCreditPolicy::yearOf('2027-01-02'))->toBe(2027)
        ->and(LeaveCreditPolicy::coveredDays('WHOLE_DAY', '2026-12-30', '2027-01-02'))->toBe(4.0)
        ->and(LeaveCreditPolicy::yearOf('2026-01-01'))->toBe(2026);
});

/* |---| Which requests count |---| */

it('tells leave from official business', function () {
    expect(LeaveCreditPolicy::isLeave('SICK'))->toBeTrue()
        ->and(LeaveCreditPolicy::isLeave('SICK_LEAVE'))->toBeTrue()
        ->and(LeaveCreditPolicy::isLeave('VACATION_LEAVE'))->toBeTrue()
        ->and(LeaveCreditPolicy::isLeave('BUSINESS_TRAVEL'))->toBeFalse();
});

it('draws on credits for paid or unmarked leave, never for unpaid leave or official business', function (string $requestType, ?bool $isPaid, bool $would) {
    expect(LeaveCreditPolicy::wouldConsume($requestType, $isPaid))->toBe($would);
})->with([
    'paid leave' => ['SICK', true, true],
    'leave with no pay mark reads as paid' => ['SICK', null, true],
    'unpaid leave' => ['SICK', false, false],
    'paid vacation' => ['VACATION_LEAVE', true, true],
    'official business, paid' => ['BUSINESS_TRAVEL', true, false],
    'official business, no pay mark' => ['BUSINESS_TRAVEL', null, false],
    'official business, unpaid' => ['BUSINESS_TRAVEL', false, false],
]);

it('counts a request against the balance only once approved', function (string $status, string $requestType, ?bool $isPaid, bool $counts) {
    expect(LeaveCreditPolicy::consumes($status, $requestType, $isPaid))->toBe($counts);
})->with([
    'APPROVED paid' => ['APPROVED', 'SICK', true, true],
    'APPROVED with no pay mark' => ['APPROVED', 'SICK', null, true],
    'APPROVED unpaid' => ['APPROVED', 'SICK', false, false],
    'PENDING paid' => ['PENDING', 'SICK', true, false],
    'REJECTED paid' => ['REJECTED', 'SICK', true, false],
    'WITHDRAWN paid' => ['WITHDRAWN', 'SICK', true, false],
    'APPROVED official business' => ['APPROVED', 'BUSINESS_TRAVEL', null, false],
    'APPROVED official business marked paid' => ['APPROVED', 'BUSINESS_TRAVEL', true, false],
    'lower-case approved is not approved' => ['approved', 'SICK', true, false],
]);

it('would still consume a pending request once approved, which is what the review dialog warns about', function () {
    expect(LeaveCreditPolicy::consumes('PENDING', 'SICK', true))->toBeFalse()
        ->and(LeaveCreditPolicy::wouldConsume('SICK', true))->toBeTrue();
});

/* |---| Which credit a request is charged to |---| */

it('matches the bare LEAVE token of the payroll fixtures to no seeded credit key — a deliberate gap', function () {
    // PayrollLeavePayTest and PaidLeaveDaysTest file under 'LEAVE', a token no
    // Leave Settings name normalises to. Credits are display only, so those
    // fixtures must never start counting against anyone's balance by accident.
    $keys = lcpolSeededKeys();

    expect($keys)->toBe([
        'VACATION_LEAVE', 'SICK_LEAVE', 'BUSINESS_TRAVEL_OB', 'EMERGENCY_LEAVE',
        'MATERNITY_LEAVE', 'PATERNITY_LEAVE', 'LEAVE_WITHOUT_PAY',
    ]);

    expect(LeaveCreditPolicy::resolveKey('LEAVE', $keys))->toBeNull();
});

it('resolves a request to its exact credit key before the _LEAVE spelling, and to nothing otherwise', function () {
    // case => [stored request token, the employee's credit keys, the ONE key charged]
    $cases = [
        'exact key wins over the suffixed one' => ['SICK', ['SICK_LEAVE', 'SICK'], 'SICK'],
        'exact key wins whatever the order' => ['SICK', ['SICK', 'SICK_LEAVE'], 'SICK'],
        'legacy token falls back to the suffixed key' => ['SICK', ['VACATION_LEAVE', 'SICK_LEAVE'], 'SICK_LEAVE'],
        'current token matches itself' => ['SICK_LEAVE', ['SICK_LEAVE'], 'SICK_LEAVE'],
        'no reverse tolerance from a suffixed token to a bare key' => ['SICK_LEAVE', ['SICK'], null],
        'no key at all' => ['SICK', [], null],
        'no matching key' => ['SICK', ['VACATION_LEAVE', 'EMERGENCY_LEAVE'], null],
        'official business is never charged' => ['BUSINESS_TRAVEL', ['BUSINESS_TRAVEL_OB', 'SICK_LEAVE'], null],
        'comparison is strict' => ['SICK', ['sick', 'Sick_Leave'], null],
        'a lower-case token matches no key' => ['sick', ['SICK', 'SICK_LEAVE'], null],
    ];

    foreach ($cases as $case => [$requestType, $creditKeys, $resolved]) {
        expect(LeaveCreditPolicy::resolveKey($requestType, $creditKeys))->toBe($resolved, $case);
    }
});

/* |---| What a typed credit may be |---| */

it('refuses a typed credit that is not a number of days with up to four decimals between 0 and 999999.9999, and lets a blank through', function (mixed $value, ?string $error) {
    expect(LeaveCreditPolicy::creditError($value))->toBe($error);
})->with([
    'blank means no row' => ['', null],
    'whitespace is blank' => [' ', null],
    'an explicit zero is a row' => ['0', null],
    'a half step' => ['12.5', null],
    'a bare half' => ['.5', null],
    'a quarter day' => ['0.25', null],
    'a month\'s earning' => ['1.167', null],
    'four decimals' => ['43.3025', null],
    'five decimals' => ['1.16675', LeaveCreditPolicy::STEP_ERROR],
    'exponent notation is not a credit' => ['1e3', LeaveCreditPolicy::STEP_ERROR],
    'a comma is not a decimal point' => ['12,5', LeaveCreditPolicy::STEP_ERROR],
    'letters' => ['abc', LeaveCreditPolicy::STEP_ERROR],
    'negative' => ['-1', LeaveCreditPolicy::RANGE_ERROR],
    'above the column ceiling' => ['1000000', LeaveCreditPolicy::RANGE_ERROR],
    'the ceiling itself' => ['999999.9999', null],
    'an int' => [12, null],
    'a float on the half' => [7.5, null],
    'a float off the half' => [7.25, null],
    'null is not a value' => [null, LeaveCreditPolicy::STEP_ERROR],
    'an array is not a value' => [[], LeaveCreditPolicy::STEP_ERROR],
    'a bool is not a value' => [true, LeaveCreditPolicy::STEP_ERROR],
]);

it('spells the two refusals the way the dialog shows them and pins the range', function () {
    expect(LeaveCreditPolicy::STEP_ERROR)->toBe('Enter a number of days with up to four decimals.')
        ->and(LeaveCreditPolicy::RANGE_ERROR)->toBe('Credits must be between 0 and 999999.9999.')
        ->and(LeaveCreditPolicy::MAX_CREDITS)->toBe(999999.9999)
        ->and(LeaveCreditPolicy::YEAR_MIN)->toBe(2000)
        ->and(LeaveCreditPolicy::YEAR_MAX)->toBe(2100);
});

it('reads a blank as no row and anything else as its days', function () {
    expect(LeaveCreditPolicy::credit(''))->toBeNull()
        ->and(LeaveCreditPolicy::credit('  '))->toBeNull()
        ->and(LeaveCreditPolicy::credit(null))->toBeNull()
        ->and(LeaveCreditPolicy::credit('12.5'))->toBe(12.5)
        ->and(LeaveCreditPolicy::credit(' 12.5 '))->toBe(12.5)
        ->and(LeaveCreditPolicy::credit('0'))->toBe(0.0)
        ->and(LeaveCreditPolicy::credit(15))->toBe(15.0)
        ->and(LeaveCreditPolicy::credit(7.5))->toBe(7.5);
});

it('prints whole days bare and a half day with one decimal', function (float $days, string $label) {
    expect(LeaveCreditPolicy::format($days))->toBe($label);
})->with([
    'whole' => [15.0, '15'],
    'half' => [7.5, '7.5'],
    'zero' => [0.0, '0'],
    'a bare half' => [0.5, '0.5'],
    'negative remainder' => [-1.5, '-1.5'],
]);

/* |---| Which types carry an entitlement |---| */

it('grants credits to the seeded leave types but never to official business or leave without pay', function () {
    $creditable = array_values(array_filter(lcpolSeededNames(), static fn (string $name): bool => LeaveCreditPolicy::creditable($name)));

    expect($creditable)->toBe(['Vacation Leave', 'Sick Leave', 'Emergency Leave', 'Maternity Leave', 'Paternity Leave'])
        ->and(LeaveCreditPolicy::creditable('Business Travel / OB'))->toBeFalse()
        ->and(LeaveCreditPolicy::creditable('Leave Without Pay'))->toBeFalse()
        ->and(LeaveCreditPolicy::creditable('Official Business'))->toBeFalse()
        ->and(LeaveCreditPolicy::creditable('Travel'))->toBeFalse()
        ->and(LeaveCreditPolicy::creditable('leave WITHOUT pay'))->toBeFalse()
        ->and(LeaveCreditPolicy::creditable('Bereavement Leave'))->toBeTrue();
});

it('requires enough remaining leave credits to file the requested calendar days', function (?float $remaining, float $needed, ?string $error) {
    expect(LeaveCreditPolicy::filingError($remaining, $needed, 2026))->toBe($error);
})->with([
    'missing allocation' => [null, 1.0, 'You have no available leave credits for the selected leave type in 2026.'],
    'zero allocation' => [0.0, 1.0, 'You have no available leave credits for the selected leave type in 2026.'],
    'overdrawn allocation' => [-1.0, 0.5, 'You have no available leave credits for the selected leave type in 2026.'],
    'insufficient allocation' => [0.5, 1.0, 'This request needs 1 day, but your available balance for this leave type in 2026 is 0.5 days.'],
    'exact half-day balance' => [0.5, 0.5, null],
    'exact whole-day balance' => [1.0, 1.0, null],
    'larger balance' => [15.0, 2.0, null],
]);
