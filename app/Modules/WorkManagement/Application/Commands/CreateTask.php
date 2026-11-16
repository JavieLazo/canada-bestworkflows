<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Projects\TaskPolicy;
use App\Models\ProjectTask;
use App\Modules\WorkManagement\Application\Queries\ProjectPeople;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Modules\WorkManagement\Application\TaskGroups;
use App\Modules\WorkManagement\Application\TaskHierarchy;
use App\Modules\WorkManagement\Application\WorkNotifications as Notifier;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView;
use App\Modules\WorkManagement\Domain\Tasks\TaskSchedule;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Support\Access;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Task-creation workflow; authorization, validation and persistence belong to WorkManagement.
 *
 * DELIBERATE DIVERGENCE: the reference adds a task to a project whatever its
 * status. Here a COMPLETED or CANCELLED project takes no new task — reopen it
 * first — decided on the project row locked after the task code scan, so a
 * completed project never gains an open task. The task's planned start date
 * (TaskSchedule) is validated beside its due date.
 */
final class CreateTask
{
    public function handle(Access $actor, string $projectId, TaskDraft $input): ?ProjectTaskView
    {
        $project = WorkRules::project($actor, $projectId);
        if (! WorkRules::editsWork($actor, $project, lock: true) && ! ($project === null && WorkRules::managesProjects($actor))) {
            throw new \RuntimeException(WorkRules::forbiddenMessage());
        }
        if ($project === null) {
            return null;
        }
        $title = trim($input->title);
        $description = trim($input->description);
        if (($error = TaskPolicy::briefError($title, $description)) !== null) {
            throw new \RuntimeException($error);
        }
        if (! TaskPolicy::isPriority($input->priority)) {
            throw new \RuntimeException('priority: is not a supported value');
        }
        if (! TaskPolicy::isMode($input->submissionMode)) {
            throw new \RuntimeException('submission_mode: is not a supported value');
        }
        $dueDate = $input->dueDate === '' ? null : $input->dueDate;
        $dateError = TaskPolicy::dateError($dueDate, ['start_date' => WorkRules::dateKey($project->start_date) ?? '', 'due_date' => WorkRules::dateKey($project->due_date)]);
        if ($dateError !== null) {
            throw new \RuntimeException($dateError);
        }
        // The planned start (DELIBERATE DIVERGENCE — see TaskSchedule): optional,
        // inside the project's window and on or before the due date.
        $startDate = $input->startDate === '' ? null : $input->startDate;
        if ($startDate !== null && ! TaskSchedule::isDateKey($startDate)) {
            throw new \RuntimeException('start_date: must be a date');
        }
        $startError = TaskSchedule::startDateError($startDate, $dueDate, WorkRules::dateKey($project->start_date) ?? '', WorkRules::dateKey($project->due_date));
        if ($startError !== null) {
            throw new \RuntimeException($startError);
        }
        // The checklist is entangled, so the list is client-writable: every
        // id is re-resolved here — to the database's own id of an ACTIVE
        // employee — before it is written, or the save is refused.
        $assigneeIds = app(ProjectPeople::class)->resolveAssignees($actor, $projectId, WorkRules::cleanIds($input->assigneeIds));
        if (is_string($assigneeIds)) {
            throw new \RuntimeException($assigneeIds);
        }
        // Individual responses need two people to tell apart: a smaller crew
        // is stored GROUP whatever was asked.
        $mode = TaskSubmissionPolicy::creationMode($input->submissionMode, count($assigneeIds));
        $byUserId = $actor->userId === null ? null : (int) $actor->userId;
        try {
            /** @var ProjectTask $task */
            $task = DB::transaction(function () use ($actor, $byUserId, $project, $title, $description, $startDate, $dueDate, $assigneeIds, $input, $mode): ProjectTask {
                $codes = DB::table('project_tasks')->lockForUpdate()->pluck('code')->all();
                // P, after the task rows the code scan locked: a completed or
                // cancelled project takes no new work — a completed one would
                // then hold an open task (ManualProjectStatus) — and a close
                // racing this create either committed first and is seen here,
                // or waits and then counts the new task.
                $locked = WorkRules::lockedProject($actor, $project->id);
                if ($locked === null) {
                    throw new \RuntimeException('That project no longer exists.');
                }
                if (! WorkRules::editsWork($actor, $locked, lock: true)) {
                    throw new \RuntimeException(WorkRules::forbiddenMessage());
                }
                if (is_string(app(ProjectPeople::class)->resolveAssignees($actor, $locked->id, $assigneeIds))) {
                    throw new \RuntimeException('Choose active employees with client access for this task.');
                }
                if (in_array($locked->status, ['COMPLETED', 'CANCELLED'], true)) {
                    throw new \RuntimeException('Reopen the project before adding a task to it.');
                }
                // The window as it stands now: a date change that committed
                // while this create waited is checked again here.
                $window = ['start_date' => WorkRules::dateKey($locked->start_date) ?? '', 'due_date' => WorkRules::dateKey($locked->due_date)];
                $windowError = TaskPolicy::dateError($dueDate, $window)
                    ?? TaskSchedule::startDateError($startDate, $dueDate, $window['start_date'], $window['due_date']);
                if ($windowError !== null) {
                    throw new \RuntimeException($windowError);
                }
                $task = new ProjectTask;
                if ($input->parentId !== null) {
                    TaskHierarchy::validateParent($project->id, $input->parentId);
                    if ($input->groupId !== null) {
                        throw new \RuntimeException('Subtasks inherit their parent task\'s activity.');
                    }
                    $task->parent_id = $input->parentId;
                    $task->group_id = null;
                } else {
                    $task->group_id = TaskGroups::resolve($project->id, $input->groupId);
                }
                $task->fill(['code' => WorkRules::nextCode('TSK', $codes), 'project_id' => $project->id, 'title' => $title, 'description' => $description === '' ? null : $description, 'status' => 'PENDING', 'priority' => $input->priority, 'submission_mode' => $mode, 'start_date' => $startDate, 'due_date' => $dueDate, 'assigned_at' => CarbonImmutable::now()]);
                // Actor column: the session, never the request.
                $task->setAttribute('assigned_by', $byUserId);
                $task->save();
                // Membership rows, minted through the pivot model in the same
                // transaction — a failed insert takes the task with it.
                $task->syncAssignees($assigneeIds, $byUserId);

                return $task;
            });
        } catch (QueryException) {
            throw new \RuntimeException('A record with those details already exists.');
        }
        $names = WorkRules::assigneeNames($task);
        $individual = TaskSubmissionPolicy::isIndividual($mode) ? ' (individual responses)' : '';
        AuditService::record($actor, AuditEvent::TASK_ASSIGNED, 'project_tasks', $task->id, $assigneeIds !== [] ? "Task {$task->code} — {$title} assigned to {$names}{$individual}" : "Task {$task->code} — {$title} created without an assignee");
        Notifier::employees($actor, $task, $assigneeIds, [], 'TASK_ASSIGNED', "Task {$task->code} assigned to you", WorkRules::assignedBody($title, $startDate, $dueDate), '/my-tasks');

        return app(WorkRecords::class)->task($actor, $task->id, $projectId);
    }
}
