<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\Shift;
use App\Modules\Timekeeping\Domain\Attendance\PunchWorkDate;
use App\Support\LocalClock;

/** Weekday changes preserve the night-start date and never invent a previous night. */
it('uses the previous weekday night for its morning punches before a regular shift', function () {
    $schedule = Shift::default()->withWeekdayShifts([
        1 => new Shift(startTime: '22:00:00', endTime: '06:00:00'),
    ]);

    foreach (['02:30:00', '06:00:00'] as $time) {
        expect(PunchWorkDate::forScheduledInstant(LocalClock::instant('2026-09-22T'.$time), $schedule))
            ->toBe('2026-09-21');
    }
    expect(PunchWorkDate::forScheduledInstant(LocalClock::instant('2026-09-22T07:00:00'), $schedule))
        ->toBe('2026-09-22');
});

it('resolves a late final out from the actual after-midnight second in', function () {
    $schedule = Shift::default()->withWeekdayShifts([
        1 => new Shift(startTime: '22:00:00', endTime: '06:00:00'),
    ]);
    $logs = [
        new DomainLog('TIME_IN', '2026-09-22T02:30:00'),
        new DomainLog('TIME_IN', '2026-09-21T22:00:00'),
        new DomainLog('TIME_OUT', '2026-09-22T02:00:00'),
    ];

    expect(PunchWorkDate::forScheduledPunch($logs, 'TIME_OUT', LocalClock::instant('2026-09-22T07:30:00'), $schedule))
        ->toBe('2026-09-21');
});

it('does not assign a day-to-night transition morning to a night that never started', function () {
    $schedule = Shift::default()->withWeekdayShifts([
        2 => new Shift(startTime: '22:00:00', endTime: '06:00:00'),
    ]);

    expect(PunchWorkDate::forScheduledInstant(LocalClock::instant('2026-09-22T05:30:00'), $schedule))
        ->toBe('2026-09-22')
        ->and(PunchWorkDate::forScheduledPunch([
            new DomainLog('TIME_IN', '2026-09-21T08:00:00'),
        ], 'TIME_OUT', LocalClock::instant('2026-09-22T06:30:00'), $schedule))
        ->toBe('2026-09-21');
});

it('starts Monday on its own date after an ordinary Sunday rest day', function () {
    $schedule = Shift::default()->withWeekdayShifts([0 => Shift::restDay()]);

    expect(PunchWorkDate::forScheduledInstant(LocalClock::instant('2026-09-21T07:00:00'), $schedule))
        ->toBe('2026-09-21');
});
