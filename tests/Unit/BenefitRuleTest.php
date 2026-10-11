<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\Benefits\BenefitRule;

/**
 * A benefit's rule is one strict value: how the amount is set, when it is
 * due, who qualifies, how it is prorated and how it is taxed. This file
 * pins the schedule, the qualification and the pesos each amount rule and
 * proration work out to, and what the figures refuse.
 */

/** @param array<string, mixed> $changes */
function benefitRuleWith(array $changes = []): BenefitRule
{
    return BenefitRule::fromArray(array_replace([
        'amount_type' => BenefitRule::AMOUNT_FIXED, 'amount' => 2000.0, 'frequency' => BenefitRule::FREQUENCY_FIRST, 'month' => null,
        'statuses' => null, 'min_service_months' => 0, 'proration' => BenefitRule::PRORATION_NONE, 'tax' => BenefitRule::TAX_NOT_INCOME,
        'cap' => null, 'approval_reference' => null, 'counts_toward_13th' => false,
    ], $changes));
}

it('keeps the typed shape the catalog had, never scheduled', function () {
    $typed = BenefitRule::typed(false);
    expect($typed->amountType)->toBe(BenefitRule::AMOUNT_TYPED)
        ->and($typed->frequency)->toBe(BenefitRule::FREQUENCY_ONCE)
        ->and($typed->tax)->toBe(BenefitRule::TAX_EXEMPT)
        ->and($typed->isTaxable())->toBeFalse()
        ->and($typed->isScheduled())->toBeFalse()
        ->and($typed->scheduledFor('2026-09-01', '2026-09-15'))->toBeFalse()
        ->and(BenefitRule::typed(true)->isTaxable())->toBeTrue()
        ->and(BenefitRule::fromArray($typed->toArray())->toArray())->toBe($typed->toArray());
});

it('falls due on the cut-offs its frequency names', function () {
    expect(benefitRuleWith(['frequency' => BenefitRule::FREQUENCY_EVERY])->scheduledFor('2026-09-16', '2026-09-30'))->toBeTrue()
        ->and(benefitRuleWith(['frequency' => BenefitRule::FREQUENCY_FIRST])->scheduledFor('2026-09-01', '2026-09-15'))->toBeTrue()
        ->and(benefitRuleWith(['frequency' => BenefitRule::FREQUENCY_FIRST])->scheduledFor('2026-09-16', '2026-09-30'))->toBeFalse()
        ->and(benefitRuleWith(['frequency' => BenefitRule::FREQUENCY_SECOND])->scheduledFor('2026-09-16', '2026-09-30'))->toBeTrue()
        ->and(benefitRuleWith(['frequency' => BenefitRule::FREQUENCY_SECOND])->scheduledFor('2026-09-01', '2026-09-15'))->toBeFalse()
        ->and(benefitRuleWith(['frequency' => BenefitRule::FREQUENCY_YEARLY, 'month' => 5])->scheduledFor('2026-05-01', '2026-05-15'))->toBeTrue()
        ->and(benefitRuleWith(['frequency' => BenefitRule::FREQUENCY_YEARLY, 'month' => 5])->scheduledFor('2026-05-16', '2026-05-31'))->toBeFalse()
        ->and(benefitRuleWith(['frequency' => BenefitRule::FREQUENCY_YEARLY, 'month' => 5])->scheduledFor('2026-06-01', '2026-06-15'))->toBeFalse()
        ->and(benefitRuleWith(['frequency' => BenefitRule::FREQUENCY_ONCE])->isScheduled())->toBeFalse();
});

it('pays only the statuses named and the service required', function () {
    $all = benefitRuleWith();
    $some = benefitRuleWith(['statuses' => ['status-1', 'status-2'], 'min_service_months' => 4]);
    expect($all->qualifies(null, 0))->toBeTrue()
        ->and($all->qualifies('status-9', 0))->toBeTrue()
        ->and($some->qualifies('status-1', 4))->toBeTrue()
        ->and($some->qualifies('status-1', 3))->toBeFalse()
        ->and($some->qualifies('status-9', 12))->toBeFalse()
        ->and($some->qualifies(null, 12))->toBeFalse();
});

it('works out the pesos each amount rule and proration give', function () {
    expect(benefitRuleWith()->amountFor(30000.0, 10.0, 11.0, 22, 12))->toBe(2000.0)
        ->and(benefitRuleWith(['amount_type' => BenefitRule::AMOUNT_PERCENT, 'amount' => 10.0])->amountFor(30000.0, 10.0, 11.0, 22, 12))->toBe(3000.0)
        ->and(benefitRuleWith(['amount_type' => BenefitRule::AMOUNT_MONTH, 'amount' => null])->amountFor(30000.0, 10.0, 11.0, 22, 12))->toBe(30000.0)
        ->and(benefitRuleWith(['amount_type' => BenefitRule::AMOUNT_MONTH, 'amount' => null])->amountFor(0.0, 10.0, 11.0, 22, 12))->toBe(0.0)
        ->and(benefitRuleWith(['amount_type' => BenefitRule::AMOUNT_DAILY, 'amount' => 150.0])->amountFor(30000.0, 10.0, 11.0, 22, 12))->toBe(1500.0)
        ->and(benefitRuleWith(['amount_type' => BenefitRule::AMOUNT_DAILY, 'amount' => 150.0])->amountFor(30000.0, 0.0, 0.0, 22, 12))->toBe(0.0)
        // By paid days: 11 of 22; by months: 5 of 12.
        ->and(benefitRuleWith(['proration' => BenefitRule::PRORATION_DAYS])->amountFor(30000.0, 10.0, 11.0, 22, 12))->toBe(1000.0)
        ->and(benefitRuleWith(['proration' => BenefitRule::PRORATION_DAYS])->amountFor(30000.0, 10.0, 11.0, 0, 12))->toBe(0.0)
        ->and(benefitRuleWith(['amount' => 12000.0, 'proration' => BenefitRule::PRORATION_MONTHS])->amountFor(30000.0, 10.0, 11.0, 22, 5))->toBe(5000.0)
        ->and(benefitRuleWith(['amount' => 12000.0, 'proration' => BenefitRule::PRORATION_MONTHS])->amountFor(30000.0, 10.0, 11.0, 22, 14))->toBe(12000.0)
        ->and(benefitRuleWith(['amount' => 1000.0, 'proration' => BenefitRule::PRORATION_DAYS])->amountFor(30000.0, 1.0, 1.0, 3, 12))->toBe(333.33);
});

it('reads figures strictly and names what is wrong', function () {
    $good = benefitRuleWith()->toArray();
    expect(BenefitRule::error($good))->toBeNull()
        ->and(BenefitRule::error('x'))->toBe('The benefit rule is missing.')
        ->and(BenefitRule::error(['amount_type' => 'WEEKLY'] + $good))->toBe('Choose how the amount is set.')
        ->and(BenefitRule::error(['amount' => null] + $good))->toBe('Enter the amount.')
        ->and(BenefitRule::error(['amount_type' => BenefitRule::AMOUNT_PERCENT, 'amount' => 2000.0] + $good))->toBe('Enter a percent between 0 and 1,000.')
        ->and(BenefitRule::error(['amount_type' => BenefitRule::AMOUNT_MONTH] + $good))->toBe('This amount rule takes no figure.')
        ->and(BenefitRule::error(['frequency' => BenefitRule::FREQUENCY_YEARLY] + $good))->toBe('Choose the month it is paid in.')
        ->and(BenefitRule::error(['month' => 5] + $good))->toBe('Only a yearly benefit has a month.')
        ->and(BenefitRule::error(['amount_type' => BenefitRule::AMOUNT_TYPED, 'amount' => null] + $good))->toBe('An amount typed per payroll cannot be scheduled.')
        ->and(BenefitRule::error(['statuses' => []] + $good))->toBe('Choose who qualifies.')
        ->and(BenefitRule::error(['min_service_months' => -1] + $good))->toBe('Enter the minimum months of service, 0 for none.')
        ->and(BenefitRule::error(['proration' => 'WEEKS'] + $good))->toBe('Choose the proration.')
        ->and(BenefitRule::error(['tax' => 'FREE'] + $good))->toBe('Choose how it is taxed.')
        ->and(BenefitRule::error(['tax' => BenefitRule::TAX_DE_MINIMIS] + $good))->toBe('Enter the cap up to which it is tax-free.')
        ->and(BenefitRule::error(['cap' => 500.0] + $good))->toBe('Only a de minimis benefit has a cap.')
        ->and(BenefitRule::error(['approval_reference' => str_repeat('x', 161)] + $good))->toBe('Keep the approval reference to 160 characters.')
        ->and(BenefitRule::error(['counts_toward_13th' => 'yes'] + $good))->toBe('Say whether it counts toward the 13th month.')
        ->and(fn () => BenefitRule::fromArray(['tax' => 'FREE'] + $good))->toThrow(InvalidArgumentException::class);
});
