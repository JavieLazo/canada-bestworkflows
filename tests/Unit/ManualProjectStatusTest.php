<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectPolicy;
use App\Modules\WorkManagement\Domain\Projects\ManualProjectStatus;

/**
 * A project status set BY HAND may reach any other status — COMPLETED and
 * CANCELLED included — but never leaves the project contradicting its facts.
 *
 * The hand-set map is keyed by the CURRENT status and pinned here as a literal
 * for all 25 pairs: every status reaches every other one, staying put is
 * allowed, and an unknown current status reaches nothing. The two reference
 * safeguards still stand (ACTIVE needs a Task Leader, COMPLETED needs every
 * task closed) and answer exactly as ProjectPolicy::statusError() does wherever
 * the reference allows the move. The reference map itself matches projects.ts:
 * COMPLETED and CANCELLED stay terminal in ProjectPolicy::TRANSITIONS, which
 * CreateProject still follows.
 */

/** @return list<string> */
function mpsStatuses(): array
{
    return ['PLANNING', 'ACTIVE', 'ON_HOLD', 'COMPLETED', 'CANCELLED'];
}

/**
 * The hand-set map: every OTHER status.
 *
 * @return array<string, list<string>>
 */
function mpsManualMap(): array
{
    return [
        'PLANNING' => ['ACTIVE', 'ON_HOLD', 'COMPLETED', 'CANCELLED'],
        'ACTIVE' => ['PLANNING', 'ON_HOLD', 'COMPLETED', 'CANCELLED'],
        'ON_HOLD' => ['PLANNING', 'ACTIVE', 'COMPLETED', 'CANCELLED'],
        'COMPLETED' => ['PLANNING', 'ACTIVE', 'ON_HOLD', 'CANCELLED'],
        'CANCELLED' => ['PLANNING', 'ACTIVE', 'ON_HOLD', 'COMPLETED'],
    ];
}

/**
 * copems projects.ts PROJECT_TRANSITIONS, verbatim.
 *
 * @return array<string, list<string>>
 */
function mpsReferenceMap(): array
{
    return [
        'PLANNING' => ['ACTIVE', 'ON_HOLD', 'CANCELLED'],
        'ACTIVE' => ['ON_HOLD', 'COMPLETED', 'CANCELLED'],
        'ON_HOLD' => ['ACTIVE', 'CANCELLED'],
        'COMPLETED' => [],
        'CANCELLED' => [],
    ];
}

/** @return array{status: string, manager_id: string|null} */
function mpsProject(string $status, ?string $managerId = '17'): array
{
    return ['status' => $status, 'manager_id' => $managerId];
}

/**
 * @param  list<string>  $statuses
 * @return list<array{status: string}>
 */
function mpsTasks(array $statuses): array
{
    return array_map(static fn (string $status): array => ['status' => $status], $statuses);
}

/* |---| The map |---| */

it('moves every project status to every other one by hand, for all 25 pairs', function () {
    $pairs = 0;
    foreach (mpsStatuses() as $from) {
        expect(ManualProjectStatus::canMoveTo($from))->toBe(mpsManualMap()[$from], $from);
        foreach (mpsStatuses() as $to) {
            expect(ManualProjectStatus::canMove($from, $to))->toBe($from !== $to, "{$from}→{$to}");
            $pairs++;
        }
    }

    expect($pairs)->toBe(25)
        ->and(ManualProjectStatus::transitions())->toBe(mpsManualMap())
        // Derived from the vocabulary, so a status added there is reachable.
        ->and(array_keys(ProjectPolicy::STATUSES))->toBe(mpsStatuses());
});

it('allows every hand-set move, reopening a completed or cancelled project included, when the facts agree', function () {
    $closedTasks = mpsTasks(['COMPLETED', 'CANCELLED', 'COMPLETED']);

    foreach (mpsStatuses() as $from) {
        foreach (mpsStatuses() as $to) {
            expect(ManualProjectStatus::error(mpsProject($from), $closedTasks, $to))->toBeNull("{$from}→{$to}")
                ->and(ManualProjectStatus::error(mpsProject($from), [], $to))->toBeNull("{$from}→{$to} with no tasks");
        }
    }

    // The reopenings the reference refuses.
    expect(ManualProjectStatus::error(mpsProject('COMPLETED'), $closedTasks, 'ACTIVE'))->toBeNull()
        ->and(ManualProjectStatus::error(mpsProject('CANCELLED'), $closedTasks, 'PLANNING'))->toBeNull()
        ->and(ManualProjectStatus::error(mpsProject('COMPLETED'), $closedTasks, 'CANCELLED'))->toBeNull()
        ->and(ManualProjectStatus::error(mpsProject('CANCELLED'), $closedTasks, 'COMPLETED'))->toBeNull();
});

it('lets a project stay on its own status, so a details-only save that echoes it passes', function () {
    foreach (mpsStatuses() as $status) {
        expect(ManualProjectStatus::canMove($status, $status))->toBeFalse($status)
            ->and(ManualProjectStatus::error(mpsProject($status), mpsTasks(['COMPLETED']), $status))->toBeNull($status);
    }
});

it('reaches nothing from an unknown current status', function (string $current) {
    expect(ManualProjectStatus::canMoveTo($current))->toBe([]);

    foreach (mpsStatuses() as $to) {
        expect(ManualProjectStatus::canMove($current, $to))->toBeFalse("{$current}→{$to}")
            ->and(ManualProjectStatus::error(mpsProject($current), [], $to))->toBe(sprintf(
                'A %s project cannot move to %s.',
                mb_strtolower($current),
                mb_strtolower(ProjectPolicy::statusLabel($to)),
            ), "{$current}→{$to}");
    }

    // The map is asked before the leader safeguard.
    expect(ManualProjectStatus::error(mpsProject($current, null), [], 'ACTIVE'))->toBe(sprintf('A %s project cannot move to active.', mb_strtolower($current)));
})->with([
    'lower-case' => 'active',
    'a task status' => 'IN_PROGRESS',
    'junk' => 'BOGUS',
]);

/* |---| Refusals, branch by branch and in order |---| */

it('refuses an unsupported target before anything else', function (string $next) {
    foreach (mpsStatuses() as $from) {
        expect(ManualProjectStatus::error(mpsProject($from), [], $next))->toBe('That project status is not supported.', $from);
    }

    // Even from an unknown status, with no leader and open tasks.
    expect(ManualProjectStatus::error(mpsProject('BOGUS', null), mpsTasks(['PENDING']), $next))->toBe('That project status is not supported.');
})->with([
    'empty' => '',
    'lower-case' => 'completed',
    'a task status' => 'IN_PROGRESS',
    'junk' => 'BOGUS',
]);

it('needs a task leader before a project is set active', function () {
    $refusal = 'Assign a project lead before activating this project.';

    foreach (mpsStatuses() as $from) {
        expect(ManualProjectStatus::error(['status' => $from], [], 'ACTIVE'))->toBe($refusal, "{$from} with no manager_id key")
            ->and(ManualProjectStatus::error(mpsProject($from, null), [], 'ACTIVE'))->toBe($refusal, "{$from} with a null leader")
            ->and(ManualProjectStatus::error(mpsProject($from, ''), [], 'ACTIVE'))->toBe($refusal, "{$from} with a blank leader")
            ->and(ManualProjectStatus::error(mpsProject($from, '17'), [], 'ACTIVE'))->toBeNull("{$from} with a leader");

        // Only ACTIVE asks for a leader.
        foreach (['PLANNING', 'ON_HOLD', 'COMPLETED', 'CANCELLED'] as $to) {
            expect(ManualProjectStatus::error(mpsProject($from, null), [], $to))->toBeNull("{$from}→{$to} with no leader");
        }
    }
});

it('needs every task completed or cancelled before a project is set completed', function () {
    $refusal = 'Complete or cancel every task before completing the project.';

    foreach (mpsStatuses() as $from) {
        foreach (['PENDING', 'IN_PROGRESS', 'SUBMITTED', 'RETURNED', 'BOGUS'] as $open) {
            expect(ManualProjectStatus::error(mpsProject($from), mpsTasks(['COMPLETED', $open, 'CANCELLED']), 'COMPLETED'))
                ->toBe($refusal, "{$from} with one {$open} task");
        }

        expect(ManualProjectStatus::error(mpsProject($from), mpsTasks(['COMPLETED', 'CANCELLED']), 'COMPLETED'))->toBeNull($from)
            ->and(ManualProjectStatus::error(mpsProject($from), [], 'COMPLETED'))->toBeNull("{$from} with no tasks");
    }

    // Open tasks block only a completion: a project with work still open may
    // be reopened, held, planned or called off.
    $open = mpsTasks(['PENDING', 'SUBMITTED']);
    expect(ManualProjectStatus::error(mpsProject('COMPLETED'), $open, 'ACTIVE'))->toBeNull()
        ->and(ManualProjectStatus::error(mpsProject('ACTIVE'), $open, 'ON_HOLD'))->toBeNull()
        ->and(ManualProjectStatus::error(mpsProject('ACTIVE'), $open, 'PLANNING'))->toBeNull()
        ->and(ManualProjectStatus::error(mpsProject('ACTIVE'), $open, 'CANCELLED'))->toBeNull();
});

it('answers exactly as the reference safeguards wherever the reference allows the move', function () {
    $leaders = [null, '', '17'];
    $taskSets = [[], mpsTasks(['COMPLETED', 'CANCELLED']), mpsTasks(['COMPLETED', 'IN_PROGRESS'])];
    $compared = 0;

    foreach (mpsStatuses() as $from) {
        foreach (mpsStatuses() as $to) {
            if ($to !== $from && ! ProjectPolicy::canMove($from, $to)) {
                continue;
            }
            foreach ($leaders as $leader) {
                foreach ($taskSets as $tasks) {
                    expect(ManualProjectStatus::error(mpsProject($from, $leader), $tasks, $to))
                        ->toBe(ProjectPolicy::statusError(mpsProject($from, $leader), $tasks, $to), "{$from}→{$to}");
                    $compared++;
                }
            }
        }
    }

    // 5 same-status pairs plus the reference's 8 moves, over 3 × 3 facts.
    expect($compared)->toBe(13 * 9);
});

/* |---| The reference map matches projects.ts |---| */

it('leaves the reference project map terminal at completed and cancelled', function () {
    expect(ProjectPolicy::TRANSITIONS)->toBe(mpsReferenceMap())
        ->and(ProjectPolicy::canMoveTo('COMPLETED'))->toBe([])
        ->and(ProjectPolicy::canMoveTo('CANCELLED'))->toBe([])
        ->and(ProjectPolicy::canMove('COMPLETED', 'ACTIVE'))->toBeFalse()
        ->and(ProjectPolicy::canMove('CANCELLED', 'PLANNING'))->toBeFalse()
        ->and(ProjectPolicy::statusError(mpsProject('COMPLETED'), [], 'ACTIVE'))->toBe('A completed project cannot move to active.')
        ->and(ProjectPolicy::statusError(mpsProject('CANCELLED'), [], 'PLANNING'))->toBe('A cancelled project cannot move to planning.')
        ->and(ProjectPolicy::statusError(mpsProject('PLANNING'), [], 'COMPLETED'))->toBe('A planning project cannot move to completed.')
        // The same moves by hand are allowed.
        ->and(ManualProjectStatus::error(mpsProject('COMPLETED'), [], 'ACTIVE'))->toBeNull()
        ->and(ManualProjectStatus::error(mpsProject('CANCELLED'), [], 'PLANNING'))->toBeNull()
        ->and(ManualProjectStatus::error(mpsProject('PLANNING'), [], 'COMPLETED'))->toBeNull();
});
