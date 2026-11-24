<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\Recurring\RecurringLine;

/**
 * A recurring line's own rules: which periods it is live on and due on,
 * what one payroll proposes, and how a total is paid off — the last
 * payroll trimmed to what is left, nothing once nothing is left.
 */

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function recurringFigures(array $changes = []): array
{
    return [...['kind' => 'DEDUCTION', 'amount' => 1000.0, 'total' => 2500.0, 'frequency' => 'EVERY', 'starts_on' => '2026-11-01', 'ends_on' => null], ...$changes];
}

it('is live from its first cut-off through its last date, and due on the payrolls its frequency names', function () {
    $line = RecurringLine::fromArray(recurringFigures(['ends_on' => '2027-01-16']));

    expect($line->liveOn('2026-10-16'))->toBeFalse()
        ->and($line->liveOn('2026-11-01'))->toBeTrue()
        ->and($line->liveOn('2027-01-16'))->toBeTrue()
        ->and($line->liveOn('2027-02-01'))->toBeFalse()
        ->and($line->dueOn('2026-11-01'))->toBeTrue()
        ->and(RecurringLine::fromArray(recurringFigures(['frequency' => 'FIRST']))->dueOn('2026-11-16'))->toBeFalse()
        ->and(RecurringLine::fromArray(recurringFigures(['frequency' => 'FIRST']))->dueOn('2026-11-15'))->toBeTrue()
        ->and(RecurringLine::fromArray(recurringFigures(['frequency' => 'SECOND']))->dueOn('2026-11-16'))->toBeTrue()
        ->and(RecurringLine::fromArray(recurringFigures(['frequency' => 'SECOND']))->dueOn('2026-11-01'))->toBeFalse();
});

it('proposes its amount until the total is nearly paid, then only what is left, then nothing', function () {
    $loan = RecurringLine::fromArray(recurringFigures());

    expect($loan->proposed(0.0))->toBe(1000.0)
        ->and($loan->left(1000.0))->toBe(1500.0)
        ->and($loan->proposed(2000.0))->toBe(500.0)
        ->and($loan->paidOff(2000.0))->toBeFalse()
        ->and($loan->proposed(2500.0))->toBe(0.0)
        ->and($loan->paidOff(2500.0))->toBeTrue()
        // Paid more than the total (a typed payment): nothing is left, never less than nothing.
        ->and($loan->left(2600.0))->toBe(0.0);

    $open = RecurringLine::fromArray(recurringFigures(['total' => null]));
    expect($open->left(99_000.0))->toBeNull()
        ->and($open->paidOff(99_000.0))->toBeFalse()
        ->and($open->proposed(99_000.0))->toBe(1000.0);
});

it('refuses figures it cannot use', function (array $changes, string $message) {
    expect(RecurringLine::error(recurringFigures($changes)))->toBe($message);
})->with([
    'unknown kind' => [['kind' => 'BONUS'], 'Choose whether it is a deduction or an incentive.'],
    'no amount' => [['amount' => 0.0], 'Enter the amount per payroll, above $0.00 and up to $99,999,999.00.'],
    'an amount that is not a number' => [['amount' => null], 'Enter the amount per payroll, above $0.00 and up to $99,999,999.00.'],
    'a total below the amount' => [['total' => 500.0], 'The total to pay off must be at least the amount per payroll, and up to $99,999,999.00.'],
    'an incentive with a total' => [['kind' => 'INCENTIVE'], 'Only a deduction has a total to pay off; end an incentive with its last date instead.'],
    'unknown frequency' => [['frequency' => 'YEARLY'], 'Choose which payrolls it is on.'],
    'a start off the cut-off' => [['starts_on' => '2026-11-05'], 'Choose the cut-off it starts on: the 1st or the 16th of a month.'],
    'an unreadable end' => [['ends_on' => 'soon'], 'Enter the last date it applies, or leave it open.'],
    'an end before the start' => [['ends_on' => '2026-10-31'], 'The last date cannot be before the start.'],
]);
