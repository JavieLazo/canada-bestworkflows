<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Domain\Credits\LeaveCashOutPolicy;

/**
 * Cashing leave credits out (LeaveCashOutPolicy): a cashable type, whole or
 * half days above zero, no more than the balance left after the days
 * already spoken for, and within the policy's yearly maximum. Pure.
 */
it('refuses what the rules refuse and accepts the rest', function (mixed $days, bool $allowed, ?float $available, ?float $max, float $asked, ?string $expected) {
    $error = LeaveCashOutPolicy::error($days, $allowed, $available, $max, $asked);
    if ($expected === null) {
        expect($error)->toBeNull();
    } else {
        expect($error)->toContain($expected);
    }
})->with([
    'a type that cannot be cashed out' => ['1', false, 10.0, null, 0.0, 'cannot be cashed out'],
    'no days' => ['', true, 10.0, null, 0.0, 'Enter the days'],
    'zero days' => ['0', true, 10.0, null, 0.0, 'Enter the days'],
    'a third of a day' => ['0.3', true, 10.0, null, 0.0, 'whole or half days'],
    'a quarter day' => ['1.25', true, 10.0, null, 0.0, 'whole or half days'],
    'no credits ever entered' => ['1', true, null, null, 0.0, 'no credits of this type'],
    'nothing left' => ['0.5', true, 0.0, null, 0.0, 'no credits of this type'],
    'more than is left' => ['8', true, 7.5, null, 0.0, 'Only 7.5 days'],
    'exactly what is left' => ['7.5', true, 7.5, null, 0.0, null],
    'a half day' => ['0.5', true, 7.5, null, 0.0, null],
    'past the yearly limit' => ['2', true, 10.0, 5.0, 4.0, 'limited to 5 days a year; 1 left'],
    'at the yearly limit' => ['1', true, 10.0, 5.0, 4.0, null],
    'no yearly limit' => ['9', true, 10.0, null, 100.0, null],
]);
