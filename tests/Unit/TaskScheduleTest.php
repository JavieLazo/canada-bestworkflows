<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectPolicy;
use App\Modules\WorkManagement\Domain\Tasks\TaskSchedule;

/**
 * A task's planned start date is optional, a real calendar day in storage
 * form, and falls inside its project's window, on or before its own due date.
 *
 * isDateKey() accepts only an exact 'Y-m-d' key for a day that exists — the
 * client-writable property behind the date field may carry anything else.
 * startDateError() lets a blank start through, then names the edge actually
 * crossed in a fixed order: the project start, the project deadline (only when
 * there is one), then the task's own due date, with ProjectPolicy::dateError's
 * wording. Both window edges are inclusive. fitsWindow() is what a project date
 * change must not break: every non-blank task date inside the window.
 */

/** A project window: 1 Sep 2026 to 31 Dec 2026. */
function tschedStart(): string
{
    return '2026-09-01';
}

function tschedDue(): string
{
    return '2026-12-31';
}

/* |---| isDateKey |---| */

it('accepts a real calendar day in storage form', function (string $value) {
    expect(TaskSchedule::isDateKey($value))->toBeTrue();
})->with([
    'an ordinary day' => '2026-09-25',
    'new year' => '2026-01-01',
    'new year eve' => '2026-12-31',
    'a leap day' => '2028-02-29',
    'the 30th of a 30-day month' => '2026-04-30',
]);

it('refuses anything that is not a real calendar day in storage form', function (string $value) {
    expect(TaskSchedule::isDateKey($value))->toBeFalse();
})->with([
    'empty' => '',
    'blank' => '   ',
    'the 30th of February' => '2026-02-30',
    'a leap day in a common year' => '2026-02-29',
    'the 31st of a 30-day month' => '2026-04-31',
    'month 13' => '2026-13-01',
    'month zero' => '2026-00-10',
    'day zero' => '2026-09-00',
    'display form' => '20 Aug 2026',
    'slashed form' => '20/08/2026',
    'unpadded key' => '2026-9-5',
    'leading space' => ' 2026-09-25',
    'trailing space' => '2026-09-25 ',
    'a timestamp' => '2026-09-25 08:00:00',
    'an ISO instant' => '2026-09-25T00:00:00Z',
    'a word' => 'tomorrow',
]);

/* |---| startDateError |---| */

it('lets a task have no planned start, whatever the other dates say', function (?string $start) {
    expect(TaskSchedule::startDateError($start, null, tschedStart(), tschedDue()))->toBeNull()
        // Even with a due date before the project and an inverted window.
        ->and(TaskSchedule::startDateError($start, '2020-01-01', '2026-12-31', '2026-01-01'))->toBeNull();
})->with([
    'null' => null,
    'empty' => '',
]);

it('refuses a start before the project starts, and allows one on its first day', function () {
    expect(TaskSchedule::startDateError('2026-08-31', null, tschedStart(), tschedDue()))->toBe('A task cannot start before the project starts.')
        ->and(TaskSchedule::startDateError('2025-12-31', '2026-10-01', tschedStart(), null))->toBe('A task cannot start before the project starts.')
        ->and(TaskSchedule::startDateError('2026-09-01', null, tschedStart(), tschedDue()))->toBeNull()
        ->and(TaskSchedule::startDateError('2026-09-02', null, tschedStart(), tschedDue()))->toBeNull();
});

it('refuses a start after the project deadline, and allows one on the deadline itself', function () {
    expect(TaskSchedule::startDateError('2027-01-01', null, tschedStart(), tschedDue()))->toBe('A task cannot start after the project deadline.')
        ->and(TaskSchedule::startDateError('2026-12-31', null, tschedStart(), tschedDue()))->toBeNull()
        ->and(TaskSchedule::startDateError('2026-12-30', '2026-12-31', tschedStart(), tschedDue()))->toBeNull();
});

it('has no upper edge for a project without a deadline', function (?string $projectDue) {
    expect(TaskSchedule::startDateError('2099-06-30', null, tschedStart(), $projectDue))->toBeNull()
        ->and(TaskSchedule::startDateError('2099-06-30', '2099-07-01', tschedStart(), $projectDue))->toBeNull()
        // The lower edge still holds.
        ->and(TaskSchedule::startDateError('2026-08-31', null, tschedStart(), $projectDue))->toBe('A task cannot start before the project starts.');
})->with([
    'null' => null,
    'empty' => '',
]);

it('has no lower edge for a project without a start', function () {
    expect(TaskSchedule::startDateError('2000-01-01', null, '', tschedDue()))->toBeNull()
        ->and(TaskSchedule::startDateError('2027-01-01', null, '', tschedDue()))->toBe('A task cannot start after the project deadline.');
});

it('refuses a task due before it starts with the reference date wording', function () {
    $message = ProjectPolicy::dateError('2026-10-10', '2026-10-09');

    expect($message)->toBe('The due date cannot fall before the start date.')
        ->and(TaskSchedule::startDateError('2026-10-10', '2026-10-09', tschedStart(), tschedDue()))->toBe($message)
        ->and(TaskSchedule::startDateError('2026-10-10', '2026-09-01', tschedStart(), null))->toBe($message)
        // Due the same day it starts, later, or not at all: fine.
        ->and(TaskSchedule::startDateError('2026-10-10', '2026-10-10', tschedStart(), tschedDue()))->toBeNull()
        ->and(TaskSchedule::startDateError('2026-10-10', '2026-10-11', tschedStart(), tschedDue()))->toBeNull()
        ->and(TaskSchedule::startDateError('2026-10-10', null, tschedStart(), tschedDue()))->toBeNull()
        ->and(TaskSchedule::startDateError('2026-10-10', '', tschedStart(), tschedDue()))->toBeNull();
});

it('names the project start first, then the deadline, then the task due date', function () {
    // Before the project start AND due before the start.
    expect(TaskSchedule::startDateError('2026-08-15', '2026-08-10', tschedStart(), tschedDue()))->toBe('A task cannot start before the project starts.')
        // After the deadline AND due before the start.
        ->and(TaskSchedule::startDateError('2027-02-01', '2027-01-15', tschedStart(), tschedDue()))->toBe('A task cannot start after the project deadline.')
        // An inverted window: both edges crossed, the start edge is named.
        ->and(TaskSchedule::startDateError('2026-09-05', null, '2026-09-10', '2026-09-01'))->toBe('A task cannot start before the project starts.');
});

/* |---| fitsWindow |---| */

it('fits a task with no dates into any window', function () {
    foreach ([null, ''] as $blank) {
        expect(TaskSchedule::fitsWindow($blank, $blank, tschedStart(), tschedDue()))->toBeTrue()
            ->and(TaskSchedule::fitsWindow($blank, null, '2026-12-31', '2026-01-01'))->toBeTrue()
            ->and(TaskSchedule::fitsWindow(null, $blank, tschedStart(), null))->toBeTrue();
    }
});

it('fits a start on the first day and a due date on the deadline, and nothing a day beyond', function () {
    expect(TaskSchedule::fitsWindow('2026-09-01', '2026-12-31', tschedStart(), tschedDue()))->toBeTrue()
        ->and(TaskSchedule::fitsWindow('2026-08-31', '2026-12-31', tschedStart(), tschedDue()))->toBeFalse()
        ->and(TaskSchedule::fitsWindow('2026-09-01', '2027-01-01', tschedStart(), tschedDue()))->toBeFalse()
        // Each date alone.
        ->and(TaskSchedule::fitsWindow('2026-09-01', null, tschedStart(), tschedDue()))->toBeTrue()
        ->and(TaskSchedule::fitsWindow('2026-08-31', null, tschedStart(), tschedDue()))->toBeFalse()
        ->and(TaskSchedule::fitsWindow(null, '2026-12-31', tschedStart(), tschedDue()))->toBeTrue()
        ->and(TaskSchedule::fitsWindow(null, '2027-01-01', tschedStart(), tschedDue()))->toBeFalse();
});

it('holds both task dates to both window edges', function () {
    // A start after the deadline, or a due date before the project starts.
    expect(TaskSchedule::fitsWindow('2027-01-01', null, tschedStart(), tschedDue()))->toBeFalse()
        ->and(TaskSchedule::fitsWindow('2026-12-31', null, tschedStart(), tschedDue()))->toBeTrue()
        ->and(TaskSchedule::fitsWindow(null, '2026-08-31', tschedStart(), tschedDue()))->toBeFalse()
        ->and(TaskSchedule::fitsWindow(null, '2026-09-01', tschedStart(), tschedDue()))->toBeTrue()
        // One date out is enough, whichever it is.
        ->and(TaskSchedule::fitsWindow('2026-10-01', '2027-03-01', tschedStart(), tschedDue()))->toBeFalse()
        ->and(TaskSchedule::fitsWindow('2026-08-01', '2026-10-01', tschedStart(), tschedDue()))->toBeFalse();
});

it('has no upper edge without a project deadline and no lower edge without a project start', function () {
    foreach ([null, ''] as $noDeadline) {
        expect(TaskSchedule::fitsWindow('2099-01-01', '2099-12-31', tschedStart(), $noDeadline))->toBeTrue()
            ->and(TaskSchedule::fitsWindow('2026-08-31', '2099-12-31', tschedStart(), $noDeadline))->toBeFalse();
    }

    expect(TaskSchedule::fitsWindow('2000-01-01', '2000-02-01', '', tschedDue()))->toBeTrue()
        ->and(TaskSchedule::fitsWindow('2000-01-01', '2027-01-01', '', tschedDue()))->toBeFalse()
        ->and(TaskSchedule::fitsWindow('2000-01-01', '2099-01-01', '', null))->toBeTrue();
});

it('checks only the window, leaving the start-before-due rule to startDateError', function () {
    // Both dates inside the window but in the wrong order: the window still
    // fits; the order is refused where the task dates are set.
    expect(TaskSchedule::fitsWindow('2026-10-10', '2026-10-01', tschedStart(), tschedDue()))->toBeTrue()
        ->and(TaskSchedule::startDateError('2026-10-10', '2026-10-01', tschedStart(), tschedDue()))->toBe('The due date cannot fall before the start date.');
});
