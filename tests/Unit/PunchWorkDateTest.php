<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\Shift;
use App\Domain\Attendance\ShiftGeometry;
use App\Domain\Attendance\ShiftResolver;
use App\Modules\Timekeeping\Domain\Attendance\PunchWorkDate;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;

/**
 * Which Manila calendar date a live punch belongs to — the shift START date
 * a DTR row is keyed on, and the date whose roster location the punch is
 * judged at.
 *
 * Two rules, one copy (Domain\Attendance\PunchWorkDate), shared by the punch
 * command and the station preview so the two cannot drift:
 *
 *   1. forInstant(): on an OVERNIGHT shift an instant at or before the raw
 *      end belongs to the previous date; a day shift, a FLEXIBLE shift and
 *      the default shift key on the instant's own date.
 *   2. forPunch(): a TIME_OUT that closes an open TIME_IN takes THAT
 *      TIME_IN's date, however late it lands; everything else is rule 1 on
 *      "now".
 *
 * Pure: value objects and instants in, a 'Y-m-d' string out. No container.
 */

/** A Manila wall-clock instant. */
function pwdAt(string $manila): CarbonImmutable
{
    return LocalClock::instant($manila);
}

/** A punch at a Manila wall-clock instant, shaped as the command reads it back from a row. */
function pwdLog(string $type, string $manila): DomainLog
{
    return DomainLog::fromArray(['log_type' => $type, 'logged_at' => pwdAt($manila)->utc()]);
}

function pwdDay(): ShiftGeometry
{
    return ShiftResolver::geometry(new Shift(startTime: '08:00:00', endTime: '17:00:00'));
}

function pwdNight(): ShiftGeometry
{
    return ShiftResolver::geometry(new Shift(startTime: '22:00:00', endTime: '06:00:00'));
}

/** A FLEXIBLE row as the roster stores it: the 08:00/17:00 filler clock and its daily hours. */
function pwdFlexible(): ShiftGeometry
{
    return ShiftResolver::geometry(new Shift(startTime: '08:00:00', endTime: '17:00:00', mode: Shift::MODE_FLEXIBLE, requiredHours: 8.0));
}

it('keys a day shift on the instant\'s own date at any hour', function () {
    $day = pwdDay();

    expect($day->overnight)->toBeFalse();
    expect(PunchWorkDate::forInstant(pwdAt('2026-09-03T05:30:00'), $day))->toBe('2026-09-03')
        ->and(PunchWorkDate::forInstant(pwdAt('2026-09-03T00:00:00'), $day))->toBe('2026-09-03')
        ->and(PunchWorkDate::forInstant(pwdAt('2026-09-03T17:00:00'), $day))->toBe('2026-09-03')
        ->and(PunchWorkDate::forInstant(pwdAt('2026-09-03T23:59:00'), $day))->toBe('2026-09-03');
});

it('keys an overnight instant at or before the raw end on the previous date, and one past it on its own', function () {
    $night = pwdNight();

    expect($night->overnight)->toBeTrue()
        ->and($night->rawEnd)->toBe(360.0);
    // 05:30 is inside the night that started the evening before.
    expect(PunchWorkDate::forInstant(pwdAt('2026-09-03T05:30:00'), $night))->toBe('2026-09-02')
        // The raw end itself still belongs to the night.
        ->and(PunchWorkDate::forInstant(pwdAt('2026-09-03T06:00:00'), $night))->toBe('2026-09-02')
        // One minute past it is the next day.
        ->and(PunchWorkDate::forInstant(pwdAt('2026-09-03T06:01:00'), $night))->toBe('2026-09-03')
        ->and(PunchWorkDate::forInstant(pwdAt('2026-09-03T06:30:00'), $night))->toBe('2026-09-03')
        // The evening is its own date.
        ->and(PunchWorkDate::forInstant(pwdAt('2026-09-02T22:00:00'), $night))->toBe('2026-09-02')
        ->and(PunchWorkDate::forInstant(pwdAt('2026-09-02T23:59:00'), $night))->toBe('2026-09-02');
});

it('reads the Manila date, not the UTC one, on either rule', function () {
    // 2026-09-02 17:30 UTC is 2026-09-03 01:30 in Manila.
    $utc = CarbonImmutable::parse('2026-09-02 17:30:00', 'UTC');

    expect(PunchWorkDate::forInstant($utc, pwdDay()))->toBe('2026-09-03')
        ->and(PunchWorkDate::forInstant($utc, pwdNight()))->toBe('2026-09-02');
});

it('keys a Time Out that closes an open Time In on that Time In\'s date, even past the raw end', function () {
    $night = pwdNight();
    $open = [pwdLog('TIME_IN', '2026-09-02T22:00:15')];

    // 06:30 is past the raw end, so rule 1 alone would say the 3rd; the open
    // Time In says the night that started on the 2nd.
    expect(PunchWorkDate::forPunch($open, DomainLog::TIME_OUT, pwdAt('2026-09-03T06:30:00'), $night))->toBe('2026-09-02')
        ->and(PunchWorkDate::forPunch($open, DomainLog::TIME_OUT, pwdAt('2026-09-03T05:30:00'), $night))->toBe('2026-09-02')
        // A forgotten Time In still owns the Time Out that finally closes it.
        ->and(PunchWorkDate::forPunch($open, DomainLog::TIME_OUT, pwdAt('2026-09-03T14:00:00'), $night))->toBe('2026-09-02');

    // The same on a day shift: a Time Out after midnight closes yesterday.
    expect(PunchWorkDate::forPunch([pwdLog('TIME_IN', '2026-09-02T08:00:00')], DomainLog::TIME_OUT, pwdAt('2026-09-03T00:30:00'), pwdDay()))->toBe('2026-09-02');
});

it('picks the latest punch in the window whatever order the rows arrive in', function () {
    $night = pwdNight();
    // Tuesday's pair, then Wednesday's open Time In — listed out of order.
    $window = [
        pwdLog('TIME_IN', '2026-09-02T22:00:00'),
        pwdLog('TIME_OUT', '2026-09-02T06:00:00'),
        pwdLog('TIME_IN', '2026-09-01T22:00:00'),
    ];

    expect(PunchWorkDate::forPunch($window, DomainLog::TIME_OUT, pwdAt('2026-09-03T06:30:00'), $night))->toBe('2026-09-02');
});

it('keys a Time In, a Time Out with no open Time In, and an empty window on now', function () {
    $night = pwdNight();
    $closed = [pwdLog('TIME_IN', '2026-09-02T22:00:00'), pwdLog('TIME_OUT', '2026-09-03T05:00:00')];

    // A Time In is always "now" under rule 1 — inside the night's end it is
    // still yesterday, past it today.
    expect(PunchWorkDate::forPunch([], DomainLog::TIME_IN, pwdAt('2026-09-03T05:30:00'), $night))->toBe('2026-09-02')
        ->and(PunchWorkDate::forPunch([], DomainLog::TIME_IN, pwdAt('2026-09-03T06:30:00'), $night))->toBe('2026-09-03')
        ->and(PunchWorkDate::forPunch($closed, DomainLog::TIME_IN, pwdAt('2026-09-03T21:30:00'), $night))->toBe('2026-09-03');

    // A Time Out whose latest punch is already a Time Out has nothing to
    // close: rule 1 on now. (The pairing check refuses it later; the date
    // rule does not.)
    expect(PunchWorkDate::forPunch($closed, DomainLog::TIME_OUT, pwdAt('2026-09-03T06:30:00'), $night))->toBe('2026-09-03')
        ->and(PunchWorkDate::forPunch([], DomainLog::TIME_OUT, pwdAt('2026-09-03T06:30:00'), $night))->toBe('2026-09-03');
});

it('never treats a flexible or default geometry as overnight', function () {
    $flexible = pwdFlexible();
    $default = ShiftResolver::geometry(null);

    expect($flexible->overnight)->toBeFalse()
        ->and($default->overnight)->toBeFalse();
    foreach ([$flexible, $default] as $geometry) {
        expect(PunchWorkDate::forInstant(pwdAt('2026-09-03T05:30:00'), $geometry))->toBe('2026-09-03')
            ->and(PunchWorkDate::forInstant(pwdAt('2026-09-03T00:00:00'), $geometry))->toBe('2026-09-03')
            ->and(PunchWorkDate::forPunch([], DomainLog::TIME_IN, pwdAt('2026-09-03T05:30:00'), $geometry))->toBe('2026-09-03');
    }
});
