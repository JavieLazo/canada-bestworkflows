<?php

declare(strict_types=1);

use App\Domain\Attendance\Minutes;
use App\Support\ManilaClock;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Regression cover for a defect that shipped in 01-STARTER-PACK.md section 5.5 and was
 * transcribed verbatim into ManilaClock::minutesBetween().
 *
 * The spec's docblock says Math.max(0, Math.round(ms / 60000)) but the code it
 * gives is max(0, (int) Money::round($ms / 60000)). Money::round() rounds to
 * TWO DECIMALS and the (int) cast then TRUNCATES, so a pair 480.5 minutes long
 * returned 480 where the reference returns 481 - an off-by-one minute on
 * roughly half of all in->out pairs.
 */
it('rounds whole minutes the way JS Math.round does', function () {
    $cases = [
        [480.5, 481], [480.75, 481], [480.499, 480],
        [1.5, 2], [2.5, 3], [0.5, 1], [7.5, 8],
        [0.0, 0], [59.5, 60], [-0.4, 0],
    ];

    foreach ($cases as [$input, $expected]) {
        expect(ManilaClock::roundToWholeMinute($input))->toBe($expected);
    }
});

it('agrees with the DTR engine minute rounder on every half-minute', function () {
    // The two implementations must never drift. Sweep every 0.1 across an
    // ordinary shift, which covers every fractional case that occurs.
    //
    // Minutes::round() returns float (it mirrors JS, where every number is a
    // double); ManilaClock::roundToWholeMinute() returns int because callers
    // index minutes. Compare the VALUES - a type difference here is by design.
    $disagreements = [];

    for ($i = 0; $i <= 6000; $i++) {
        $n = $i / 10;

        if (ManilaClock::roundToWholeMinute($n) !== (int) Minutes::round($n)) {
            $disagreements[] = $n;
        }
    }

    expect($disagreements)->toBe([]);
});

it('never expresses minute rounding as a truncated peso rounding', function () {
    // The exact defect, pinned. If someone reintroduces
    // (int) Money::round($n) these must diverge and this test must fail.
    $viaPeso = (int) Money::round(480.5);
    $correct = ManilaClock::roundToWholeMinute(480.5);

    expect($viaPeso)->toBe(480);       // what the buggy form produced
    expect($correct)->toBe(481);       // what the reference produces
    expect($correct)->not->toBe($viaPeso);
});

it('measures real punch pairs correctly', function () {
    $in = CarbonImmutable::parse('2026-01-15T08:00:00.000Z');

    // 8h 0m 30s -> 480.5 minutes -> 481, not 480.
    expect(ManilaClock::minutesBetween($in, $in->addMinutes(480)->addSeconds(30)))->toBe(481);

    // Never negative, even when the punches are inverted.
    expect(ManilaClock::minutesBetween($in->addHours(2), $in))->toBe(0);
});
