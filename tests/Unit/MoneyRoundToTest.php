<?php

declare(strict_types=1);

use App\Support\Money;

/**
 * Money::roundTo is the spreadsheet's ROUND(n, d) for a RATE STEP a Pay
 * Basis & Rates version rounds (divergence 16): half-up like Money::round,
 * the same function at two decimals, junk collapsing to 0.0, and never a
 * call to PHP's round().
 */
it('rounds half up at the chosen decimals, exactly as Money::round at two', function () {
    expect(Money::roundTo(105.09375, 2))->toBe(105.09)
        ->and(Money::roundTo(131.3625, 2))->toBe(131.36)
        ->and(Money::roundTo(840.7536, 0))->toBe(841.0)
        ->and(Money::roundTo(105.125, 0))->toBe(105.0)
        ->and(Money::roundTo(2.5, 0))->toBe(3.0)
        ->and(Money::roundTo(1.23456, 4))->toBe(1.2346)
        ->and(Money::roundTo(1.25, 1))->toBe(1.3)
        ->and(Money::roundTo(-0.4, 0))->toBe(0.0)
        ->and(Money::roundTo(1.005, 2))->toBe(Money::round(1.005))
        ->and(Money::roundTo(10241.0 / 2 / 40, 2))->toBe(Money::round(10241.0 / 2 / 40))
        ->and(Money::roundTo('junk', 2))->toBe(0.0)
        ->and(Money::roundTo(NAN, 3))->toBe(0.0)
        ->and(Money::roundTo(INF, 1))->toBe(0.0);
});
