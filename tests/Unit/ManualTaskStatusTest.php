<?php

declare(strict_types=1);

use App\Domain\Projects\TaskPolicy;
use App\Modules\WorkManagement\Domain\Tasks\ManualTaskStatus;

it('keeps review cancellation and reopening deliberate while ordinary moves can settle immediately', function () {
    expect(ManualTaskStatus::needsDecision('PENDING', 'IN_PROGRESS'))->toBeFalse()
        ->and(ManualTaskStatus::needsDecision('IN_PROGRESS', 'PENDING'))->toBeFalse()
        ->and(ManualTaskStatus::needsDecision('SUBMITTED', 'IN_PROGRESS'))->toBeTrue()
        ->and(ManualTaskStatus::needsDecision('IN_PROGRESS', 'COMPLETED'))->toBeTrue()
        ->and(ManualTaskStatus::needsDecision('PENDING', 'CANCELLED'))->toBeTrue()
        ->and(ManualTaskStatus::needsDecision('CANCELLED', 'PENDING'))->toBeTrue();
});

/**
 * A task status set BY HAND moves along a map keyed by the CURRENT status,
 * never a flat list of targets, and never leaves the task contradicting its
 * own facts.
 *
 * Both maps (GROUP / no mode, and INDIVIDUAL) are pinned here as literals for
 * every one of the 36 pairs, so an edit to the constants cannot quietly agree
 * with itself; an unrecognised mode moves nowhere and SUBMITTED is never a
 * target. The refusals are checked branch by branch AND in their order: an
 * unsupported target, then the map, then the reopen gate (only a closed task
 * inside a COMPLETED or CANCELLED project), then PENDING after a hand-in,
 * RETURNED without one, then an INDIVIDUAL completion gap. writes() stamps a
 * closing move as a decision, clears the stale completion/hand-in stamps, and
 * clears the undone decision's note only on a reopen.
 */

/** @return list<string> */
function mtsStatuses(): array
{
    return ['PENDING', 'IN_PROGRESS', 'SUBMITTED', 'RETURNED', 'COMPLETED', 'CANCELLED'];
}

/**
 * The hand-set GROUP map (and a null mode).
 *
 * @return array<string, list<string>>
 */
function mtsGroupMap(): array
{
    return [
        'PENDING' => ['IN_PROGRESS', 'COMPLETED', 'CANCELLED'],
        'IN_PROGRESS' => ['PENDING', 'COMPLETED', 'CANCELLED'],
        'SUBMITTED' => ['RETURNED', 'COMPLETED', 'CANCELLED'],
        'RETURNED' => ['IN_PROGRESS', 'COMPLETED', 'CANCELLED'],
        'COMPLETED' => ['PENDING', 'IN_PROGRESS', 'RETURNED', 'CANCELLED'],
        'CANCELLED' => ['PENDING', 'IN_PROGRESS'],
    ];
}

/**
 * The hand-set INDIVIDUAL map: the task never enters SUBMITTED or RETURNED.
 *
 * @return array<string, list<string>>
 */
function mtsIndividualMap(): array
{
    return [
        'PENDING' => ['IN_PROGRESS', 'CANCELLED'],
        'IN_PROGRESS' => ['PENDING', 'COMPLETED', 'CANCELLED'],
        'SUBMITTED' => [],
        'RETURNED' => [],
        'COMPLETED' => ['PENDING', 'IN_PROGRESS', 'CANCELLED'],
        'CANCELLED' => ['PENDING', 'IN_PROGRESS'],
    ];
}

/**
 * Keys sorted, values untouched — so writes() is compared strictly without
 * pinning the order its columns are assembled in.
 *
 * @param  array<string, int|string|null>  $columns
 * @return array<string, int|string|null>
 */
function mtsSorted(array $columns): array
{
    ksort($columns);

    return $columns;
}

/** moveError() with facts that trip no gate but the map. */
function mtsPlainError(string $from, string $to, ?string $mode): ?string
{
    return ManualTaskStatus::moveError($from, $to, $mode, $to === 'RETURNED', 'ACTIVE', null);
}

/* |---| The maps |---| */

it('moves a GROUP or mode-less task by hand exactly along its map, for all 36 pairs', function () {
    $pairs = 0;
    foreach (mtsStatuses() as $from) {
        foreach ([null, 'GROUP'] as $mode) {
            expect(ManualTaskStatus::canMoveTo($from, $mode))->toBe(mtsGroupMap()[$from], "{$from} ".($mode ?? 'null'));
        }
        foreach (mtsStatuses() as $to) {
            $allowed = in_array($to, mtsGroupMap()[$from], true);
            foreach ([null, 'GROUP'] as $mode) {
                expect(ManualTaskStatus::canMove($from, $to, $mode))->toBe($allowed, "{$from}→{$to} ".($mode ?? 'null'))
                    ->and(mtsPlainError($from, $to, $mode))->toBe($allowed ? null : TaskPolicy::transitionError(), "{$from}→{$to} ".($mode ?? 'null'));
            }
            $pairs++;
        }
    }

    expect($pairs)->toBe(36)
        ->and(ManualTaskStatus::TRANSITIONS)->toBe(mtsGroupMap());
});

it('moves an INDIVIDUAL task by hand exactly along its own map, for all 36 pairs', function () {
    $pairs = 0;
    foreach (mtsStatuses() as $from) {
        expect(ManualTaskStatus::canMoveTo($from, 'INDIVIDUAL'))->toBe(mtsIndividualMap()[$from], $from);
        foreach (mtsStatuses() as $to) {
            $allowed = in_array($to, mtsIndividualMap()[$from], true);
            expect(ManualTaskStatus::canMove($from, $to, 'INDIVIDUAL'))->toBe($allowed, "{$from}→{$to}")
                ->and(mtsPlainError($from, $to, 'INDIVIDUAL'))->toBe($allowed ? null : TaskPolicy::transitionError(), "{$from}→{$to}");
            $pairs++;
        }
    }

    // SUBMITTED and RETURNED live on each member's response: an INDIVIDUAL
    // task is never moved into them, nor out of them.
    $targets = array_merge(...array_values(ManualTaskStatus::INDIVIDUAL_TRANSITIONS));
    expect($pairs)->toBe(36)
        ->and(ManualTaskStatus::INDIVIDUAL_TRANSITIONS)->toBe(mtsIndividualMap())
        ->and($targets)->not->toContain('SUBMITTED')
        ->and($targets)->not->toContain('RETURNED')
        ->and(ManualTaskStatus::canMoveTo('SUBMITTED', 'INDIVIDUAL'))->toBe([])
        ->and(ManualTaskStatus::canMoveTo('RETURNED', 'INDIVIDUAL'))->toBe([]);
});

it('moves a task nowhere under an unrecognised mode', function (string $mode) {
    foreach (mtsStatuses() as $from) {
        expect(ManualTaskStatus::canMoveTo($from, $mode))->toBe([], $from);
        foreach (mtsStatuses() as $to) {
            expect(ManualTaskStatus::canMove($from, $to, $mode))->toBeFalse("{$from}→{$to}")
                ->and(mtsPlainError($from, $to, $mode))->toBe(TaskPolicy::transitionError(), "{$from}→{$to}");
        }
    }
})->with([
    'empty' => '',
    'lower-case group' => 'group',
    'lower-case individual' => 'individual',
    'a member state' => 'NONE',
    'junk' => 'BOGUS',
]);

it('moves a task nowhere from an unrecognised current status', function (?string $mode) {
    foreach (['', 'pending', 'APPROVED', 'BOGUS'] as $from) {
        expect(ManualTaskStatus::canMoveTo($from, $mode))->toBe([], $from);
        foreach (mtsStatuses() as $to) {
            expect(ManualTaskStatus::canMove($from, $to, $mode))->toBeFalse("{$from}→{$to}")
                ->and(mtsPlainError($from, $to, $mode))->toBe(TaskPolicy::transitionError(), "{$from}→{$to}");
        }
    }
})->with([
    'no mode' => null,
    'GROUP' => 'GROUP',
    'INDIVIDUAL' => 'INDIVIDUAL',
]);

it('never sets SUBMITTED by hand, whatever the mode, status or facts', function () {
    $targets = array_merge(
        ...array_values(ManualTaskStatus::TRANSITIONS),
        ...array_values(ManualTaskStatus::INDIVIDUAL_TRANSITIONS),
    );
    expect($targets)->not->toContain('SUBMITTED');

    foreach ([null, 'GROUP', 'INDIVIDUAL'] as $mode) {
        foreach (mtsStatuses() as $from) {
            foreach ([true, false] as $handedIn) {
                expect(ManualTaskStatus::canMove($from, 'SUBMITTED', $mode))->toBeFalse()
                    ->and(ManualTaskStatus::moveError($from, 'SUBMITTED', $mode, $handedIn, 'ACTIVE', null))
                    ->toBe(TaskPolicy::transitionError(), "{$from}→SUBMITTED ".($mode ?? 'null'));
            }
        }
    }
});

it('never refuses by hand a move the review buttons already make', function () {
    // The hand-set maps widen the reference manager maps; they never narrow
    // them, so "Set status" and the decision buttons cannot disagree.
    foreach ([null, 'GROUP', 'INDIVIDUAL'] as $mode) {
        foreach (mtsStatuses() as $from) {
            foreach (TaskPolicy::managerCanMoveTo($from, $mode) as $to) {
                expect(ManualTaskStatus::canMove($from, $to, $mode))->toBeTrue("{$from}→{$to} ".($mode ?? 'null'));
            }
        }
    }
});

/* |---| Review moves and hand-ins |---| */

it('counts completing, returning, and undoing a hand-in or an acceptance as a review move, for all 36 pairs', function () {
    expect(ManualTaskStatus::DECISIONS)->toBe(['COMPLETED', 'RETURNED'])
        ->and(ManualTaskStatus::DECIDED)->toBe(['SUBMITTED', 'COMPLETED']);

    $pairs = 0;
    foreach (mtsStatuses() as $from) {
        foreach (mtsStatuses() as $to) {
            $review = in_array($to, ['COMPLETED', 'RETURNED'], true) || in_array($from, ['SUBMITTED', 'COMPLETED'], true);
            expect(ManualTaskStatus::isReviewMove($from, $to))->toBe($review, "{$from}→{$to}");
            $pairs++;
        }
    }

    // The ordinary running of a task is not a review move.
    expect($pairs)->toBe(36)
        ->and(ManualTaskStatus::isReviewMove('PENDING', 'IN_PROGRESS'))->toBeFalse()
        ->and(ManualTaskStatus::isReviewMove('IN_PROGRESS', 'PENDING'))->toBeFalse()
        ->and(ManualTaskStatus::isReviewMove('RETURNED', 'IN_PROGRESS'))->toBeFalse()
        ->and(ManualTaskStatus::isReviewMove('IN_PROGRESS', 'CANCELLED'))->toBeFalse()
        ->and(ManualTaskStatus::isReviewMove('CANCELLED', 'PENDING'))->toBeFalse()
        // Undoing an acceptance or a hand-in is.
        ->and(ManualTaskStatus::isReviewMove('COMPLETED', 'IN_PROGRESS'))->toBeTrue()
        ->and(ManualTaskStatus::isReviewMove('COMPLETED', 'CANCELLED'))->toBeTrue()
        ->and(ManualTaskStatus::isReviewMove('SUBMITTED', 'CANCELLED'))->toBeTrue();
});

it('reads a hand-in from the task row for GROUP and from response rows for INDIVIDUAL', function () {
    foreach ([null, 'GROUP', 'BOGUS'] as $mode) {
        expect(ManualTaskStatus::handedIn($mode, true, 0))->toBeTrue(($mode ?? 'null').' row')
            ->and(ManualTaskStatus::handedIn($mode, false, 0))->toBeFalse(($mode ?? 'null').' none')
            // Response rows mean nothing to a GROUP task.
            ->and(ManualTaskStatus::handedIn($mode, false, 3))->toBeFalse(($mode ?? 'null').' stray rows');
    }

    expect(ManualTaskStatus::handedIn('INDIVIDUAL', false, 1))->toBeTrue()
        ->and(ManualTaskStatus::handedIn('INDIVIDUAL', false, 4))->toBeTrue()
        ->and(ManualTaskStatus::handedIn('INDIVIDUAL', false, 0))->toBeFalse()
        // A stale GROUP stamp on the row means nothing to an INDIVIDUAL task.
        ->and(ManualTaskStatus::handedIn('INDIVIDUAL', true, 0))->toBeFalse();
});

/* |---| moveError, branch by branch and in order |---| */

it('refuses an unsupported target before anything else', function () {
    foreach (['', 'pending', 'APPROVED', 'NONE', 'BOGUS'] as $to) {
        expect(ManualTaskStatus::moveError('PENDING', $to, null, false, 'ACTIVE', null))->toBe('That task status is not supported.', $to)
            // Even from an unknown status, under an unknown mode, in a closed
            // project, with a completion gap waiting.
            ->and(ManualTaskStatus::moveError('BOGUS', $to, 'BOGUS', true, 'COMPLETED', 'gap'))->toBe('That task status is not supported.', $to);
    }
});

it('refuses a move outside the map with the reference transition error', function () {
    expect(TaskPolicy::transitionError())->toBe('That task cannot move to this status from its current state.')
        // Staying put is not a move.
        ->and(ManualTaskStatus::moveError('PENDING', 'PENDING', null, false, 'ACTIVE', null))->toBe(TaskPolicy::transitionError())
        ->and(ManualTaskStatus::moveError('CANCELLED', 'COMPLETED', 'GROUP', true, 'ACTIVE', null))->toBe(TaskPolicy::transitionError())
        ->and(ManualTaskStatus::moveError('SUBMITTED', 'IN_PROGRESS', 'GROUP', true, 'ACTIVE', null))->toBe(TaskPolicy::transitionError())
        ->and(ManualTaskStatus::moveError('PENDING', 'COMPLETED', 'INDIVIDUAL', false, 'ACTIVE', null))->toBe(TaskPolicy::transitionError())
        ->and(ManualTaskStatus::moveError('IN_PROGRESS', 'RETURNED', 'INDIVIDUAL', true, 'ACTIVE', null))->toBe(TaskPolicy::transitionError())
        // The map is asked before the reopen gate …
        ->and(ManualTaskStatus::moveError('CANCELLED', 'RETURNED', null, true, 'COMPLETED', null))->toBe(TaskPolicy::transitionError())
        // … before the hand-in gates …
        ->and(ManualTaskStatus::moveError('IN_PROGRESS', 'RETURNED', null, false, 'ACTIVE', null))->toBe(TaskPolicy::transitionError())
        ->and(ManualTaskStatus::moveError('SUBMITTED', 'PENDING', null, true, 'ACTIVE', null))->toBe(TaskPolicy::transitionError())
        // … and before an INDIVIDUAL completion gap.
        ->and(ManualTaskStatus::moveError('PENDING', 'COMPLETED', 'INDIVIDUAL', false, 'ACTIVE', 'gap'))->toBe(TaskPolicy::transitionError());
});

it('refuses reopening a closed task inside a completed or cancelled project, and only then', function (?string $mode) {
    $reopen = 'Reopen the project before reopening one of its tasks.';
    $map = $mode === 'INDIVIDUAL' ? mtsIndividualMap() : mtsGroupMap();
    $checked = 0;

    foreach ($map as $from => $targets) {
        foreach ($targets as $to) {
            $isReopen = TaskPolicy::isClosed($from) && ! TaskPolicy::isClosed($to);
            foreach (['PLANNING', 'ACTIVE', 'ON_HOLD', 'COMPLETED', 'CANCELLED'] as $project) {
                $gated = $isReopen && in_array($project, ['COMPLETED', 'CANCELLED'], true);
                $error = ManualTaskStatus::moveError($from, $to, $mode, $to === 'RETURNED', $project, null);
                expect($error === $reopen)->toBe($gated, "{$from}→{$to} in a {$project} project")
                    ->and($gated || $error === null)->toBeTrue("{$from}→{$to} in a {$project} project: {$error}");
                $checked += $gated ? 1 : 0;
            }
        }
    }

    // GROUP: COMPLETED→PENDING/IN_PROGRESS/RETURNED and CANCELLED→PENDING/
    // IN_PROGRESS, in two closed project statuses. INDIVIDUAL: no RETURNED.
    expect($checked)->toBe($mode === 'INDIVIDUAL' ? 8 : 10);
})->with([
    'no mode' => null,
    'GROUP' => 'GROUP',
    'INDIVIDUAL' => 'INDIVIDUAL',
]);

it('lets an open task move, and any task close, inside a closed project', function () {
    foreach (['COMPLETED', 'CANCELLED'] as $project) {
        expect(ManualTaskStatus::moveError('PENDING', 'IN_PROGRESS', null, false, $project, null))->toBeNull()
            ->and(ManualTaskStatus::moveError('IN_PROGRESS', 'PENDING', null, false, $project, null))->toBeNull()
            ->and(ManualTaskStatus::moveError('SUBMITTED', 'RETURNED', null, true, $project, null))->toBeNull()
            ->and(ManualTaskStatus::moveError('RETURNED', 'IN_PROGRESS', null, true, $project, null))->toBeNull()
            ->and(ManualTaskStatus::moveError('IN_PROGRESS', 'CANCELLED', 'INDIVIDUAL', true, $project, null))->toBeNull()
            ->and(ManualTaskStatus::moveError('COMPLETED', 'CANCELLED', null, true, $project, null))->toBeNull()
            ->and(ManualTaskStatus::moveError('PENDING', 'COMPLETED', null, false, $project, null))->toBeNull();
    }
});

it('asks the reopen gate before the hand-in gates', function () {
    $reopen = 'Reopen the project before reopening one of its tasks.';

    expect(ManualTaskStatus::moveError('COMPLETED', 'PENDING', null, true, 'COMPLETED', null))->toBe($reopen)
        ->and(ManualTaskStatus::moveError('CANCELLED', 'PENDING', 'INDIVIDUAL', true, 'CANCELLED', null))->toBe($reopen)
        ->and(ManualTaskStatus::moveError('COMPLETED', 'RETURNED', null, false, 'CANCELLED', null))->toBe($reopen)
        // In an open project the hand-in gates answer instead.
        ->and(ManualTaskStatus::moveError('COMPLETED', 'PENDING', null, true, 'ACTIVE', null))->toBe('Work has already been handed in, so this task cannot go back to pending.')
        ->and(ManualTaskStatus::moveError('COMPLETED', 'RETURNED', null, false, 'ACTIVE', null))->toBe('Nothing has been handed in, so there is nothing to return.');
});

it('refuses putting a task back to pending once work has been handed in', function () {
    $pending = 'Work has already been handed in, so this task cannot go back to pending.';

    foreach ([[null, 'IN_PROGRESS'], [null, 'COMPLETED'], [null, 'CANCELLED'], ['INDIVIDUAL', 'IN_PROGRESS'], ['INDIVIDUAL', 'COMPLETED'], ['INDIVIDUAL', 'CANCELLED']] as [$mode, $from]) {
        expect(ManualTaskStatus::moveError($from, 'PENDING', $mode, true, 'ACTIVE', null))->toBe($pending, "{$from} ".($mode ?? 'null'))
            ->and(ManualTaskStatus::moveError($from, 'PENDING', $mode, false, 'ACTIVE', null))->toBeNull("{$from} ".($mode ?? 'null'));
    }

    // A hand-in blocks only PENDING: every other open target still moves.
    expect(ManualTaskStatus::moveError('CANCELLED', 'IN_PROGRESS', null, true, 'ACTIVE', null))->toBeNull()
        ->and(ManualTaskStatus::moveError('COMPLETED', 'IN_PROGRESS', 'INDIVIDUAL', true, 'ACTIVE', null))->toBeNull();
});

it('refuses returning a task nothing was handed in on', function () {
    $nothing = 'Nothing has been handed in, so there is nothing to return.';

    foreach (['SUBMITTED', 'COMPLETED'] as $from) {
        foreach ([null, 'GROUP'] as $mode) {
            expect(ManualTaskStatus::moveError($from, 'RETURNED', $mode, false, 'ACTIVE', null))->toBe($nothing, $from)
                ->and(ManualTaskStatus::moveError($from, 'RETURNED', $mode, true, 'ACTIVE', null))->toBeNull($from);
        }
    }
});

it('passes an INDIVIDUAL completion gap through, and only for an INDIVIDUAL completion', function () {
    $gap = "Every assignee's response has to be approved before the task can be completed.";

    expect(ManualTaskStatus::moveError('IN_PROGRESS', 'COMPLETED', 'INDIVIDUAL', true, 'ACTIVE', $gap))->toBe($gap)
        ->and(ManualTaskStatus::moveError('IN_PROGRESS', 'COMPLETED', 'INDIVIDUAL', false, 'ACTIVE', 'Assign someone before completing this task.'))->toBe('Assign someone before completing this task.')
        ->and(ManualTaskStatus::moveError('IN_PROGRESS', 'COMPLETED', 'INDIVIDUAL', true, 'ACTIVE', null))->toBeNull()
        // A GROUP task completes by hand without a hand-in and ignores the gap.
        ->and(ManualTaskStatus::moveError('IN_PROGRESS', 'COMPLETED', 'GROUP', false, 'ACTIVE', $gap))->toBeNull()
        ->and(ManualTaskStatus::moveError('PENDING', 'COMPLETED', null, false, 'ACTIVE', $gap))->toBeNull()
        // The gap is asked for a completion only.
        ->and(ManualTaskStatus::moveError('IN_PROGRESS', 'CANCELLED', 'INDIVIDUAL', true, 'ACTIVE', $gap))->toBeNull()
        ->and(ManualTaskStatus::moveError('COMPLETED', 'IN_PROGRESS', 'INDIVIDUAL', true, 'ACTIVE', $gap))->toBeNull();
});

/* |---| Notes |---| */

it('requires a note to return a task, and only to return it', function () {
    $needed = 'Say what needs reworking before returning the task.';

    expect(ManualTaskStatus::noteError('RETURNED', ''))->toBe($needed)
        ->and(ManualTaskStatus::noteError('RETURNED', "  \n\t "))->toBe($needed)
        ->and(ManualTaskStatus::noteError('RETURNED', 'Redo the totals.'))->toBeNull();

    foreach (array_diff(mtsStatuses(), ['RETURNED']) as $to) {
        expect(ManualTaskStatus::noteError($to, ''))->toBeNull($to)
            ->and(ManualTaskStatus::noteError($to, 'Any note'))->toBeNull($to);
    }
});

/* |---| writes() |---| */

it('stamps a completion as a decision and keeps the hand-in time', function () {
    $now = '2026-09-25 03:04:05.678';

    expect(mtsSorted(ManualTaskStatus::writes('SUBMITTED', 'COMPLETED', 7, "  Good work \n", $now)))->toBe(mtsSorted([
        'status' => 'COMPLETED',
        'completed_at' => $now,
        'reviewed_by' => 7,
        'review_remarks' => 'Good work',
    ]))
        ->and(mtsSorted(ManualTaskStatus::writes('PENDING', 'COMPLETED', 7, '', $now)))->toBe(mtsSorted([
            'status' => 'COMPLETED',
            'completed_at' => $now,
            'reviewed_by' => 7,
            'review_remarks' => null,
        ]));
});

it('stamps a return as a decision and clears the hand-in time', function () {
    expect(mtsSorted(ManualTaskStatus::writes('SUBMITTED', 'RETURNED', 7, ' Redo the table ', '2026-09-25 00:00:00.000')))->toBe(mtsSorted([
        'status' => 'RETURNED',
        'completed_at' => null,
        'submitted_at' => null,
        'reviewed_by' => 7,
        'review_remarks' => 'Redo the table',
    ]))
        // Returning an accepted task drops its completion time too.
        ->and(mtsSorted(ManualTaskStatus::writes('COMPLETED', 'RETURNED', 9, 'One more pass', '2026-09-25 00:00:00.000')))->toBe(mtsSorted([
            'status' => 'RETURNED',
            'completed_at' => null,
            'submitted_at' => null,
            'reviewed_by' => 9,
            'review_remarks' => 'One more pass',
        ]));
});

it('stamps a cancellation as a decision, clears any completion and keeps the hand-in time', function () {
    expect(mtsSorted(ManualTaskStatus::writes('IN_PROGRESS', 'CANCELLED', 7, '', '2026-09-25 00:00:00.000')))->toBe(mtsSorted([
        'status' => 'CANCELLED',
        'completed_at' => null,
        'reviewed_by' => 7,
        'review_remarks' => null,
    ]))
        ->and(mtsSorted(ManualTaskStatus::writes('COMPLETED', 'CANCELLED', null, 'Dropped by the client', '2026-09-25 00:00:00.000')))->toBe(mtsSorted([
            'status' => 'CANCELLED',
            'completed_at' => null,
            'reviewed_by' => null,
            'review_remarks' => 'Dropped by the client',
        ]));
});

it('clears the undone decision note on a reopen without stamping a reviewer', function (string $from, string $to) {
    $columns = ManualTaskStatus::writes($from, $to, 7, 'This note is not stored on the task', '2026-09-25 00:00:00.000');

    expect(mtsSorted($columns))->toBe(mtsSorted([
        'status' => $to,
        'completed_at' => null,
        'submitted_at' => null,
        'review_remarks' => null,
    ]))
        ->and($columns)->not->toHaveKey('reviewed_by');
})->with([
    'completed to pending' => ['COMPLETED', 'PENDING'],
    'completed to in progress' => ['COMPLETED', 'IN_PROGRESS'],
    'cancelled to pending' => ['CANCELLED', 'PENDING'],
    'cancelled to in progress' => ['CANCELLED', 'IN_PROGRESS'],
]);

it('leaves the reviewer and the last decision note alone when an open task starts or pauses', function (string $from, string $to) {
    $columns = ManualTaskStatus::writes($from, $to, 7, 'Ignored', '2026-09-25 00:00:00.000');

    expect(mtsSorted($columns))->toBe(mtsSorted([
        'status' => $to,
        'completed_at' => null,
        'submitted_at' => null,
    ]))
        ->and($columns)->not->toHaveKey('reviewed_by')
        ->and($columns)->not->toHaveKey('review_remarks');
})->with([
    'pending to in progress' => ['PENDING', 'IN_PROGRESS'],
    'in progress to pending' => ['IN_PROGRESS', 'PENDING'],
    // A GROUP rework note survives the restart, as it does StartTask.
    'returned to in progress' => ['RETURNED', 'IN_PROGRESS'],
]);

it('writes exactly the columns each move owns, for all 36 pairs', function () {
    $now = '2026-09-25 12:00:00.000';
    $pairs = 0;

    foreach (mtsStatuses() as $from) {
        foreach (mtsStatuses() as $to) {
            $columns = ManualTaskStatus::writes($from, $to, 42, ' note ', $now);
            $closing = in_array($to, ['COMPLETED', 'RETURNED', 'CANCELLED'], true);
            $reopen = ! $closing && in_array($from, ['COMPLETED', 'CANCELLED'], true);

            expect($columns['status'])->toBe($to)
                ->and($columns['completed_at'])->toBe($to === 'COMPLETED' ? $now : null, "{$from}→{$to} completed_at")
                ->and(array_key_exists('submitted_at', $columns))->toBe(in_array($to, ['PENDING', 'IN_PROGRESS', 'RETURNED'], true), "{$from}→{$to} submitted_at")
                ->and(array_key_exists('reviewed_by', $columns))->toBe($closing, "{$from}→{$to} reviewed_by")
                ->and(array_key_exists('review_remarks', $columns))->toBe($closing || $reopen, "{$from}→{$to} review_remarks");

            if (array_key_exists('submitted_at', $columns)) {
                expect($columns['submitted_at'])->toBeNull();
            }
            if ($closing) {
                expect($columns['reviewed_by'])->toBe(42)
                    ->and($columns['review_remarks'])->toBe('note');
            } elseif ($reopen) {
                expect($columns['review_remarks'])->toBeNull();
            }

            // Nothing beyond the five columns a hand-set move owns.
            expect(array_diff(array_keys($columns), ['status', 'completed_at', 'submitted_at', 'reviewed_by', 'review_remarks']))->toBe([]);
            $pairs++;
        }
    }

    expect($pairs)->toBe(36);
});
