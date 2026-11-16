<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Projects\TaskPolicy;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Modules\WorkManagement\Application\Queries\ProjectPeople;
use App\Modules\WorkManagement\Application\TaskDateEdit;
use App\Modules\WorkManagement\Application\TaskDetails;
use App\Modules\WorkManagement\Application\TaskPropertyEdit;
use App\Modules\WorkManagement\Application\WorkNotifications as Notifier;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Domain\Tasks\TaskSchedule;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Task-details workflow; authorization, validation and persistence belong to WorkManagement.
 *
 * The submission mode moves only while nothing has been handed in: no GROUP
 * hand-in, no member response, no SUBMISSION evidence, and the task still
 * PENDING or IN_PROGRESS (TaskSubmissionPolicy::modeLocked()). That is
 * re-checked under the task-row lock (L1 in WorkRules), where every writer of
 * those facts queues too. A null mode on the input means "not touched" and
 * never changes it; with fewer than two people on the crew the stored mode is
 * kept, so an assignee edit is never refused over it.
 *
 * The planned start date (DELIBERATE DIVERGENCE, TaskSchedule) is
 * one more field: null on the input leaves it alone. Managers and task editors
 * plan these fields; a project lead without editing rights changes only the
 * start date — everything else they post is ignored as untouched — and is
 * re-checked on the project row locked right after the task (P after L1),
 * where the start and due date that will stand are checked again against the
 * project's window as it stands.
 */
final class UpdateTaskDetails
{
    public function handle(Access $actor, string $projectId, ?string $taskId, TaskDetails|TaskDateEdit|TaskPropertyEdit $input): ?string
    {
        // A task editor plans the fields; the project's Task Leader
        // only the start date (checked below, once the changes are known).
        // Anyone else is refused before learning whether the task exists.
        $task = WorkRules::task($actor, $projectId, $taskId);
        $editsWork = WorkRules::editsWork($actor, $task?->project);
        if (! $editsWork && ($task === null || ! WorkRules::runsTask($actor, $task))) {
            throw new \RuntimeException(WorkRules::forbiddenMessage());
        }
        if ($task === null || $task->project === null) {
            return null;
        }
        $property = $input instanceof TaskPropertyEdit ? $input : null;
        if ($property !== null) {
            if (! $editsWork && $property->field !== 'start') {
                throw new \RuntimeException(WorkRules::forbiddenMessage());
            }
            self::checkPropertyVersion($task, $property);
            $value = is_string($property->value) ? $property->value : null;
            $input = new TaskDetails(
                $property->field === 'priority' ? ($value ?? '') : $task->priority,
                $property->field === 'due' ? $value : null,
                $property->field === 'assignees' && is_array($property->value) ? $property->value : $task->assigneeIds(),
                $property->field === 'mode' ? $value : null,
                $property->field === 'start' ? $value : null,
            );
        }
        $dates = $input instanceof TaskDateEdit ? $input : null;
        if ($dates !== null) {
            self::checkDateVersion($task, $dates);
            if (! $editsWork && $dates->dueDate !== (WorkRules::dateKey($task->due_date) ?? '')) {
                throw new \RuntimeException(WorkRules::forbiddenMessage());
            }
            $input = new TaskDetails($task->priority, $dates->dueDate, $task->assigneeIds(), null, $dates->startDate);
        }
        // The leader's panel shows the start date alone: every other field
        // is "untouched", whatever was posted — so a manager's concurrent
        // edit of them is never compared against, reverted or refused over.
        if (! $editsWork) {
            $input = new TaskDetails($task->priority, null, $task->assigneeIds(), null, $input->startDate, $input->seenStartDate);
        }
        self::checkDateVersion($task, $input);
        if (TaskPolicy::isClosed($task->status)) {
            throw new \RuntimeException('A closed task can no longer be edited.');
        }
        if (! TaskPolicy::isPriority($input->priority)) {
            throw new \RuntimeException('priority: is not a supported value');
        }
        if ($input->submissionMode !== null && ! TaskPolicy::isMode($input->submissionMode)) {
            throw new \RuntimeException('submission_mode: is not a supported value');
        }
        $nextDue = $input->dueDate === null ? WorkRules::dateKey($task->due_date) : ($input->dueDate === '' ? null : $input->dueDate);
        // Null = untouched: the stored start stands, and is still checked
        // against a moved due date.
        if ($input->startDate !== null && $input->startDate !== '' && ! TaskSchedule::isDateKey($input->startDate)) {
            throw new \RuntimeException('start_date: must be a date');
        }
        $currentStart = WorkRules::dateKey($task->start_date);
        $nextStart = $input->startDate === null ? $currentStart : ($input->startDate === '' ? null : $input->startDate);
        $dateError = self::dateError($nextStart, $nextDue, WorkRules::dateKey($task->project->start_date) ?? '', WorkRules::dateKey($task->project->due_date));
        if ($dateError !== null) {
            throw new \RuntimeException($dateError);
        }
        // The assignee SET is diffed, not a scalar: order in the checklist
        // does not matter, membership does. Only NEWCOMERS must be active:
        // someone assigned earlier and since deactivated may stay on (or be
        // unticked). Newcomers are resolved to the database's own ids and
        // the set is diffed AFTER that, so a current id posted in
        // another letter case collapses onto its own row — a no-op — instead
        // of reaching the pivot's case-insensitive unique index as an insert.
        $posted = WorkRules::cleanIds($input->assigneeIds);
        $currentIds = $task->assigneeIds();
        $newcomers = array_values(array_diff($posted, $currentIds));
        // Only newcomers need eligibility checks; current assignees keep their records.
        $incoming = $newcomers === [] ? [] : app(ProjectPeople::class)->resolveAssignees($actor, $projectId, $newcomers);
        if (is_string($incoming)) {
            throw new \RuntimeException($incoming);
        }
        $nextIds = array_values(array_unique([...array_values(array_intersect($posted, $currentIds)), ...$incoming]));
        $sortedNext = $nextIds;
        sort($sortedNext, SORT_STRING);
        $changedAssignees = $sortedNext !== $currentIds;
        $changedPriority = $input->priority !== $task->priority;
        $changedDueDate = $nextDue !== WorkRules::dateKey($task->due_date);
        $changedStartDate = $nextStart !== $currentStart;
        // Null = untouched. A posted mode counts only for a crew of two or
        // more; below that the stored mode stays whatever it is.
        $currentMode = $task->submission_mode;
        $nextMode = $input->submissionMode !== null && count($nextIds) >= 2 ? $input->submissionMode : $currentMode;
        $changedMode = $nextMode !== $currentMode;
        if (! $changedAssignees && ! $changedPriority && ! $changedDueDate && ! $changedMode && ! $changedStartDate) {
            return '';
        }
        $data = [];
        if ($changedStartDate) {
            $data['start_date'] = $nextStart;
        }
        if ($changedPriority) {
            $data['priority'] = $input->priority;
        }
        if ($changedDueDate) {
            $data['due_date'] = $nextDue;
        }
        // Set HERE, not inside the transaction: the closure writes all of
        // `$data` or nothing, so the changed-field list audited below from
        // this same array is exactly what was written.
        if ($changedMode) {
            $data['submission_mode'] = $nextMode;
        }
        $byUserId = $actor->userId === null ? null : (int) $actor->userId;
        // Optimistic concurrency, as the reference `updateMany({id, status})`.
        // L1 first: the task row FOR UPDATE, then the row must still be in the
        // status and mode seen earlier. The hand-in guard uses locking reads
        // so an earlier snapshot cannot hide evidence. Discard counts stay
        // plain after L1, where their writers also queue. The
        // pivot sync rides in the same transaction, after the guarded UPDATE.
        // When only the assignees moved `$data` is empty and the Eloquent
        // update still stamps updated_at — the task DID change. A constraint
        // that still fails degrades to the toast saveTask() uses, never a 500.
        try {
            /** @var array{count: int, added: list<string>, removed: list<string>, discarded: int} $result */
            $result = DB::transaction(function () use ($actor, $incoming, $editsWork, $task, $data, $changedMode, $nextIds, $changedAssignees, $byUserId, $nextStart, $nextDue, $changedStartDate, $changedDueDate, $dates, $input, $property): array {
                $unchanged = ['count' => 0, 'added' => [], 'removed' => [], 'discarded' => 0];
                $locked = WorkRules::lockedTask($actor, $task->id);
                if ($locked === null || $locked->status !== $task->status || $locked->submission_mode !== $task->submission_mode) {
                    return $unchanged;
                }
                if ($property !== null) {
                    self::checkPropertyVersion($locked, $property, true);
                }
                // P, right after L1: the leader and the project's window as
                // they stand now. A leader named away while this request
                // waited is refused, and a date checked against a window
                // that has since moved is checked again.
                $project = WorkRules::lockedProject($actor, $locked->project_id);
                if ($project === null) {
                    return $unchanged;
                }
                // A leader added to the crew while this waited plans nothing
                // on it (L2: a locking read of their place on the crew).
                if ($editsWork && ! WorkRules::editsWork($actor, $project, lock: true)) {
                    throw new \RuntimeException(WorkRules::forbiddenMessage());
                }
                if ($incoming !== [] && is_string(app(ProjectPeople::class)->resolveAssignees($actor, $project->id, $incoming))) {
                    throw new \RuntimeException('Choose active employees with client access for this task.');
                }
                if (! $editsWork && (! WorkRules::leadsProject($actor, $project) || WorkRules::stillAssigned($locked, $actor))) {
                    throw new \RuntimeException(WorkRules::forbiddenMessage());
                }
                self::checkDateVersion($locked, $dates ?? $input);
                // Both dates as they will stand: the posted one where it
                // changed, the LOCKED row's otherwise — a start moved by a
                // leader while a manager moved the due date is checked as a
                // pair, never against a date read before the lock.
                if ($changedStartDate || $changedDueDate) {
                    $start = $changedStartDate ? $nextStart : WorkRules::dateKey($locked->start_date);
                    $due = $changedDueDate ? $nextDue : WorkRules::dateKey($locked->due_date);
                    $dateError = self::dateError($start, $due, WorkRules::dateKey($project->start_date) ?? '', WorkRules::dateKey($project->due_date));
                    if ($dateError !== null) {
                        throw new \RuntimeException($dateError);
                    }
                }
                $modeLocked = ($changedMode || $changedAssignees) && TaskSubmissionPolicy::modeLocked(
                    $locked->status,
                    TaskSubmissionPolicy::groupHandedIn($locked->submitted_by, $locked->submitted_at, $locked->remarks),
                    ProjectTaskSubmission::query()->where('task_id', $locked->id)->lockForUpdate()->first(['id']) !== null ? 1 : 0,
                    ProjectTaskFile::query()->where('task_id', $locked->id)->where('kind', 'SUBMISSION')->lockForUpdate()->first(['id']) !== null,
                );
                if ($changedAssignees && $modeLocked && ! WorkRules::managesProject($actor, $project)) {
                    throw new \RuntimeException('A project manager must change the crew once work or evidence is handed in.');
                }
                if ($changedMode && $modeLocked) {
                    throw new \RuntimeException('The submission type is locked once anyone has handed in work or attached evidence.');
                }
                // Response rows are a subset of the crew, so the rows of
                // everyone not staying are exactly what the sync will delete.
                $discarded = $changedAssignees
                    ? ProjectTaskSubmission::query()->where('task_id', $locked->id)->whereNotIn('employee_id', $nextIds)->count()
                    : 0;
                $count = ProjectTask::query()->whereKey($task->id)->where('status', $task->status)->update($data);
                if ($count === 0 || ! $changedAssignees) {
                    return ['count' => $count, 'added' => [], 'removed' => [], 'discarded' => 0];
                }
                $diff = $task->syncAssignees($nextIds, $byUserId);

                return ['count' => $count, 'added' => $diff['added'], 'removed' => $diff['removed'], 'discarded' => $discarded];
            });
        } catch (QueryException) {
            throw new \RuntimeException('A record with those details already exists.');
        }
        if ($result['count'] === 0) {
            throw new \RuntimeException('That task changed while you were editing it.');
        }
        $changedFields = array_keys($data);
        if ($changedAssignees) {
            $changedFields[] = 'assignees';
        }
        // A removed member's response goes with their place on the crew
        // (ProjectTask::syncAssignees); this count is the only trace it
        // leaves, and never its content.
        $discarded = $result['discarded'] > 0
            ? sprintf(' (%d %s discarded)', $result['discarded'], $result['discarded'] === 1 ? 'response' : 'responses')
            : '';
        // Server-canonical name: a details edit is always TASK_UPDATED, even
        // when the assignees moved (AuditEvent::taskDetails documents why the
        // demo's TASK_REASSIGNED name is read-only here).
        AuditService::record($actor, AuditEvent::taskDetails($changedAssignees), 'project_tasks', $task->id, sprintf('Task %s "%s" updated (%s)%s', $task->code, $task->title, implode(', ', $changedFields), $discarded));
        // ADDED employees are told they have a task; REMOVED that it moved on
        // — or, when nobody is left on it, simply that it is no longer theirs;
        // anyone retained hears nothing — their work did not change.
        $taskLabel = WorkRules::taskLabel($task);
        Notifier::employees($actor, $task, $result['added'], [], 'TASK_ASSIGNED', "{$taskLabel} assigned to you", WorkRules::assignedBody($task->title, $nextStart, $nextDue), '/my-tasks');
        Notifier::employees($actor, $task, $result['removed'], [], 'TASK_REASSIGNED', "{$taskLabel} was reassigned", $nextIds === [] ? "You were removed from {$taskLabel} — it is no longer assigned to you." : 'The task is now assigned to another employee.', '/my-tasks');

        return $task->code;
    }

    private static function checkDateVersion(ProjectTask $task, TaskDateEdit|TaskDetails $input): void
    {
        $start = $input->startDate === null ? null : $input->seenStartDate;
        $due = $input->dueDate === null ? null : $input->seenDueDate;
        if (($start !== null && $start !== (WorkRules::dateKey($task->start_date) ?? ''))
            || ($due !== null && $due !== (WorkRules::dateKey($task->due_date) ?? ''))) {
            throw new \RuntimeException('Task dates changed since you opened them. Reset or reopen the editor and try again.');
        }
    }

    /**
     * The due date by the reference's rule (TaskPolicy::dateError, a literal
     * port), then the planned start by ems's (TaskSchedule).
     */
    private static function dateError(?string $start, ?string $due, string $projectStart, ?string $projectDue): ?string
    {
        return TaskPolicy::dateError($due, ['start_date' => $projectStart, 'due_date' => $projectDue])
            ?? TaskSchedule::startDateError($start, $due, $projectStart, $projectDue);
    }

    private static function checkPropertyVersion(ProjectTask $task, TaskPropertyEdit $input, bool $lock = false): void
    {
        if (! in_array($input->field, ['priority', 'assignees', 'mode', 'start', 'due'], true)
            || ($input->field === 'assignees' ? ! is_array($input->value) || ! is_array($input->seen) : ! is_string($input->value) || ! is_string($input->seen))) {
            throw new \RuntimeException('That task property is not supported.');
        }
        $actual = match ($input->field) {
            'priority' => $task->priority,
            'mode' => $task->submission_mode,
            'start' => WorkRules::dateKey($task->start_date) ?? '',
            'due' => WorkRules::dateKey($task->due_date) ?? '',
            default => $lock
                ? ProjectTaskAssignee::query()->where('task_id', $task->id)->lockForUpdate()->pluck('employee_id')->map(static fn ($id): string => (string) $id)->all()
                : $task->assigneeIds(),
        };
        $seen = $input->seen;
        if (is_array($actual) && is_array($seen)) {
            sort($actual, SORT_STRING);
            sort($seen, SORT_STRING);
        }
        if ($actual !== $seen) {
            throw new \RuntimeException('That property changed elsewhere. Reload its latest value before saving.');
        }
    }
}
