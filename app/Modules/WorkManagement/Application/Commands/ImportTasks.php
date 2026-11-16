<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Projects\TaskPolicy;
use App\Models\ProjectTask;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Modules\WorkManagement\Application\Queries\ClientPeople;
use App\Modules\WorkManagement\Application\Queries\ProjectPeople;
use App\Modules\WorkManagement\Application\Queries\WorkExports;
use App\Modules\WorkManagement\Application\TaskDetails;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Platform\Transfer\ImportLayout;
use App\Platform\Transfer\ImportOutcome;
use App\Platform\Transfer\ImportRow;
use App\Platform\Transfer\TableImport;
use App\Support\Access;
use App\Support\EmployeeName;
use RuntimeException;

/**
 * One project's task list read back from its CSV. `$context` is the
 * project's id; its manager or delegated task editor may import planning
 * changes. Status and handed-in work keep their existing authority checks.
 *
 * A blank Code creates through {@see CreateTask} (the minted TSK- code, the
 * project window, the assignees told once it commits); a new task starts
 * Pending. A known Code saves priority, dates and assignees through
 * {@see UpdateTaskDetails}, then a changed status through
 * {@see ChangeTaskStatus} — the manual map, the no-self-review rule, and the
 * note some moves require (those are refused here; make them on screen).
 * Rename briefs in the task panel so their prior versions stay in the thread.
 * CSV edits keep titles unchanged. Assignees are named as the table names them (or by
 * their ID), "; "-separated.
 */
final class ImportTasks implements TableImport
{
    /** @var array<string, list<string>>|null lower-cased name or employee code => employee ids */
    private ?array $people = null;

    private ?string $peopleContext = null;

    public function layout(Access $actor, string $context): ImportLayout
    {
        return new ImportLayout('tasks', 'Code', WorkExports::TASK_COLUMNS, [
            'Assignees lists people as the table names them (or by their ID), separated by "; ". A dash or a blank cell means nobody.',
            'Taking someone off Assignees removes them from the task, as the task screen does; on a task with individual responses, their response goes with them.',
            'Priority is one of '.implode(', ', TaskPolicy::PRIORITIES).'. Status moves only as the task screen allows; a new task starts Pending.',
            'Rename existing tasks in their task panel. CSV updates keep the task title unchanged.',
        ], 'Leave Code blank to add a new task. A row whose Code matches a task in this project updates it. No task is ever deleted.');
    }

    public function allows(Access $actor, string $context): bool
    {
        return $context !== '' && WorkRules::editsWork($actor, WorkRules::project($actor, $context));
    }

    public function row(Access $actor, string $context, ImportRow $row): ImportOutcome
    {
        abort_unless($this->allows($actor, $context), 403);
        $code = $row->identifier('Code');
        $task = null;
        if ($code !== '') {
            $task = ProjectTask::query()->where('project_id', $context)->where('code', $code)->first();
            if ($task === null) {
                throw new RuntimeException("This project has no task {$code}. Leave Code blank to add a new task.");
            }
        }

        $title = trim($row->text('Task'));
        $priority = $row->choice('Priority', TaskPolicy::PRIORITIES);
        $status = $row->choice('Status', TaskPolicy::STATUSES);
        $start = $row->date('Start date') ?? '';
        $due = $row->date('Due date') ?? '';
        $assignees = $this->assignees($actor, $row->text('Assignees'), $context, $task?->id);

        if ($task === null) {
            if ($status !== '' && $status !== 'PENDING') {
                throw new RuntimeException('A new task starts as Pending; change its status once it exists.');
            }
            // Null: the project left the actor's scope or was deleted meanwhile.
            app(CreateTask::class)->handle($actor, $context, new TaskDraft($title, '', $priority === '' ? 'NORMAL' : $priority, $due, $assignees, 'GROUP', $start))
                ?? throw new RuntimeException('That project no longer exists.');

            return ImportOutcome::CREATED;
        }

        if ($title !== $task->title) {
            throw new RuntimeException("Task titles cannot be changed; {$task->code} is \"{$task->title}\".");
        }
        $current = $task->assigneeIds();
        sort($current);
        $wanted = $assignees;
        sort($wanted);
        $priority = $priority === '' ? $task->priority : $priority;

        $changed = false;
        if ($priority !== $task->priority || $due !== (WorkRules::dateKey($task->due_date) ?? '')
            || $start !== (WorkRules::dateKey($task->start_date) ?? '') || $wanted !== $current) {
            app(UpdateTaskDetails::class)->handle($actor, $context, (string) $task->getKey(), new TaskDetails($priority, $due, $assignees, null, $start))
                ?? throw new RuntimeException('That task no longer exists.');
            $changed = true;
        }
        if ($status !== '' && $status !== $task->status) {
            app(ChangeTaskStatus::class)->handle($actor, $context, (string) $task->getKey(), $status, '', $task->status);
            $changed = true;
        }

        return $changed ? ImportOutcome::UPDATED : ImportOutcome::UNCHANGED;
    }

    /**
     * "Ana Garcia; Ben Reyes" → employee ids. CreateTask and
     * UpdateTaskDetails re-resolve each one as an ACTIVE employee.
     *
     * @return list<string>
     */
    private function assignees(Access $actor, string $cell, string $projectId, ?string $taskId): array
    {
        $cell = trim($cell);
        if ($cell === '' || $cell === EmployeeName::display(null) || $cell === '-') {
            return [];
        }
        $context = $projectId.':'.($taskId ?? '');
        if ($this->people === null || $this->peopleContext !== $context) {
            $this->peopleContext = $context;
            $this->people = [];
            $project = WorkRules::project($actor, $projectId);
            $employees = ! WorkRules::managesProject($actor, $project)
                ? app(ProjectPeople::class)->employees($actor, $projectId, taskId: $taskId)
                : ($project?->client_id === null ? app(OperationalDirectory::class)->list($actor)
                    : app(ClientPeople::class)->employees($actor, $project->client_id, taskId: $taskId));
            foreach ($employees as $employee) {
                $this->people[mb_strtolower(EmployeeName::display($employee))][] = $employee->id;
                $this->people[mb_strtolower($employee->employee_code)][] = $employee->id;
            }
        }

        $ids = [];
        foreach (preg_split('/\s*;\s*/', $cell) ?: [] as $name) {
            if ($name === '') {
                continue;
            }
            $matches = array_values(array_unique($this->people[mb_strtolower($name)] ?? []));
            $ids[] = match (count($matches)) {
                1 => $matches[0],
                0 => throw new RuntimeException("Assignees: nobody is named {$name}."),
                default => throw new RuntimeException("Assignees: more than one person is named {$name}; write their ID instead."),
            };
        }

        return array_values(array_unique($ids));
    }
}
