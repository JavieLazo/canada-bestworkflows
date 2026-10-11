<?php

declare(strict_types=1);

use App\Domain\Attendance\Shift;
use App\Modules\Timekeeping\Domain\Roster\ScheduleBasis;
use App\Modules\Timekeeping\Domain\Roster\ScheduleTimeline;

/**
 * A dated schedule history answers each date with the parts in effect THAT
 * day: the default shift, each weekday's hours and each weekday's place
 * resolve independently, the latest entry on or before the date wins (the
 * baseline, dated NULL, first), and a later save on the same date wins over
 * an earlier one.
 */
/** @param  list<int>|null  $workDays */
function tlShift(string $start, string $end, ?array $workDays = null): Shift
{
    return Shift::fromArray(['start_time' => $start, 'end_time' => $end, 'grace_minutes' => 0, 'mode' => 'FIXED', 'work_days' => $workDays ?? [1, 2, 3, 4, 5]]);
}

function tlWeekday(int $weekday, string $start, string $end): Shift
{
    return tlShift($start, $end, [$weekday]);
}

it('resolves the default shift in effect on each date, the baseline first', function () {
    $timeline = new ScheduleTimeline([
        ['from' => '2026-10-05', 'order' => '02', 'shift' => tlShift('22:00:00', '06:00:00')],
        ['from' => null, 'order' => '01', 'shift' => tlShift('08:00:00', '17:00:00')],
    ]);

    expect($timeline->scheduleAt('2026-09-01')?->startTime)->toBe('08:00:00')
        ->and($timeline->scheduleAt('2026-10-04')?->startTime)->toBe('08:00:00')
        ->and($timeline->scheduleAt('2026-10-05')?->startTime)->toBe('22:00:00')
        ->and($timeline->scheduleAt('2027-01-01')?->startTime)->toBe('22:00:00');
});

it('lets the later save win two entries dated the same day', function () {
    $timeline = new ScheduleTimeline([
        ['from' => '2026-10-05', 'order' => '09', 'shift' => tlShift('07:00:00', '16:00:00')],
        ['from' => '2026-10-05', 'order' => '03', 'shift' => tlShift('22:00:00', '06:00:00')],
    ]);

    expect($timeline->scheduleAt('2026-10-05')?->startTime)->toBe('07:00:00')
        ->and($timeline->scheduleAt('2026-10-04'))->toBeNull();
});

it('keeps each weekday on its own history, independent of a default-shift change', function () {
    $timeline = new ScheduleTimeline(
        [
            ['from' => null, 'order' => '01', 'shift' => tlShift('08:00:00', '17:00:00')],
            ['from' => '2026-10-12', 'order' => '05', 'shift' => tlShift('09:00:00', '18:00:00')],
        ],
        [
            ['from' => '2026-10-01', 'order' => '02', 'weekday' => 3, 'shift' => tlWeekday(3, '22:00:00', '06:00:00')],
            // Wednesday back to the default shift from the 21st.
            ['from' => '2026-10-21', 'order' => '07', 'weekday' => 3, 'shift' => null],
        ],
    );

    // Wednesday 2026-09-30: before any weekday entry.
    expect($timeline->scheduleAt('2026-09-30')?->forDate('2026-09-30')->startTime)->toBe('08:00:00')
        // Wednesday 2026-10-07: the weekday night shift.
        ->and($timeline->scheduleAt('2026-10-07')?->forDate('2026-10-07')->startTime)->toBe('22:00:00')
        // Wednesday 2026-10-14: still the weekday night, although the default moved on the 12th.
        ->and($timeline->scheduleAt('2026-10-14')?->forDate('2026-10-14')->startTime)->toBe('22:00:00')
        // Thursday 2026-10-15: the new default.
        ->and($timeline->scheduleAt('2026-10-15')?->forDate('2026-10-15')->startTime)->toBe('09:00:00')
        // Wednesday 2026-10-21: back on the default shift in effect then.
        ->and($timeline->scheduleAt('2026-10-21')?->forDate('2026-10-21')->startTime)->toBe('09:00:00')
        ->and($timeline->scheduleAt('2026-10-21')?->weekdayShifts)->toBe([]);
});

it('keeps the engine default for other weekdays when only weekday hours were ever set, and null with nothing at all', function () {
    $timeline = new ScheduleTimeline([], [['from' => null, 'order' => '01', 'weekday' => 6, 'shift' => tlWeekday(6, '09:00:00', '13:00:00')]]);

    expect($timeline->scheduleAt('2026-10-03')?->forDate('2026-10-03')->startTime)->toBe('09:00:00')
        ->and($timeline->scheduleAt('2026-10-02')?->forDate('2026-10-02')->startTime)->toBe('08:00:00')
        ->and((new ScheduleTimeline)->scheduleAt('2026-10-02'))->toBeNull()
        ->and((new ScheduleTimeline([], [['from' => null, 'order' => '01', 'weekday' => 6, 'shift' => null]]))->scheduleAt('2026-10-03'))->toBeNull();
});

it('resolves weekday places by date, a kind-less entry meaning the assigned worksite', function () {
    $timeline = new ScheduleTimeline([], [], [
        ['from' => null, 'order' => '01', 'weekday' => 1, 'kind' => 'WFH', 'site' => null],
        ['from' => '2026-10-12', 'order' => '02', 'weekday' => 1, 'kind' => 'ONSITE', 'site' => 'site-9'],
        ['from' => '2026-10-19', 'order' => '03', 'weekday' => 1, 'kind' => null, 'site' => null],
    ]);

    expect($timeline->placeAt('2026-10-05'))->toBe(['kind' => 'WFH', 'site' => null])
        ->and($timeline->placeAt('2026-10-12'))->toBe(['kind' => 'ONSITE', 'site' => 'site-9'])
        ->and($timeline->placeAt('2026-10-19'))->toBeNull()
        ->and($timeline->placeAt('2026-10-13'))->toBeNull();
});

it('lists the dates inside a range on which the hours change, never the range start itself', function () {
    $timeline = new ScheduleTimeline(
        [['from' => '2026-10-05', 'order' => '01', 'shift' => tlShift('08:00:00', '17:00:00')], ['from' => '2026-10-20', 'order' => '03', 'shift' => tlShift('09:00:00', '18:00:00')]],
        [['from' => '2026-10-07', 'order' => '02', 'weekday' => 3, 'shift' => null]],
    );

    expect($timeline->hourChangesWithin('2026-10-05', '2026-10-31'))->toBe(['2026-10-07', '2026-10-20'])
        ->and($timeline->hourChangesWithin('2026-10-01', '2026-10-06'))->toBe(['2026-10-05'])
        ->and($timeline->hourChangesWithin('2026-10-21', '2026-10-31'))->toBe([]);
});

it('names the part that decides a date: weekday hours, else the default shift, else nothing', function () {
    $timeline = new ScheduleTimeline(
        [['from' => '2026-10-05', 'order' => '01', 'shift' => tlShift('08:00:00', '17:00:00')]],
        [
            ['from' => '2026-10-05', 'order' => '02', 'weekday' => 2, 'shift' => tlWeekday(2, '13:00:00', '22:00:00')],
            // Tuesday goes back to the default shift from the 13th.
            ['from' => '2026-10-13', 'order' => '03', 'weekday' => 2, 'shift' => null],
        ],
    );

    // Before anything was assigned: nobody's schedule, whatever the weekday.
    expect($timeline->partAt('2026-10-02'))->toBe(ScheduleBasis::SOURCE_NONE)
        ->and($timeline->partAt('2026-10-05'))->toBe(ScheduleBasis::SOURCE_DEFAULT)
        ->and($timeline->partAt('2026-10-06'))->toBe(ScheduleBasis::SOURCE_WEEKLY)
        // A rest day of the default shift is still the default shift's answer.
        ->and($timeline->partAt('2026-10-10'))->toBe(ScheduleBasis::SOURCE_DEFAULT)
        ->and($timeline->partAt('2026-10-13'))->toBe(ScheduleBasis::SOURCE_DEFAULT)
        ->and((new ScheduleTimeline)->partAt('2026-10-06'))->toBe(ScheduleBasis::SOURCE_NONE)
        // Weekday hours alone leave every other weekday unassigned.
        ->and((new ScheduleTimeline([], [['from' => null, 'order' => '01', 'weekday' => 2, 'shift' => tlWeekday(2, '13:00:00', '22:00:00')]]))->partAt('2026-10-07'))->toBe(ScheduleBasis::SOURCE_NONE);
});
