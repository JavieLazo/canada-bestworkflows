<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\CutoffAdvice;

/**
 * A basic payroll over anything but a 1st–15th or 16th–end-of-month cut-off
 * is WARNED about, never refused: the statutory lines follow the semi-monthly
 * reference rule, which only such a cut-off matches exactly. The last day is
 * the month's own (28, 29, 30 or 31), a range that crosses a month end is
 * never standard, and dates that PayrollPeriod::cutoffError already refuses
 * produce no second message.
 *
 * Pure PHP; no container, so this runs under tests/Unit.
 */
it('stays quiet on a standard cut-off, whatever the month length', function (string $start, string $end) {
    expect(CutoffAdvice::warning($start, $end))->toBeNull()
        ->and(CutoffAdvice::isStandardCutoff($start, $end))->toBeTrue();
})->with([
    'first half of August' => ['2026-08-01', '2026-08-15'],
    'second half of August (31)' => ['2026-08-16', '2026-08-31'],
    'second half of September (30)' => ['2026-09-16', '2026-09-30'],
    'second half of February 2026 (28)' => ['2026-02-16', '2026-02-28'],
    'second half of February 2028 (29)' => ['2028-02-16', '2028-02-29'],
]);

it('warns on any other range of days', function (string $start, string $end) {
    expect(CutoffAdvice::warning($start, $end))->toBe(CutoffAdvice::WARNING)
        ->and(CutoffAdvice::isStandardCutoff($start, $end))->toBeFalse();
})->with([
    'a few days' => ['2026-08-03', '2026-08-12'],
    'a whole month' => ['2026-08-01', '2026-08-31'],
    'short of the month end' => ['2026-08-16', '2026-08-30'],
    'the 27th is not the end of February' => ['2026-02-16', '2026-02-27'],
    'across a month end' => ['2026-08-28', '2026-09-03'],
    'one day' => ['2026-08-15', '2026-08-15'],
]);

it('says nothing about dates the period rule already refuses', function () {
    expect(CutoffAdvice::warning('2026-08-15', '2026-08-01'))->toBeNull()
        ->and(CutoffAdvice::warning('', '2026-08-15'))->toBeNull()
        ->and(CutoffAdvice::warning('2026-8-1', '2026-08-15'))->toBeNull();
});
