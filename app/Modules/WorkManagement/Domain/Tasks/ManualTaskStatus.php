<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Domain\Tasks;

use App\Domain\Projects\TaskPolicy;

/**
 * A task status set BY HAND — the "Set status" control of the review dialog,
 * for whoever runs the task's project (a Projects manager or its Task Leader).
 *
 * DELIBERATE DIVERGENCE: the reference lets management only accept, return or
 * cancel (TaskPolicy::MANAGER_TRANSITIONS, pinned verbatim to copems). Here
 * the people who run a project may also start work for the crew, put a task
 * back to pending, complete it without a hand-in, cancel one awaiting review,
 * and reopen a completed or cancelled one. It is still a MAP KEYED BY THE
 * CURRENT STATUS — never a flat list of targets, which is the reference bug
 * TaskPolicy's docblock records — and the moves it leaves out are the ones
 * that would contradict the task's own facts:
 *
 *  - SUBMITTED is never set by hand: it means an assignee handed work in.
 *  - RETURNED only for a GROUP task that was handed in, and never without a
 *    note saying what to rework. An INDIVIDUAL task never enters SUBMITTED
 *    or RETURNED at all — those states live on each member's response.
 *  - PENDING only while nothing has been handed in.
 *  - An INDIVIDUAL task completes only through the completion gate (every
 *    current assignee's response approved), exactly as ReviewTask completes
 *    it. Reopened, it keeps its members' responses: a reviewer then returns
 *    the ones that need rework, which REVIEWER_TRANSITIONS allows (APPROVED
 *    → RETURNED) once the task is in progress again.
 *  - No task is reopened inside a closed project (COMPLETED or CANCELLED):
 *    a completed project has no open task, and a cancelled one is not
 *    worked on. Reopen the project first.
 *  - Nobody on the crew makes a review move (isReviewMove()): completing or
 *    returning the task, or undoing a hand-in or an acceptance — the caller
 *    checks the crew, as ReviewTask does.
 *
 * A reopened task or project is not reminded about a due date it was already
 * reminded about: ReminderSweep's dedupe keys ({KIND}:{id}:{due}) are frozen
 * and ignore reopening. Moving the due date gives a new key.
 *
 * Pure: no Eloquent, no Carbon, no config(). Facts arrive as plain values.
 */
final class ManualTaskStatus
{
    /**
     * GROUP (and a null mode): keyed by the CURRENT status.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'PENDING' => ['IN_PROGRESS', 'COMPLETED', 'CANCELLED'],
        'IN_PROGRESS' => ['PENDING', 'COMPLETED', 'CANCELLED'],
        'SUBMITTED' => ['RETURNED', 'COMPLETED', 'CANCELLED'],
        'RETURNED' => ['IN_PROGRESS', 'COMPLETED', 'CANCELLED'],
        'COMPLETED' => ['PENDING', 'IN_PROGRESS', 'RETURNED', 'CANCELLED'],
        'CANCELLED' => ['PENDING', 'IN_PROGRESS'],
    ];

    /**
     * INDIVIDUAL: the task itself only ever goes PENDING / IN_PROGRESS /
     * COMPLETED / CANCELLED. SUBMITTED and RETURNED reach nothing because an
     * INDIVIDUAL task is never in them.
     *
     * @var array<string, list<string>>
     */
    public const INDIVIDUAL_TRANSITIONS = [
        'PENDING' => ['IN_PROGRESS', 'CANCELLED'],
        'IN_PROGRESS' => ['PENDING', 'COMPLETED', 'CANCELLED'],
        'SUBMITTED' => [],
        'RETURNED' => [],
        'COMPLETED' => ['PENDING', 'IN_PROGRESS', 'CANCELLED'],
        'CANCELLED' => ['PENDING', 'IN_PROGRESS'],
    ];

    /** The review decisions among the targets: refused to anyone on the task's crew. */
    public const DECISIONS = ['COMPLETED', 'RETURNED'];

    /**
     * The statuses a review decision leaves behind — a hand-in waiting on a
     * reviewer, or an accepted one. Moving a task OUT of them undoes someone's
     * hand-in or someone's acceptance, so it is a review move too.
     */
    public const DECIDED = ['SUBMITTED', 'COMPLETED'];

    private function __construct() {}

    /**
     * The map a mode moves through; an unrecognised mode moves nowhere.
     *
     * @return list<string>
     */
    public static function canMoveTo(string $from, ?string $mode): array
    {
        $map = match ($mode) {
            null, TaskPolicy::MODE_GROUP => self::TRANSITIONS,
            TaskPolicy::MODE_INDIVIDUAL => self::INDIVIDUAL_TRANSITIONS,
            default => [],
        };

        return $map[$from] ?? [];
    }

    public static function canMove(string $from, string $to, ?string $mode): bool
    {
        return in_array($to, self::canMoveTo($from, $mode), true);
    }

    /**
     * Whether a move is a review move — refused to anyone on the task's crew,
     * as ReviewTask refuses an assignee: completing or returning it, or moving
     * it out of a hand-in or an acceptance.
     */
    public static function isReviewMove(string $from, string $to): bool
    {
        return in_array($to, self::DECISIONS, true) || in_array($from, self::DECIDED, true);
    }

    /**
     * Whether work has been handed in on the task, in its mode's own terms:
     * a GROUP hand-in on the task row (TaskSubmissionPolicy::groupHandedIn),
     * or any member response row on an INDIVIDUAL task.
     */
    public static function handedIn(?string $mode, bool $groupHandedIn, int $responseRows): bool
    {
        return TaskSubmissionPolicy::isIndividual($mode) ? $responseRows > 0 : $groupHandedIn;
    }

    /**
     * Why the task cannot be set from `$from` to `$to` by hand, or null when
     * it can. `$completionError` is TaskSubmissionPolicy::completeError() for
     * the task's current crew and responses — asked only for an INDIVIDUAL
     * completion, and read under the task lock by the command.
     */
    public static function moveError(string $from, string $to, ?string $mode, bool $handedIn, string $projectStatus, ?string $completionError): ?string
    {
        if (! TaskPolicy::isStatus($to)) {
            return 'That task status is not supported.';
        }

        if (! self::canMove($from, $to, $mode)) {
            return TaskPolicy::transitionError();
        }

        if (TaskPolicy::isClosed($from) && ! TaskPolicy::isClosed($to) && in_array($projectStatus, ['COMPLETED', 'CANCELLED'], true)) {
            return 'Reopen the project before reopening one of its tasks.';
        }

        if ($to === 'PENDING' && $handedIn) {
            return 'Work has already been handed in, so this task cannot go back to pending.';
        }

        if ($to === 'RETURNED' && ! $handedIn) {
            return 'Nothing has been handed in, so there is nothing to return.';
        }

        if ($to === 'COMPLETED' && TaskSubmissionPolicy::isIndividual($mode) && $completionError !== null) {
            return $completionError;
        }

        return null;
    }

    /** A return must say what to rework; every other note is optional. */
    public static function noteError(string $to, string $note): ?string
    {
        return $to === 'RETURNED' && trim($note) === ''
            ? 'Say what needs reworking before returning the task.'
            : null;
    }

    /**
     * Everything a hand-set move writes besides the status, so no reader is
     * left with a stale fact:
     *
     *  - COMPLETED stamps completed_at; any other target clears it, so a
     *    reopened or cancelled task never keeps a completion time (the stale
     *    completed_at is exactly what the reference bug left behind).
     *  - PENDING, IN_PROGRESS and RETURNED clear submitted_at, as StartTask
     *    and a return do: nothing sits with a reviewer any more. The last
     *    submitter and note stay — they are the record of what was handed in.
     *  - A CLOSING move (COMPLETED, RETURNED, CANCELLED) is a decision: it
     *    stamps `reviewed_by` and replaces `review_remarks` with the note, as
     *    ReviewTask does. Starting or pausing work is not: it keeps the last
     *    decision's note — a GROUP rework note survives a start, as it does
     *    StartTask — except that a REOPEN clears the note of the decision it
     *    undoes. An INDIVIDUAL member's own return note lives on their
     *    response row, so the task row never carries a note while the task
     *    is open.
     *
     * @return array<string, int|string|null>
     */
    public static function writes(string $from, string $to, ?int $actorUserId, string $note, string $now): array
    {
        $columns = ['status' => $to, 'completed_at' => $to === 'COMPLETED' ? $now : null];
        if (in_array($to, ['PENDING', 'IN_PROGRESS', 'RETURNED'], true)) {
            $columns['submitted_at'] = null;
        }
        if (in_array($to, ['COMPLETED', 'RETURNED', 'CANCELLED'], true)) {
            $columns['reviewed_by'] = $actorUserId;
            $columns['review_remarks'] = trim($note) === '' ? null : trim($note);
        } elseif (TaskPolicy::isClosed($from)) {
            $columns['review_remarks'] = null;
        }

        return $columns;
    }
}
