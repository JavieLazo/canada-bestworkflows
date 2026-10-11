<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Domain\Tasks;

use App\Domain\Projects\TaskPolicy;

/**
 * The rules for one assignee's own response on an INDIVIDUAL task — the state
 * TaskPolicy's task-level maps deliberately leave out.
 *
 * DELIBERATE DIVERGENCE: the reference has one assignee per task and no
 * member state (copems prisma/schema.prisma:401-402). A GROUP task
 * keeps the reference flow on the task row. On an INDIVIDUAL task each
 * assignee has a response of their own, stored as one project_task_submissions
 * row: NONE (no row yet — "Not submitted"), SUBMITTED, RETURNED or APPROVED.
 * The task itself stays IN_PROGRESS while responses move, and completes only
 * when every CURRENT assignee's response is APPROVED.
 *
 * Moves are keyed by the CURRENT member state, like TaskPolicy's maps, and
 * both actors move a response only while the task is IN_PROGRESS. Every
 * method treats a null member status and 'NONE' as the same thing — no row —
 * and an unrecognised state or mode as allowing no move.
 *
 * Pure: no Eloquent, no Carbon, no config(). Statuses arrive as strings, sets
 * of them as plain arrays.
 */
final class TaskSubmissionPolicy
{
    /** A current assignee with no response row. Never stored. */
    public const NONE = 'NONE';

    /**
     * Ordered map — iteration order is display order.
     *
     * @var array<string, string>
     */
    public const MEMBER_STATUSES = [
        self::NONE => 'Not submitted',
        'SUBMITTED' => 'Submitted',
        'RETURNED' => 'Returned',
        'APPROVED' => 'Approved',
    ];

    /**
     * What the ASSIGNEE may move their own response to. Handing in the first
     * time and resubmitting after a return are the only moves: a submitted
     * response sits with the reviewer, and an approved one is done.
     *
     * @var array<string, list<string>>
     */
    public const MEMBER_TRANSITIONS = [
        self::NONE => ['SUBMITTED'],
        'SUBMITTED' => [],
        'RETURNED' => ['SUBMITTED'],
        'APPROVED' => [],
    ];

    /**
     * What the REVIEWER may move a response to. There is no terminal reject:
     * a response that should not count is returned, and a person who should
     * not be required any more is unassigned. APPROVED → RETURNED stays open
     * until the task completes, so an approval can be taken back.
     *
     * @var array<string, list<string>>
     */
    public const REVIEWER_TRANSITIONS = [
        self::NONE => [],
        'SUBMITTED' => ['APPROVED', 'RETURNED'],
        'RETURNED' => [],
        'APPROVED' => ['RETURNED'],
    ];

    /**
     * The status an assignee sees for their own task: TaskPolicy::STATUSES
     * with APPROVED after SUBMITTED, since an INDIVIDUAL member's own
     * response can be approved while the task is still in progress. The
     * labels are read from their one home so the lists cannot drift.
     *
     * @var array<string, string>
     */
    public const MY_STATUSES = [
        'PENDING' => TaskPolicy::STATUSES['PENDING'],
        'IN_PROGRESS' => TaskPolicy::STATUSES['IN_PROGRESS'],
        'SUBMITTED' => TaskPolicy::STATUSES['SUBMITTED'],
        'APPROVED' => self::MEMBER_STATUSES['APPROVED'],
        'RETURNED' => TaskPolicy::STATUSES['RETURNED'],
        'COMPLETED' => TaskPolicy::STATUSES['COMPLETED'],
        'CANCELLED' => TaskPolicy::STATUSES['CANCELLED'],
    ];

    private function __construct() {}

    public static function isIndividual(?string $mode): bool
    {
        return $mode === TaskPolicy::MODE_INDIVIDUAL;
    }

    /**
     * The mode a new task is stored with. Individual responses need at
     * least two people; with fewer there is nothing to tell apart, so the
     * task is GROUP whatever was asked. An unrecognised request is GROUP too
     * — the command validates it first, this only refuses to store it.
     */
    public static function creationMode(string $requested, int $assigneeCount): string
    {
        if ($assigneeCount < 2 || ! TaskPolicy::isMode($requested)) {
            return TaskPolicy::MODE_GROUP;
        }

        return $requested;
    }

    /** Whether the assignee may move their own response from $from to $to. */
    public static function memberCanMove(string $taskStatus, ?string $from, string $to): bool
    {
        if ($taskStatus !== 'IN_PROGRESS') {
            return false;
        }

        return in_array($to, self::MEMBER_TRANSITIONS[$from ?? self::NONE] ?? [], true);
    }

    /**
     * What a reviewer may move a response to right now.
     *
     * @return list<string>
     */
    public static function reviewerMovesFrom(string $taskStatus, ?string $from): array
    {
        if ($taskStatus !== 'IN_PROGRESS') {
            return [];
        }

        return self::REVIEWER_TRANSITIONS[$from ?? self::NONE] ?? [];
    }

    /** As memberCanMove(), for a reviewer. */
    public static function reviewerCanMove(string $taskStatus, ?string $from, string $to): bool
    {
        return in_array($to, self::reviewerMovesFrom($taskStatus, $from), true);
    }

    /**
     * Every CURRENT assignee whose response is not approved yet, mapped to
     * their state (NONE when they have no row). A row for someone who is no
     * longer assigned is ignored: it neither blocks nor counts.
     *
     * @param  list<string>  $assigneeIds
     * @param  array<string, string>  $statusByEmployee  employee id => member status
     * @return array<string, string>
     */
    public static function completionGaps(array $assigneeIds, array $statusByEmployee): array
    {
        $gaps = [];
        foreach ($assigneeIds as $employeeId) {
            $status = $statusByEmployee[$employeeId] ?? self::NONE;
            if ($status !== 'APPROVED') {
                $gaps[$employeeId] = $status;
            }
        }

        return $gaps;
    }

    /**
     * Why an INDIVIDUAL task cannot be completed yet, or null when it can. A
     * task with nobody on it has no responses to approve, and is refused
     * rather than completed vacuously.
     *
     * @param  list<string>  $assigneeIds
     * @param  array<string, string>  $statusByEmployee
     */
    public static function completeError(array $assigneeIds, array $statusByEmployee): ?string
    {
        if ($assigneeIds === []) {
            return 'Assign someone before completing this task.';
        }

        if (self::completionGaps($assigneeIds, $statusByEmployee) !== []) {
            return "Every assignee's response has to be approved before the task can be completed.";
        }

        return null;
    }

    /**
     * @param  list<string>  $assigneeIds
     * @param  array<string, string>  $statusByEmployee
     */
    public static function canComplete(array $assigneeIds, array $statusByEmployee): bool
    {
        return self::completeError($assigneeIds, $statusByEmployee) === null;
    }

    /**
     * How far an INDIVIDUAL task's responses have got, from the counts a
     * query aggregates. "Not submitted" is whoever is left; it never goes
     * below zero.
     *
     * @return array{assignees: int, approved: int, submitted: int, returned: int, not_submitted: int}
     */
    public static function progress(int $assignees, int $submitted, int $approved, int $returned): array
    {
        return [
            'assignees' => $assignees,
            'approved' => $approved,
            'submitted' => $submitted,
            'returned' => $returned,
            'not_submitted' => max(0, $assignees - $submitted - $approved - $returned),
        ];
    }

    /**
     * progress() as one line — "2 of 3 approved · 1 awaiting review". Counts
     * of zero are left out. A submitted response reads "awaiting review" only
     * while it is one ({@see self::responseAwaitsReview()}); once the task is
     * closed nobody reviews it, so it reads "submitted".
     *
     * @param  array{assignees: int, approved: int, submitted: int, returned: int, not_submitted: int}  $progress
     */
    public static function progressSentence(array $progress, string $taskStatus): string
    {
        if ($progress['assignees'] === 0) {
            return 'Nobody assigned';
        }

        $submitted = self::responseAwaitsReview($taskStatus, 'SUBMITTED') ? 'awaiting review' : 'submitted';
        $parts = [$progress['approved'].' of '.$progress['assignees'].' approved'];
        foreach (['submitted' => $submitted, 'returned' => 'returned', 'not_submitted' => 'not submitted'] as $key => $phrase) {
            if ($progress[$key] > 0) {
                $parts[] = $progress[$key].' '.$phrase;
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * Whether a task is waiting on a reviewer: a GROUP task that was handed
     * in, or an INDIVIDUAL task in progress with at least one response
     * submitted. ANY SQL THAT SELECTS NEEDS-REVIEW TASKS MUST SAY EXACTLY
     * THIS — a queue that disagrees with its own counter is the bug. The SQL
     * twin is WorkScope::NEEDS_REVIEW_SQL, in this module's Infrastructure.
     */
    public static function awaitsReview(?string $mode, string $taskStatus, int $submittedResponses): bool
    {
        if (TaskPolicy::isAwaitingReview($taskStatus)) {
            return true;
        }

        return self::isIndividual($mode) && $submittedResponses > 0 && self::responseAwaitsReview($taskStatus, 'SUBMITTED');
    }

    /**
     * Whether ONE member's response sits with the reviewer: it is SUBMITTED
     * and its task is still IN_PROGRESS. A response left submitted when the
     * task was cancelled waits on nobody, like the task itself — this is the
     * per-member half of awaitsReview(), and every per-response "awaiting
     * review" reads it.
     */
    public static function responseAwaitsReview(string $taskStatus, ?string $memberStatus): bool
    {
        return $taskStatus === 'IN_PROGRESS' && $memberStatus === 'SUBMITTED';
    }

    /**
     * The status a task sorts by in a reviewer's stack: one waiting on
     * review sorts as SUBMITTED whatever its mode. The SQL twin is
     * WorkScope::STACK_STATUS_SQL.
     */
    public static function stackStatus(?string $mode, string $taskStatus, int $submittedResponses): string
    {
        return self::awaitsReview($mode, $taskStatus, $submittedResponses) ? 'SUBMITTED' : $taskStatus;
    }

    /**
     * The status an assignee sees for their own task: their response's state
     * while an INDIVIDUAL task is in progress and they have a row, otherwise
     * the task's. ANY SQL THAT SHOWS AN ASSIGNEE THEIR OWN STATUS MUST SAY
     * EXACTLY THIS. The SQL twin is WorkScope::MY_STATUS_SQL: there a
     * missing row is a NULL left join, here null and NONE both stand for it,
     * so the two cannot disagree.
     */
    public static function myStatus(?string $mode, string $taskStatus, ?string $memberStatus): string
    {
        if (self::isIndividual($mode) && $taskStatus === 'IN_PROGRESS'
            && $memberStatus !== null && $memberStatus !== self::NONE) {
            return $memberStatus;
        }

        return $taskStatus;
    }

    /** A myStatus() value's label; anything unrecognised reads as itself. */
    public static function myStatusLabel(string $status): string
    {
        return self::MY_STATUSES[$status] ?? $status;
    }

    /** A myStatus() value's pill: TaskPolicy's tiers, APPROVED as strong success. */
    public static function myStatusPill(string $status): string
    {
        return $status === 'APPROVED' ? 'pill pill-success-strong' : TaskPolicy::statusPill($status);
    }

    /** A member state's label; null is "Not submitted", anything unrecognised reads as itself. */
    public static function memberStatusLabel(?string $status): string
    {
        $status ??= self::NONE;

        return self::MEMBER_STATUSES[$status] ?? $status;
    }

    /** A member state's pill, on the tiers of TaskPolicy::statusPill(). */
    public static function memberStatusPill(?string $status): string
    {
        return match ($status ?? self::NONE) {
            'SUBMITTED' => 'pill pill-success',
            'RETURNED' => 'pill pill-danger',
            'APPROVED' => 'pill pill-success-strong',
            default => 'pill pill-muted',
        };
    }

    /**
     * Whether a GROUP response has ever been handed in on the task row. Any
     * one of the three counts: a return clears submitted_at but keeps the
     * submitter and the note, and a legacy row can carry a note with
     * neither stamp — hence the trimmed remarks.
     */
    public static function groupHandedIn(?string $submittedBy, mixed $submittedAt, ?string $remarks): bool
    {
        return ($submittedBy !== null && $submittedBy !== '')
            || ($submittedAt !== null && $submittedAt !== '')
            || trim((string) $remarks) !== '';
    }

    /**
     * Whether a task's mode can no longer change: once anyone has handed in
     * work or attached evidence, switching would strand it under the other
     * mode's rules. The mode changes ONLY while the task is PENDING or
     * IN_PROGRESS. A SUBMITTED or RETURNED task got there through a GROUP
     * hand-in even when a legacy row kept no other trace of it, and under
     * INDIVIDUAL those statuses have no move at all; a closed or
     * unrecognised status is locked too.
     */
    public static function modeLocked(string $taskStatus, bool $groupHandedIn, int $responseRows, bool $hasSubmissionFiles): bool
    {
        return ! in_array($taskStatus, ['PENDING', 'IN_PROGRESS'], true)
            || $groupHandedIn || $responseRows > 0 || $hasSubmissionFiles;
    }

    /**
     * Whether an assignee still has something to do on an open task. A
     * closed task owes nothing, and a response (or a GROUP hand-in) that is
     * submitted or approved sits with the reviewer, so its owner is not
     * chased about it.
     */
    public static function memberOwesWork(?string $mode, string $taskStatus, ?string $memberStatus): bool
    {
        if (TaskPolicy::isClosed($taskStatus)) {
            return false;
        }

        return ! in_array(self::myStatus($mode, $taskStatus, $memberStatus), ['SUBMITTED', 'APPROVED'], true);
    }

    /** The refusal when a response move is not in the maps above. */
    public static function transitionError(): string
    {
        return 'That response cannot move to this status from its current state.';
    }
}
