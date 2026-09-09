<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Domain\Enums\AuditEvent;
use App\Domain\Projects\ProjectPolicy;
use App\Domain\Projects\TaskPolicy;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\TaskComment;
use App\Models\User;
use App\Models\WorkSite;
use App\Support\Access;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * /projects/{id} — the port of project-detail.tsx plus its three dialogs
 * (edit-project-dialog.tsx, add-task-dialog.tsx, task-review-dialog.tsx).
 *
 * Every mutation follows the server routes (POST /api/tasks,
 * PATCH /api/tasks/[id], PATCH /api/projects/[id], the files and comments
 * routes): actor columns come from {@see Access}, writes re-check
 * requireAllDataModule("PROJECTS") inside the action, and EVERY status move —
 * project or task — goes through the {@see ProjectPolicy} /
 * {@see TaskPolicy} transition maps.
 *
 * ⚠ Divergence from the reference API, on purpose: the reference's task PATCH
 * route once checked flat target lists (REVIEWER_MOVES held PENDING and
 * IN_PROGRESS) instead of the transition maps, which let a closed task be
 * reopened (documented bug — see TaskPolicy's class docblock). Here the maps
 * are the ONLY check, so those two targets are simply unreachable.
 */
#[Title('Project')]
class Show extends Component
{
    use WithFileUploads;
    use WithSorting;
    use WithTableFilters;

    private const array QUEUES = ['ALL', 'REVIEW', 'OVERDUE', 'DUE_SOON', 'UNASSIGNED'];

    /** BRIEF uploads/deletes are allowed only in these task statuses (files route). */
    private const array BRIEF_EDITABLE_STATUSES = ['PENDING', 'IN_PROGRESS', 'RETURNED'];

    #[Locked]
    public string $projectId;

    /* Task-table filters — plain filter state, deliberately unlocked. */
    public string $status = 'ALL';

    public string $assignee = 'ALL';

    public string $priority = 'ALL';

    public string $queue = 'ALL';

    /* ------------------------- edit-project dialog ------------------------- */

    public bool $editing = false;

    public string $editName = '';

    public string $editDescription = '';

    public string $editSiteId = 'NONE';

    public string $editStatus = 'PLANNING';

    public string $editStartDate = '';

    public string $editDueDate = '';

    public string $editManagerId = 'NONE';

    /* --------------------------- add-task dialog --------------------------- */

    public bool $adding = false;

    public string $taskTitle = '';

    public string $taskDescription = '';

    /** Chosen through the kit employee picker; re-validated on save. */
    public string $taskAssigneeId = '';

    public string $taskPriority = 'NORMAL';

    public string $taskDueDate = '';

    /**
     * The picker input this batch landed on. Moved into $briefStage by the
     * updated hook so a second pick APPENDS instead of replacing — the
     * reference dialog staged across picks (add-task-dialog.tsx:88-104).
     *
     * @var list<TemporaryUploadedFile>
     */
    public array $briefPick = [];

    /** @var list<TemporaryUploadedFile> */
    public array $briefStage = [];

    /* -------------------------- task-review dialog ------------------------- */

    public bool $reviewOpen = false;

    #[Locked]
    public ?string $openTaskId = null;

    public string $remarks = '';

    public string $commentBody = '';

    public bool $editingDetails = false;

    public string $draftAssigneeId = '';

    public string $draftPriority = 'NORMAL';

    public string $draftDueDate = '';

    public ?TemporaryUploadedFile $reviewBriefUpload = null;

    public function mount(string $project): void
    {
        $this->projectId = $project;
        $this->sortKey = 'stack';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['stack', 'code', 'task', 'assignee', 'priority', 'due', 'status'];
    }

    /* Every filter select on the detail page resets to page 1
       (project-detail.tsx:553-619); the queue CARDS do not. */
    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedAssignee(): void
    {
        $this->resetPage();
    }

    public function updatedPriority(): void
    {
        $this->resetPage();
    }

    public function updatedQueue(mixed $value): void
    {
        if (! in_array($value, self::QUEUES, true)) {
            $this->queue = 'ALL';

            return;
        }
        $this->resetPage();
    }

    public function setQueue(string $queue): void
    {
        if (in_array($queue, self::QUEUES, true)) {
            $this->queue = $queue;
        }
    }

    /** A workload row narrows the table to that person (project-detail.tsx:436-440). */
    public function filterByAssignee(string $employeeId): void
    {
        $this->assignee = $employeeId;
        $this->queue = 'ALL';
        $this->resetPage();
    }

    #[On('kit-employee-picked')]
    public function employeePicked(string $name, string $id): void
    {
        if ($name === 'task-assignee') {
            $this->taskAssigneeId = $id;
        }
        if ($name === 'review-assignee') {
            $this->draftAssigneeId = $id;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Project status + edit */
    /* ------------------------------------------------------------------ */

    /** The header status select (project-detail.tsx:244-271). */
    public function changeStatus(string $next): void
    {
        $actor = $this->access();
        $project = $this->scopedProject($actor);

        if ($project === null) {
            return;
        }
        if ($next === $project->status) {
            return;
        }
        if (! ProjectsData::managesProjects($actor)) {
            Flux::toast(variant: 'danger', text: ProjectsData::forbiddenMessage());

            return;
        }

        $tasks = $project->tasks()->get()->all();
        $statusError = ProjectPolicy::statusError(
            ProjectsData::projectShape($project),
            ProjectsData::taskShapes($tasks),
            $next,
        );
        if ($statusError !== null) {
            Flux::toast(variant: 'danger', text: $statusError);

            return;
        }

        $from = $project->status;
        $project->status = $next;
        $project->save();

        AuditService::record(
            $actor,
            ProjectPolicy::auditAction($from, $next),
            'projects',
            $project->id,
            sprintf(
                '%s — %s moved from %s to %s',
                $project->code,
                $project->name,
                ProjectPolicy::statusLabel($from),
                ProjectPolicy::statusLabel($next),
            ),
        );

        Flux::toast(variant: 'success', text: 'Project marked '.mb_strtolower(ProjectPolicy::statusLabel($next)).'.');
    }

    /** Opening fills the drafts from the row (edit-project-dialog.tsx:67-76). */
    public function openEdit(): void
    {
        $project = $this->scopedProject($this->access());
        if ($project === null) {
            return;
        }

        $this->editName = $project->name;
        $this->editDescription = $project->description ?? '';
        $this->editSiteId = $project->site_id ?? 'NONE';
        $this->editStatus = $project->status;
        $this->editStartDate = ProjectsData::dateKey($project->start_date) ?? '';
        $this->editDueDate = ProjectsData::dateKey($project->due_date) ?? '';
        $this->editManagerId = $project->manager_id === null ? 'NONE' : (string) $project->manager_id;
        $this->editing = true;
    }

    /** edit-project-dialog.tsx `save()` + PATCH /api/projects/[id]. */
    public function saveProject(): void
    {
        $actor = $this->access();

        if (! ProjectsData::managesProjects($actor)) {
            Flux::toast(variant: 'danger', text: ProjectsData::forbiddenMessage());

            return;
        }

        $project = $this->scopedProject($actor);
        if ($project === null) {
            return;
        }

        $name = trim($this->editName);
        if ($name === '' || $this->editStartDate === '') {
            Flux::toast(variant: 'danger', text: 'A project needs a name and a start date.');

            return;
        }
        if (mb_strlen($name) > 200) {
            Flux::toast(variant: 'danger', text: 'name: must be at most 200 characters');

            return;
        }
        $description = trim($this->editDescription);
        if (mb_strlen($description) > 4000) {
            Flux::toast(variant: 'danger', text: 'description: must be at most 4000 characters');

            return;
        }

        $dueDate = $this->editDueDate === '' ? null : $this->editDueDate;
        $dateError = ProjectPolicy::dateError($this->editStartDate, $dueDate);
        if ($dateError !== null) {
            Flux::toast(variant: 'danger', text: $dateError);

            return;
        }

        $tasks = $project->tasks()->get();
        foreach ($tasks as $task) {
            $issue = TaskPolicy::dateError(ProjectsData::dateKey($task->due_date), [
                'start_date' => $this->editStartDate,
                'due_date' => $dueDate,
            ]);
            if ($issue !== null) {
                Flux::toast(
                    variant: 'danger',
                    text: "The project deadline would leave {$task->code} outside the project timeline.",
                );

                return;
            }
        }

        $managerId = $this->editManagerId === 'NONE' ? null : $this->editManagerId;
        $statusError = ProjectPolicy::statusError(
            ['status' => $project->status, 'manager_id' => $managerId],
            ProjectsData::taskShapes($tasks->all()),
            $this->editStatus,
        );
        if ($statusError !== null) {
            Flux::toast(variant: 'danger', text: $statusError);

            return;
        }

        $siteId = $this->editSiteId === 'NONE' ? null : $this->editSiteId;
        if ($siteId !== null && WorkSite::query()->whereKey($siteId)->doesntExist()) {
            Flux::toast(variant: 'danger', text: 'Choose an existing work site for the project.');

            return;
        }
        if ($managerId !== null) {
            $managerRow = User::query()->whereKey($managerId)->first();
            if ($managerRow === null || ! (bool) $managerRow->getAttribute('is_active')) {
                Flux::toast(variant: 'danger', text: 'Choose an active account as project manager.');

                return;
            }
        }

        $from = $project->status;
        $project->fill([
            'name' => $name,
            'description' => $description === '' ? null : $description,
            'site_id' => $siteId,
            'status' => $this->editStatus,
            'start_date' => $this->editStartDate,
            'due_date' => $dueDate,
        ]);
        $project->setAttribute('manager_id', $managerId === null ? null : (int) $managerId);
        $changed = array_keys($project->getDirty());

        try {
            $project->save();
        } catch (QueryException) {
            Flux::toast(variant: 'danger', text: 'A record with those details already exists.');

            return;
        }

        $statusChanged = $this->editStatus !== $from;
        AuditService::record(
            $actor,
            ProjectPolicy::auditAction($from, $this->editStatus),
            'projects',
            $project->id,
            $statusChanged
                ? sprintf(
                    '%s — %s moved from %s to %s',
                    $project->code,
                    $project->name,
                    ProjectPolicy::statusLabel($from),
                    ProjectPolicy::statusLabel($this->editStatus),
                )
                : sprintf('%s — %s updated (%s)', $project->code, $project->name, implode(', ', $changed)),
        );

        Flux::toast(variant: 'success', text: "{$project->code} updated.");
        $this->editing = false;
    }

    /* ------------------------------------------------------------------ */
    /*  Add task */
    /* ------------------------------------------------------------------ */

    public function updatedAdding(mixed $value): void
    {
        if (! $value) {
            $this->resetTaskDraft();
        }
    }

    /** Stage a picked batch, rejecting oversized files one by one. */
    public function updatedBriefPick(): void
    {
        foreach ($this->briefPick as $file) {
            $rejection = ProjectsData::attachmentRejection(
                $file->getClientOriginalName(),
                (int) $file->getSize(),
            );
            if ($rejection !== null) {
                Flux::toast(variant: 'danger', text: $rejection);

                continue;
            }
            $this->briefStage[] = $file;
        }

        $this->briefPick = [];
    }

    public function removeBrief(int $index): void
    {
        $this->briefStage = array_values(array_filter(
            $this->briefStage,
            static fn (int $i): bool => $i !== $index,
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /** add-task-dialog.tsx `save()` + POST /api/tasks. */
    public function saveTask(): void
    {
        $actor = $this->access();

        if (! ProjectsData::managesProjects($actor)) {
            Flux::toast(variant: 'danger', text: ProjectsData::forbiddenMessage());

            return;
        }

        $project = $this->scopedProject($actor);
        if ($project === null) {
            return;
        }

        $title = trim($this->taskTitle);
        if ($title === '') {
            Flux::toast(variant: 'danger', text: 'Give the task a title.');

            return;
        }
        if (mb_strlen($title) > 300) {
            Flux::toast(variant: 'danger', text: 'title: must be at most 300 characters');

            return;
        }
        $description = trim($this->taskDescription);
        if (mb_strlen($description) > 8000) {
            Flux::toast(variant: 'danger', text: 'description: must be at most 8000 characters');

            return;
        }
        if (! TaskPolicy::isPriority($this->taskPriority)) {
            Flux::toast(variant: 'danger', text: 'priority: is not a supported value');

            return;
        }

        $dueDate = $this->taskDueDate === '' ? null : $this->taskDueDate;
        $dateError = TaskPolicy::dateError($dueDate, [
            'start_date' => ProjectsData::dateKey($project->start_date) ?? '',
            'due_date' => ProjectsData::dateKey($project->due_date),
        ]);
        if ($dateError !== null) {
            Flux::toast(variant: 'danger', text: $dateError);

            return;
        }

        $assignee = null;
        if ($this->taskAssigneeId !== '') {
            $assignee = EmployeeProfile::query()->whereKey($this->taskAssigneeId)->first();
            if ($assignee === null || ! (bool) $assignee->is_active) {
                Flux::toast(variant: 'danger', text: 'Choose an active employee for the task.');

                return;
            }
        }

        try {
            /** @var ProjectTask $task */
            $task = DB::transaction(function () use ($actor, $project, $title, $description, $dueDate): ProjectTask {
                $codes = DB::table('project_tasks')->lockForUpdate()->pluck('code')->all();

                $task = new ProjectTask;
                $task->fill([
                    'code' => ProjectsData::nextCode('TSK', $codes),
                    'project_id' => $project->id,
                    'title' => $title,
                    'description' => $description === '' ? null : $description,
                    'assignee_id' => $this->taskAssigneeId === '' ? null : $this->taskAssigneeId,
                    'status' => 'PENDING',
                    'priority' => $this->taskPriority,
                    'due_date' => $dueDate,
                    'assigned_at' => CarbonImmutable::now(),
                ]);
                // Actor column: the session, never the request.
                $task->setAttribute('assigned_by', $actor->userId === null ? null : (int) $actor->userId);
                $task->save();

                return $task;
            });
        } catch (QueryException) {
            Flux::toast(variant: 'danger', text: 'A record with those details already exists.');

            return;
        }

        AuditService::record(
            $actor,
            AuditEvent::TASK_ASSIGNED,
            'project_tasks',
            $task->id,
            "{$task->code} — {$title} assigned",
        );
        ProjectsData::notifyEmployee(
            $task->assignee_id,
            'TASK_ASSIGNED',
            "{$task->code} assigned to you",
            $dueDate !== null ? "{$title} — due ".ProjectsData::fmtDate($dueDate).'.' : $title,
            '/my-tasks',
        );

        // The task is already real, so a file that fails is reported on its
        // own and never rolls the assignment back (add-task-dialog.tsx:159-183).
        $attached = 0;
        foreach ($this->briefStage as $file) {
            $error = $this->storeTaskFile($actor, $task, $file, 'BRIEF');
            if ($error !== null) {
                Flux::toast(
                    variant: 'danger',
                    text: "{$task->code} was created, but {$file->getClientOriginalName()} could not be attached: {$error}",
                );

                continue;
            }
            $attached++;
        }

        if ($attached > 0) {
            // One entry covers the whole batch, pointing at the task the files
            // were handed out with (add-task-dialog.tsx:186-195).
            AuditService::record(
                $actor,
                AuditEvent::TASK_BRIEF_ATTACHED,
                'project_task_files',
                $task->id,
                sprintf('%d brief %s attached to %s', $attached, $attached === 1 ? 'file' : 'files', $task->code),
            );
        }

        Flux::toast(variant: 'success', text: $assignee !== null
            ? "{$task->code} assigned to ".ProjectsData::employeeName($assignee).'.'
            : "{$task->code} created without an assignee.");
        $this->resetTaskDraft();
        $this->adding = false;
    }

    /* ------------------------------------------------------------------ */
    /*  Task review dialog */
    /* ------------------------------------------------------------------ */

    /** A row click opens the dialog; the id is re-resolved through the scope. */
    public function openTask(string $taskId): void
    {
        $task = $this->scopedTask($this->access(), $taskId);
        if ($task === null) {
            return;
        }

        $this->openTaskId = $task->id;
        $this->reviewOpen = true;

        // A new task must not inherit the draft typed against the last
        // (task-review-dialog.tsx:486-492).
        $this->remarks = '';
        $this->commentBody = '';
        $this->editingDetails = false;
        $this->draftAssigneeId = $task->assignee_id ?? '';
        $this->draftPriority = $task->priority;
        $this->draftDueDate = ProjectsData::dateKey($task->due_date) ?? '';
        $this->reviewBriefUpload = null;
    }

    public function updatedReviewOpen(mixed $value): void
    {
        if (! $value) {
            $this->openTaskId = null;
            $this->editingDetails = false;
        }
    }

    public function toggleEditingDetails(): void
    {
        $this->editingDetails = ! $this->editingDetails;
    }

    /** task-review-dialog.tsx `saveDetails()` + the details half of PATCH /api/tasks/[id]. */
    public function saveDetails(): void
    {
        $actor = $this->access();

        if (! ProjectsData::managesProjects($actor)) {
            Flux::toast(variant: 'danger', text: ProjectsData::forbiddenMessage());

            return;
        }

        $task = $this->currentTask($actor);
        if ($task === null || $task->project === null) {
            return;
        }

        if (TaskPolicy::isClosed($task->status)) {
            Flux::toast(variant: 'danger', text: 'A closed task can no longer be edited.');

            return;
        }
        if (! TaskPolicy::isPriority($this->draftPriority)) {
            Flux::toast(variant: 'danger', text: 'priority: is not a supported value');

            return;
        }

        $nextDue = $this->draftDueDate === '' ? null : $this->draftDueDate;
        $dateError = TaskPolicy::dateError($nextDue, [
            'start_date' => ProjectsData::dateKey($task->project->start_date) ?? '',
            'due_date' => ProjectsData::dateKey($task->project->due_date),
        ]);
        if ($dateError !== null) {
            Flux::toast(variant: 'danger', text: $dateError);

            return;
        }

        $nextAssignee = $this->draftAssigneeId === '' ? null : $this->draftAssigneeId;
        $changedAssignee = $nextAssignee !== $task->assignee_id;
        $changedPriority = $this->draftPriority !== $task->priority;
        $changedDueDate = $nextDue !== ProjectsData::dateKey($task->due_date);

        if (! $changedAssignee && ! $changedPriority && ! $changedDueDate) {
            $this->editingDetails = false;

            return;
        }

        if ($changedAssignee && $nextAssignee !== null) {
            $assignee = EmployeeProfile::query()->whereKey($nextAssignee)->first();
            if ($assignee === null || ! (bool) $assignee->is_active) {
                Flux::toast(variant: 'danger', text: 'Choose an active employee for the task.');

                return;
            }
        }

        $data = [];
        if ($changedAssignee) {
            $data['assignee_id'] = $nextAssignee;
        }
        if ($changedPriority) {
            $data['priority'] = $this->draftPriority;
        }
        if ($changedDueDate) {
            $data['due_date'] = $nextDue;
        }

        // Optimistic concurrency, as the reference `updateMany({id, status})`.
        $count = ProjectTask::query()
            ->whereKey($task->id)
            ->where('status', $task->status)
            ->update($data);

        if ($count === 0) {
            Flux::toast(variant: 'danger', text: 'That task changed while you were editing it.');

            return;
        }

        // Server-canonical name: a details edit is always TASK_UPDATED, even
        // when the assignee moved (AuditEvent::taskDetails documents why the
        // demo's TASK_REASSIGNED name is read-only here).
        AuditService::record(
            $actor,
            AuditEvent::taskDetails($changedAssignee),
            'project_tasks',
            $task->id,
            sprintf('%s "%s" updated (%s)', $task->code, $task->title, implode(', ', array_keys($data))),
        );

        if ($changedAssignee) {
            ProjectsData::notifyEmployee(
                $nextAssignee,
                'TASK_REASSIGNED',
                ($task->code !== '' ? $task->code : $task->title).' assigned to you',
                $nextDue !== null ? "{$task->title} — due ".ProjectsData::fmtDate($nextDue).'.' : $task->title,
                '/my-tasks',
            );
            if ($task->assignee_id !== null) {
                ProjectsData::notifyEmployee(
                    $task->assignee_id,
                    'TASK_REASSIGNED',
                    ($task->code !== '' ? $task->code : $task->title).' was reassigned',
                    'The task is now assigned to another employee.',
                    '/my-tasks',
                );
            }
        }

        Flux::toast(variant: 'success', text: "{$task->code} updated.");
        $this->editingDetails = false;
    }

    /** task-review-dialog.tsx `review()` + the status half of PATCH /api/tasks/[id]. */
    public function review(string $decision): void
    {
        if (! in_array($decision, ['COMPLETED', 'RETURNED', 'CANCELLED'], true)) {
            return;
        }

        $actor = $this->access();
        $task = $this->currentTask($actor);
        if ($task === null) {
            return;
        }

        $remark = trim($this->remarks);
        if ($decision === 'RETURNED' && $remark === '') {
            Flux::toast(variant: 'danger', text: 'Say what needs reworking before returning the task.');

            return;
        }
        if (mb_strlen($remark) > 8000) {
            Flux::toast(variant: 'danger', text: 'review_remarks: must be at most 8000 characters');

            return;
        }
        if (! ProjectsData::managesProjects($actor)) {
            Flux::toast(variant: 'danger', text: ProjectsData::forbiddenMessage());

            return;
        }

        // THE MAP, and nothing but the map — never a flat target list.
        if (! TaskPolicy::managerCanMove($task->status, $decision)) {
            Flux::toast(variant: 'danger', text: TaskPolicy::transitionError());

            return;
        }

        $data = [
            'status' => $decision,
            // Actor column, from the session (PATCH route: reviewed_by).
            'reviewed_by' => $actor->userId === null ? null : (int) $actor->userId,
            'review_remarks' => $remark === '' ? null : $remark,
        ];
        // Accepting stamps the completion; returning drops the submission so
        // the assignee is the one being waited on again.
        if ($decision === 'COMPLETED') {
            $data['completed_at'] = CarbonImmutable::now()->format('Y-m-d H:i:s.v');
        }
        if ($decision === 'RETURNED') {
            $data['submitted_at'] = null;
        }

        $count = ProjectTask::query()
            ->whereKey($task->id)
            ->where('status', $task->status)
            ->update($data);

        if ($count === 0) {
            Flux::toast(variant: 'danger', text: 'That task changed before this action completed.');

            return;
        }

        $assignee = $task->assignee_id === null
            ? null
            : EmployeeProfile::query()->whereKey($task->assignee_id)->first();

        $outcome = match ($decision) {
            'COMPLETED' => 'accepted as complete',
            'RETURNED' => $assignee !== null
                ? 'returned to '.ProjectsData::employeeName($assignee).' for rework'
                : 'returned for rework',
            default => 'cancelled',
        };

        AuditService::record(
            $actor,
            AuditEvent::taskStatus($decision),
            'project_tasks',
            $task->id,
            sprintf('%s "%s" → %s', $task->code, $task->title, mb_strtolower($decision)),
        );

        // `review_remarks` only ever holds the latest text, so the remark that
        // decided this round is appended to the thread as well
        // (task-review-dialog.tsx:596-618).
        if ($remark !== '' && in_array($decision, ['RETURNED', 'COMPLETED'], true)) {
            $comment = new TaskComment;
            $comment->fill([
                'task_id' => $task->id,
                'side' => 'MANAGEMENT',
                'body' => $remark,
                'event' => $decision,
            ]);
            $comment->setAttribute('author_id', $actor->userId === null ? null : (int) $actor->userId);
            $comment->setAttribute('author_name', $actor->fullName !== '' ? $actor->fullName : 'Unknown');
            $comment->save();

            AuditService::record(
                $actor,
                AuditEvent::TASK_COMMENTED,
                'task_comments',
                $comment->id,
                "Comment added to {$task->code}",
            );
        }

        $notifyOutcome = match ($decision) {
            'COMPLETED' => 'accepted as complete',
            'RETURNED' => 'returned for rework',
            default => 'cancelled',
        };
        ProjectsData::notifyEmployee(
            $task->assignee_id,
            'TASK_'.$decision,
            ($task->code !== '' ? $task->code : $task->title).' '.$notifyOutcome,
            // A return is the one outcome they must act on, and the remark is why.
            $decision === 'RETURNED' ? $remark : $task->title,
            '/my-tasks',
        );

        Flux::toast(variant: 'success', text: "{$task->code} {$outcome}.");
        $this->remarks = '';
        $this->reviewOpen = false;
        $this->openTaskId = null;
    }

    /** The "Brief & references" attach button in the review dialog. */
    public function updatedReviewBriefUpload(): void
    {
        $file = $this->reviewBriefUpload;
        $this->reviewBriefUpload = null;

        if ($file === null) {
            return;
        }

        $actor = $this->access();
        if (! ProjectsData::managesProjects($actor)) {
            Flux::toast(variant: 'danger', text: ProjectsData::forbiddenMessage());

            return;
        }

        $task = $this->currentTask($actor);
        if ($task === null) {
            return;
        }

        // The files route locks evidence outside these statuses; the dialog
        // keeps the button visible and the server answer becomes this toast.
        if (! in_array($task->status, self::BRIEF_EDITABLE_STATUSES, true)) {
            Flux::toast(variant: 'danger', text: 'Task evidence is locked in this status.');

            return;
        }

        $rejection = ProjectsData::attachmentRejection($file->getClientOriginalName(), (int) $file->getSize());
        if ($rejection !== null) {
            Flux::toast(variant: 'danger', text: $rejection);

            return;
        }

        $error = $this->storeTaskFile($actor, $task, $file, 'BRIEF');
        if ($error !== null) {
            Flux::toast(variant: 'danger', text: $error);

            return;
        }

        AuditService::record(
            $actor,
            AuditEvent::taskFile('BRIEF'),
            'project_task_files',
            null,
            "{$file->getClientOriginalName()} attached to {$task->code}",
        );

        Flux::toast(variant: 'success', text: "{$file->getClientOriginalName()} attached.");
    }

    /** DELETE /api/tasks/files/[id] — the BRIEF side only, on this screen. */
    public function deleteFile(string $fileId): void
    {
        $actor = $this->access();
        $task = $this->currentTask($actor);
        if ($task === null) {
            return;
        }

        $file = ProjectTaskFile::query()
            ->whereKey($fileId)
            ->where('task_id', $task->id)
            ->first();
        if ($file === null) {
            return;
        }

        // The brief is the reviewer's spec — deleting it is not the assignee's
        // to do; the submission is not deletable from this screen at all.
        if ($file->kind !== 'BRIEF' || ! ProjectsData::managesProjects($actor)) {
            Flux::toast(variant: 'danger', text: ProjectsData::forbiddenMessage());

            return;
        }
        if (! in_array($task->status, self::BRIEF_EDITABLE_STATUSES, true)) {
            Flux::toast(variant: 'danger', text: 'Task evidence is locked in this status.');

            return;
        }

        $path = $file->getAttribute('path');
        $file->delete();
        if (is_string($path) && $path !== '') {
            Storage::disk('local')->delete($path);
        }

        AuditService::record(
            $actor,
            AuditEvent::TASK_FILE_REMOVED,
            'project_task_files',
            $fileId,
            "{$file->file_name} removed from {$task->code}",
        );

        Flux::toast(variant: 'success', text: "{$file->file_name} removed.");
    }

    /** TaskComments composer + POST /api/tasks/[id]/comments. */
    public function postComment(): void
    {
        $text = trim($this->commentBody);
        if ($text === '') {
            Flux::toast(variant: 'danger', text: 'Write something before posting.');

            return;
        }
        if (mb_strlen($text) > 8000) {
            Flux::toast(variant: 'danger', text: 'body: must be at most 8000 characters');

            return;
        }

        $actor = $this->access();
        $task = $this->currentTask($actor);
        if ($task === null) {
            return;
        }

        // Side is derived, never sent: whether you are MANAGEMENT or the
        // ASSIGNEE is a fact about the task (comments route).
        $isReviewer = ProjectsData::managesProjects($actor);
        $isAssignee = $task->assignee_id !== null && $task->assignee_id === $actor->employeeId;
        if (! $isReviewer && ! $isAssignee) {
            Flux::toast(variant: 'danger', text: ProjectsData::forbiddenMessage());

            return;
        }

        $comment = new TaskComment;
        $comment->fill([
            'task_id' => $task->id,
            'side' => $isReviewer ? 'MANAGEMENT' : 'ASSIGNEE',
            'body' => $text,
            'event' => null,
        ]);
        $comment->setAttribute('author_id', $actor->userId === null ? null : (int) $actor->userId);
        $comment->setAttribute('author_name', $actor->fullName !== '' ? $actor->fullName : 'Unknown');
        $comment->save();

        $taskLabel = $task->code !== '' ? $task->code : $task->title;

        AuditService::record(
            $actor,
            AuditEvent::TASK_COMMENTED,
            'task_comments',
            $comment->id,
            "Comment added to {$taskLabel}",
        );

        $preview = mb_strlen($text) > 120 ? mb_substr($text, 0, 117).'…' : $text;
        if ($isReviewer) {
            ProjectsData::notifyEmployee($task->assignee_id, 'TASK_COMMENT', "New comment on {$taskLabel}", $preview, '/my-tasks');
        } else {
            ProjectsData::notifyAccount($task->getAttribute('assigned_by'), 'TASK_COMMENT', "New comment on {$taskLabel}", $preview, '/projects');
        }

        $this->commentBody = '';
        Flux::toast(variant: 'success', text: 'Comment posted.');
    }

    /* ------------------------------------------------------------------ */
    /*  Render */
    /* ------------------------------------------------------------------ */

    public function render(): View
    {
        $actor = $this->access();
        $project = $this->scopedProject($actor);
        $today = ProjectsData::todayKey();

        if ($project === null) {
            return view('livewire.projects.show', [
                'project' => null,
                'today' => $today,
            ]);
        }

        /** @var list<ProjectTask> $projectTasks */
        $projectTasks = ProjectsData::scopedTasks($actor)
            ->where('project_id', $project->id)
            ->orderBy('code')
            ->get()
            ->all();
        $taskIds = array_map(static fn (ProjectTask $t): string => $t->id, $projectTasks);

        $files = ProjectTaskFile::query()->whereIn('task_id', $taskIds)->orderBy('created_at')->get();
        $comments = TaskComment::query()->whereIn('task_id', $taskIds)->orderBy('created_at')->get();

        $fileCounts = $files->countBy('task_id');
        $commentCounts = $comments->countBy('task_id');

        $assigneeIds = array_values(array_unique(array_filter(
            array_map(static fn (ProjectTask $t): ?string => $t->assignee_id, $projectTasks),
        )));
        $employees = EmployeeProfile::query()->whereIn('id', $assigneeIds)->get();

        // Only the people actually holding work here are worth filtering by.
        $holders = $employees
            ->sortBy([['last_name', 'asc'], ['first_name', 'asc']], SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $workload = $holders->map(function (EmployeeProfile $employee) use ($projectTasks, $today): array {
            $assigned = array_values(array_filter($projectTasks, static fn (ProjectTask $t): bool => $t->assignee_id === $employee->id));

            return [
                'employee' => $employee,
                'active' => count(array_filter($assigned, static fn (ProjectTask $t): bool => ! TaskPolicy::isClosed($t->status))),
                'overdue' => count(array_filter($assigned, static fn (ProjectTask $t): bool => TaskPolicy::isOverdue(ProjectsData::taskShape($t), $today))),
                'review' => count(array_filter($assigned, static fn (ProjectTask $t): bool => $t->status === 'SUBMITTED')),
            ];
        });

        $recentActivity = $comments->sortByDesc(fn (TaskComment $c): string => $c->created_at->format('Y-m-d H:i:s.v'))->take(8)->values();

        $visible = ProjectsData::visibleTasks(
            $projectTasks,
            $employees,
            [
                'q' => $this->search,
                'status' => $this->status,
                'assignee' => $this->assignee,
                'priority' => $this->priority,
                'queue' => $this->queue,
            ],
            $this->sortKey,
            $this->sortDir,
            $today,
        );

        $progress = ProjectPolicy::progress(ProjectsData::taskShapes($projectTasks));
        $reviewCount = count(array_filter($projectTasks, static fn (ProjectTask $t): bool => $t->status === 'SUBMITTED'));
        $overdueCount = count(array_filter($projectTasks, static fn (ProjectTask $t): bool => TaskPolicy::isOverdue(ProjectsData::taskShape($t), $today)));
        $dueSoonCount = count(array_filter($projectTasks, static fn (ProjectTask $t): bool => ProjectsData::isTaskDueSoon($t, $today)));
        $unassignedCount = count(array_filter($projectTasks, static fn (ProjectTask $t): bool => $t->assignee_id === null));
        $health = ProjectPolicy::health(
            ProjectsData::projectShape($project),
            ProjectsData::taskShapes($projectTasks),
            $today,
        );

        $openTask = null;
        foreach ($projectTasks as $task) {
            if ($task->id === $this->openTaskId) {
                $openTask = $task;
            }
        }

        $accounts = User::query()->orderBy('name')->get();

        return view('livewire.projects.show', [
            'project' => $project,
            'today' => $today,
            'projectTasks' => $projectTasks,
            'taskCount' => count($projectTasks),
            'visibleCount' => count($visible),
            'rows' => $this->paginateClamped($visible),
            'employees' => $employees,
            'holders' => $holders,
            'workload' => $workload,
            'recentActivity' => $recentActivity,
            'fileCounts' => $fileCounts,
            'commentCounts' => $commentCounts,
            'sites' => WorkSite::query()->orderBy('name')->get(),
            'accounts' => $accounts,
            'progress' => $progress,
            'reviewCount' => $reviewCount,
            'overdueCount' => $overdueCount,
            'dueSoonCount' => $dueSoonCount,
            'unassignedCount' => $unassignedCount,
            'health' => $health,
            'openTask' => $openTask,
            'openTaskAssignee' => $openTask?->assignee_id === null
                ? null
                : $employees->firstWhere('id', $openTask->assignee_id),
            'openTaskBrief' => $openTask === null ? collect() : $files->where('task_id', $openTask->id)->where('kind', 'BRIEF')->values(),
            'openTaskSubmitted' => $openTask === null ? collect() : $files->where('task_id', $openTask->id)->where('kind', 'SUBMISSION')->values(),
            'openTaskComments' => $openTask === null ? collect() : $comments->where('task_id', $openTask->id)->values(),
            'tasksExportUrl' => route('projects.tasks.export', array_merge(['project' => $project->id], array_filter([
                'q' => $this->search,
                'status' => $this->status,
                'assignee' => $this->assignee,
                'priority' => $this->priority,
                'queue' => $this->queue,
                'sort' => $this->sortKey,
                'dir' => $this->sortDir,
            ], static fn (string $v): bool => $v !== ''))),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function resetTaskDraft(): void
    {
        $this->taskTitle = '';
        $this->taskDescription = '';
        $this->taskAssigneeId = '';
        $this->taskPriority = 'NORMAL';
        $this->taskDueDate = '';
        $this->briefPick = [];
        $this->briefStage = [];
    }

    /**
     * Store one uploaded file for a task. Returns an error sentence, or null.
     */
    private function storeTaskFile(Access $actor, ProjectTask $task, TemporaryUploadedFile $file, string $kind): ?string
    {
        $size = (int) $file->getSize();

        $rejection = ProjectsData::attachmentRejection($file->getClientOriginalName(), $size);
        if ($rejection !== null) {
            return $rejection;
        }
        $ceiling = ProjectsData::storageCeilingError($size);
        if ($ceiling !== null) {
            return $ceiling;
        }

        $stored = $file->storeAs(ProjectsData::FILE_DIR, (string) Str::ulid(), 'local');
        if ($stored === false) {
            return 'upload failed';
        }

        $row = new ProjectTaskFile;
        $row->fill([
            'task_id' => $task->id,
            'kind' => $kind,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 300),
            'mime' => mb_substr($file->getClientMimeType() ?: 'application/octet-stream', 0, 120),
            'size' => $size,
            'path' => $stored,
        ]);
        // Actor column.
        $row->setAttribute('uploaded_by', $actor->userId === null ? null : (int) $actor->userId);
        $row->save();

        return null;
    }

    /**
     * The project, through the actor's scope — GET /api/projects/[id].
     */
    private function scopedProject(Access $actor): ?Project
    {
        return ProjectsData::scopedProjects($actor)->whereKey($this->projectId)->first();
    }

    /** A task on THIS project, through the actor's scope. */
    private function scopedTask(Access $actor, string $taskId): ?ProjectTask
    {
        return ProjectsData::scopedTasks($actor)
            ->whereKey($taskId)
            ->where('project_id', $this->projectId)
            ->first();
    }

    /** The dialog's current task, freshly re-read. */
    private function currentTask(Access $actor): ?ProjectTask
    {
        if ($this->openTaskId === null) {
            return null;
        }

        return $this->scopedTask($actor, $this->openTaskId);
    }

    /** Rebuilt from the session on EVERY request — never cached on a property. */
    private function access(): Access
    {
        return Access::forActor(auth()->user());
    }
}
