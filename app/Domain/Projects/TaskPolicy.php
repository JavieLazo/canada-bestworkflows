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
 * a command must NEVER check a flat target list INSTEAD of the map, because a
 * flat list checked on its own is how the bug happened. A vocabulary
 * whitelist may narrow the map (ReviewTask names the three decisions it
 * takes), never replace it. The map says only that a move EXISTS: some moves
 * carry further gates — no self-review in ReviewTask, and an INDIVIDUAL
 * completion (see INDIVIDUAL_MANAGER_TRANSITIONS).
 *
 * DELIBERATE DIVERGENCE: the reference has one assignee per task and no
 * submission mode (copems prisma/schema.prisma:401-402, projects.ts
 * 227-244). ems's shared multi-assignee task is what it calls GROUP — one
 * response for the whole crew. ems adds INDIVIDUAL, where each assignee hands
 * in their own response and the task itself only goes PENDING → IN_PROGRESS →
 * COMPLETED/CANCELLED: it never enters SUBMITTED or RETURNED, because those
 * states live on each member's response (TaskSubmissionPolicy in the
 * WorkManagement module). The move methods take the mode as a trailing
 * argument: null or GROUP is the reference maps BYTE FOR BYTE, INDIVIDUAL is
 * the two INDIVIDUAL_* maps, and any other value allows NO move.
 *
 * Pure: no Eloquent, no Carbon, no config(). Calendar days are 'Y-m-d'
 * STRINGS, rows arrive as plain arrays.
 */
final class TaskPolicy
{
    public const int TITLE_LIMIT = 300;

    public const int DESCRIPTION_LIMIT = 8000;

    public static function briefError(string $title, string $description): ?string
    {
        return match (true) {
            trim($title) === '' => 'Give the task a title.',
            mb_strlen($title) > self::TITLE_LIMIT => 'title: must be at most '.self::TITLE_LIMIT.' characters',
            mb_strlen($description) > self::DESCRIPTION_LIMIT => 'description: must be at most '.self::DESCRIPTION_LIMIT.' characters',
            default => null,
        };
    }

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
     * returned first. Ported as written, and pinned verbatim by
     * TaskSubmissionPolicyTest. The moves it leaves out are reachable ONLY as
     * a status set BY HAND by whoever runs the project, through the
     * WorkManagement module's ManualTaskStatus map (DELIBERATE DIVERGENCE) —
     * a separate map, never an edit of this one.
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

    /** One shared response for the whole crew — the reference behaviour. */
    public const MODE_GROUP = 'GROUP';

    /** Each assignee hands in, and is reviewed on, their own response. */
    public const MODE_INDIVIDUAL = 'INDIVIDUAL';

    /**
     * No TS counterpart (see the class docblock). Ordered map — iteration
     * order is picker order, and the labels are the picker's option text.
     *
     * @var array<string, string>
     */
    public const MODES = [
        self::MODE_GROUP => 'One team response',
        self::MODE_INDIVIDUAL => 'Each assignee submits their own response',
    ];

    /**
     * EMPLOYEE_TRANSITIONS for an INDIVIDUAL task: the assignee may only
     * start it. Handing in and resubmitting move the member's own response
     * (TaskSubmissionPolicy::MEMBER_TRANSITIONS), never the task.
     *
     * @var array<string, list<string>>
     */
    public const INDIVIDUAL_EMPLOYEE_TRANSITIONS = [
        'PENDING' => ['IN_PROGRESS'],
        'IN_PROGRESS' => [],
        'SUBMITTED' => [],
        'RETURNED' => [],
        'COMPLETED' => [],
        'CANCELLED' => [],
    ];

    /**
     * MANAGER_TRANSITIONS for an INDIVIDUAL task: complete it, or call it
     * off. Accepting and returning happen per response (TaskSubmissionPolicy::
     * REVIEWER_TRANSITIONS), so SUBMITTED and RETURNED are empty here.
     *
     * IN_PROGRESS → COMPLETED only says the move EXISTS. Whoever makes it
     * must also pass the completion gate (TaskSubmissionPolicy::
     * completeError(): every current assignee's response approved), read
     * under the same lock as the status write.
     *
     * @var array<string, list<string>>
     */
    public const INDIVIDUAL_MANAGER_TRANSITIONS = [
        'PENDING' => ['CANCELLED'],
        'IN_PROGRESS' => ['COMPLETED', 'CANCELLED'],
        'SUBMITTED' => [],
        'RETURNED' => [],
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
     * API boundary. Here the WorkManagement commands validate their own
     * input, and whatever checks a status against the list asks this one
     * home for it.
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

    /** No TS counterpart: the reference has no submission mode at all. */
    public static function isMode(string $value): bool
    {
        return array_key_exists($value, self::MODES);
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
     * TS: employeeCanMoveTo() — EMPLOYEE_TRANSITIONS[from] ?? [], for a null
     * or GROUP mode. See the class docblock for the other modes.
     *
     * @return list<string>
     */
    public static function employeeCanMoveTo(string $from, ?string $mode = null): array
    {
        return self::transitionsFor($mode, self::EMPLOYEE_TRANSITIONS, self::INDIVIDUAL_EMPLOYEE_TRANSITIONS)[$from] ?? [];
    }

    /**
     * TS: managerCanMoveTo() — MANAGER_TRANSITIONS[from] ?? [], for a null or
     * GROUP mode. See the class docblock for the other modes.
     *
     * @return list<string>
     */
    public static function managerCanMoveTo(string $from, ?string $mode = null): array
    {
        return self::transitionsFor($mode, self::MANAGER_TRANSITIONS, self::INDIVIDUAL_MANAGER_TRANSITIONS)[$from] ?? [];
    }

    /**
     * The check the reference API layer should have made. Both statuses are
     * required: a target alone decides nothing. Pass the task's mode; a
     * caller that omits it gets the GROUP (reference) map.
     */
    public static function employeeCanMove(string $from, string $to, ?string $mode = null): bool
    {
        return in_array($to, self::employeeCanMoveTo($from, $mode), true);
    }

    /** As employeeCanMove(), for a reviewer. */
    public static function managerCanMove(string $from, string $to, ?string $mode = null): bool
    {
        return in_array($to, self::managerCanMoveTo($from, $mode), true);
    }

    /**
     * The map a mode moves through. An unrecognised mode gets NO map, so a
     * corrupt or future value fails closed instead of borrowing GROUP's moves.
     *
     * @param  array<string, list<string>>  $group
     * @param  array<string, list<string>>  $individual
     * @return array<string, list<string>>
     */
    private static function transitionsFor(?string $mode, array $group, array $individual): array
    {
        return match ($mode) {
            null, self::MODE_GROUP => $group,
            self::MODE_INDIVIDUAL => $individual,
            default => [],
        };
    }

    /**
     * The 409 message the reference API raises when a move is refused:
     * "That task cannot move to this status from its current state."
     * (tasks/[id]/route.ts, the managerCanMoveTo branch; the assignee branch
     * answered 403 instead.) ems has no status code to pick: the commands
     * throw it and the screens show it as a toast. This only owns the wording.
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
