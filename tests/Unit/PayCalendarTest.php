<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\Schedules\PayCalendar;
use App\Modules\Payroll\Domain\Schedules\PaySchedule;

/**
 * The pay calendar a schedule makes, pure: the month's salary periods, the
 * pay day as the schedule names it (a day the month lacks becomes its last
 * day; "next month" rolls the year), moved off weekends and holidays the way
 * the schedule says, the deadlines counted back from the pay date, each
 * period's place in the month and the month whose contributions it carries;
 * and the figures a schedule refuses.
 */

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function pcalFigures(array $changes = []): array
{
    return array_replace(PaySchedule::standard()->toArray(), $changes);
}

/** @return array<string, mixed> */
function pcalPeriod(int $start, ?int $end, string $kind = PaySchedule::PAY_PERIOD_END, ?int $day = null, bool $nextMonth = false): array
{
    return ['start' => $start, 'end' => $end, 'pay_day' => ['kind' => $kind, 'day' => $day, 'next_month' => $nextMonth]];
}

it('builds the standard semi-monthly calendar, paid on each period\'s last day, with no move and no deadlines', function () {
    $february = PayCalendar::month(PaySchedule::standard(), 2026, 2);
    expect($february)->toHaveCount(2)
        ->and($february[0])->toMatchArray(['start' => '2026-02-01', 'end' => '2026-02-15', 'pay_date' => '2026-02-15', 'position' => PayCalendar::FIRST, 'contribution_month' => '2026-02', 'deadlines' => []])
        ->and($february[1])->toMatchArray(['start' => '2026-02-16', 'end' => '2026-02-28', 'pay_date' => '2026-02-28', 'position' => PayCalendar::LAST])
        ->and(PayCalendar::month(PaySchedule::standard(), 2028, 2)[1]['end'])->toBe('2028-02-29')
        ->and(PayCalendar::periodFor(PaySchedule::standard(), '2026-02-16', '2026-02-28')['pay_date'] ?? null)->toBe('2026-02-28')
        ->and(PayCalendar::periodFor(PaySchedule::standard(), '2026-02-10', '2026-02-25'))->toBeNull()
        ->and(PayCalendar::nextAfter(PaySchedule::standard(), '2026-12-16')['start'])->toBe('2027-01-01')
        ->and(PayCalendar::nextAfter(PaySchedule::standard(), '2026-02-15')['start'])->toBe('2026-02-16');
});

it('pays on the 15th and the 30th, the working day before a weekend or holiday, with deadlines counted back', function () {
    $schedule = PaySchedule::fromArray(pcalFigures([
        'periods' => [pcalPeriod(1, 15, PaySchedule::PAY_DAY, 15), pcalPeriod(16, null, PaySchedule::PAY_DAY, 30)],
        'holiday_rule' => PaySchedule::MOVE_BEFORE,
        'deadlines' => [['label' => 'Draft complete', 'days_before' => 5], ['label' => 'Approved', 'days_before' => 2]],
    ]));
    // February has no 30th: the 28th, a Saturday in 2026, moves to Friday the 27th.
    $february = PayCalendar::month($schedule, 2026, 2);
    expect($february[1]['pay_date'])->toBe('2026-02-27')
        ->and($february[1]['deadlines'])->toBe([['label' => 'Draft complete', 'date' => '2026-02-22'], ['label' => 'Approved', 'date' => '2026-02-25']])
        // 15 Feb 2026 is a Sunday: the Friday before.
        ->and($february[0]['pay_date'])->toBe('2026-02-13');
    // A holiday followed by a weekend: Friday 30 Oct 2026 a holiday → Thursday the 29th; with the 29th a holiday too → Wednesday.
    expect(PayCalendar::month($schedule, 2026, 10, ['2026-10-30'])[1]['pay_date'])->toBe('2026-10-29')
        ->and(PayCalendar::month($schedule, 2026, 10, ['2026-10-30', '2026-10-29'])[1]['pay_date'])->toBe('2026-10-28')
        // AFTER moves the other way: Saturday 31 Jan 2026 → Monday 2 Feb, and a pay date in the next month carries that month's contributions.
        ->and(PayCalendar::month(PaySchedule::fromArray(pcalFigures(['periods' => [pcalPeriod(1, 15), pcalPeriod(16, null, PaySchedule::PAY_DAY, 31)], 'holiday_rule' => PaySchedule::MOVE_AFTER, 'contribution_month' => PaySchedule::MONTH_PAY_DATE])), 2026, 1)[1])
        ->toMatchArray(['pay_date' => '2026-02-02', 'contribution_month' => '2026-02'])
        // KEEP leaves a weekend pay date alone.
        ->and(PayCalendar::month(PaySchedule::fromArray(pcalFigures(['periods' => [pcalPeriod(1, 15, PaySchedule::PAY_DAY, 15), pcalPeriod(16, null)]])), 2026, 2)[0]['pay_date'])->toBe('2026-02-15');
});

it('pays a monthly period on a day of the following month, rolling the year', function () {
    $schedule = PaySchedule::fromArray(pcalFigures(['periods' => [pcalPeriod(1, null, PaySchedule::PAY_DAY, 5, true)]]));
    $december = PayCalendar::month($schedule, 2026, 12);
    expect($december)->toHaveCount(1)
        ->and($december[0])->toMatchArray(['start' => '2026-12-01', 'end' => '2026-12-31', 'pay_date' => '2027-01-05', 'position' => PayCalendar::ONLY, 'contribution_month' => '2026-12'])
        ->and($schedule->isSemiMonthly())->toBeFalse()
        ->and(PaySchedule::standard()->isSemiMonthly())->toBeTrue();
});

it('lists the periods whose pay date or deadline falls within the coming days', function () {
    $schedule = PaySchedule::fromArray(pcalFigures(['deadlines' => [['label' => 'Approved', 'days_before' => 3]]]));
    $soon = PayCalendar::upcoming($schedule, '2026-11-10', 3);
    expect(array_column($soon, 'pay_date'))->toBe(['2026-11-15'])
        ->and(array_column(PayCalendar::upcoming($schedule, '2026-11-13', 20), 'pay_date'))->toBe(['2026-11-15', '2026-11-30'])
        ->and(PayCalendar::upcoming($schedule, '2026-11-16', 5))->toBe([])
        ->and(PayCalendar::isOff('2026-11-14', []))->toBeTrue()
        ->and(PayCalendar::isOff('2026-11-16', []))->toBeFalse()
        ->and(PayCalendar::isOff('2026-11-16', ['2026-11-16']))->toBeTrue();
});

it('refuses figures it cannot read, naming what is wrong', function (array $changes, string $message) {
    expect(PaySchedule::error(pcalFigures($changes)))->toBe($message);
})->with([
    'no periods' => [['periods' => []], 'Cut the month into one to four salary periods.'],
    'gap' => [['periods' => [pcalPeriod(1, 14), pcalPeriod(16, null)]], 'The salary periods must cover the month in order, the first starting on the 1st and each starting the day after the one before.'],
    'first not on the 1st' => [['periods' => [pcalPeriod(2, null)]], 'The salary periods must cover the month in order, the first starting on the 1st and each starting the day after the one before.'],
    'last ends early' => [['periods' => [pcalPeriod(1, 15), pcalPeriod(16, 28)]], 'The last salary period ends on the month\'s last day.'],
    'middle open' => [['periods' => [pcalPeriod(1, null), pcalPeriod(16, null)]], 'A salary period ends on or after the day it starts, and only the last one reaches the month\'s end.'],
    'day 29' => [['periods' => [pcalPeriod(1, 29), pcalPeriod(30, null)]], 'A salary period starts and ends on a day of the month (1 to 28), or on the month\'s last day.'],
    'pay day 32' => [['periods' => [pcalPeriod(1, null, PaySchedule::PAY_DAY, 32)]], 'A pay day is a day of the month, 1 to 31.'],
    'pay kind' => [['periods' => [pcalPeriod(1, null, 'WHENEVER')]], 'Choose when each salary period is paid.'],
    'move' => [['holiday_rule' => 'SKIP'], 'Choose what a pay date on a weekend or holiday does.'],
    'deadline label' => [['deadlines' => [['label' => '', 'days_before' => 2]]], 'A deadline label is 1 to 60 characters.'],
    'deadline days' => [['deadlines' => [['label' => 'x', 'days_before' => 61]]], 'A deadline falls 0 to 60 days before the pay date.'],
    'too many deadlines' => [['deadlines' => array_fill(0, 5, ['label' => 'x', 'days_before' => 1])], 'Enter up to 4 deadlines.'],
    'month rule' => [['contribution_month' => 'NEXT'], 'Choose which month\'s contributions a payroll carries.'],
    'lag' => [['attendance_lag' => 40], 'The attendance window ends 0 to 31 days before the salary period.'],
    'missing key' => [['deadlines' => null], 'Enter up to 4 deadlines.'],
]);

it('reads its own figures back unchanged', function () {
    $figures = pcalFigures([
        'periods' => [pcalPeriod(1, 10, PaySchedule::PAY_DAY, 12), pcalPeriod(11, 20), pcalPeriod(21, null, PaySchedule::PAY_DAY, 2, true)],
        'holiday_rule' => PaySchedule::MOVE_AFTER, 'deadlines' => [['label' => ' Approved ', 'days_before' => 2]], 'attendance_lag' => 5,
    ]);
    $schedule = PaySchedule::fromArray($figures);
    expect($schedule->toArray())->toBe([...$figures, 'deadlines' => [['label' => 'Approved', 'days_before' => 2]]])
        ->and(PayCalendar::month($schedule, 2026, 3)[1]['position'])->toBe(PayCalendar::MIDDLE)
        ->and(fn () => PaySchedule::fromArray(['periods' => []]))->toThrow(InvalidArgumentException::class);
});
