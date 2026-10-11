<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Domain\Roster\ScheduleBasis;

/**
 * How far a date's schedule can be trusted: recorded once history is kept,
 * assumed before it, "may have differed" up to the last recorded change —
 * and NO ASSIGNED SCHEDULE wins over all of them, because the engine's
 * default is nobody's history.
 */
it('reads a date as recorded from the day history began, and always when it was kept from the start', function () {
    expect(ScheduleBasis::of('2026-10-05', ScheduleBasis::SOURCE_DEFAULT, '2026-10-05', '2026-10-01'))->toBe(ScheduleBasis::RECORDED)
        ->and(ScheduleBasis::of('2027-01-01', ScheduleBasis::SOURCE_WEEKLY, '2026-10-05', null))->toBe(ScheduleBasis::RECORDED)
        ->and(ScheduleBasis::of('2020-01-01', ScheduleBasis::SOURCE_TEMPORARY, null, null))->toBe(ScheduleBasis::RECORDED);
});

it('reads a date before history as assumed, and as may have differed up to the last recorded change', function () {
    expect(ScheduleBasis::of('2026-10-04', ScheduleBasis::SOURCE_DEFAULT, '2026-10-05', null))->toBe(ScheduleBasis::ASSUMED)
        ->and(ScheduleBasis::of('2026-10-02', ScheduleBasis::SOURCE_DEFAULT, '2026-10-05', '2026-10-01'))->toBe(ScheduleBasis::ASSUMED)
        ->and(ScheduleBasis::of('2026-10-01', ScheduleBasis::SOURCE_DEFAULT, '2026-10-05', '2026-10-01'))->toBe(ScheduleBasis::MAY_DIFFER)
        ->and(ScheduleBasis::of('2026-01-15', ScheduleBasis::SOURCE_TEMPORARY, '2026-10-05', '2026-10-01'))->toBe(ScheduleBasis::MAY_DIFFER);
});

it('never calls a date with nothing assigned recorded or assumed', function (string $date, ?string $assumedBefore, ?string $mayDiffer) {
    expect(ScheduleBasis::of($date, ScheduleBasis::SOURCE_NONE, $assumedBefore, $mayDiffer))->toBe(ScheduleBasis::NONE);
})->with([
    'history kept from the start' => ['2026-10-06', null, null],
    'after history began' => ['2026-10-06', '2026-10-05', null],
    'before history began' => ['2026-09-01', '2026-10-05', null],
    'inside the may-differ span' => ['2026-09-01', '2026-10-05', '2026-10-01'],
]);
