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
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * /projects/{id} — the port of project-detail.tsx plus its three dialogs
 * (edit-project-dialog.tsx, add-task-dialog.tsx, task-review-dialog.tsx),
 * and one deliberate addition with no reference route behind it: a
 * "Delete project" confirm ({@see self::deleteProject()}).
 *
 * Every mutation follows the server routes (POST /api/tasks,
 * PATCH /api/tasks/[id], PATCH /api/projects/[id], the files and comments
 * routes): actor columns come from {@see Access}, writes re-check
 * requireAllDataModule("PROJECTS") inside the action, and EVERY status move —
 * project or task — goes through the {@see ProjectPolicy} /
 * {@see TaskPolicy} transition maps.
 *
 * The summary card shows Site / Task Leader / Due Date / Progress — the
 * reference's Timeline and Health cells were dropped on request, so the page
 * no longer computes {@see ProjectPolicy::health()} (the queue cards below it
 * carry the same counts). "Task Leader" is the user-facing name of
 * `projects.manager_id`; every identifier keeps the reference's "manager".
 *
 * ⚠ Divergence from the reference API, on purpose: the reference's task PATCH
 * route once checked flat target lists (REVIEWER_MOVES held PENDING and
 * IN_PROGRESS) instead of the transition maps, which let a closed task be
 * reopened (documented bug — see TaskPolicy's class docblock). Here the maps
 * are the ONLY check, so those two targets are simply unreachable.
 *
 * ⚠ Second divergence (2026-09-10): a task is handed to 0..N employees, not
 * one. The reference's single `assignee_id` became the
 * `project_task_assignees` pivot ({@see ProjectTask::syncAssignees()}), so
 * both dialogs bind a list — `$taskAssigneeIds` / `$draftAssigneeIds` —
 * through the kit employee CHECKLIST rather than the picker. Those lists are
 * entangled with the child (wire:model) and therefore cannot be #[Locked];
 * Access INVARIANT 3 is met by resolving every id server-side before it is
 * written ({@see self::resolveActiveIds()}: an ACTIVE employee, in the
 * database's own spelling, or the save is refused). Reassignment diffs the
 * set: ADDED employees get TASK_ASSIGNED, REMOVED get TASK_REASSIGNED,
 * RETAINED hear nothing. Review outcomes and comments fan out to the rest of
 * the crew through {@see ProjectsData::notifyEmployees()} — never to the
 * actor, and never twice to one account ({@see self::crewExcept()}).
 *
 * The blade shows the manage-only "Delete project" control to managers alone
 * (`$canManage` from render()); every action still re-checks the gate itself.
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

    /* ------------------------- delete-project confirm ----------------------- */

    public bool $deleteConfirmOpen = false;

    /* --------------------------- add-task dialog --------------------------- */

    public bool $adding = false;

    public string $taskTitle = '';

    public string $taskDescription = '';

    /**
     * Ticked through the kit employee checklist (entangled, so NOT #[Locked]);
     * every id is re-validated as an active employee in saveTask().
     *
     * @var list<string>
     */
    public array $taskAssigneeIds = [];

    /**
     * Bumped whenever the add-task draft resets, so the checklist child
     * remounts with a clean search box on the next open.
     */
    #[Locked]
    public int $taskDraftNonce = 0;

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

    /**
     * The review dialog's assignee draft, seeded from the task's current set
     * on open; re-validated in saveDetails() (see $taskAssigneeIds).
     *
     * @var list<string>
     */
    public array $draftAssigneeIds = [];

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
                Flux::toast(variant: 'danger', text: 'Choose an active account as task leader.');

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

    /**
     * The "Delete project" confirm. A deliberate addition — the reference app
     * had no DELETE /api/projects/[id] — gated exactly like every other write
     * here. The DB cascades take the tasks, their files and their comments
     * with the row; the bytes on disk go only AFTER the transaction has
     * committed, so a rolled-back delete never leaves a task pointing at a
     * missing file (deleteFile() makes the same row-then-disk choice).
     */
    public function deleteProject(): void
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

        $taskIds = $project->tasks()->pluck('id')->all();
        $taskCount = count($taskIds);

        /** @var list<string> $paths */
        $paths = ProjectTaskFile::query()
            ->whereIn('task_id', $taskIds)
            ->pluck('path')
            ->filter(static fn (mixed $path): bool => is_string($path) && $path !== '')
            ->values()
            ->all();

        DB::transaction(function () use ($actor, $project, $taskCount): void {
            $project->delete();

            AuditService::record(
                $actor,
                AuditEvent::PROJECT_DELETED,
                'projects',
                $project->id,
                sprintf(
                    '%s — %s deleted with %d %s',
                    $project->code,
                    $project->name,
                    $taskCount,
                    $taskCount === 1 ? 'task' : 'tasks',
                ),
            );
        });

        foreach ($paths as $path) {
            Storage::disk('local')->delete($path);
        }

        $this->deleteConfirmOpen = false;
        Flux::toast(variant: 'success', text: "{$project->code} deleted.");
        $this->redirect(route('projects.index'));
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

        // The checklist is entangled, so the list is client-writable: every
        // id is re-resolved here — to the database's own id of an ACTIVE
        // employee — before it is written, or the save is refused.
        $assigneeIds = $this->resolveActiveIds(self::cleanIds($this->taskAssigneeIds));
        if (is_string($assigneeIds)) {
            Flux::toast(variant: 'danger', text: $assigneeIds);

            return;
        }

        $byUserId = $actor->userId === null ? null : (int) $actor->userId;

        try {
            /** @var ProjectTask $task */
            $task = DB::transaction(function () use ($byUserId, $project, $title, $description, $dueDate, $assigneeIds): ProjectTask {
                $codes = DB::table('project_tasks')->lockForUpdate()->pluck('code')->all();

                $task = new ProjectTask;
                $task->fill([
                    'code' => ProjectsData::nextCode('TSK', $codes),
                    'project_id' => $project->id,
                    'title' => $title,
                    'description' => $description === '' ? null : $description,
                    'status' => 'PENDING',
                    'priority' => $this->taskPriority,
                    'due_date' => $dueDate,
                    'assigned_at' => CarbonImmutable::now(),
                ]);
                // Actor column: the session, never the request.
                $task->setAttribute('assigned_by', $byUserId);
                $task->save();

                // Membership rows, minted through the pivot model in the same
                // transaction — a failed insert takes the task with it.
                $task->syncAssignees($assigneeIds, $byUserId);

                return $task;
            });
        } catch (QueryException) {
            Flux::toast(variant: 'danger', text: 'A record with those details already exists.');

            return;
        }

        $names = ProjectsData::assigneeNames($task);

        AuditService::record(
            $actor,
            AuditEvent::TASK_ASSIGNED,
            'project_tasks',
            $task->id,
            $assigneeIds !== []
                ? "{$task->code} — {$title} assigned to {$names}"
                : "{$task->code} — {$title} created without an assignee",
        );
        ProjectsData::notifyEmployees(
            $assigneeIds,
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

        Flux::toast(variant: 'success', text: $assigneeIds !== []
            ? "{$task->code} assigned to {$names}."
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
        $this->draftAssigneeIds = $task->assigneeIds();
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

        // The assignee SET is diffed, not a scalar: order in the checklist
        // does not matter, membership does. Only NEWCOMERS must be active:
        // someone assigned earlier and since deactivated may stay on (or be
        // unticked), exactly as the single picker only ever vetted the
        // incoming employee. Newcomers are resolved to the database's own
        // ids and the set is diffed AFTER that, so a current id posted in
        // another letter case collapses onto its own row — a no-op — instead
        // of reaching the pivot's case-insensitive unique index as an insert.
        $posted = self::cleanIds($this->draftAssigneeIds);
        $currentIds = $task->assigneeIds();
        $incoming = $this->resolveActiveIds(array_values(array_diff($posted, $currentIds)));
        if (is_string($incoming)) {
            Flux::toast(variant: 'danger', text: $incoming);

            return;
        }
        $nextIds = array_values(array_unique([...array_values(array_intersect($posted, $currentIds)), ...$incoming]));
        $sortedNext = $nextIds;
        sort($sortedNext, SORT_STRING);
        $changedAssignees = $sortedNext !== $currentIds;
        $changedPriority = $this->draftPriority !== $task->priority;
        $changedDueDate = $nextDue !== ProjectsData::dateKey($task->due_date);

        if (! $changedAssignees && ! $changedPriority && ! $changedDueDate) {
            $this->editingDetails = false;

            return;
        }

        $data = [];
        if ($changedPriority) {
            $data['priority'] = $this->draftPriority;
        }
        if ($changedDueDate) {
            $data['due_date'] = $nextDue;
        }

        $byUserId = $actor->userId === null ? null : (int) $actor->userId;

        // Optimistic concurrency, as the reference `updateMany({id, status})`.
        // The pivot sync rides in the same transaction, after the guarded
        // UPDATE has proven the row is still in the status the reviewer saw.
        // When only the assignees moved `$data` is empty and the Eloquent
        // update still stamps updated_at — the task DID change. A constraint
        // that still fails degrades to the toast saveTask() uses, never a 500.
        try {
            /** @var array{count: int, added: list<string>, removed: list<string>} $result */
            $result = DB::transaction(function () use ($task, $data, $nextIds, $changedAssignees, $byUserId): array {
                $count = ProjectTask::query()
                    ->whereKey($task->id)
                    ->where('status', $task->status)
                    ->update($data);

                if ($count === 0 || ! $changedAssignees) {
                    return ['count' => $count, 'added' => [], 'removed' => []];
                }

                $diff = $task->syncAssignees($nextIds, $byUserId);

                return ['count' => $count, 'added' => $diff['added'], 'removed' => $diff['removed']];
            });
        } catch (QueryException) {
            Flux::toast(variant: 'danger', text: 'A record with those details already exists.');

            return;
        }

        if ($result['count'] === 0) {
            Flux::toast(variant: 'danger', text: 'That task changed while you were editing it.');

            return;
        }

        $changedFields = array_keys($data);
        if ($changedAssignees) {
            $changedFields[] = 'assignees';
        }

        // Server-canonical name: a details edit is always TASK_UPDATED, even
        // when the assignees moved (AuditEvent::taskDetails documents why the
        // demo's TASK_REASSIGNED name is read-only here).
        AuditService::record(
            $actor,
            AuditEvent::taskDetails($changedAssignees),
            'project_tasks',
            $task->id,
            sprintf('%s "%s" updated (%s)', $task->code, $task->title, implode(', ', $changedFields)),
        );

        // ADDED employees are told they have a task; REMOVED that it moved on
        // — or, when nobody is left on it, simply that it is no longer theirs;
        // anyone retained hears nothing — their work did not change.
        $taskLabel = $task->code !== '' ? $task->code : $task->title;
        ProjectsData::notifyEmployees(
            $result['added'],
            'TASK_ASSIGNED',
            "{$taskLabel} assigned to you",
            $nextDue !== null ? "{$task->title} — due ".ProjectsData::fmtDate($nextDue).'.' : $task->title,
            '/my-tasks',
        );
        ProjectsData::notifyEmployees(
            $result['removed'],
            'TASK_REASSIGNED',
            "{$taskLabel} was reassigned",
            $nextIds === []
                ? "You were removed from {$taskLabel} — it is no longer assigned to you."
                : 'The task is now assigned to another employee.',
            '/my-tasks',
        );

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

        $assigneeIds = $task->assigneeIds();
        $assigneeNames = ProjectsData::assigneeNames($task);

        $outcome = match ($decision) {
            'COMPLETED' => 'accepted as complete',
            'RETURNED' => $assigneeIds !== []
                ? "returned to {$assigneeNames} for rework"
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
        // Every assignee shares the outcome — the task is one unit of work —
        // except a reviewer who is on the crew: nobody is told of their own
        // decision.
        ProjectsData::notifyEmployees(
            self::crewExcept($task, $actor),
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
        $isAssignee = $task->hasAssignee($actor->employeeId);
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
            // Management speaks to the whole crew — minus themselves, when
            // the reviewer is on it.
            ProjectsData::notifyEmployees(self::crewExcept($task, $actor), 'TASK_COMMENT', "New comment on {$taskLabel}", $preview, '/my-tasks');
        } else {
            // An assignee speaks to the assigner — and to their co-assignees,
            // who share the task and the thread; never back to themselves,
            // and never twice to one account: an assigner who is also on the
            // crew is told once, through their account, and their employee
            // record leaves the crew fan-out. Only while that account is
            // active, though — employee fan-out fills in that person's other
            // active accounts while the direct assigned_by account is kept in
            // the ledger so it does not receive the same event twice.
            $assignedBy = $task->getAttribute('assigned_by');
            $assignerUserId = $assignedBy === null ? null : (int) $assignedBy;
            if ($assignerUserId !== null && (string) $assignerUserId !== $actor->userId) {
                ProjectsData::notifyAccount($assignerUserId, 'TASK_COMMENT', "New comment on {$taskLabel}", $preview, '/projects');
            }
            ProjectsData::notifyEmployeesExceptAccounts(
                self::crewExcept($task, $actor),
                [$assignerUserId],
                'TASK_COMMENT',
                "New comment on {$taskLabel}",
                $preview,
                '/my-tasks',
            );
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
        // The blade keeps the manage-only "Delete project" control away from
        // a viewer it could only ever refuse; deleteProject() re-checks anyway.
        $canManage = ProjectsData::managesProjects($actor);

        if ($project === null) {
            return view('livewire.projects.show', [
                'project' => null,
                'today' => $today,
                'canManage' => $canManage,
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

        // Everyone holding work here — the union of every task's assignee set
        // (scopedTasks() eager-loaded the pivot, so this reads memory).
        $assigneeIds = [];
        foreach ($projectTasks as $t) {
            foreach ($t->assigneeIds() as $id) {
                $assigneeIds[$id] = true;
            }
        }
        $employees = EmployeeProfile::query()->whereIn('id', array_keys($assigneeIds))->get();

        // Only the people actually holding work here are worth filtering by.
        $holders = $employees
            ->sortBy([['last_name', 'asc'], ['first_name', 'asc']], SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $workload = $holders->map(function (EmployeeProfile $employee) use ($projectTasks, $today): array {
            // A shared task counts once for EACH of its assignees.
            $assigned = array_values(array_filter($projectTasks, static fn (ProjectTask $t): bool => $t->hasAssignee($employee->id)));

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
        $unassignedCount = count(array_filter($projectTasks, static fn (ProjectTask $t): bool => $t->assigneeIds() === []));

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
            'canManage' => $canManage,
            'projectTasks' => $projectTasks,
            'taskCount' => count($projectTasks),
            'visibleCount' => count($visible),
            'rows' => $this->paginateClamped($visible),
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
            'openTask' => $openTask,
            'openTaskAssignees' => $openTask === null ? [] : self::sortedAssignees($openTask),
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
        $this->taskAssigneeIds = [];
        $this->taskPriority = 'NORMAL';
        $this->taskDueDate = '';
        $this->briefPick = [];
        $this->briefStage = [];
        $this->taskDraftNonce++;
    }

    /**
     * An entangled checklist array as a clean, deduped list<string> — the
     * client can post anything into it, so nothing downstream trusts its shape.
     *
     * @param  array<array-key, mixed>  $ids
     * @return list<string>
     */
    private static function cleanIds(array $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            if (is_string($id)) {
                $id = trim($id);
                if ($id !== '' && ! in_array($id, $clean, true)) {
                    $clean[] = $id;
                }
            }
        }

        return $clean;
    }

    /**
     * The posted ids as the database's OWN ids — every one an ACTIVE employee
     * — or the toast sentence (the single picker's, pluralised for a set)
     * when any id is unknown or inactive. An empty list resolves to itself.
     *
     * Plucked rather than counted on purpose: an entangled checklist array is
     * client-writable, and under MySQL's case-insensitive collation a re-cased
     * id ('EMP-4' for 'emp-4') passes a count and then trips the pivot's
     * unique index as a duplicate insert. Writing what the database answered
     * makes that a no-op instead (SQLite, which the suite runs on, compares
     * case-sensitively and simply refuses the id).
     *
     * @param  list<string>  $ids
     * @return list<string>|string
     */
    private function resolveActiveIds(array $ids): array|string
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<string> $resolved */
        $resolved = EmployeeProfile::query()
            ->whereKey($ids)
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        if (count($resolved) !== count($ids)) {
            return count($ids) === 1
                ? 'Choose an active employee for the task.'
                : 'Choose active employees for the task.';
        }

        return $resolved;
    }

    /**
     * The crew a notice goes to: every assignee EXCEPT the actor — nobody is
     * told about their own action — and except `$alsoExcept`, an employee
     * whose account the caller has already told directly, so one action
     * never lands twice in one bell.
     *
     * @return list<string>
     */
    private static function crewExcept(ProjectTask $task, Access $actor, ?string $alsoExcept = null): array
    {
        $skip = array_values(array_filter(
            [$actor->employeeId, $alsoExcept],
            static fn (?string $id): bool => $id !== null && $id !== '',
        ));

        return array_values(array_diff($task->assigneeIds(), $skip));
    }

    /**
     * A task's assignees in display order (last name, then first) — the ONE
     * list the tasks table's Assignees cell draws both of its lines from, so
     * a code sits under its own name, and the review dialog header iterates.
     * The same order assigneeNames() prints.
     *
     * @return list<EmployeeProfile>
     */
    public static function sortedAssignees(ProjectTask $task): array
    {
        $employees = $task->assignees->all();

        usort($employees, static function (EmployeeProfile $a, EmployeeProfile $b): int {
            $last = ProjectsData::compareText($a->last_name, $b->last_name);

            return $last !== 0 ? $last : ProjectsData::compareText($a->first_name, $b->first_name);
        });

        return $employees;
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
