<?php

declare(strict_types=1);

use App\Support\LocalClock;
use Carbon\CarbonImmutable;

/**
 * LocalClock on the zone the application ships with.
 *
 * The rest of the suite runs on the reference clock, which never changes its
 * offset. These tests name America/Edmonton themselves and pin what a NAMED
 * zone must do that a fixed offset cannot: follow the offset in force on each
 * date, measure a night across a clock change as the hours that really
 * passed, and read a wall clock a change skips or repeats one stated way.
 *
 * Alberta changed its clocks twice a year through March 2026 and has kept one
 * offset, six hours behind UTC, since November 2026. Both halves are pinned,
 * because records from before the change must keep reading as they did. The
 * later half needs PHP's time zone data from 2026 or newer: on older data it
 * fails, and so would every winter punch.
 */
function localClockSuiteZone(): string
{
    return (string) (getenv('BESTWORKFLOWS_TIMEZONE') ?: LocalClock::DEFAULT_ZONE);
}

beforeEach(function (): void {
    LocalClock::useZone('America/Edmonton');
});

afterEach(function (): void {
    LocalClock::useZone(localClockSuiteZone());
});

/* |---| The zone |---| */

it('ships on Alberta time', function () {
    expect(LocalClock::DEFAULT_ZONE)->toBe('America/Edmonton');
});

it('refuses a zone PHP does not know and keeps the one in use', function () {
    expect(fn () => LocalClock::useZone('Canada/Nowhere'))->toThrow(InvalidArgumentException::class)
        ->and(LocalClock::zone())->toBe('America/Edmonton');
});

it('takes another named zone', function () {
    LocalClock::useZone('America/Toronto');

    expect(LocalClock::zone())->toBe('America/Toronto')
        ->and(LocalClock::instant('2026-01-15T08:00:00')->utc()->format('Y-m-d H:i'))->toBe('2026-01-15 13:00');
});

/* |---| Wall clock to instant |---| */

it('reads an offset-less stamp on the offset in force that day', function () {
    // Winter was seven hours behind UTC and summer six, while the clocks changed.
    expect(LocalClock::instant('2026-01-15T08:00:00')->utc()->format('Y-m-d H:i'))->toBe('2026-01-15 15:00')
        ->and(LocalClock::instant('2026-07-15T08:00:00')->utc()->format('Y-m-d H:i'))->toBe('2026-07-15 14:00');
});

it('keeps Alberta six hours behind UTC all year from November 2026', function () {
    expect(LocalClock::instant('2026-12-15T08:00:00')->utc()->format('Y-m-d H:i'))->toBe('2026-12-15 14:00', 'PHP\'s time zone data predates Alberta\'s single year-round time. Update it before trusting any winter timestamp.')
        ->and(LocalClock::instant('2027-01-15T08:00:00')->utc()->format('Y-m-d H:i'))->toBe('2027-01-15 14:00')
        ->and(LocalClock::instant('2027-07-15T08:00:00')->utc()->format('Y-m-d H:i'))->toBe('2027-07-15 14:00')
        // No hour is skipped in March 2027 and none repeated in November 2026.
        ->and(LocalClock::instant('2027-03-14T02:30:00')->utc()->format('Y-m-d H:i'))->toBe('2027-03-14 08:30')
        ->and(LocalClock::minutesBetween(LocalClock::instant('2026-10-31T22:00:00'), LocalClock::instant('2026-11-01T06:00:00')))->toBe(480);
});

it('leaves a stamp that names its own zone as that instant', function () {
    expect(LocalClock::instant('2026-07-15T08:00:00Z')->utc()->format('Y-m-d H:i'))->toBe('2026-07-15 08:00')
        ->and(LocalClock::instant('2026-07-15T08:00:00+08:00')->utc()->format('Y-m-d H:i'))->toBe('2026-07-15 00:00')
        ->and(LocalClock::instant('2026-07-15T08:00:00-0600')->utc()->format('Y-m-d H:i'))->toBe('2026-07-15 14:00');
});

it('reads a wall clock the spring change skips an hour later', function () {
    // 02:00 to 03:00 does not exist on 2026-03-08 in Alberta.
    expect(LocalClock::instant('2026-03-08T02:30:00')->utc()->format('Y-m-d H:i'))->toBe('2026-03-08 09:30');
});

it('reads a wall clock the autumn change repeats as its first occurrence', function () {
    // 01:00 to 02:00 happened twice on 2025-11-02 in Alberta.
    expect(LocalClock::instant('2025-11-02T01:30:00')->utc()->format('Y-m-d H:i'))->toBe('2025-11-02 07:30');
});

/* |---| Instant to local day and minute |---| */

it('puts a late-evening punch on its local day, not the UTC one', function () {
    expect(LocalClock::dayKey(CarbonImmutable::parse('2026-07-15T05:30:00Z')))->toBe('2026-07-14')
        ->and(LocalClock::dayKey(CarbonImmutable::parse('2026-01-15T06:59:59Z')))->toBe('2026-01-14')
        ->and(LocalClock::dayKey(CarbonImmutable::parse('2026-01-15T07:00:00Z')))->toBe('2026-01-15');
});

it('counts the minute of the local day with seconds kept', function () {
    expect(LocalClock::minutesOfDay(CarbonImmutable::parse('2026-01-15T15:15:30Z')))->toBe(495.5)
        ->and(LocalClock::minutesOfDay(CarbonImmutable::parse('2026-07-15T14:15:30Z')))->toBe(495.5);
});

/* |---| Elapsed time across a clock change |---| */

it('measures a night across the spring change as the seven hours that passed', function () {
    $in = LocalClock::instant('2026-03-07T22:00:00');
    $out = LocalClock::instant('2026-03-08T06:00:00');

    expect(LocalClock::minutesBetween($in, $out))->toBe(420);
});

it('measures a night across the autumn change as the nine hours that passed', function () {
    $in = LocalClock::instant('2025-11-01T22:00:00');
    $out = LocalClock::instant('2025-11-02T06:00:00');

    expect(LocalClock::minutesBetween($in, $out))->toBe(540);
});

it('measures an ordinary night as eight hours', function () {
    $in = LocalClock::instant('2026-06-10T22:00:00');
    $out = LocalClock::instant('2026-06-11T06:00:00');

    expect(LocalClock::minutesBetween($in, $out))->toBe(480);
});
