<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog;
use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\Shift;
use App\Domain\Attendance\ShiftResolver;
use App\Support\ManilaClock;

/**
 * DtrCalculator::rowPunches() — the stored punches the DTR reads as ONE
 * date's row, which is exactly what a correction of that row replaces. It
 * must agree with run()'s own carry dispatch on all three carry paths:
 *
 *   - a fixed night WITHOUT a break: the next morning up to its first Time In,
 *     sorted BEFORE the slice, with "no Time In" carrying everything and
 *     "opens with a Time In" carrying nothing;
 *   - a night with a SCHEDULED BREAK (22:00–06:00, break 02:00–03:00): the
 *     shift timeline, so a next-day Time In can still belong to the night;
 *   - FLEXIBLE: across midnight only when the day ends on an open Time In.
 *
 * A fixed day shift and a missing shift never carry. The previous night's
 * after-midnight punches stay off the next row, every punch belongs to
 * exactly one row, and the row hands back the very objects it was given —
 * the carry bookkeeping is by spl_object_id, so a copy would silently break
 * it. OWNING IS NOT SHOWING: the one known gap (an unclosed break night
 * lends the next row its Second Time In) is pinned last, so any change to it
 * is deliberate.
 */

/**
 * Every weekday, so no fixture reads a rest day by accident.
 *
 * @return list<int>
 */
function rowpEveryDay(): array
{
    return [0, 1, 2, 3, 4, 5, 6];
}

/** A fixed 22:00–06:00 night with no break window: the sort-then-slice carry. */
function rowpNight(): Shift
{
    return new Shift(startTime: '22:00:00', endTime: '06:00:00', graceMinutes: 15, mode: Shift::MODE_FIXED, workDays: rowpEveryDay());
}

/** The seeded Night Shift's shape — 22:00–06:00, break 02:00–03:00 — the timeline carry. */
function rowpBreakNight(): Shift
{
    return new Shift(
        startTime: '22:00:00',
        endTime: '06:00:00',
        graceMinutes: 15,
        mode: Shift::MODE_FIXED,
        workDays: rowpEveryDay(),
        breakStart: '02:00:00',
        breakEnd: '03:00:00',
        breakMinutes: 60,
    );
}

/** FLEXIBLE with the 08:00–17:00 filler times ShiftPolicy stores: the open-Time-In carry. */
function rowpFlexible(): Shift
{
    return new Shift(startTime: '08:00:00', endTime: '17:00:00', graceMinutes: 15, mode: Shift::MODE_FLEXIBLE, requiredHours: 8.0, workDays: rowpEveryDay());
}

/** A fixed 08:00–17:00 day: never overnight, never flexible, so it never carries. */
function rowpDayShift(): Shift
{
    return new Shift(startTime: '08:00:00', endTime: '17:00:00', graceMinutes: 15, mode: Shift::MODE_FIXED, workDays: rowpEveryDay());
}

/** A punch as the engine sees it: an offset-less Manila stamp unless the test says otherwise. */
function rowpLog(string $type, string $stamp, string $id): AttendanceLog
{
    return new AttendanceLog($type, $stamp, $id);
}

/**
 * @param  list<array{0: string, 1: string, 2: string}>  $rows  [log type, stamp, id]
 * @return list<AttendanceLog>
 */
function rowpLogs(array $rows): array
{
    return array_map(static fn (array $row): AttendanceLog => rowpLog($row[0], $row[1], $row[2]), $rows);
}

/**
 * @param  list<AttendanceLog>  $logs
 * @return list<string>
 */
function rowpIds(array $logs): array
{
    return array_map(static fn (AttendanceLog $log): string => (string) $log->id, $logs);
}

/**
 * @param  list<AttendanceLog>  $logs
 * @return list<string>
 */
function rowpRow(array $logs, ?Shift $shift, string $date): array
{
    return rowpIds(DtrCalculator::rowPunches($logs, $shift, $date));
}

/** The punch's Manila calendar day — what the engine buckets it by. */
function rowpDayOf(AttendanceLog $log): string
{
    return ManilaClock::dayKey(ManilaClock::instant($log->loggedAt));
}

/**
 * Five calendar days (Sep 21–25, 2026) of punches for one carry path, so
 * rows Sep 22–24 each have both neighbours on record. Sep 23 is the middle
 * day every path puts punches on from BOTH sides of midnight.
 *
 * @return array{0: ?Shift, 1: list<AttendanceLog>}
 */
function rowpFixture(string $path): array
{
    $night = [
        ['TIME_IN', '2026-09-21T22:00:00', 'n21-in'], ['TIME_OUT', '2026-09-22T06:00:00', 'n21-out'],
        ['TIME_IN', '2026-09-22T22:00:00', 'n22-in'], ['TIME_OUT', '2026-09-23T06:00:00', 'n22-out'],
        // A stray Out before the evening's Time In: still the previous night's, by the slice.
        ['TIME_OUT', '2026-09-23T12:00:00', 'n22-stray'],
        ['TIME_IN', '2026-09-23T22:00:00', 'n23-in'], ['TIME_OUT', '2026-09-24T06:00:00', 'n23-out'],
        ['TIME_IN', '2026-09-24T22:00:00', 'n24-in'], ['TIME_OUT', '2026-09-25T06:00:00', 'n24-out'],
    ];

    $breakNight = [
        // The Sep 21 night starts after midnight — its first Time In is a next-day punch.
        ['TIME_IN', '2026-09-22T00:30:00', 'b21-in'], ['TIME_OUT', '2026-09-22T02:00:00', 'b21-bo'],
        ['TIME_IN', '2026-09-22T03:00:00', 'b21-bi'], ['TIME_OUT', '2026-09-22T06:00:00', 'b21-out'],
        ['TIME_IN', '2026-09-22T22:00:00', 'b22-in'], ['TIME_OUT', '2026-09-23T02:00:00', 'b22-bo'],
        ['TIME_IN', '2026-09-23T03:00:00', 'b22-bi'], ['TIME_OUT', '2026-09-23T06:00:00', 'b22-out'],
        ['TIME_IN', '2026-09-23T22:00:00', 'b23-in'], ['TIME_OUT', '2026-09-24T02:00:00', 'b23-bo'],
        ['TIME_IN', '2026-09-24T03:00:00', 'b23-bi'], ['TIME_OUT', '2026-09-24T06:00:00', 'b23-out'],
        ['TIME_IN', '2026-09-24T22:00:00', 'b24-in'], ['TIME_OUT', '2026-09-25T02:00:00', 'b24-bo'],
        ['TIME_IN', '2026-09-25T03:00:00', 'b24-bi'], ['TIME_OUT', '2026-09-25T06:00:00', 'b24-out'],
    ];

    $breakNightLeftOpen = [
        ['TIME_IN', '2026-09-21T22:00:00', 'b21-in'], ['TIME_OUT', '2026-09-22T02:00:00', 'b21-bo'],
        ['TIME_IN', '2026-09-22T03:00:00', 'b21-bi'], ['TIME_OUT', '2026-09-22T06:00:00', 'b21-out'],
        // The Sep 22 night never records its Final Time Out.
        ['TIME_IN', '2026-09-22T22:00:00', 'b22-in'], ['TIME_OUT', '2026-09-23T02:00:00', 'b22-bo'],
        ['TIME_IN', '2026-09-23T03:00:00', 'b22-bi'],
        ['TIME_IN', '2026-09-23T22:00:00', 'b23-in'], ['TIME_OUT', '2026-09-24T02:00:00', 'b23-bo'],
        ['TIME_IN', '2026-09-24T03:00:00', 'b23-bi'], ['TIME_OUT', '2026-09-24T06:00:00', 'b23-out'],
        ['TIME_IN', '2026-09-24T22:00:00', 'b24-in'], ['TIME_OUT', '2026-09-25T02:00:00', 'b24-bo'],
        ['TIME_IN', '2026-09-25T03:00:00', 'b24-bi'], ['TIME_OUT', '2026-09-25T06:00:00', 'b24-out'],
    ];

    $flexible = [
        ['TIME_IN', '2026-09-21T20:00:00', 'f21-in'], ['TIME_OUT', '2026-09-22T02:00:00', 'f21-out'],
        // Ends on an open Time In: its Out on Sep 23 is carried back.
        ['TIME_IN', '2026-09-22T21:00:00', 'f22-in'], ['TIME_OUT', '2026-09-23T05:00:00', 'f22-out'],
        // Closed the same day: nothing carried.
        ['TIME_IN', '2026-09-23T13:00:00', 'f23-in'], ['TIME_OUT', '2026-09-23T19:00:00', 'f23-out'],
        ['TIME_IN', '2026-09-24T08:00:00', 'f24-in'], ['TIME_OUT', '2026-09-24T17:00:00', 'f24-out'],
        ['TIME_IN', '2026-09-24T22:00:00', 'f24-in2'], ['TIME_OUT', '2026-09-25T03:00:00', 'f24-out2'],
    ];

    $days = [
        ['TIME_IN', '2026-09-22T08:00:00', 'd22-in'], ['TIME_OUT', '2026-09-22T17:00:00', 'd22-out'],
        // An after-midnight Out that a night shift would carry back — a day shift never does.
        ['TIME_OUT', '2026-09-23T00:30:00', 'd23-early'],
        ['TIME_IN', '2026-09-23T08:00:00', 'd23-in'], ['TIME_OUT', '2026-09-23T12:00:00', 'd23-bo'],
        ['TIME_IN', '2026-09-23T13:00:00', 'd23-bi'], ['TIME_OUT', '2026-09-23T17:00:00', 'd23-out'],
        ['TIME_IN', '2026-09-24T08:00:00', 'd24-in'], ['TIME_OUT', '2026-09-24T17:00:00', 'd24-out'],
    ];

    return match ($path) {
        'fixed night without a break' => [rowpNight(), rowpLogs($night)],
        'night with a scheduled break' => [rowpBreakNight(), rowpLogs($breakNight)],
        'night with a scheduled break left open' => [rowpBreakNight(), rowpLogs($breakNightLeftOpen)],
        'flexible' => [rowpFlexible(), rowpLogs($flexible)],
        'fixed day shift' => [rowpDayShift(), rowpLogs($days)],
        'no shift' => [null, rowpLogs($days)],
        default => throw new InvalidArgumentException("Unknown carry path {$path}."),
    };
}

/*
|--------------------------------------------------------------------------
| The fixtures exercise the path they are named for
|--------------------------------------------------------------------------
*/

it('uses shift fixtures that take each carry path', function () {
    // The timeline carry runs only when the scheduled break window resolves.
    expect(ShiftResolver::scheduledBreakWindow(rowpBreakNight()))->toBe([1560.0, 1620.0])
        ->and(ShiftResolver::scheduledBreakWindow(rowpNight()))->toBeNull()
        ->and(ShiftResolver::geometry(rowpNight())->overnight)->toBeTrue()
        ->and(ShiftResolver::geometry(rowpFlexible())->overnight)->toBeFalse()
        ->and(rowpFlexible()->isFlexible())->toBeTrue()
        ->and(ShiftResolver::geometry(rowpDayShift())->overnight)->toBeFalse()
        ->and(ShiftResolver::geometry(null)->overnight)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| A fixed night without a break — sort, then slice at the first Time In
|--------------------------------------------------------------------------
*/

it('gives a fixed night the evening and the next morning up to its first time in, sorting before it slices', function () {
    // Sep 24 is handed in newest first: sliced before sorting, its 22:00
    // Time In would come first and the 06:00 Time Out would be carried by
    // nobody's night.
    $logs = rowpLogs([
        ['TIME_IN', '2026-09-23T22:00:00', 'eve-in'],
        ['TIME_IN', '2026-09-24T22:00:00', 'next-eve-in'],
        ['TIME_OUT', '2026-09-24T06:00:00', 'morn-out'],
    ]);

    expect(rowpRow($logs, rowpNight(), '2026-09-23'))->toBe(['eve-in', 'morn-out'])
        ->and(rowpRow($logs, rowpNight(), '2026-09-24'))->toBe(['next-eve-in']);
});

it('carries the whole next morning when it holds no time in, and none of it when it opens with one', function () {
    // array_search === false (no Time In) is a slice length of null — carry
    // all — never 0, which carries none.
    $noTimeIn = rowpLogs([
        ['TIME_IN', '2026-09-23T22:00:00', 'eve-in'],
        ['TIME_OUT', '2026-09-24T06:00:00', 'a'],
        ['TIME_OUT', '2026-09-24T07:30:00', 'b'],
    ]);
    $opensWithTimeIn = rowpLogs([
        ['TIME_IN', '2026-09-23T22:00:00', 'eve-in'],
        ['TIME_OUT', '2026-09-24T23:00:00', 'y'],
        ['TIME_IN', '2026-09-24T21:00:00', 'x'],
    ]);

    expect(rowpRow($noTimeIn, rowpNight(), '2026-09-23'))->toBe(['eve-in', 'a', 'b'])
        ->and(rowpRow($noTimeIn, rowpNight(), '2026-09-24'))->toBe([])
        ->and(rowpRow($opensWithTimeIn, rowpNight(), '2026-09-23'))->toBe(['eve-in'])
        ->and(rowpRow($opensWithTimeIn, rowpNight(), '2026-09-24'))->toBe(['y', 'x'])
        ->and(rowpRow([rowpLog('TIME_IN', '2026-09-23T22:00:00', 'eve-in')], rowpNight(), '2026-09-23'))->toBe(['eve-in']);
});

it('keeps the previous night\'s carried time out off the next row', function () {
    // Out of order, so the previous date's slice has to sort too.
    $logs = rowpLogs([
        ['TIME_IN', '2026-09-23T22:00:00', 'eve-in'],
        ['TIME_OUT', '2026-09-23T06:00:00', 'prev-out'],
        ['TIME_IN', '2026-09-22T22:00:00', 'prev-in'],
        ['TIME_OUT', '2026-09-24T06:00:00', 'morn-out'],
    ]);

    expect(rowpRow($logs, rowpNight(), '2026-09-23'))->toBe(['eve-in', 'morn-out'])
        ->and(rowpRow($logs, rowpNight(), '2026-09-22'))->toBe(['prev-in', 'prev-out']);
});

it('buckets stored UTC stamps by their Manila day, not their UTC day', function () {
    // The same night as stored rows: 06:00 Manila is 22:00Z the day BEFORE,
    // so a UTC bucket would put the previous night's Time Out on Sep 22.
    $logs = rowpLogs([
        ['TIME_IN', '2026-09-22T14:00:00.000Z', 'prev-in'],   // Sep 22 22:00 Manila
        ['TIME_OUT', '2026-09-22T22:00:00.000Z', 'prev-out'], // Sep 23 06:00 Manila
        ['TIME_IN', '2026-09-23T14:00:00.000Z', 'eve-in'],    // Sep 23 22:00 Manila
        ['TIME_OUT', '2026-09-23T22:00:00.000Z', 'morn-out'], // Sep 24 06:00 Manila
    ]);

    expect(rowpRow($logs, rowpNight(), '2026-09-23'))->toBe(['eve-in', 'morn-out'])
        ->and(rowpRow($logs, rowpNight(), '2026-09-22'))->toBe(['prev-in', 'prev-out']);
});

it('reads an after-midnight time in on a night without a break as the next date\'s', function () {
    // The slice stops at the next day's first Time In, so on this shift only
    // a Time Out can fall on the next day.
    $logs = rowpLogs([
        ['TIME_IN', '2026-09-24T00:30:00', 'late-in'],
        ['TIME_OUT', '2026-09-24T06:00:00', 'late-out'],
    ]);

    expect(rowpRow($logs, rowpNight(), '2026-09-23'))->toBe([])
        ->and(rowpRow($logs, rowpNight(), '2026-09-24'))->toBe(['late-in', 'late-out']);
});

/*
|--------------------------------------------------------------------------
| A night with a scheduled break — the shift timeline
|--------------------------------------------------------------------------
*/

it('gives a break night its next-morning second time in, which still belongs to the night', function () {
    $logs = rowpLogs([
        // The previous night's after-midnight half, on Sep 23.
        ['TIME_IN', '2026-09-22T22:00:00', 'prev-in'],
        ['TIME_OUT', '2026-09-23T02:00:00', 'prev-bo'],
        ['TIME_IN', '2026-09-23T03:00:00', 'prev-bi'],
        ['TIME_OUT', '2026-09-23T06:00:00', 'prev-out'],
        // This night.
        ['TIME_IN', '2026-09-23T22:00:00', 'eve-in'],
        ['TIME_OUT', '2026-09-24T02:00:00', 'brk-out'],
        ['TIME_IN', '2026-09-24T03:00:00', 'brk-in'],
        ['TIME_OUT', '2026-09-24T06:00:00', 'fin-out'],
        // The next night's Time In is past the shift end: the timeline stops there.
        ['TIME_IN', '2026-09-24T22:00:00', 'next-in'],
    ]);

    expect(rowpRow($logs, rowpBreakNight(), '2026-09-23'))->toBe(['eve-in', 'brk-out', 'brk-in', 'fin-out'])
        ->and(rowpRow($logs, rowpBreakNight(), '2026-09-22'))->toBe(['prev-in', 'prev-bo', 'prev-bi', 'prev-out'])
        ->and(rowpRow($logs, rowpBreakNight(), '2026-09-24'))->toBe(['next-in']);

    // This night's punches without the break window stop at the 03:00 Time
    // In — the reason the timeline path exists.
    $thisNight = array_values(array_filter(
        $logs,
        static fn (AttendanceLog $log): bool => ! str_starts_with((string) $log->id, 'prev-'),
    ));
    expect(rowpRow($thisNight, rowpNight(), '2026-09-23'))->toBe(['eve-in', 'brk-out']);
});

it('gives a break night a first time in that falls after midnight', function () {
    $logs = rowpLogs([
        ['TIME_IN', '2026-09-24T00:30:00', 'late-in'],
        ['TIME_OUT', '2026-09-24T02:00:00', 'brk-out'],
        ['TIME_IN', '2026-09-24T03:00:00', 'brk-in'],
        ['TIME_OUT', '2026-09-24T06:00:00', 'fin-out'],
        ['TIME_IN', '2026-09-24T22:00:00', 'next-in'],
    ]);

    expect(rowpRow($logs, rowpBreakNight(), '2026-09-23'))->toBe(['late-in', 'brk-out', 'brk-in', 'fin-out'])
        ->and(rowpRow($logs, rowpBreakNight(), '2026-09-24'))->toBe(['next-in']);

    // And the computed Sep 23 row reads it as its First Time In.
    $day = DtrCalculator::compute($logs, rowpBreakNight(), [], '2026-09-23', '2026-09-23')[0];
    expect($day->timeIn)->toBe('2026-09-24T00:30:00')
        ->and($day->timeOut)->toBe('2026-09-24T06:00:00');
});

/*
|--------------------------------------------------------------------------
| FLEXIBLE — across midnight only after an open Time In
|--------------------------------------------------------------------------
*/

it('carries a flexible day across midnight only when it ends on an open time in', function () {
    // Open at midnight; Sep 24 handed in out of order, so the carry sorts
    // before it slices at the next Time In.
    $open = rowpLogs([
        ['TIME_IN', '2026-09-23T20:00:00', 'in'],
        ['TIME_IN', '2026-09-24T09:00:00', 'next-in'],
        ['TIME_OUT', '2026-09-24T17:00:00', 'next-out'],
        ['TIME_OUT', '2026-09-24T02:00:00', 'out'],
    ]);
    // Closed the same day: a stray after-midnight Out stays on its own date.
    $closed = rowpLogs([
        ['TIME_IN', '2026-09-23T08:00:00', 'in'],
        ['TIME_OUT', '2026-09-23T17:00:00', 'out'],
        ['TIME_OUT', '2026-09-24T01:00:00', 'stray'],
    ]);

    expect(rowpRow($open, rowpFlexible(), '2026-09-23'))->toBe(['in', 'out'])
        ->and(rowpRow($open, rowpFlexible(), '2026-09-24'))->toBe(['next-in', 'next-out'])
        ->and(rowpRow($closed, rowpFlexible(), '2026-09-23'))->toBe(['in', 'out'])
        ->and(rowpRow($closed, rowpFlexible(), '2026-09-24'))->toBe(['stray']);
});

/*
|--------------------------------------------------------------------------
| A day shift, or no shift at all, never carries
|--------------------------------------------------------------------------
*/

it('never carries across midnight on a fixed day shift or with no shift at all', function (?Shift $shift) {
    $logs = rowpLogs([
        ['TIME_OUT', '2026-09-23T00:30:00', 'early'],
        ['TIME_IN', '2026-09-23T08:00:00', 'in'],
        ['TIME_OUT', '2026-09-23T17:00:00', 'out'],
        ['TIME_OUT', '2026-09-24T00:30:00', 'stray'],
        ['TIME_IN', '2026-09-24T08:00:00', 'next-in'],
    ]);

    expect(rowpRow($logs, $shift, '2026-09-23'))->toBe(['early', 'in', 'out'])
        ->and(rowpRow($logs, $shift, '2026-09-24'))->toBe(['stray', 'next-in']);
})->with([
    'fixed day shift' => [rowpDayShift()],
    'no shift (the 08:00–17:00 default)' => [null],
]);

/*
|--------------------------------------------------------------------------
| Every punch belongs to exactly one row, and a row shows only its own
|--------------------------------------------------------------------------
*/

it('puts every punch of a date in exactly one row', function (string $path) {
    [$shift, $logs] = rowpFixture($path);
    // Newest first, the way EdtrPage loads them: ownership must not depend on order.
    $logs = array_reverse($logs);

    $rows = [
        DtrCalculator::rowPunches($logs, $shift, '2026-09-22'),
        DtrCalculator::rowPunches($logs, $shift, '2026-09-23'),
        DtrCalculator::rowPunches($logs, $shift, '2026-09-24'),
    ];

    /** @var array<int, int> $holders */
    $holders = [];
    foreach ($rows as $row) {
        foreach ($row as $log) {
            // The same objects as handed in, never copies.
            expect(in_array($log, $logs, true))->toBeTrue();
            $holders[spl_object_id($log)] = ($holders[spl_object_id($log)] ?? 0) + 1;
        }
    }

    // Sep 23's punches can only be owned by the Sep 22 or Sep 23 row, and
    // Sep 24's by the Sep 23 or Sep 24 row: all of those rows are read here,
    // so each such punch must be held exactly once.
    $checked = 0;
    foreach ($logs as $log) {
        if (in_array(rowpDayOf($log), ['2026-09-23', '2026-09-24'], true)) {
            expect($holders[spl_object_id($log)] ?? 0)->toBe(1, "{$log->id} must belong to exactly one row");
            $checked++;
        }
    }

    // And no punch anywhere is held by two of the three rows.
    expect($checked)->toBeGreaterThan(3)
        ->and(array_values(array_unique($holders)))->toBe([1]);
})->with([
    'fixed night without a break',
    'night with a scheduled break',
    'night with a scheduled break left open',
    'flexible',
    'fixed day shift',
    'no shift',
]);

it('shows in a computed row only the punches that row owns', function (string $path) {
    [$shift, $logs] = rowpFixture($path);

    $shown = 0;
    foreach (DtrCalculator::compute($logs, $shift, [], '2026-09-22', '2026-09-24') as $day) {
        $owned = array_map(
            static fn (AttendanceLog $log): string => $log->loggedAt,
            DtrCalculator::rowPunches($logs, $shift, $day->date),
        );

        foreach (['timeIn' => $day->timeIn, 'breakOut' => $day->breakOut, 'breakIn' => $day->breakIn, 'timeOut' => $day->timeOut] as $slot => $stamp) {
            if ($stamp === null) {
                continue;
            }

            expect(in_array($stamp, $owned, true))->toBeTrue("{$day->date} shows {$slot} {$stamp}, which it does not own");
            $shown++;
        }
    }

    expect($shown)->toBeGreaterThan(5);
})->with([
    'fixed night without a break',
    'night with a scheduled break',
    'flexible',
    'fixed day shift',
    'no shift',
]);

it('hands back the very objects it was given, never copies', function () {
    $eveIn = rowpLog('TIME_IN', '2026-09-23T22:00:00', 'eve-in');
    $brkOut = rowpLog('TIME_OUT', '2026-09-24T02:00:00', 'brk-out');
    $brkIn = rowpLog('TIME_IN', '2026-09-24T03:00:00', 'brk-in');
    $finOut = rowpLog('TIME_OUT', '2026-09-24T06:00:00', 'fin-out');
    $logs = [$finOut, $brkIn, $eveIn, $brkOut];

    $timeline = DtrCalculator::rowPunches($logs, rowpBreakNight(), '2026-09-23');
    $slice = DtrCalculator::rowPunches($logs, rowpNight(), '2026-09-23');
    $flexible = DtrCalculator::rowPunches([$eveIn, $finOut], rowpFlexible(), '2026-09-23');

    // toBe on objects is identity (assertSame), not equality.
    expect($timeline)->toHaveCount(4)
        ->and($timeline[0])->toBe($eveIn)
        ->and($timeline[1])->toBe($brkOut)
        ->and($timeline[2])->toBe($brkIn)
        ->and($timeline[3])->toBe($finOut)
        ->and($slice)->toHaveCount(2)
        ->and($slice[0])->toBe($eveIn)
        ->and($slice[1])->toBe($brkOut)
        ->and($flexible)->toHaveCount(2)
        ->and($flexible[0])->toBe($eveIn)
        ->and($flexible[1])->toBe($finOut);
});

/*
|--------------------------------------------------------------------------
| KNOWN QUIRK — owning is not showing
|--------------------------------------------------------------------------
*/

it('pins the known gap: an unclosed break night shows the next row a time in it does not own', function () {
    // 22:00 In, 02:00 Out, 03:00 In, and no Final Time Out. The timeline
    // gives all three to Sep 23. run() still merges Sep 24's whole calendar
    // day into the Sep 24 row and hides only carried Time OUTs, so the
    // borrowed 03:00 Time In reads there as Sep 24's First Time In too, and
    // turns that day INCOMPLETE. A correction replaces what a row OWNS, so
    // the Sep 24 dialog must flag the slot rather than save it back. If this
    // starts failing, the engine changed: update DayCorrections' borrowed
    // slot, CorrectionWindow::misattribution and this pin together.
    $logs = rowpLogs([
        ['TIME_IN', '2026-09-23T22:00:00', 'eve-in'],
        ['TIME_OUT', '2026-09-24T02:00:00', 'brk-out'],
        ['TIME_IN', '2026-09-24T03:00:00', 'brk-in'],
    ]);

    expect(rowpRow($logs, rowpBreakNight(), '2026-09-23'))->toBe(['eve-in', 'brk-out', 'brk-in'])
        ->and(rowpRow($logs, rowpBreakNight(), '2026-09-24'))->toBe([]);

    $alone = DtrCalculator::compute($logs, rowpBreakNight(), [], '2026-09-24', '2026-09-24')[0];
    expect($alone->timeIn)->toBe('2026-09-24T03:00:00')
        ->and($alone->status)->toBe(DayRecord::INCOMPLETE);

    // Computed with the night before, both rows show the same punch.
    [$night, $next] = DtrCalculator::compute($logs, rowpBreakNight(), [], '2026-09-23', '2026-09-24');
    expect($night->timeIn)->toBe('2026-09-23T22:00:00')
        ->and($night->breakOut)->toBe('2026-09-24T02:00:00')
        ->and($night->breakIn)->toBe('2026-09-24T03:00:00')
        ->and($night->timeOut)->toBeNull()
        ->and($next->timeIn)->toBe('2026-09-24T03:00:00');
});
