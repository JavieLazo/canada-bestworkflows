<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Domain\Notifications\Notifier;
use App\Domain\Projects\ProjectPolicy;
use App\Domain\Projects\TaskPolicy;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\IdentityAccess\Contracts\AccountLabel as User;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\IdentityAccess\Contracts\ProjectAccounts;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\WorkManagement\Application\Commands\ChangeProjectStatus;
use App\Modules\WorkManagement\Application\Commands\ChangeTaskStatus;
use App\Modules\WorkManagement\Application\Commands\CommentOnProjectTask;
use App\Modules\WorkManagement\Application\Commands\CreateTask;
use App\Modules\WorkManagement\Application\Commands\DeleteProject;
use App\Modules\WorkManagement\Application\Commands\ReviewTask;
use App\Modules\WorkManagement\Application\Commands\ReviewTaskResponse;
use App\Modules\WorkManagement\Application\Commands\ScheduleProject;
use App\Modules\WorkManagement\Application\Commands\TaskBriefs;
use App\Modules\WorkManagement\Application\Commands\UpdateProject;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskDetails;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Modules\WorkManagement\Application\Queries\WorkLists;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Modules\WorkManagement\Application\TaskDetails;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskFileView;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView as ProjectTask;
use App\Modules\WorkManagement\Contracts\Views\ProjectView as Project;
use App\Modules\WorkManagement\Contracts\Views\TaskCommentView as TaskComment;
use App\Modules\WorkManagement\Contracts\Views\TaskResponseView;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
 * routes): actor columns come from {@see Access}, every write re-checks its
 * gate inside the command — requireAllDataModule("PROJECTS"), or
 * WorkRules::runsProject() where the project's Task Leader may act
 * — and EVERY status move, project or task, goes through a transition map
 * keyed by the current status: {@see ProjectPolicy} / {@see TaskPolicy}, or
 * the hand-set maps ManualProjectStatus / ManualTaskStatus.
 *
 * The summary card shows Site / Task Leader / Due Date / Progress — no
 * Timeline or Health cell (the reference has both), so the page does not
 * compute {@see ProjectPolicy::health()} (the queue cards below it carry the
 * same counts). "Task Leader" is the user-facing name of
 * `projects.manager_id`; every identifier keeps the reference's "manager".
 *
 * ⚠ Divergence from the reference API, on purpose: the reference's task PATCH
 * route declares flat target lists (REVIEWER_MOVES holds PENDING and
 * IN_PROGRESS); checked instead of the transition maps, they let a closed
 * task be reopened (documented bug — see TaskPolicy's class docblock). Here
 * the maps are the ONLY check. Those two targets are reachable only through
 * "Set status" ({@see self::setTaskStatus()}) — a map of its own
 * (ManualTaskStatus), keyed by the CURRENT status like every other, that
 * clears what a reopen would leave stale.
 *
 * ⚠ Second divergence: a task is handed to 0..N employees, not one. The
 * reference's single `assignee_id` is here the
 * `project_task_assignees` pivot ({@see ProjectTask::syncAssignees()}), so
 * both dialogs bind a list — `$taskAssigneeIds` / `$draftAssigneeIds` —
 * through the kit employee CHECKLIST rather than the picker. Those lists are
 * entangled with the child (wire:model) and therefore cannot be #[Locked];
 * Access INVARIANT 3 is met by resolving every id server-side before it is
 * written (CreateTask and UpdateTaskDetails, through
 * AssignmentEmployees::resolveActive(): an ACTIVE employee, in the
 * database's own spelling, or the save is refused). Reassignment diffs the
 * set: ADDED employees get TASK_ASSIGNED, REMOVED get TASK_REASSIGNED,
 * RETAINED hear nothing. Review outcomes and comments fan out to the rest of
 * the crew through {@see Notifier::employees()} — never to the
 * actor, and never twice to one account ({@see ProjectsData::crewExcept()}).
 *
 * ⚠ Third divergence — DELIBERATE DIVERGENCE: the reference has
 * no submission mode. A crew of two or more hands its work in either as ONE
 * team response (GROUP — the flow above) or member by member (INDIVIDUAL):
 * each assignee submits their own response, the reviewer approves or returns
 * each one ({@see self::reviewResponse()}), and "Complete task" stays
 * disabled until every CURRENT assignee's response is approved — ReviewTask
 * re-checks that gate under the task lock. The add dialog picks the mode; the
 * edit panel may switch it until anything has been handed in, and posts it
 * ONLY when the reviewer changed it ($seenSubmissionMode), so a dialog opened
 * before another reviewer's switch can never revert it. Nobody decides on
 * work they share: Accept, Return, Approve and Complete belong to someone who
 * runs the project — a PROJECTS manager, or its Task Leader — OFF the crew
 * ({@see ProjectsData::reviewsTask()}); a manager on it keeps Cancel, a
 * leader on it has no say over that task at all. On an INDIVIDUAL task a member's note, files and review
 * are theirs and the reviewer's alone: the dialog draws a response's content
 * only where WorkRecords::responses() hands it over, and a peer's evidence
 * never reaches WorkRecords::files() at all.
 *
 * The blade shows the manage-only "Delete project" control to managers alone
 * (`$canManage` from render()); every action still re-checks the gate itself.
 *
 * ⚠ Fourth divergence — DELIBERATE DIVERGENCE: the project's Task
 * Leader (`projects.manager_id`) runs it alongside the managers
 * (`$runsProject`): the status select and "Set status", the start dates
 * ({@see self::saveProject()} routes a leader to ScheduleProject; the edit
 * panel takes only their start date), and the reviews — never on a task they
 * are assigned to. Their dialogs post a field only when it was touched
 * (`$seenEditStatus`, `$seenEditStartDate`, `$seenStartDate`), so two people
 * running one project cannot revert each other unseen.
 */
#[Title('Project')]
class Show extends Component
{
    use WithFileUploads;
    use WithSorting;
    use WithTableFilters;

    private const array QUEUES = ['ALL', 'REVIEW', 'OVERDUE', 'DUE_SOON', 'UNASSIGNED'];

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

    /** Dialog draft — re-validated as an existing work site in saveProject(), so not #[Locked]. */
    public string $editSiteId = 'NONE';

    public string $editStatus = 'PLANNING';

    public string $editStartDate = '';

    public string $editDueDate = '';

    /** Dialog draft — re-validated as an active account in saveProject(), so not #[Locked]. */
    public string $editManagerId = 'NONE';

    /**
     * The status and start date the edit dialog opened with. The project's
     * Task Leader may move either while a manager has the dialog open, so
     * saveProject() posts each only when the manager changed it — an
     * untouched field is sent as null and keeps whatever the row holds.
     */
    #[Locked]
    public string $seenEditStatus = '';

    #[Locked]
    public string $seenEditStartDate = '';

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

    /** The planned start, 'Y-m-d' or ''; CreateTask validates it. */
    public string $taskStartDate = '';

    public string $taskDueDate = '';

    /**
     * GROUP or INDIVIDUAL (TaskPolicy::MODES) — offered only once two people
     * are ticked. CreateTask validates it, and stores GROUP whatever was
     * asked for a smaller crew.
     */
    public string $taskSubmissionMode = 'GROUP';

    /**
     * The picker input this batch landed on. Moved into $briefStage by the
     * updated hook so a second pick APPENDS instead of replacing — the
     * reference dialog stages across picks (add-task-dialog.tsx:88-104).
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
     * on open; entangled and re-validated in saveDetails(), so NOT #[Locked]
     * (see $taskAssigneeIds).
     *
     * @var list<string>
     */
    public array $draftAssigneeIds = [];

    public string $draftPriority = 'NORMAL';

    /** The planned start, seeded from the task on open; UpdateTaskDetails validates it. */
    public string $draftStartDate = '';

    /**
     * The start date the edit panel started from. saveDetails() posts the
     * draft only when it differs — the project's Task Leader may move the
     * start while a manager edits the priority, and an untouched field must
     * not revert them ({@see self::$seenSubmissionMode} is the same idea).
     */
    #[Locked]
    public string $seenStartDate = '';

    public string $draftDueDate = '';

    /**
     * The "Set status" choice ('' until one is picked). A status, not an id:
     * ChangeTaskStatus checks it against the map from the task's CURRENT
     * status, under the task lock.
     */
    public string $manualStatus = '';

    /** The edit panel's mode choice; UpdateTaskDetails validates it and re-checks the mode lock. */
    public string $draftSubmissionMode = 'GROUP';

    /**
     * The stored mode the edit panel started from (re-read when the panel
     * opens, and after a refused save once the mode is locked —
     * {@see self::reseedHiddenMode()}). saveDetails() posts the draft only
     * when it differs — an untouched choice is sent as "no change" (null),
     * so a stale dialog cannot revert another reviewer's switch or trip the
     * mode lock over an edit that never touched it.
     */
    #[Locked]
    public string $seenSubmissionMode = 'GROUP';

    /**
     * The assignee (employee id) whose response is expanded in the review
     * dialog. Set only by selectResponse(), which checks they are on the task;
     * what the panel shows is still only what WorkRecords::responses() lets
     * this viewer see.
     */
    #[Locked]
    public ?string $expandedMemberId = null;

    /** The reviewer's note on one response — required to return it. */
    public string $responseRemark = '';

    public ?TemporaryUploadedFile $reviewBriefUpload = null;

    public function mount(string $project): void
    {
        $this->projectId = $project;
        $this->sortKey = 'stack';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['stack', 'code', 'task', 'assignee', 'priority', 'start', 'due', 'status'];
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

    /**
     * The header status select (project-detail.tsx:244-271). `$from` is the
     * status the select was drawn with; ChangeProjectStatus refuses the move
     * if the project has moved since. A status set by hand may reopen a
     * closed project (ManualProjectStatus).
     */
    public function changeStatus(string $next, ?string $from = null): void
    {
        $actor = $this->access();
        try {
            $changed = app(ChangeProjectStatus::class)->handle($actor, $this->projectId, $next, $from);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'A record with those details already exists.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        if (! $changed) {
            return;
        }
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
        $this->editStatus = $this->seenEditStatus = $project->status;
        $this->editStartDate = $this->seenEditStartDate = ProjectsData::dateKey($project->start_date) ?? '';
        $this->editDueDate = ProjectsData::dateKey($project->due_date) ?? '';
        $this->editManagerId = $project->manager_id === null ? 'NONE' : (string) $project->manager_id;
        $this->editing = true;
    }

    /**
     * edit-project-dialog.tsx `save()` + PATCH /api/projects/[id]. A Projects
     * manager saves every field (UpdateProject). Anyone else is at most the
     * project's Task Leader, whose dialog shows the start date alone, and
     * saves only that (ScheduleProject, which refuses everyone else).
     */
    public function saveProject(): void
    {
        $actor = $this->access();
        try {
            $code = ProjectsData::managesProjects($actor)
                ? app(UpdateProject::class)->handle($actor, $this->projectId, new ProjectDraft(
                    $this->editName,
                    $this->editDescription,
                    $this->editSiteId,
                    $this->editStatus === $this->seenEditStatus ? null : $this->editStatus,
                    $this->editStartDate === $this->seenEditStartDate ? null : $this->editStartDate,
                    $this->editDueDate,
                    $this->editManagerId,
                ))
                : app(ScheduleProject::class)->handle($actor, $this->projectId, $this->editStartDate);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'A record with those details already exists.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        if ($code === null) {
            return;
        }
        Flux::toast(variant: 'success', text: "Project {$code} updated.");
        $this->editing = false;
    }

    /**
     * The "Delete project" confirm. A deliberate addition — the reference app
     * has no DELETE /api/projects/[id] — gated exactly like every other write
     * here. The DB cascades take the tasks, their files and their comments
     * with the row; the bytes on disk go only AFTER the transaction has
     * committed, so a rolled-back delete never leaves a task pointing at a
     * missing file (deleteFile() makes the same row-then-disk choice).
     */
    public function deleteProject(): void
    {
        $actor = $this->access();
        try {
            $code = app(DeleteProject::class)->handle($actor, $this->projectId);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'A record with those details already exists.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        if ($code === null) {
            return;
        }
        $this->deleteConfirmOpen = false;
        Flux::toast(variant: 'success', text: "Project {$code} deleted.");
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
        try {
            $task = app(CreateTask::class)->handle($actor, $this->projectId, new TaskDraft($this->taskTitle, $this->taskDescription, $this->taskPriority, $this->taskDueDate, $this->taskAssigneeIds, $this->taskSubmissionMode, $this->taskStartDate));
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'A record with those details already exists.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        if ($task === null) {
            return;
        } $assigneeIds = $task->assigneeIds();
        $names = ProjectsData::assigneeNames($task);
        foreach (app(TaskBriefs::class)->batch($actor, $this->projectId, $task->id, $this->briefStage) as $error) {
            Flux::toast(variant: 'danger', text: $error);
        }
        Flux::toast(variant: 'success', text: $assigneeIds !== []
            ? "Task {$task->code} assigned to {$names}."
            : "Task {$task->code} created without an assignee.");
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
        $this->draftStartDate = $this->seenStartDate = ProjectsData::dateKey($task->start_date) ?? '';
        $this->draftDueDate = ProjectsData::dateKey($task->due_date) ?? '';
        $this->manualStatus = '';
        $this->draftSubmissionMode = $this->seenSubmissionMode = $task->submission_mode;
        $this->expandedMemberId = null;
        $this->responseRemark = '';
        $this->reviewBriefUpload = null;
    }

    public function updatedReviewOpen(mixed $value): void
    {
        if (! $value) {
            $this->openTaskId = null;
            $this->editingDetails = false;
            $this->expandedMemberId = null;
        }
    }

    public function toggleEditingDetails(): void
    {
        $this->editingDetails = ! $this->editingDetails;
        if (! $this->editingDetails) {
            return;
        }
        // The mode choice starts from the mode as stored NOW, not as it was
        // when the dialog opened: saveDetails() posts it only if it moves
        // from here.
        $task = $this->currentTask($this->access());
        if ($task !== null) {
            $this->draftSubmissionMode = $this->seenSubmissionMode = $task->submission_mode;
            $this->draftStartDate = $this->seenStartDate = ProjectsData::dateKey($task->start_date) ?? '';
        }
    }

    /** task-review-dialog.tsx `saveDetails()` + the details half of PATCH /api/tasks/[id]. */
    public function saveDetails(): void
    {
        $actor = $this->access();
        // Null unless the reviewer changed the choice ($seenSubmissionMode),
        // and likewise the start date ($seenStartDate).
        $mode = $this->draftSubmissionMode === $this->seenSubmissionMode ? null : $this->draftSubmissionMode;
        $start = $this->draftStartDate === $this->seenStartDate ? null : $this->draftStartDate;
        try {
            $code = app(UpdateTaskDetails::class)->handle($actor, $this->projectId, $this->openTaskId, new TaskDetails($this->draftPriority, $this->draftDueDate, $this->draftAssigneeIds, $mode, $start));
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'A record with those details already exists.');
            $this->reseedHiddenMode($actor);

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
            $this->reseedHiddenMode($actor);

            return;
        }
        if ($code === null) {
            return;
        }
        $this->seenSubmissionMode = $this->draftSubmissionMode;
        $this->seenStartDate = $this->draftStartDate;
        if ($code === '') {
            $this->editingDetails = false;

            return;
        }
        Flux::toast(variant: 'success', text: "Task {$code} updated.");
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
        if (! ProjectsData::runsProject($actor, $task->project)) {
            Flux::toast(variant: 'danger', text: ProjectsData::forbiddenMessage());

            return;
        }
        // A Task Leader on the crew decides nothing on the task, Cancel
        // included; a manager on it may still cancel.
        if (! ProjectsData::runsTask($actor, $task)) {
            Flux::toast(variant: 'danger', text: WorkRules::selfReviewMessage());

            return;
        }
        // An assignee never accepts or returns their own task; cancelling is
        // still theirs to do. ReviewTask re-checks this under the task lock.
        if (in_array($decision, ['COMPLETED', 'RETURNED'], true) && ! ProjectsData::reviewsTask($actor, $task)) {
            Flux::toast(variant: 'danger', text: WorkRules::selfReviewMessage());

            return;
        }

        // THE MAP, and nothing but the map — never a flat target list. The
        // task's own mode picks it: an INDIVIDUAL task is never returned as a
        // whole, and completes from IN_PROGRESS once every response is
        // approved (ReviewTask re-checks that gate under the task lock).
        if (! TaskPolicy::managerCanMove($task->status, $decision, $task->submission_mode)) {
            Flux::toast(variant: 'danger', text: TaskPolicy::transitionError());

            return;
        }

        try {
            app(ReviewTask::class)->handle($actor, $task->project_id, $task->id, $decision, $remark);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The review could not be saved. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        $assigneeIds = $task->assigneeIds();
        $assigneeNames = ProjectsData::assigneeNames($task);

        // An INDIVIDUAL task was never handed in as a whole, so there was
        // nothing to accept: its members' responses were approved one by one.
        $outcome = match ($decision) {
            'COMPLETED' => $task->isIndividual() ? 'completed' : 'accepted as complete',
            'RETURNED' => $assigneeIds !== []
                ? "returned to {$assigneeNames} for rework"
                : 'returned for rework',
            default => 'cancelled',
        };

        Flux::toast(variant: 'success', text: "Task {$task->code} {$outcome}.");
        $this->remarks = '';
        $this->reviewOpen = false;
        $this->openTaskId = null;
    }

    /**
     * The review dialog's "Set status": a task status set by hand, for
     * whoever runs the project (a Projects manager or its Task Leader).
     * `$from` is the status the dialog showed — ChangeTaskStatus refuses the
     * move if the task has moved since — and the note is the shared review
     * remark, required to return. The command decides everything under the
     * task lock (ManualTaskStatus); this only relays it.
     */
    public function setTaskStatus(string $from): void
    {
        $actor = $this->access();
        $task = $this->currentTask($actor);
        if ($task === null || $this->manualStatus === '') {
            return;
        }
        $to = $this->manualStatus;

        try {
            app(ChangeTaskStatus::class)->handle($actor, $task->project_id, $task->id, $to, $this->remarks, $from);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The status could not be saved. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: "Task {$task->code} set to ".mb_strtolower(TaskPolicy::statusLabel($to)).'.');
        $this->remarks = '';
        $this->manualStatus = '';
    }

    /**
     * Expand one member's response in the review dialog, or collapse it when
     * it is already open. A read: the id must be one of the open task's
     * assignees NOW, and the panel still shows only what
     * WorkRecords::responses() hands this viewer.
     */
    public function selectResponse(string $employeeId): void
    {
        $task = $this->currentTask($this->access());
        if ($task === null || ! $task->hasAssignee($employeeId)) {
            return;
        }

        $this->expandedMemberId = $this->expandedMemberId === $employeeId ? null : $employeeId;
        $this->responseRemark = '';
    }

    /**
     * A reviewer's decision on ONE member's response to an INDIVIDUAL task —
     * the expanded response's Approve / Return buttons. The row id and the
     * revision are the ones the dialog rendered; ReviewTaskResponse re-checks
     * both under the task lock, with the self-review refusal and the member
     * map, so a response that changed since it was drawn is refused rather
     * than decided unseen. The dialog stays open on the rest of the crew.
     */
    public function reviewResponse(string $decision, string $responseId, int $revision): void
    {
        if (! in_array($decision, ['APPROVED', 'RETURNED'], true)) {
            return;
        }

        $actor = $this->access();
        $task = $this->currentTask($actor);
        if ($task === null) {
            return;
        }

        try {
            app(ReviewTaskResponse::class)->handle($actor, $task->project_id, $task->id, $responseId, $decision, $this->responseRemark, $revision);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The review could not be saved. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        // Named from the row the decision landed on, not from the expanded
        // panel: the row id is what the command decided.
        $decided = app(WorkRecords::class)->responses($actor, $task->id)
            ->first(static fn (TaskResponseView $response): bool => $response->id === $responseId);
        $member = $decided === null ? null : $task->assignees->firstWhere('id', $decided->employee_id);
        $outcome = $decision === 'APPROVED' ? 'response approved.' : 'response returned for rework.';

        Flux::toast(variant: 'success', text: $member === null ? ucfirst($outcome) : ProjectsData::employeeName($member)."'s {$outcome}");
        $this->responseRemark = '';
        $this->expandedMemberId = null;
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
        try {
            $name = app(TaskBriefs::class)->attach($actor, $this->projectId, $this->openTaskId, $file);
            if ($name !== null) {
                Flux::toast(variant: 'success', text: "{$name} attached.");
            }
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'Upload failed');
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    /** DELETE /api/tasks/files/[id] — the BRIEF side only, on this screen. */
    public function deleteFile(string $fileId): void
    {
        $actor = $this->access();
        try {
            $name = app(TaskBriefs::class)->delete($actor, $this->projectId, $this->openTaskId, $fileId);
            if ($name !== null) {
                Flux::toast(variant: 'success', text: "{$name} removed.");
            }
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'That file could not be removed.');
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    /** TaskComments composer + POST /api/tasks/[id]/comments. */
    public function postComment(): void
    {
        $actor = $this->access();
        try {
            $done = app(CommentOnProjectTask::class)->handle($actor, $this->projectId, $this->openTaskId, $this->commentBody);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The comment could not be posted. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        if (! $done) {
            return;
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
                'runsProject' => false,
            ]);
        }
        // A Projects manager, or this project's Task Leader: the hand-set
        // status controls and the start dates. Display only — every command
        // re-checks it on the locked rows.
        $runsProject = ProjectsData::runsProject($actor, $project);

        $rows = $this->acceptPage(app(WorkLists::class)->taskPage(
            $actor,
            $project->id,
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
            (int) $this->getPage(),
            $this->perPage,
        ));
        $records = app(WorkRecords::class);
        $summary = app(WorkSummary::class);
        $taskTotals = $summary->projects($actor, [$project->id], $today)[$project->id];
        $workload = $summary->workload($actor, $project->id, $today);
        $taskIds = $rows->getCollection()->map(static fn (ProjectTask $task): string => $task->id)->values()->all();
        $counts = $records->counts($actor, array_values($taskIds));
        $recentActivity = $records->recentComments($actor, $project->id);
        $activityIds = $recentActivity->map(static fn (TaskComment $comment): string => $comment->task_id)->unique()->values()->all();
        $openTask = $this->currentTask($actor);
        $files = $openTask === null ? collect() : $records->files($actor, [$openTask->id]);
        $comments = $openTask === null ? collect() : $records->comments($actor, [$openTask->id]);
        $accounts = app(AccountNames::class)->list($actor);
        $openTaskAssignees = $openTask === null ? [] : ProjectsData::sortedAssignees($openTask);
        $openTaskSubmitted = $openTask === null ? collect() : $files->where('task_id', $openTask->id)->where('kind', 'SUBMISSION')->values();
        // An INDIVIDUAL task's responses, one per assignee, each carrying its
        // content only where this viewer may see it (WorkRecords::responses()).
        // Read only while such a task's dialog is open; a GROUP task has none.
        $openTaskResponses = $openTask !== null && $openTask->isIndividual() ? $records->responses($actor, $openTask->id) : collect();
        // Who "Complete task" still waits on, and why it is disabled — both
        // empty once every current assignee's response is approved. The same
        // gate ReviewTask re-checks under the task lock; display only.
        $openTaskGaps = $openTask !== null && $openTask->isIndividual()
            ? ProjectsData::completionGaps(
                $openTask->assigneeIds(),
                $openTaskResponses->map(static fn (TaskResponseView $response): string => $response->status)->all(),
            )
            : [];
        $openTaskWaitingOn = $openTask !== null && $openTask->isIndividual()
            ? ProjectsData::completionWaitingOn($openTaskGaps, $openTaskAssignees)
            : '';

        return view('livewire.projects.show', [
            'project' => $project,
            'today' => $today,
            'canManage' => $canManage,
            'runsProject' => $runsProject,
            'taskTotals' => $taskTotals,
            'taskCount' => $taskTotals->total,
            'visibleCount' => $rows->total(),
            'rows' => $rows,
            'holders' => $workload->pluck('employee'),
            'workload' => $workload,
            'recentActivity' => $recentActivity,
            'activityTasks' => $records->tasksByIds($actor, array_values($activityIds))->keyBy('id'),
            'fileCounts' => $counts['files'],
            'commentCounts' => $counts['comments'],
            'sites' => app(OrganizationDirectory::class)->sites($actor),
            'accounts' => $accounts,
            // Who the edit dialog's Task Leader picker offers (UpdateProject
            // re-checks); the leader already named stays listed regardless.
            'leaderIds' => $canManage ? app(ProjectAccounts::class)->leaderIds($actor) : [],
            'progress' => $taskTotals->progress(),
            'reviewCount' => $taskTotals->review,
            'overdueCount' => $taskTotals->overdue,
            'dueSoonCount' => $taskTotals->dueSoon,
            'unassignedCount' => $taskTotals->unassigned,
            'openTask' => $openTask,
            // Decision buttons — Accept, Return, Complete and a response's
            // Approve / Return — only for someone who runs the project (a
            // manager or its Task Leader) off the crew.
            'openTaskMayReview' => $openTask !== null && ProjectsData::reviewsTask($actor, $openTask),
            // The "Set status" choices: ManualTaskStatus's map from the
            // current status, less what the task's facts or the self-review
            // rule would refuse. Display only — ChangeTaskStatus re-decides.
            'openTaskRunnable' => $openTask !== null && ProjectsData::runsTask($actor, $openTask),
            'openTaskManualMoves' => $openTask !== null && ProjectsData::runsTask($actor, $openTask)
                ? ProjectsData::manualMoves(
                    $actor,
                    $openTask,
                    $project->status,
                    $openTaskResponses->map(static fn (TaskResponseView $response): string => $response->status)->all(),
                )
                : [],
            'openTaskIsAssignee' => $openTask?->hasAssignee($actor->employeeId) ?? false,
            'openTaskAssignees' => $openTaskAssignees,
            'openTaskBrief' => $openTask === null ? collect() : $files->where('task_id', $openTask->id)->where('kind', 'BRIEF')->values(),
            'openTaskSubmitted' => $openTaskSubmitted,
            'openTaskComments' => $openTask === null ? collect() : $comments->where('task_id', $openTask->id)->values(),
            'openTaskResponses' => $openTaskResponses,
            'openTaskGaps' => $openTaskGaps,
            'openTaskWaitingOn' => $openTaskWaitingOn,
            // Display only — UpdateTaskDetails re-checks it under the task
            // lock ({@see self::modeLocked()}).
            'openTaskModeLocked' => $openTask !== null && $this->modeLocked($openTask),
            // Evidence from people since taken off an INDIVIDUAL task. Kept
            // for the record; only a reviewer is ever handed it by files().
            'openTaskFormerFiles' => $openTask !== null && $openTask->isIndividual()
                ? $openTaskSubmitted->reject(static fn (ProjectTaskFileView $file): bool => $openTask->hasAssignee($file->employee_id))->values()
                : collect(),
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
        $this->taskStartDate = '';
        $this->taskDueDate = '';
        $this->taskSubmissionMode = 'GROUP';
        $this->briefPick = [];
        $this->briefStage = [];
        $this->taskDraftNonce++;
    }

    /**
     * The project, through the actor's scope — GET /api/projects/[id].
     */
    private function scopedProject(Access $actor): ?Project
    {
        return app(WorkRecords::class)->project($actor, $this->projectId);
    }

    /** A task on THIS project, through the actor's scope. */
    private function scopedTask(Access $actor, string $taskId): ?ProjectTask
    {
        return app(WorkRecords::class)->task($actor, $taskId, $this->projectId);
    }

    /** The dialog's current task, freshly re-read. */
    private function currentTask(Access $actor): ?ProjectTask
    {
        if ($this->openTaskId === null) {
            return null;
        }

        return $this->scopedTask($actor, $this->openTaskId);
    }

    /**
     * Whether the task's submission type can no longer change — what hides
     * the edit panel's radio. Built from the task's CONTENT-FREE facts, not
     * from the files the viewer is handed: those are narrowed for a manager
     * on the crew, who must still see the lock a peer's evidence causes.
     * UpdateTaskDetails re-checks it under the task lock.
     */
    private function modeLocked(ProjectTask $task): bool
    {
        return ProjectsData::modeLocked(
            $task->status,
            ProjectsData::groupHandedIn($task->submitted_by, $task->submitted_at, $task->remarks),
            $task->responsesTotal(),
            (bool) $task->has_submission_files,
        );
    }

    /**
     * After a refused details save. Once the mode is locked the radio is
     * hidden, yet a choice changed before the lock landed would still differ
     * from $seenSubmissionMode, so every later save would post it unseen and
     * be refused again — the priority, due date or crew edit never landing.
     * Re-seed both from the mode as stored NOW. An unlocked panel keeps the
     * reviewer's choice: the radio still shows it.
     */
    private function reseedHiddenMode(Access $actor): void
    {
        $task = $this->currentTask($actor);
        if ($task !== null && $this->modeLocked($task)) {
            $this->draftSubmissionMode = $this->seenSubmissionMode = $task->submission_mode;
        }
    }

    /** Rebuilt from the session on EVERY request — never cached on a property. */
    private function access(): Access
    {
        return Access::forActor(auth()->user());
    }
}
