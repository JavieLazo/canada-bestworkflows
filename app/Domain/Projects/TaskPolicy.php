<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/**
 * A literal port of the task half of copems/src/lib/projects.ts.
 *
 * PENDING → IN_PROGRESS → SUBMITTED → COMPLETED, with RETURNED sending a
 * submission back for rework and CANCELLED as management calling the task off.
 * Two actors move a task and they are allowed different moves, so there are
 * two maps: EMPLOYEE_TRANSITIONS (the assignee) and MANAGER_TRANSITIONS (the
 * reviewer).
 *
 * ⚠ THE BUG THIS CLASS EXISTS TO CLOSE. The reference README calls
 * src/lib/projects.ts "the only place a status change is allowed to come
 * from"; docs/status-2026-08-20.md records that the claim "holds for the UI
 * only" — PATCH /api/tasks/[id] declared two FLAT target lists
 * (ASSIGNEE_MOVES = [IN_PROGRESS, SUBMITTED], REVIEWER_MOVES = [COMPLETED,
 * RETURNED, CANCELLED, PENDING, IN_PROGRESS]) and at one point checked only
 * those, never the current status. Reproduced live on the reference: an
 * assignee moved a COMPLETED task back to IN_PROGRESS, got a 204, and left a
 * stale completed_at behind.
 *
 * The maps below SUBSUME both flat lists — every target in ASSIGNEE_MOVES and
 * REVIEWER_MOVES already appears in the corresponding map wherever it is
 * legal, and the map additionally denies it from the wrong current status. So
 * the Laravel controller needs the map check and nothing else; do not
 * reintroduce a flat list "as well", because a flat list that is checked
 * FIRST is how the bug happened.
 *
 * Pure: no Eloquent, no Carbon, no config(). Calendar days are 'Y-m-d'
 * STRINGS, rows arrive as plain arrays.
 */
final class TaskPolicy
{
    /**
     * TS: TASK_STATUSES. Ordered map — iteration order is picker order, the
     * key is the lookup taskStatusLabel() did with .find().
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        'PENDING' => 'Pending',
        'IN_PROGRESS' => 'In Progress',
        'SUBMITTED' => 'Submitted',
        'RETURNED' => 'Returned',
        'COMPLETED' => 'Completed',
        'CANCELLED' => 'Cancelled',
    ];

    /**
     * TS: TASK_PRIORITIES.
     *
     * @var array<string, string>
     */
    public const PRIORITIES = [
        'LOW' => 'Low',
        'NORMAL' => 'Normal',
        'HIGH' => 'High',
        'URGENT' => 'Urgent',
    ];

    /**
     * TS: EMPLOYEE_TRANSITIONS — what the ASSIGNEE may move a task to, keyed
     * by its current status.
     *
     * Employees advance their own work but can never mark it complete: that is
     * the reviewer's call. SUBMITTED is deliberately empty — once submitted,
     * the task sits with the reviewer and the assignee cannot take it back.
     *
     * @var array<string, list<string>>
     */
    public const EMPLOYEE_TRANSITIONS = [
        'PENDING' => ['IN_PROGRESS'],
        'IN_PROGRESS' => ['SUBMITTED'],
        'RETURNED' => ['IN_PROGRESS'],
        'SUBMITTED' => [],
        'COMPLETED' => [],
        'CANCELLED' => [],
    ];

    /**
     * TS: MANAGER_TRANSITIONS — what MANAGEMENT may move a task to: accept,
     * send back, or call it off.
     *
     * A manager cannot start or submit work on someone's behalf (no
     * IN_PROGRESS or SUBMITTED target anywhere), cannot reopen a closed task,
     * and cannot cancel one that is awaiting review — it must be accepted or
     * returned first. Ported as written.
     *
     * @var array<string, list<string>>
     */
    public const MANAGER_TRANSITIONS = [
        'PENDING' => ['CANCELLED'],
        'IN_PROGRESS' => ['CANCELLED'],
        'SUBMITTED' => ['COMPLETED', 'RETURNED'],
        'RETURNED' => ['CANCELLED'],
        'COMPLETED' => [],
        'CANCELLED' => [],
    ];

    /**
     * TS: TASK_STACK_RANK. Manager-facing stack order for a project task
     * table: work that needs review stays at the top, active/open work
     * follows, finished/cancelled work drops out of the way.
     *
     * @var array<string, int>
     */
    public const STACK_RANK = [
        'SUBMITTED' => 0,
        'RETURNED' => 1,
        'IN_PROGRESS' => 2,
        'PENDING' => 3,
        'COMPLETED' => 4,
        'CANCELLED' => 5,
    ];

    /**
     * TS: priorityRank — urgent first.
     *
     * @var array<string, int>
     */
    public const PRIORITY_RANK = [
        'URGENT' => 0,
        'HIGH' => 1,
        'NORMAL' => 2,
        'LOW' => 3,
    ];

    /** TS: taskStatusLabel() */
    public static function statusLabel(string $value): string
    {
        return self::STATUSES[$value] ?? $value;
    }

    /**
     * No TS counterpart: zod's .enum([...]) validated the six literals at the
     * API boundary. The FormRequest replacing it needs the list, and the list
     * has exactly one home.
     */
    public static function isStatus(string $value): bool
    {
        return array_key_exists($value, self::STATUSES);
    }

    /** No TS counterpart, for the same reason as isStatus(). */
    public static function isPriority(string $value): bool
    {
        return array_key_exists($value, self::PRIORITIES);
    }

    /**
     * TS: taskStatusPill() — the one deliberate departure from the reference
     * classes. The React app rendered SUBMITTED as info and IN_PROGRESS as
     * warning; here the tiers follow the app.css pill palette so a task reads
     * as "whose move is it": muted while nobody has started, primary while the
     * assignee is on it, soft success once submitted (the employee's part is
     * done), strong success once the reviewer accepts it, danger when it comes
     * back. Cancelled is as quiet as pending — nothing is waiting on anyone.
     */
    public static function statusPill(string $value): string
    {
        return match ($value) {
            'PENDING' => 'pill pill-muted',
            'IN_PROGRESS' => 'pill pill-primary',
            'SUBMITTED' => 'pill pill-success',
            'COMPLETED' => 'pill pill-success-strong',
            'RETURNED' => 'pill pill-danger',
            'CANCELLED' => 'pill pill-muted',
            default => 'pill pill-muted',
        };
    }

    /** TS: taskPriorityLabel() */
    public static function priorityLabel(string $value): string
    {
        return self::PRIORITIES[$value] ?? $value;
    }

    /**
     * TS: taskPriorityPill(). NORMAL falls through to the default, so an
     * unknown priority renders as info — not as muted. Ported as written.
     */
    public static function priorityPill(string $value): string
    {
        return match ($value) {
            'URGENT' => 'pill pill-danger',
            'HIGH' => 'pill pill-warning',
            'LOW' => 'pill pill-muted',
            default => 'pill pill-info',
        };
    }

    /**
     * TS: priorityRank() — unknown priorities sort as NORMAL.
     *
     * Sort on this with the spaceship operator and NOTHING else:
     * usort($rows, fn ($a, $b) => TaskPolicy::priorityRank($a['priority'])
     *                         <=> TaskPolicy::priorityRank($b['priority']));
     * A comparator returning a float is cast to int by PHP, which silently
     * turns any fractional difference into "equal".
     */
    public static function priorityRank(string $value): int
    {
        return self::PRIORITY_RANK[$value] ?? 2;
    }

    /** TS: taskStackRank() — anything unrecognised sorts last. */
    public static function stackRank(string $value): int
    {
        return self::STACK_RANK[$value] ?? 6;
    }

    /**
     * TS: employeeCanMoveTo() — EMPLOYEE_TRANSITIONS[from] ?? [].
     *
     * @return list<string>
     */
    public static function employeeCanMoveTo(string $from): array
    {
        return self::EMPLOYEE_TRANSITIONS[$from] ?? [];
    }

    /**
     * TS: managerCanMoveTo() — MANAGER_TRANSITIONS[from] ?? [].
     *
     * @return list<string>
     */
    public static function managerCanMoveTo(string $from): array
    {
        return self::MANAGER_TRANSITIONS[$from] ?? [];
    }

    /**
     * The check the reference API layer should have made. Both arguments are
     * required: a target alone decides nothing.
     */
    public static function employeeCanMove(string $from, string $to): bool
    {
        return in_array($to, self::employeeCanMoveTo($from), true);
    }

    /** As employeeCanMove(), for a reviewer. */
    public static function managerCanMove(string $from, string $to): bool
    {
        return in_array($to, self::managerCanMoveTo($from), true);
    }

    /**
     * The 409 message the reference API raises when a move is refused:
     * "That task cannot move to this status from its current state."
     * (tasks/[id]/route.ts, the managerCanMoveTo branch.) The assignee branch
     * answers 403 instead of 409 — it falls through to forbidden() — so the
     * controller decides the status code; this only owns the wording.
     */
    public static function transitionError(): string
    {
        return 'That task cannot move to this status from its current state.';
    }

    /** TS: isTaskClosed() — a task nobody is waiting on any more. */
    public static function isClosed(string $status): bool
    {
        return $status === 'COMPLETED' || $status === 'CANCELLED';
    }

    /** TS: isAwaitingReview() — waiting on a reviewer rather than the assignee. */
    public static function isAwaitingReview(string $status): bool
    {
        return $status === 'SUBMITTED';
    }

    /**
     * TS: isTaskOverdue() — past its due date and still open.
     *
     * Compared on the date key, so a task due today is not overdue until
     * tomorrow. strcmp rather than `<` for the reason given in
     * ProjectPolicy::dateError().
     *
     * @param  array{status: string, due_date?: string|null}  $task
     */
    public static function isOverdue(array $task, string $today): bool
    {
        $due = $task['due_date'] ?? null;

        if ($due === null || $due === '' || self::isClosed($task['status'])) {
            return false;
        }

        return strcmp($due, $today) < 0;
    }

    /**
     * TS: taskDateError() — a task must fall inside its project's window.
     *
     * @param  array{start_date: string, due_date?: string|null}  $project
     */
    public static function dateError(?string $dueDate, array $project): ?string
    {
        if ($dueDate === null || $dueDate === '') {
            return null;
        }

        if (strcmp($dueDate, $project['start_date']) < 0) {
            return 'A task cannot be due before the project starts.';
        }

        $projectDue = $project['due_date'] ?? null;
        if ($projectDue !== null && $projectDue !== '' && strcmp($dueDate, $projectDue) > 0) {
            return 'A task cannot be due after the project deadline.';
        }

        return null;
    }
}
