<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\Shift as DomainShift;
use App\Livewire\Edtr\Support\Hris;
use App\Modules\Timekeeping\Domain\Attendance\CorrectionWindow;
use App\Modules\Timekeeping\Domain\Attendance\PunchSlots;

/**
 * The pure rules behind a DTR correction of one date.
 *
 * A save supersedes EXACTLY the punches the DTR reads as that date's row —
 * DtrCalculator::rowPunches, the engine's own grouping: never the previous
 * night's after-midnight punches that sit on the corrected calendar day, and
 * every next-morning punch the shift groups with the night. A slot ticked
 * "Next day" is stamped on the next calendar day, and only an overnight or
 * FLEXIBLE shift may hold one.
 *
 * Of the superseded rows, a save keeps any whose type and Manila
 * floor-minute (day included, seconds ignored) still equal a slot; the
 * dialog's prefill and the keep-match read the same minute, so a slot left
 * as prefilled always keeps its row.
 *
 * Before anything is written the save is simulated, and it is refused unless
 * the date would own exactly the punches saved: no punch at the very instant
 * of another, no punch moving to or from a neighbouring row, and no change
 * that leaves the night ending on an open next-day Time In — a save that
 * changes nothing is let through.
 */

/** A punch as the engine and the correction see it: an offset-less Manila stamp unless told otherwise. */
function cwinLog(string $type, string $stamp, string $id): DomainLog
{
    return new DomainLog($type, $stamp, $id);
}

/**
 * @param  list<DomainLog>  $logs
 * @return list<string>
 */
function cwinIds(array $logs): array
{
    return array_map(static fn (DomainLog $log): string => (string) $log->id, $logs);
}

/** A 22:00–06:00 night with no scheduled break: one continuous punch stream, carried up to the next day's first Time In. */
function cwinNoBreakNight(): DomainShift
{
    return new DomainShift(startTime: '22:00:00', endTime: '06:00:00');
}

/** The seeded Night Shift's shape: 22:00–06:00 with a 02:00–03:00 break, read on the four-slot timeline. */
function cwinBreakNight(): DomainShift
{
    return new DomainShift(startTime: '22:00:00', endTime: '06:00:00', breakStart: '02:00:00', breakEnd: '03:00:00', breakMinutes: 60);
}

/** A rostered 08:00–17:00 day shift — it ends the same day, so it carries nothing. */
function cwinDayShift(): DomainShift
{
    return new DomainShift(startTime: '08:00:00', endTime: '17:00:00');
}

/** A FLEXIBLE shift: its stored 08:00–17:00 are filler, and it carries only after an open Time In. */
function cwinFlexible(): DomainShift
{
    return new DomainShift(mode: DomainShift::MODE_FLEXIBLE);
}

/** @return array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string} */
function cwinSlots(?string $timeIn, ?string $breakOut = null, ?string $breakIn = null, ?string $timeOut = null): array
{
    return ['time_in' => $timeIn, 'break_out' => $breakOut, 'break_in' => $breakIn, 'time_out' => $timeOut];
}

/** @return array{time_in: bool, break_out: bool, break_in: bool, time_out: bool} */
function cwinTicks(bool $timeIn = false, bool $breakOut = false, bool $breakIn = false, bool $timeOut = false): array
{
    return ['time_in' => $timeIn, 'break_out' => $breakOut, 'break_in' => $breakIn, 'time_out' => $timeOut];
}

/**
 * Two Manila days stored as UTC `Z` stamps, several of them on a different
 * UTC day than their Manila one.
 *
 * @return list<DomainLog>
 */
function cwinTwoUtcDays(): array
{
    return [
        // 2026-09-02 07:30 Manila — the day's own row, stored as UTC.
        cwinLog('TIME_IN', '2026-09-01T23:30:00.000Z', 'day-in'),
        // 2026-09-03 00:30 Manila — a next-morning Time Out.
        cwinLog('TIME_OUT', '2026-09-02T16:30:00.000Z', 'next-out'),
        // 2026-09-03 09:00 Manila — after the next day's first Time In: never carried.
        cwinLog('TIME_IN', '2026-09-03T01:00:00.000Z', 'next-in'),
        cwinLog('TIME_OUT', '2026-09-03T09:00:00.000Z', 'next-out-2'),
        // Another day entirely: ignored.
        cwinLog('TIME_IN', '2026-09-04T00:00:00.000Z', 'other'),
    ];
}

/**
 * Two back-to-back 22:00–06:00 nights with no break, Sep 23 and Sep 24:
 * Sep 23's 06:00 Time Out sits on Sep 24's calendar day.
 *
 * @return list<DomainLog>
 */
function cwinBackToBackNights(): array
{
    return [
        cwinLog('TIME_IN', '2026-09-23T22:00:08', 'n1-in'),
        cwinLog('TIME_OUT', '2026-09-24T06:00:21', 'n1-out'),
        cwinLog('TIME_IN', '2026-09-24T22:00:12', 'n2-in'),
        cwinLog('TIME_OUT', '2026-09-25T06:00:33', 'n2-out'),
    ];
}

/**
 * The same two nights on the break Night Shift, four punches each: three of
 * Sep 23's punches sit on Sep 24's calendar day.
 *
 * @return list<DomainLog>
 */
function cwinBackToBackBreakNights(): array
{
    return [
        cwinLog('TIME_IN', '2026-09-23T22:00:08', 'n1-in'),
        cwinLog('TIME_OUT', '2026-09-24T02:00:14', 'n1-bo'),
        cwinLog('TIME_IN', '2026-09-24T03:00:02', 'n1-bi'),
        cwinLog('TIME_OUT', '2026-09-24T06:00:21', 'n1-out'),
        cwinLog('TIME_IN', '2026-09-24T22:00:12', 'n2-in'),
        cwinLog('TIME_OUT', '2026-09-25T02:00:40', 'n2-bo'),
        cwinLog('TIME_IN', '2026-09-25T03:00:05', 'n2-bi'),
        cwinLog('TIME_OUT', '2026-09-25T06:00:33', 'n2-out'),
    ];
}

/**
 * The save CorrectPunches runs, minus the database: the ticks are read as
 * nextDayFlags() reads them, the shape must pass (so a refusal below is
 * misattribution's, not the shape's), then replaceable → reconcile →
 * misattribution on the same span.
 *
 * @param  list<DomainLog>  $logs
 * @param  array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}  $slots
 * @param  array{time_in: bool, break_out: bool, break_in: bool, time_out: bool}  $nextDay
 * @return array{plan: array{keep: list<string>, delete: list<string>, insert: list<array{log_type: string, logged_at: string}>, standing: list<array{log_type: string, logged_at: string}>, claimed: list<?string>}, error: ?string}
 */
function cwinSave(string $date, array $logs, ?DomainShift $shift, array $slots, array $nextDay): array
{
    $flags = PunchSlots::nextDayFlags($nextDay, $slots);
    expect(CorrectionWindow::nextDayError($date, $shift, $flags))->toBeNull()
        ->and(PunchSlots::error($slots, $flags, CorrectionWindow::allowsNextDay($shift)))->toBeNull();

    $desired = PunchSlots::punches($date, $slots, $flags);
    $plan = CorrectionWindow::reconcile(CorrectionWindow::replaceable($date, $logs, $shift), $desired);

    return [
        'plan' => $plan,
        'error' => CorrectionWindow::misattribution($date, $logs, $desired, $plan, $shift, PunchSlots::filledKeys($slots)),
    ];
}

/*
|--------------------------------------------------------------------------
| The rows a save supersedes
|--------------------------------------------------------------------------
*/

it('carries a no-break night only up to the next first time in, sorting before it slices', function () {
    // Handed in out of order: the sort has to happen before the slice, or
    // the 08:00 Time In would open the next day and nothing would be carried.
    $rows = [
        cwinLog('TIME_IN', '2026-09-03T08:00:00', 'in-morning'),
        cwinLog('TIME_OUT', '2026-09-03T00:30:00', 'out-night'),
        cwinLog('TIME_IN', '2026-09-02T22:00:00', 'in-night'),
    ];

    expect(cwinIds(CorrectionWindow::replaceable('2026-09-02', $rows, cwinNoBreakNight())))->toBe(['in-night', 'out-night']);
});

it('carries the whole next day when it holds no time in, and nothing when it opens with one', function () {
    // array_search === false (no TIME_IN at all) must not read as index 0.
    expect(cwinIds(CorrectionWindow::replaceable('2026-09-02', [
        cwinLog('TIME_OUT', '2026-09-03T00:30:00', 'a'),
        cwinLog('TIME_OUT', '2026-09-03T01:00:00', 'b'),
    ], cwinNoBreakNight())))->toBe(['a', 'b']);

    expect(CorrectionWindow::replaceable('2026-09-02', [
        cwinLog('TIME_IN', '2026-09-03T08:00:00', 'a'),
        cwinLog('TIME_OUT', '2026-09-03T17:00:00', 'b'),
    ], cwinNoBreakNight()))->toBe([]);

    expect(CorrectionWindow::replaceable('2026-09-02', [], cwinNoBreakNight()))->toBe([]);
});

it('partitions two days of rows by their Manila day, not their UTC day', function () {
    expect(cwinIds(CorrectionWindow::replaceable('2026-09-02', cwinTwoUtcDays(), cwinNoBreakNight())))->toBe(['day-in', 'next-out']);
});

it('leaves a day shift\'s stray next-morning time out to the next date', function () {
    // A shift that ends the same day carries nothing, so the engine shows
    // this 00:30 Out on Sep 3's row — and a save of Sep 2 leaves it there.
    // The same for an unrostered employee (the default day).
    foreach ([cwinDayShift(), null] as $shift) {
        expect(cwinIds(CorrectionWindow::replaceable('2026-09-02', cwinTwoUtcDays(), $shift)))->toBe(['day-in']);
    }
});

it('supersedes exactly the row, never the previous night\'s after-midnight time out', function () {
    $rows = cwinBackToBackNights();

    // Sep 23's 06:00 Out sits on Sep 24 but belongs to Sep 23 — no save of
    // Sep 24, changed or not, supersedes it.
    expect(cwinIds(CorrectionWindow::replaceable('2026-09-24', $rows, cwinNoBreakNight())))->toBe(['n2-in', 'n2-out'])
        ->and(cwinIds(CorrectionWindow::replaceable('2026-09-23', $rows, cwinNoBreakNight())))->toBe(['n1-in', 'n1-out']);

    // A changed Time In replaces only that row; Sep 23 is never touched.
    $save = cwinSave('2026-09-24', $rows, cwinNoBreakNight(), cwinSlots('22:05', timeOut: '06:00'), cwinTicks(timeOut: true));
    expect($save['plan']['keep'])->toBe(['n2-out'])
        ->and($save['plan']['delete'])->toBe(['n2-in'])
        ->and($save['plan']['insert'])->toBe([['log_type' => 'TIME_IN', 'logged_at' => '2026-09-24T22:05:00']])
        ->and($save['error'])->toBeNull();

    // Clearing every slot of Sep 24 takes Sep 24 off the record and leaves Sep 23 whole.
    $cleared = cwinSave('2026-09-24', $rows, cwinNoBreakNight(), cwinSlots(null), cwinTicks());
    expect($cleared['plan']['delete'])->toBe(['n2-in', 'n2-out'])
        ->and($cleared['error'])->toBeNull();
});

it('covers every next-morning punch a break night groups with it', function () {
    // The row runs past the next day's first Time In (the 03:00 Second Time
    // In) to the 06:00 end, so a re-save supersedes 03:00 and 06:00 rather
    // than inserting them twice.
    $rows = cwinBackToBackBreakNights();

    expect(cwinIds(CorrectionWindow::replaceable('2026-09-24', $rows, cwinBreakNight())))->toBe(['n2-in', 'n2-bo', 'n2-bi', 'n2-out'])
        ->and(cwinIds(CorrectionWindow::replaceable('2026-09-23', $rows, cwinBreakNight())))->toBe(['n1-in', 'n1-bo', 'n1-bi', 'n1-out']);
});

/*
|--------------------------------------------------------------------------
| Which shifts may hold a next-day time
|--------------------------------------------------------------------------
*/

it('allows a next-day time only on an overnight or flexible shift', function () {
    expect(CorrectionWindow::allowsNextDay(cwinNoBreakNight()))->toBeTrue()
        ->and(CorrectionWindow::allowsNextDay(cwinBreakNight()))->toBeTrue()
        // Its stored 08:00–17:00 never cross midnight, and must not refuse a flexible night.
        ->and(CorrectionWindow::allowsNextDay(cwinFlexible()))->toBeTrue()
        ->and(CorrectionWindow::allowsNextDay(cwinDayShift()))->toBeFalse()
        // Not rostered: the default 08:00–17:00 day.
        ->and(CorrectionWindow::allowsNextDay(null))->toBeFalse();
});

it('refuses a next-day tick on a day shift or an unrostered employee with the shift roster pointer', function () {
    expect(CorrectionWindow::nextDayError('2026-09-23', cwinDayShift(), cwinTicks(timeOut: true)))
        ->toBe('The shift on Sep 23, 2026 (8:00 AM – 5:00 PM) ends the same day, so a Next day time would show on '
            .'Sep 24, 2026 instead. Assign an overnight shift in Shift Roster first.');

    // The rostered hours are the ones quoted.
    expect(CorrectionWindow::nextDayError('2026-09-23', new DomainShift(startTime: '07:00:00', endTime: '16:00:00'), cwinTicks(breakOut: true, breakIn: true)))
        ->toBe('The shift on Sep 23, 2026 (7:00 AM – 4:00 PM) ends the same day, so a Next day time would show on '
            .'Sep 24, 2026 instead. Assign an overnight shift in Shift Roster first.');

    expect(CorrectionWindow::nextDayError('2026-09-23', null, cwinTicks(timeOut: true)))
        ->toBe('This employee has no shift in Shift Roster, and the default 8:00 AM – 5:00 PM day ends the same day, '
            .'so a Next day time would show on Sep 24, 2026 instead. Assign an overnight shift in Shift Roster first.');
});

it('has no next-day refusal when nothing is ticked or the shift allows it', function () {
    expect(CorrectionWindow::nextDayError('2026-09-23', cwinDayShift(), PunchSlots::SAME_DAY))->toBeNull()
        ->and(CorrectionWindow::nextDayError('2026-09-23', null, PunchSlots::SAME_DAY))->toBeNull()
        ->and(CorrectionWindow::nextDayError('2026-09-23', cwinNoBreakNight(), cwinTicks(timeOut: true)))->toBeNull()
        ->and(CorrectionWindow::nextDayError('2026-09-23', cwinBreakNight(), cwinTicks(breakOut: true, breakIn: true, timeOut: true)))->toBeNull()
        ->and(CorrectionWindow::nextDayError('2026-09-23', cwinFlexible(), cwinTicks(timeOut: true)))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Keeping untouched punches
|--------------------------------------------------------------------------
*/

it('keeps a row whose type and floor-minute equal a slot and ignores its seconds', function () {
    $existing = [
        cwinLog('TIME_IN', '2026-09-02T08:00:31', 'in'),
        cwinLog('TIME_OUT', '2026-09-02T16:00:05', 'out'),
    ];
    $desired = PunchSlots::punches('2026-09-02', cwinSlots('08:00', timeOut: '17:00'), PunchSlots::SAME_DAY);

    $plan = CorrectionWindow::reconcile($existing, $desired);

    expect($plan['keep'])->toBe(['in'])
        ->and($plan['delete'])->toBe(['out'])
        ->and($plan['insert'])->toBe([['log_type' => 'TIME_OUT', 'logged_at' => '2026-09-02T17:00:00']])
        // What stands, in slot order: the kept row's REAL stamp, then the insert.
        ->and($plan['standing'])->toBe([
            ['log_type' => 'TIME_IN', 'logged_at' => '2026-09-02T08:00:31'],
            ['log_type' => 'TIME_OUT', 'logged_at' => '2026-09-02T17:00:00'],
        ])
        // The same walk by id: the kept row, then null for the insert.
        ->and($plan['claimed'])->toBe(['in', null]);
});

it('replaces a row whose minute matches but whose type differs', function () {
    $existing = [cwinLog('TIME_OUT', '2026-09-02T08:00:20', 'out')];
    $desired = PunchSlots::punches('2026-09-02', cwinSlots('08:00'), PunchSlots::SAME_DAY);

    $plan = CorrectionWindow::reconcile($existing, $desired);

    expect($plan['keep'])->toBe([])
        ->and($plan['delete'])->toBe(['out'])
        ->and($plan['insert'])->toBe([['log_type' => 'TIME_IN', 'logged_at' => '2026-09-02T08:00:00']])
        ->and($plan['claimed'])->toBe([null]);
});

it('keeps the first of two rows in the same minute and deletes the duplicate', function () {
    // A double tap: the later one is the duplicate, whatever order they arrive in.
    $existing = [
        cwinLog('TIME_IN', '2026-09-02T08:00:40', 'later'),
        cwinLog('TIME_IN', '2026-09-02T08:00:10', 'earlier'),
    ];
    $desired = PunchSlots::punches('2026-09-02', cwinSlots('08:00'), PunchSlots::SAME_DAY);

    $plan = CorrectionWindow::reconcile($existing, $desired);

    expect($plan['keep'])->toBe(['earlier'])
        ->and($plan['delete'])->toBe(['later'])
        ->and($plan['insert'])->toBe([])
        ->and($plan['claimed'])->toBe(['earlier']);
});

it('matches a next-day row against a slot ticked Next day, and only that', function () {
    // A 22:00 night: the Time Out is entered as 06:01 with Next day ticked,
    // so it is stamped on the next date, where the row lives — same day key,
    // same minute.
    $existing = [
        cwinLog('TIME_IN', '2026-09-02T22:00:15', 'in'),
        cwinLog('TIME_OUT', '2026-09-03T06:01:40', 'out'),
    ];
    $slots = cwinSlots('22:00', timeOut: '06:01');

    $plan = CorrectionWindow::reconcile($existing, PunchSlots::punches('2026-09-02', $slots, cwinTicks(timeOut: true)));

    expect($plan['keep'])->toBe(['in', 'out'])
        ->and($plan['delete'])->toBe([])
        ->and($plan['insert'])->toBe([])
        ->and($plan['claimed'])->toBe(['in', 'out']);

    // The day is part of the minute: the same 06:01 unticked is the morning
    // of Sep 2, which is not the stored row, so nothing guesses it back.
    $unticked = CorrectionWindow::reconcile($existing, PunchSlots::punches('2026-09-02', $slots, PunchSlots::SAME_DAY));

    expect($unticked['keep'])->toBe(['in'])
        ->and($unticked['delete'])->toBe(['out'])
        ->and($unticked['insert'])->toBe([['log_type' => 'TIME_OUT', 'logged_at' => '2026-09-02T06:01:00']])
        ->and($unticked['claimed'])->toBe(['in', null]);
});

it('deletes everything when every slot is cleared', function () {
    $existing = [
        cwinLog('TIME_IN', '2026-09-02T08:00:00', 'in'),
        cwinLog('TIME_OUT', '2026-09-02T17:00:00', 'out'),
    ];

    $plan = CorrectionWindow::reconcile($existing, []);

    expect($plan['keep'])->toBe([])
        ->and($plan['delete'])->toBe(['in', 'out'])
        ->and($plan['insert'])->toBe([])
        ->and($plan['claimed'])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The saved punches must read back as the row
|--------------------------------------------------------------------------
*/

it('lets an unchanged re-save of a break night keep all four punches', function () {
    // Between two break nights, so three of the previous night's punches
    // share the corrected calendar day.
    $save = cwinSave('2026-09-24', cwinBackToBackBreakNights(), cwinBreakNight(), cwinSlots('22:00', '02:00', '03:00', '06:00'), cwinTicks(breakOut: true, breakIn: true, timeOut: true));

    expect($save['plan']['keep'])->toBe(['n2-in', 'n2-bo', 'n2-bi', 'n2-out'])
        ->and($save['plan']['delete'])->toBe([])
        ->and($save['plan']['insert'])->toBe([])
        ->and($save['error'])->toBeNull();
});

it('refuses a punch on the exact moment of another punch on record', function () {
    // The previous night's Time Out, stored as UTC, is 06:00:00 Manila on
    // Sep 24 — the same instant as a 06:00 Time In typed for Sep 24. The DTR
    // reads rows in no fixed order, so which row each belongs to would be chance.
    $rows = [
        cwinLog('TIME_IN', '2026-09-23T14:00:00.000Z', 'n1-in'),
        cwinLog('TIME_OUT', '2026-09-23T22:00:00.000Z', 'n1-out'),
    ];

    $save = cwinSave('2026-09-24', $rows, cwinNoBreakNight(), cwinSlots('06:00', timeOut: '14:00'), cwinTicks());

    expect($save['error'])->toBe('First Time In 6:00 AM is the exact moment of another punch on record. Use a different minute.');
});

it('refuses a saved punch the previous night would read as its own', function () {
    // A break night on Sep 23 whose 03:00 Second Time In was never punched.
    // Typed on Sep 24 without the tick, 03:00 still falls inside Sep 23's
    // night, so the DTR would show it there, not on Sep 24.
    $rows = [
        cwinLog('TIME_IN', '2026-09-23T22:00:00', 'n1-in'),
        cwinLog('TIME_OUT', '2026-09-24T02:00:00', 'n1-bo'),
    ];

    $save = cwinSave('2026-09-24', $rows, cwinBreakNight(), cwinSlots('03:00'), cwinTicks());

    expect($save['plan']['insert'])->toBe([['log_type' => 'TIME_IN', 'logged_at' => '2026-09-24T03:00:00']])
        ->and($save['error'])->toBe('First Time In 3:00 AM would read as part of the Sep 23, 2026 record, not this one. '
            .'If it was after midnight, tick Next day beside it; if it belongs to Sep 23, 2026, correct that date instead.');
});

it('refuses a next-day time in on a shift that carries only a time out', function () {
    // No scheduled break window: the next morning is carried only up to its
    // first Time In, so a next-day Second Time In (and everything after it)
    // would open the Sep 25 row instead.
    foreach (['no-break night' => cwinNoBreakNight(), 'flexible' => cwinFlexible()] as $label => $shift) {
        $save = cwinSave('2026-09-24', [], $shift, cwinSlots('22:00', '23:30', '00:30', '06:00'), cwinTicks(breakIn: true, timeOut: true));

        expect($save['error'])->toBe('Second Time In 12:30 AM next day would be read as part of the Sep 25, 2026 record, not this date\'s. '
            .'Check the times and the Next day boxes. On this shift only a Time Out can fall on the next day.', $label);
    }
});

it('refuses a next-day punch the next date would read as its own, without the time out hint where it does not apply', function () {
    // A break night carries next-day punches up to its 06:00 end; a Time In
    // after that opens Sep 25. The shift CAN carry a Time In, so no hint.
    $breakNight = cwinSave('2026-09-24', [], cwinBreakNight(), cwinSlots('22:00', '02:00', '07:00', '08:00'), cwinTicks(breakOut: true, breakIn: true, timeOut: true));

    expect($breakNight['error'])->toBe('Second Time In 7:00 AM next day would be read as part of the Sep 25, 2026 record, not this date\'s. '
        .'Check the times and the Next day boxes.');

    // Sep 25 already opens with a 05:00 Time In, so a 06:00 Out ticked Next
    // day lands after it and closes Sep 25's pair, not Sep 24's. A Time Out,
    // so no hint either.
    $rows = [
        cwinLog('TIME_IN', '2026-09-25T05:00:00', 'next-in'),
        cwinLog('TIME_OUT', '2026-09-25T13:00:00', 'next-out'),
    ];
    $noBreak = cwinSave('2026-09-24', $rows, cwinNoBreakNight(), cwinSlots('22:00', timeOut: '06:00'), cwinTicks(timeOut: true));

    expect($noBreak['error'])->toBe('Final Time Out 6:00 AM next day would be read as part of the Sep 25, 2026 record, not this date\'s. '
        .'Check the times and the Next day boxes.');
});

it('refuses a save that would take a punch away from the previous night', function () {
    // A 05:00 Time In on Sep 24 comes before Sep 23's 06:00 Out, so the
    // previous night stops carrying it and Sep 24 would swallow it.
    $save = cwinSave('2026-09-24', array_slice(cwinBackToBackNights(), 0, 2), cwinNoBreakNight(), cwinSlots('05:00', timeOut: '13:00'), cwinTicks());

    expect($save['plan']['delete'])->toBe([])
        ->and($save['error'])->toBe('Saving would take the Time Out at 6:00 AM away from the Sep 23, 2026 record. '
            .'Correct Sep 23, 2026 instead, or check the times.');
});

it('refuses a save that would pull a punch in from the next date', function () {
    // Flexible: a day left on an open Time In carries the next morning up to
    // its first Time In, so Sep 25's stray 01:00 Out would join Sep 24.
    $rows = [cwinLog('TIME_OUT', '2026-09-25T01:00:00', 'stray-out')];

    expect(cwinIds(CorrectionWindow::replaceable('2026-09-24', $rows, cwinFlexible())))->toBe([]);

    $save = cwinSave('2026-09-24', $rows, cwinFlexible(), cwinSlots('22:00'), cwinTicks());

    expect($save['error'])->toBe('Saving would also pull the Time Out at 1:00 AM next day from the Sep 25, 2026 record into this date. '
        .'Correct Sep 25, 2026 first.');
});

it('refuses a change that leaves a break night ending on an open next-day time in', function () {
    // Clearing the Final Time Out: Sep 25 would read the 03:00 Second Time In
    // as its own Time In (a rest day after it turns INCOMPLETE).
    $rows = array_slice(cwinBackToBackBreakNights(), 4);

    $save = cwinSave('2026-09-24', $rows, cwinBreakNight(), cwinSlots('22:00', '02:00', '03:00'), cwinTicks(breakOut: true, breakIn: true));

    expect($save['plan']['delete'])->toBe(['n2-out'])
        ->and($save['error'])->toBe('Record the Final Time Out too — without it the DTR reads Second Time In 3:00 AM next day '
            .'as the Sep 25, 2026 record\'s Time In.');
});

it('lets a night already ending on an open next-day time in be saved unchanged', function () {
    // Recorded that way at the station: a save that changes nothing (a
    // remark) must still go through.
    $rows = array_slice(cwinBackToBackBreakNights(), 4, 3);

    $save = cwinSave('2026-09-24', $rows, cwinBreakNight(), cwinSlots('22:00', '02:00', '03:00'), cwinTicks(breakOut: true, breakIn: true));

    expect($save['plan']['keep'])->toBe(['n2-in', 'n2-bo', 'n2-bi'])
        ->and($save['plan']['delete'])->toBe([])
        ->and($save['plan']['insert'])->toBe([])
        ->and($save['error'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The one minute definition
|--------------------------------------------------------------------------
*/

it('reads the same minute for a Manila stamp and its UTC form', function () {
    expect(PunchSlots::minuteKey('2026-09-02T08:00:31'))->toBe('2026-09-02T08:00')
        ->and(PunchSlots::minuteKey('2026-09-02T00:00:31.000Z'))->toBe('2026-09-02T08:00')
        // Floored, never rounded: 59.9 seconds is still that minute.
        ->and(PunchSlots::minuteKey('2026-09-02T08:00:59.900Z'))->toBe('2026-09-02T16:00');

    expect(PunchSlots::slotOf('2026-01-05T07:47:31'))->toBe('07:47')
        ->and(PunchSlots::slotOf('2026-01-04T23:47:31.000Z'))->toBe('07:47')
        ->and(PunchSlots::slotOf(null))->toBeNull()
        ->and(PunchSlots::slotOf(''))->toBeNull();
});

it('keeps the eDTR slot helper forwarding to the domain rule', function () {
    foreach (['2026-01-05T07:47:31', '2026-01-04T23:47:31.000Z', '2026-09-02T16:00:59.999Z'] as $iso) {
        expect(Hris::slotOf($iso))->toBe(PunchSlots::slotOf($iso));
    }
    expect(Hris::slotOf(null))->toBeNull();
});
