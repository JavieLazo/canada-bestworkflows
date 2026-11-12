<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Domain\Credits\AttendanceChargePlan;
use App\Modules\Timekeeping\Domain\Credits\LeaveAcquisitionRule;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeRule;
use App\Modules\Timekeeping\Domain\Credits\MinuteConversion;

/**
 * The pure leave rules. A leave type with a waiting period is acquired the
 * given months after the date hired, pushed back one day for each WHOLE day
 * without pay before it (half days add up, a leftover half moves nothing),
 * never earlier than the version that gives it and only while that version
 * is in force. A leave type's rule holds only what was entered. And a
 * payroll charges a type that waits only from the day it was acquired.
 */

/** @return list<array{id: string, from: string, to: ?string, months: ?int, extends: bool}> */
function lacqVersions(int $months = 6, bool $extends = true, string $from = '2026-01-01'): array
{
    return [['id' => 'v1', 'from' => $from, 'to' => null, 'months' => $months, 'extends' => $extends]];
}

/*
|--------------------------------------------------------------------------
| The day a leave type is acquired
|--------------------------------------------------------------------------
*/

it('adds whole months to the date hired and clamps the day to the month', function (string $date, int $months, string $expected) {
    expect(LeaveAcquisitionRule::addMonths($date, $months))->toBe($expected);
})->with([
    'plain' => ['2026-01-15', 6, '2026-07-15'],
    'over the year' => ['2026-09-10', 6, '2027-03-10'],
    'clamped to February' => ['2026-08-31', 6, '2027-02-28'],
    'clamped to a leap February' => ['2027-08-31', 6, '2028-02-29'],
    'one month' => ['2026-01-31', 1, '2026-02-28'],
]);

it('acquires the type on the day the months are over, and not a day before', function () {
    expect(LeaveAcquisitionRule::resolve('2026-01-15', lacqVersions(), [], '2026-07-14'))->toBeNull()
        ->and(LeaveAcquisitionRule::resolve('2026-01-15', lacqVersions(), [], '2026-07-15'))
        ->toMatchArray(['date' => '2026-07-15', 'version_id' => 'v1', 'months' => 6, 'extension_days' => 0]);
});

it('pushes the day back one day for each whole day without pay, and counts the days the extension itself adds', function () {
    // Five days without pay inside the six months: the day moves five days.
    $unpaid = ['2026-02-02' => 1.0, '2026-02-03' => 1.0, '2026-03-10' => 1.0, '2026-04-01' => 1.0, '2026-05-20' => 1.0];
    expect(LeaveAcquisitionRule::resolve('2026-01-15', lacqVersions(), $unpaid, '2026-12-31'))
        ->toMatchArray(['date' => '2026-07-20', 'extension_days' => 5, 'unpaid_days' => 5.0]);

    // A day without pay inside the extension pushes it once more.
    $unpaid['2026-07-16'] = 1.0;
    expect(LeaveAcquisitionRule::resolve('2026-01-15', lacqVersions(), $unpaid, '2026-12-31')['date'])->toBe('2026-07-21')
        // Not reached yet on the day before.
        ->and(LeaveAcquisitionRule::resolve('2026-01-15', lacqVersions(), $unpaid, '2026-07-20'))->toBeNull();
});

it('adds half days up and lets a leftover half move nothing', function () {
    expect(LeaveAcquisitionRule::resolve('2026-01-15', lacqVersions(), ['2026-02-02' => 0.5], '2026-12-31')['date'])->toBe('2026-07-15')
        ->and(LeaveAcquisitionRule::resolve('2026-01-15', lacqVersions(), ['2026-02-02' => 0.5, '2026-02-09' => 0.5], '2026-12-31')['date'])->toBe('2026-07-16')
        ->and(LeaveAcquisitionRule::resolve('2026-01-15', lacqVersions(), ['2026-02-02' => 0.5, '2026-02-09' => 0.5, '2026-03-02' => 0.5], '2026-12-31'))
        ->toMatchArray(['date' => '2026-07-16', 'extension_days' => 1, 'unpaid_days' => 1.5]);
});

it('ignores days without pay when the rule does not extend the wait, and those after the day', function () {
    $unpaid = ['2026-02-02' => 1.0, '2026-08-01' => 1.0];
    expect(LeaveAcquisitionRule::resolve('2026-01-15', lacqVersions(extends: false), $unpaid, '2026-12-31')['date'])->toBe('2026-07-15')
        ->and(LeaveAcquisitionRule::resolve('2026-01-15', lacqVersions(), ['2026-08-01' => 1.0], '2026-12-31')['date'])->toBe('2026-07-15');
});

it('never acquires a type before the version that gives it, nor after the employee left', function () {
    // Hired years before the rule began: acquired the day the rule began.
    expect(LeaveAcquisitionRule::resolve('2020-03-01', lacqVersions(from: '2026-11-10'), [], '2026-12-31')['date'])->toBe('2026-11-10')
        ->and(LeaveAcquisitionRule::resolve('2020-03-01', lacqVersions(from: '2026-11-10'), [], '2026-11-09'))->toBeNull()
        // Left before the months were over.
        ->and(LeaveAcquisitionRule::resolve('2026-01-15', lacqVersions(), [], '2026-12-31', '2026-06-30'))->toBeNull();
});

it('walks the versions oldest first and gives the day only while the version is in force', function () {
    $versions = [
        ['id' => 'six', 'from' => '2026-01-01', 'to' => '2026-05-31', 'months' => 6, 'extends' => false],
        ['id' => 'three', 'from' => '2026-06-01', 'to' => null, 'months' => 3, 'extends' => false],
    ];
    // Six months would end in July, after that version stopped; the three-month version gives it from its own first day.
    expect(LeaveAcquisitionRule::resolve('2026-01-15', $versions, [], '2026-12-31'))->toMatchArray(['date' => '2026-06-01', 'version_id' => 'three', 'months' => 3])
        // A version with no waiting period gives no day of its own.
        ->and(LeaveAcquisitionRule::resolve('2026-01-15', [['id' => 'none', 'from' => '2026-01-01', 'to' => null, 'months' => null, 'extends' => false]], [], '2026-12-31'))->toBeNull()
        ->and(LeaveAcquisitionRule::expected('2026-01-15', lacqVersions()))->toBe('2026-07-15');
});

/*
|--------------------------------------------------------------------------
| A leave type's rule
|--------------------------------------------------------------------------
*/

it('validates a leave type rule strictly and stores only what was entered', function () {
    $earning = ['amount' => '1.25', 'method' => 'FLAT', 'table' => [], 'partial' => 'PRO_RATA', 'cap' => null];
    $tokens = ['VACATION_LEAVE', 'SICK_LEAVE'];
    $base = ['waiting_months' => '6', 'waiting_extends' => true, 'on_acquire' => 'ZERO', 'earning' => $earning, 'fallback' => null];

    expect(LeaveTypeRule::error($base, 'VACATION_LEAVE', true, $tokens))->toBeNull()
        ->and(LeaveTypeRule::error([...$base, 'waiting_months' => '0'], 'VACATION_LEAVE', true, $tokens))->toContain('1 to 600 months')
        ->and(LeaveTypeRule::error([...$base, 'waiting_months' => '2.5'], 'VACATION_LEAVE', true, $tokens))->toContain('whole number of months')
        ->and(LeaveTypeRule::error([...$base, 'on_acquire' => 'LATER'], 'VACATION_LEAVE', true, $tokens))->toContain('on the day the leave is acquired')
        ->and(LeaveTypeRule::error($base, 'VACATION_LEAVE', false, $tokens))->toContain('Only a leave type paid from earned leave credits accrues credits')
        ->and(LeaveTypeRule::error([...$base, 'earning' => null, 'fallback' => 'SICK_LEAVE'], 'VACATION_LEAVE', false, $tokens))->toContain('can fall back on another')
        ->and(LeaveTypeRule::error([...$base, 'on_acquire' => 'GRANT', 'earning' => null], 'VACATION_LEAVE', true, $tokens))->toContain('needs a monthly accrual')
        ->and(LeaveTypeRule::error([...$base, 'fallback' => 'VACATION_LEAVE'], 'VACATION_LEAVE', true, $tokens))->toContain('fall back on itself')
        ->and(LeaveTypeRule::error([...$base, 'fallback' => 'GONE'], 'VACATION_LEAVE', true, $tokens))->toContain('from Leave Settings')
        // A rule with nothing in it is a rule: available from the first day, earning nothing.
        ->and(LeaveTypeRule::error(['waiting_months' => null, 'waiting_extends' => false, 'on_acquire' => 'ZERO', 'earning' => null, 'fallback' => null], 'SICK_LEAVE', false, $tokens))->toBeNull();

    $rule = LeaveTypeRule::fromParams([...$base, 'on_acquire' => 'GRANT', 'fallback' => 'SICK_LEAVE']);
    expect($rule->waitingMonths())->toBe(6)->and($rule->extendsWait())->toBeTrue()
        ->and($rule->grant())->toBe(7.5)
        ->and($rule->fallback())->toBe('SICK_LEAVE')
        ->and($rule->describe(['SICK_LEAVE' => 'Sick Leave']))->toBe('acquired after 6 months, extended by days without pay · 7.5 granted on acquiring · earns 1.25 a month · falls back on Sick Leave');

    // With no waiting period the waiting options are dropped, and nothing is granted.
    $plain = LeaveTypeRule::fromParams([...$base, 'waiting_months' => '', 'on_acquire' => 'GRANT']);
    expect($plain->waitingMonths())->toBeNull()->and($plain->extendsWait())->toBeFalse()->and($plain->grant())->toBe(0.0)
        ->and($plain->describe([]))->toBe('available from the first day · earns 1.25 a month')
        ->and(LeaveTypeRule::none()->describe([]))->toBe('available from the first day · earns nothing');
});

/*
|--------------------------------------------------------------------------
| Charging a type that waits
|--------------------------------------------------------------------------
*/

it('charges a leave type only from the day it was acquired, and leaves an earlier day to pay unmarked', function () {
    $table = MinuteConversion::rows(8.0, 3, 'HALF_DOWN');
    $conversion = ['rows' => $table, 'hours_per_day' => 8.0, 'decimals' => 3];
    $charges = ['enabled' => true, 'combine' => 'DAY', 'late' => ['VACATION_LEAVE'], 'absence' => ['VACATION_LEAVE']];
    $balances = ['VACATION_LEAVE' => ['available' => 5.0, 'below_zero_limit' => 0.0, 'from' => '2026-09-08']];
    $days = [
        ['date' => '2026-09-03', 'late_minutes' => 0.0, 'undertime_minutes' => 0.0, 'absence_days' => 1.0],
        ['date' => '2026-09-04', 'late_minutes' => 60.0, 'undertime_minutes' => 0.0, 'absence_days' => 0.0],
        ['date' => '2026-09-08', 'late_minutes' => 60.0, 'undertime_minutes' => 0.0, 'absence_days' => 0.0],
        ['date' => '2026-09-09', 'late_minutes' => 0.0, 'undertime_minutes' => 0.0, 'absence_days' => 1.0],
    ];

    $plan = AttendanceChargePlan::plan($charges, $conversion, $balances, $days);
    expect(array_column($plan['lines'], 'date'))->toBe(['2026-09-09', '2026-09-08'])
        ->and($plan['balances']['VACATION_LEAVE'])->toBe(3.875);

    // Pooled lateness: the day before the acquisition is not in the pool.
    $pooled = AttendanceChargePlan::plan([...$charges, 'combine' => 'PAYROLL'], $conversion, $balances, $days);
    $late = array_values(array_filter($pooled['lines'], static fn (array $line): bool => $line['kind'] === AttendanceChargePlan::LATE));
    expect($late)->toHaveCount(1)->and($late[0]['date'])->toBe('2026-09-08')->and($late[0]['covered'])->toBe(['VACATION_LEAVE' => 0.125]);

    // A balance with no day is charged on every day.
    $open = AttendanceChargePlan::plan($charges, $conversion, ['VACATION_LEAVE' => ['available' => 5.0, 'below_zero_limit' => 0.0]], $days);
    expect($open['lines'])->toHaveCount(4)
        ->and(AttendanceChargePlan::plan([...$charges, 'combine' => 'PAYROLL'], $conversion, ['VACATION_LEAVE' => ['available' => 5.0, 'below_zero_limit' => 0.0]], $days)['balances']['VACATION_LEAVE'])->toBe(2.75);
});
