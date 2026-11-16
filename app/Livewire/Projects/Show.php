<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Domain\Projects\ProjectPolicy;
use App\Domain\Projects\TaskPolicy;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Concerns\WithTaskLink;
use App\Livewire\Concerns\WithTaskTimeline;
use App\Livewire\Projects\Support\WithPeopleList;
use App\Modules\IdentityAccess\Contracts\AccountLabel as User;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\IdentityAccess\Contracts\ProjectAccounts;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\WorkManagement\Application\Commands\ChangeProjectStatus;
use App\Modules\WorkManagement\Application\Commands\ChangeTaskStatus;
use App\Modules\WorkManagement\Application\Commands\CommentOnProjectTask;
use App\Modules\WorkManagement\Application\Commands\CreateTaskInStatus;
use App\Modules\WorkManagement\Application\Commands\DeleteProject;
use App\Modules\WorkManagement\Application\Commands\DeleteTaskGroup;
use App\Modules\WorkManagement\Application\Commands\MoveTaskToGroup;
use App\Modules\WorkManagement\Application\Commands\ReviewTask;
use App\Modules\WorkManagement\Application\Commands\ReviewTaskResponse;
use App\Modules\WorkManagement\Application\Commands\SaveTaskGroup;
use App\Modules\WorkManagement\Application\Commands\ScheduleProject;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Commands\TaskBriefs;
use App\Modules\WorkManagement\Application\Commands\UpdateProject;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskContent;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskDetails;
use App\Modules\WorkManagement\Application\PeopleList;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Modules\WorkManagement\Application\Queries\ClientPeople;
use App\Modules\WorkManagement\Application\Queries\ProjectClients;
use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Modules\WorkManagement\Application\Queries\ProjectPeople;
use App\Modules\WorkManagement\Application\Queries\ProjectPortfolios;
use App\Modules\WorkManagement\Application\Queries\ProjectReports;
use App\Modules\WorkManagement\Application\Queries\ProjectResults;
use App\Modules\WorkManagement\Application\Queries\ProjectTaskGroups;
use App\Modules\WorkManagement\Application\Queries\WorkLists;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Modules\WorkManagement\Application\TaskDateEdit;
use App\Modules\WorkManagement\Application\TaskDetails;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Modules\WorkManagement\Application\TaskHierarchy;
use App\Modules\WorkManagement\Application\TaskPropertyEdit;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\PortfolioView;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskFileView;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView as ProjectTask;
use App\Modules\WorkManagement\Contracts\Views\ProjectView as Project;
use App\Modules\WorkManagement\Contracts\Views\TaskCommentView as TaskComment;
use App\Modules\WorkManagement\Contracts\Views\TaskResponseView;
use App\Modules\WorkManagement\Domain\Tasks\ManualTaskStatus;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
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
 * same counts). "Project lead" is the user-facing name of
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
    use WithPeopleList;
    use WithSorting;
    use WithTableFilters;
    use WithTaskLink;
    use WithTaskTimeline;

    public bool $scheduling = false;

    #[Locked]
    public ?string $scheduleTaskId = null;

    #[Locked]
    public string $scheduleSeenStart = '';

    #[Locked]
    public string $scheduleSeenDue = '';

    public string $scheduleStart = '';

    public string $scheduleDue = '';

    private const array QUEUES = ['ALL', 'REVIEW', 'OVERDUE', 'DUE_SOON', 'UNASSIGNED'];

    #[Locked]
    public string $projectId;

    /* Task-table filters — plain filter state, deliberately unlocked. */
    #[Url(except: 'ALL')]
    public string $status = 'ALL';

    public string $assignee = 'ALL';

    public string $priority = 'ALL';

    public string $queue = 'ALL';

    #[Url(as: 'group', except: 'ALL')]
    public string $group = 'ALL';

    /** @var list<string> Editable filters, validated by the scoped task query before use. */
    #[Url(as: 'groups', except: [])]
    public array $groupSelection = [];

    #[Url(as: 'parent', except: 'ALL')]
    public string $parent = 'ALL';

    public bool $groupList = true;

    #[Locked]
    public ?string $taskParentId = null;

    public bool $groupEditorOpen = false;

    #[Locked]
    public ?string $editingGroupId = null;

    public string $groupName = '';

    /** Editable draft, not #[Locked]; CreateTask revalidates its project/group link under the project lock. */
    public string $taskGroupId = 'NONE';

    #[Locked]
    public ?string $seenGroupId = null;

    #[Url(as: 'view', except: 'overview')]
    public string $taskView = 'overview';

    public string $lastTaskView = 'board';

    public function quickCreate(string $kind): void
    {
        $actor = $this->access();
        if ($kind === 'indicator' && app(ProjectResults::class)->permissions($actor, $this->projectId)['edit']) {
            $this->taskView = 'reporting';
            $this->dispatch('ppm-quick-indicator', projectId: $this->projectId)->to(ProjectReportingWorkspace::class);
        } elseif ($kind === 'expense' && app(ProjectFinancials::class)->permissions($actor, $this->projectId)['edit'] && app(ProjectFinancials::class)->settings($actor, $this->projectId) !== null) {
            $this->taskView = 'finance';
            $this->dispatch('ppm-quick-expense', projectId: $this->projectId)->to(ProjectFinanceWorkspace::class);
        }
    }

    public function openTasks(): void
    {
        $this->taskView = in_array($this->lastTaskView, ['board', 'list', 'gantt'], true) ? $this->lastTaskView : 'board';
    }

    public bool $memberEditorOpen = false;

    public bool $membersOpen = false;

    public bool $detailsOpen = false;

    public bool $projectStatusEditing = false;

    /** @var array<string, string> */
    #[Locked]
    public array $projectEditOriginal = [];

    #[Locked]
    public string $requestedTaskStatus = 'PENDING';

    #[Locked]
    public string $taskCreateStatus = 'PENDING';

    public string $activitySearch = '';

    public string $activityEvent = 'ALL';

    public string $activityOrder = 'date-desc';

    public function openPeople(): void
    {
        $this->resetPeople();
        $this->membersOpen = true;
        $this->detailsOpen = true;
    }

    public function updatedDetailsOpen(bool $open): void
    {
        if (! $open) {
            $this->discardProjectDetails(true);
        }
    }

    public function cancelProjectEdit(): void
    {
        $this->requestProjectDetailsExit(false);
    }

    public function closeProjectDetails(): void
    {
        $this->requestProjectDetailsExit(true);
    }

    public function discardProjectDetails(bool $close = false): void
    {
        $this->editing = $this->projectStatusEditing = false;
        $this->projectEditOriginal = [];
        $this->reset('editName', 'editDescription', 'editSiteId', 'editStatus', 'editStartDate', 'editDueDate', 'editManagerId');
        $this->resetValidation(['projectEdit', 'projectStatus']);
        if ($close) {
            $this->membersOpen = $this->detailsOpen = false;
        }
    }

    private function requestProjectDetailsExit(bool $close): void
    {
        if (($this->editing && $this->projectEditOriginal !== $this->projectEditValues())
            || ($this->projectStatusEditing && $this->editStatus !== $this->seenEditStatus)) {
            $this->dispatch('social-confirm', title: 'Discard unsaved changes?', body: "Your edits haven't been saved.", confirm: 'Discard changes', cancel: 'Keep editing', danger: true, call: ['discardProjectDetails', $close]);

            return;
        }
        $this->discardProjectDetails($close);
    }

    /**
     * Compare with the opened draft, even if someone else updates the project.
     *
     * @return array<string, string>
     */
    private function projectEditValues(): array
    {
        return ['name' => trim($this->editName), 'description' => trim($this->editDescription), 'site' => $this->editSiteId,
            'status' => $this->editStatus, 'start' => $this->editStartDate, 'due' => $this->editDueDate, 'lead' => $this->editManagerId];
    }

    public function openProjectDetails(): void
    {
        $this->resetPeople();
        $this->editing = $this->projectStatusEditing = false;
        $this->projectEditOriginal = [];
        $this->detailsOpen = true;
    }

    public function editProjectStatus(): void
    {
        $project = $this->scopedProject($this->access());
        if ($project === null || ! WorkRules::runsProject($this->access(), $project)) {
            return;
        }
        $this->editStatus = $this->seenEditStatus = $project->status;
        $this->resetValidation('projectStatus');
        $this->projectStatusEditing = true;
    }

    public function saveProjectStatus(): void
    {
        $actor = $this->access();
        $project = $this->scopedProject($actor);
        if ($project === null || ! WorkRules::runsProject($actor, $project)) {
            Flux::toast(variant: 'danger', text: WorkRules::forbiddenMessage());

            return;
        }
        $this->changeStatus($this->editStatus, $this->seenEditStatus);
    }

    /** Editable draft, not #[Locked]; SetProjectMember revalidates the account, project and role on save. */
    public string $memberUserId = '';

    #[Locked]
    public ?int $editingMemberUserId = null;

    public string $memberRole = 'PARTICIPANT';

    public string $memberPosition = '';

    public bool $editingContent = false;

    public string $contentTitle = '';

    public string $contentDescription = '';

    #[Locked]
    public string $seenContentTitle = '';

    #[Locked]
    public string $seenContentDescription = '';

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

    public bool $taskStatusConfirm = false;

    #[Locked]
    public string $seenTaskStatus = 'PENDING';

    #[Locked]
    public string $seenPriority = 'NORMAL';

    /** @var list<string> Observed crew for the field-specific stale-edit check. */
    #[Locked]
    public array $seenAssigneeIds = [];

    /**
     * The review dialog's assignee draft, seeded from the task's current set
     * on open; entangled and re-validated in saveDetails(), so NOT #[Locked]
     * (see $taskAssigneeIds).
     *
     * @var list<string>
     */
    public array $draftAssigneeIds = [];

    #[Locked]
    public int $reviewAssigneeNonce = 0;

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

    #[Locked]
    public string $seenDueDate = '';

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

    /** @return list<string> */
    protected function pageSizePageNames(): array
    {
        return ['page', 'activityPage'];
    }

    public function mount(string $project): void
    {
        $this->projectId = $project;
        $this->sortKey = 'stack';
        $this->followTaskLink();
    }

    public function openMember(?int $userId = null): void
    {
        $actor = $this->access();
        if (! WorkRules::managesProject($actor, $this->scopedProject($actor))) {
            return;
        }
        $member = $userId === null ? null : app(ProjectPeople::class)->member($actor, $this->projectId, $userId);
        if ($userId !== null && $member === null && ! app(ProjectPeople::class)->candidates($actor, $this->projectId)->contains('id', $userId)) {
            return;
        }
        $this->resetValidation();
        $this->editingMemberUserId = $member?->user_id;
        $this->memberUserId = $userId === null ? '' : (string) $userId;
        $this->memberRole = $member->role ?? 'PARTICIPANT';
        $this->memberPosition = $member->position ?? '';
        $this->memberEditorOpen = true;
    }

    public function saveMember(): void
    {
        $actor = $this->access();
        $this->validate(['memberUserId' => 'required|integer|min:1']);
        $this->writeMember($actor, $this->editingMemberUserId ?? (int) $this->memberUserId, $this->memberRole);
    }

    public function removeMember(): void
    {
        $actor = $this->access();
        if ($this->editingMemberUserId !== null) {
            $this->writeMember($actor, $this->editingMemberUserId, null);
        }
    }

    private function writeMember(Access $actor, int $userId, ?string $role): void
    {
        try {
            app(SetProjectMember::class)->handle($actor, $this->projectId, $userId, $role, $this->memberPosition, mustBeNew: $this->editingMemberUserId === null);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'Project access could not be saved. Your draft is still here.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        $this->memberEditorOpen = false;
        Flux::toast(variant: 'success', text: $role === null ? 'Shared project role removed. Existing assignments and lead access remain.' : 'Project access saved.');
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['stack', 'code', 'task', 'group', 'assignee', 'priority', 'start', 'due', 'status'];
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

    public function updatedGroup(): void
    {
        $this->groupSelection = [];
        $this->parent = 'ALL';
        $this->resetPage();
        foreach (TaskPolicy::STATUSES as $status => $label) {
            $this->resetPage('board_'.$status);
        }
    }

    public function updatedGroupSelection(): void
    {
        $this->group = 'ALL';
        $this->parent = 'ALL';
        $this->resetPage();
        foreach (TaskPolicy::STATUSES as $status => $label) {
            $this->resetPage('board_'.$status);
        }
    }

    public function openGroupEditor(?string $groupId = null): void
    {
        $actor = $this->access();
        if (! WorkRules::editsWork($actor, $this->scopedProject($actor))) {
            return;
        }
        $group = $groupId === null ? null : app(ProjectTaskGroups::class)->forProject($actor, $this->projectId)->firstWhere('id', $groupId);
        if ($groupId !== null && $group === null) {
            return;
        }
        $this->resetValidation('groupName');
        $this->editingGroupId = $groupId;
        $this->groupName = $group->name ?? '';
        $this->groupEditorOpen = true;
    }

    public function saveGroup(): void
    {
        try {
            $group = app(SaveTaskGroup::class)->handle($this->access(), $this->projectId, $this->editingGroupId, $this->groupName);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The activity could not be saved. Your draft is still here.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        $this->group = $group->id;
        $this->updatedGroup();
        $this->groupEditorOpen = false;
        Flux::toast(variant: 'success', text: 'Activity saved.');
    }

    public function deleteGroup(string $groupId): void
    {
        try {
            app(DeleteTaskGroup::class)->handle($this->access(), $this->projectId, $groupId);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The activity could not be removed.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        if ($this->group === $groupId) {
            $this->group = 'ALL';
            $this->updatedGroup();
        }
        Flux::toast(variant: 'success', text: 'Empty activity removed.');
    }

    public function openTaskCreation(?string $groupId = null, string $status = 'PENDING'): void
    {
        $actor = $this->access();
        $project = $this->scopedProject($actor);
        if (! WorkRules::editsWork($actor, $project) || in_array($project?->status, ['COMPLETED', 'CANCELLED'], true)) {
            return;
        }
        if (! TaskPolicy::isStatus($status)) {
            return;
        }
        $this->resetValidation('taskCreation');
        $this->requestedTaskStatus = $status;
        $this->taskCreateStatus = in_array($status, ['IN_PROGRESS', 'COMPLETED', 'CANCELLED'], true) && WorkRules::runsProject($actor, $project) ? $status : 'PENDING';
        if ($this->parent !== 'ALL') {
            $this->openSubtaskCreation($this->parent, $status);

            return;
        }
        $groups = app(ProjectTaskGroups::class)->forProject($actor, $this->projectId);
        $selected = $groupId ?? (count($this->groupSelection) === 1 ? $this->groupSelection[0] : $this->group);
        $this->taskParentId = null;
        $this->taskGroupId = $groups->contains('id', $selected) ? $selected : 'NONE';
        $this->adding = true;
    }

    public function openSubtaskCreation(string $parentId, string $status = 'PENDING'): void
    {
        $actor = $this->access();
        $project = $this->scopedProject($actor);
        $task = $this->scopedTask($actor, $parentId);
        if (! WorkRules::editsWork($actor, $project) || $task === null || in_array($project?->status, ['COMPLETED', 'CANCELLED'], true)
            || ! TaskHierarchy::canAdd($task->status, $task->parent_id, $this->modeLocked($task))) {
            Flux::toast(variant: 'danger', text: 'Add subtasks to a top-level task before its work is handed in or closed.');

            return;
        }
        $this->requestedTaskStatus = $status;
        $this->taskCreateStatus = in_array($status, ['IN_PROGRESS', 'COMPLETED', 'CANCELLED'], true) && WorkRules::runsProject($actor, $project) ? $status : 'PENDING';
        $this->taskParentId = $task->id;
        $this->taskGroupId = 'NONE';
        $this->closeTaskPanel();
        $this->adding = true;
    }

    public function showSubtasks(string $parentId): void
    {
        $task = $this->scopedTask($this->access(), $parentId);
        if ($task === null || $task->isSubtask()) {
            return;
        }
        $this->parent = $parentId;
        $this->groupSelection = [];
        $this->taskView = 'list';
        $this->closeTaskPanel();
        $this->clearFilters();
        $this->group = $task->effective_group_id ?? 'NONE';
    }

    public function clearParent(): void
    {
        $this->parent = 'ALL';
        $this->resetPage();
    }

    public function updatedGroupList(): void
    {
        $this->resetPage();
    }

    public function moveTaskGroup(string $groupId): void
    {
        if ($this->openTaskId === null) {
            return;
        }
        $target = $groupId === 'NONE' ? null : $groupId;
        try {
            app(MoveTaskToGroup::class)->handle($this->access(), $this->projectId, $this->openTaskId, $target, $this->seenGroupId);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The activity could not be changed.');

            return;
        } catch (\RuntimeException $e) {
            $this->addError('taskProperty', $e->getMessage());
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        $this->seenGroupId = $target;
        $this->resetValidation('taskProperty');
        $this->dispatch('task-property-saved', taskId: $this->openTaskId);
        Flux::toast(variant: 'success', text: 'Activity updated.');
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
            if ($this->taskView === 'overview') {
                $this->clearFilters();
                $this->group = $this->parent = 'ALL';
                $this->taskView = 'list';
            }
            $this->queue = $queue;
        }
    }

    /** A workload row narrows the table to that person (project-detail.tsx:436-440). */
    public function filterByAssignee(string $employeeId): void
    {
        $this->membersOpen = false;
        $this->detailsOpen = false;
        $this->clearFilters();
        $this->group = $this->parent = 'ALL';
        $this->taskView = 'list';
        $this->assignee = $employeeId;
        $this->queue = 'ALL';
        $this->resetPage();
    }

    /* ------------------------------------------------------------------ */
    /*  Project status + edit */
    /* ------------------------------------------------------------------ */

    /**
     * Keep the status the editor saw so a later save cannot replace a newer change.
     */
    public function changeStatus(string $next, ?string $from = null): void
    {
        $this->resetValidation('projectStatus');
        $actor = $this->access();
        try {
            $changed = app(ChangeProjectStatus::class)->handle($actor, $this->projectId, $next, $from);
        } catch (QueryException $e) {
            report($e);
            $this->addError('projectStatus', 'The status could not be saved. Please try again.');
            Flux::toast(variant: 'danger', text: 'A record with those details already exists.');

            return;
        } catch (\RuntimeException $e) {
            $this->addError('projectStatus', $e->getMessage());
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        if (! $changed) {
            $this->projectStatusEditing = false;

            return;
        }
        $this->projectStatusEditing = false;
        Flux::toast(variant: 'success', text: 'Project marked '.mb_strtolower(ProjectPolicy::statusLabel($next)).'.');
    }

    /** Opening fills the drafts from the row (edit-project-dialog.tsx:67-76). */
    public function openEdit(): void
    {
        $this->resetValidation('projectEdit');
        $project = $this->scopedProject($this->access());
        if ($project === null || ! WorkRules::runsProject($this->access(), $project)) {
            return;
        }

        $this->editName = $project->name;
        $this->editDescription = $project->description ?? '';
        $this->editSiteId = $project->site_id ?? 'NONE';
        $this->editStatus = $this->seenEditStatus = $project->status;
        $this->editStartDate = $this->seenEditStartDate = ProjectsData::dateKey($project->start_date) ?? '';
        $this->editDueDate = ProjectsData::dateKey($project->due_date) ?? '';
        $this->editManagerId = $project->manager_id === null ? 'NONE' : (string) $project->manager_id;
        $this->projectEditOriginal = $this->projectEditValues();
        $this->projectStatusEditing = false;
        $this->editing = true;
        $this->detailsOpen = true;
    }

    /**
     * edit-project-dialog.tsx `save()` + PATCH /api/projects/[id]. A Projects
     * manager saves every field (UpdateProject). Anyone else is at most the
     * project's Task Leader, whose dialog shows the start date alone, and
     * saves only that (ScheduleProject, which refuses everyone else).
     */
    public function saveProject(): void
    {
        $this->resetValidation('projectEdit');
        $actor = $this->access();
        try {
            $code = ProjectsData::managesProject($actor, $this->scopedProject($actor))
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
            $this->addError('projectEdit', 'A record with those details already exists.');

            return;
        } catch (\RuntimeException $e) {
            $this->addError('projectEdit', $e->getMessage());

            return;
        }
        if ($code === null) {
            return;
        }
        Flux::toast(variant: 'success', text: "Project {$code} updated.");
        $this->editing = false;
        $this->projectEditOriginal = [];
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
            $parentId = $this->taskParentId;
            $this->resetTaskDraft();
            if ($parentId !== null) {
                $this->openTask($parentId);
            }
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
        $this->resetValidation('taskCreation');
        if ($this->briefStage !== [] && in_array($this->taskCreateStatus, ['COMPLETED', 'CANCELLED'], true)) {
            $this->addError('taskCreation', 'Attach references to an open task before closing it.');

            return;
        }
        try {
            $task = app(CreateTaskInStatus::class)->handle($actor, $this->projectId, new TaskDraft($this->taskTitle, $this->taskDescription, $this->taskPriority, $this->taskDueDate, $this->taskAssigneeIds, $this->taskSubmissionMode, $this->taskStartDate, $this->taskGroupId === 'NONE' ? null : $this->taskGroupId, $this->taskParentId), $this->taskCreateStatus);
        } catch (QueryException $e) {
            report($e);
            $this->addError('taskCreation', 'A record with those details already exists.');
            Flux::toast(variant: 'danger', text: 'A record with those details already exists.');

            return;
        } catch (\RuntimeException $e) {
            $this->addError('taskCreation', $e->getMessage());
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
        $this->openTask($task->id);
    }

    /* ------------------------------------------------------------------ */
    /*  Task review dialog */
    /* ------------------------------------------------------------------ */

    public function clearFilters(): void
    {
        if ($this->taskView === 'activity') {
            $this->activityEvent = 'ALL';
            $this->resetPage('activityPage');

            return;
        }
        $this->search = '';
        $this->groupSelection = [];
        $this->group = 'ALL';
        $this->status = $this->assignee = $this->priority = $this->queue = 'ALL';
        $this->resetPage();
        foreach (TaskPolicy::STATUSES as $status => $label) {
            $this->resetPage('board_'.$status);
        }
    }

    /** A row click opens the dialog; the id is re-resolved through the scope. */
    public function openTask(string $taskId): void
    {
        $task = $this->scopedTask($this->access(), $taskId);
        if ($task === null) {
            $this->linkedTask = null;
            Flux::toast(variant: 'danger', text: 'This task is no longer available to you.');

            return;
        }

        $this->openTaskId = $task->id;
        $this->linkedTask = $task->id;
        $this->resetPage('subtasks');
        $this->seenGroupId = $task->group_id;
        $this->reviewOpen = true;

        // A new task must not inherit the draft typed against the last
        // (task-review-dialog.tsx:486-492).
        $this->remarks = '';
        $this->commentBody = '';
        $this->editingDetails = false;
        $this->editingContent = false;
        $this->contentTitle = $this->contentDescription = $this->seenContentTitle = $this->seenContentDescription = '';
        $this->seedDetails($task);
        $this->manualStatus = '';
        $this->expandedMemberId = null;
        $this->responseRemark = '';
        $this->reviewBriefUpload = null;
    }

    public function editContent(): void
    {
        $actor = $this->access();
        $task = $this->currentTask($actor);
        if ($task === null || ! WorkRules::editsWork($actor, $task->project) || $this->modeLocked($task)) {
            return;
        }
        $this->contentTitle = $this->seenContentTitle = $task->title;
        $this->contentDescription = $this->seenContentDescription = (string) $task->description;
        $this->editingContent = true;
    }

    public function saveContent(): void
    {
        $actor = $this->access();
        if ($this->openTaskId === null) {
            return;
        }
        $this->validate(['contentTitle' => 'required|string|max:'.TaskPolicy::TITLE_LIMIT, 'contentDescription' => 'string|max:'.TaskPolicy::DESCRIPTION_LIMIT], [], ['contentTitle' => 'task title', 'contentDescription' => 'description']);
        try {
            app(UpdateTaskContent::class)->handle($actor, $this->projectId, $this->openTaskId, $this->contentTitle, $this->contentDescription, $this->seenContentTitle, $this->seenContentDescription);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The brief could not be saved. Your draft is still here.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        $this->editingContent = false;
        Flux::toast(variant: 'success', text: 'Task brief saved.');
    }

    public function resetDetails(): void
    {
        $this->resetValidation('taskProperty');
        $task = $this->currentTask($this->access());
        if ($task !== null) {
            $this->seedDetails($task);
        }
    }

    private function seedDetails(ProjectTask $task): void
    {
        // Recreate the chooser so discarded ticks and its summary reset together.
        $this->reviewAssigneeNonce++;
        $this->draftAssigneeIds = $this->seenAssigneeIds = $task->assigneeIds();
        $this->draftPriority = $this->seenPriority = $task->priority;
        $this->seenTaskStatus = $task->status;
        $this->seenGroupId = $task->group_id;
        $this->draftStartDate = $this->seenStartDate = ProjectsData::dateKey($task->start_date) ?? '';
        $this->draftDueDate = $this->seenDueDate = ProjectsData::dateKey($task->due_date) ?? '';
        $this->draftSubmissionMode = $this->seenSubmissionMode = $task->submission_mode;
    }

    public function updatedReviewOpen(mixed $value): void
    {
        if (! $value) {
            $this->linkedTask = null;
            $this->openTaskId = null;
            $this->editingDetails = false;
            $this->expandedMemberId = null;
            $this->taskStatusConfirm = false;
        }
    }

    protected function closeTaskPanel(): void
    {
        $this->reviewOpen = false;
        $this->updatedReviewOpen(false);
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
            $this->draftDueDate = $this->seenDueDate = ProjectsData::dateKey($task->due_date) ?? '';
        }
    }

    public function saveTaskProperty(string $field, ?string $value = null): void
    {
        $this->resetValidation('taskProperty');
        $property = match ($field) {
            'priority' => new TaskPropertyEdit($field, $value ?? $this->draftPriority, $this->seenPriority),
            'assignees' => new TaskPropertyEdit($field, WorkRules::cleanIds($this->draftAssigneeIds), $this->seenAssigneeIds),
            'mode' => new TaskPropertyEdit($field, $value ?? $this->draftSubmissionMode, $this->seenSubmissionMode),
            'start' => new TaskPropertyEdit($field, $this->draftStartDate, $this->seenStartDate),
            'due' => new TaskPropertyEdit($field, $this->draftDueDate, $this->seenDueDate),
            default => null,
        };
        if ($property === null || $this->openTaskId === null) {
            return;
        }
        try {
            $code = app(UpdateTaskDetails::class)->handle($this->access(), $this->projectId, $this->openTaskId, $property);
        } catch (QueryException $e) {
            report($e);
            $this->addError('taskProperty', 'That property could not be saved. Your draft is still here.');

            return;
        } catch (\RuntimeException $e) {
            $this->addError('taskProperty', $e->getMessage());

            return;
        }
        if ($code === null) {
            $this->addError('taskProperty', 'That task changed before the property was saved. Reload and try again.');

            return;
        }
        $this->resetDetails();
        $this->dispatch('task-property-saved', taskId: $this->openTaskId);
    }

    public function chooseTaskStatus(string $status): void
    {
        $actor = $this->access();
        $task = $this->currentTask($actor);
        if ($task === null || ! TaskPolicy::isStatus($status)) {
            return;
        }
        if (! WorkRules::runsProject($actor, $task->project)) {
            Flux::toast(variant: 'danger', text: WorkRules::forbiddenMessage());

            return;
        }
        $this->manualStatus = $status;
        if (ManualTaskStatus::needsDecision($this->seenTaskStatus, $status)) {
            $this->taskStatusConfirm = true;
        } else {
            $this->setTaskStatus($this->seenTaskStatus);
        }
    }

    /** task-review-dialog.tsx `saveDetails()` + the details half of PATCH /api/tasks/[id]. */
    public function saveDetails(): void
    {
        $actor = $this->access();
        // Untouched fields keep changes made from another view.
        $mode = $this->draftSubmissionMode === $this->seenSubmissionMode ? null : $this->draftSubmissionMode;
        $start = $this->draftStartDate === $this->seenStartDate ? null : $this->draftStartDate;
        $due = $this->draftDueDate === $this->seenDueDate ? null : $this->draftDueDate;
        try {
            $code = app(UpdateTaskDetails::class)->handle($actor, $this->projectId, $this->openTaskId, new TaskDetails($this->draftPriority, $due, $this->draftAssigneeIds, $mode, $start, $this->seenStartDate, $this->seenDueDate));
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
        $fresh = $this->currentTask($actor);
        if ($fresh !== null) {
            $this->seedDetails($fresh);
        }
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
        $this->closeTaskPanel();
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
        $this->resetValidation('taskProperty');
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
            $this->addError('taskProperty', 'The status could not be saved. Please try again.');
            Flux::toast(variant: 'danger', text: 'The status could not be saved. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            $this->addError('taskProperty', $e->getMessage());
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: "Task {$task->code} set to ".mb_strtolower(TaskPolicy::statusLabel($to)).'.');
        $this->remarks = '';
        $this->manualStatus = '';
        $this->taskStatusConfirm = false;
        $this->resetDetails();
        $this->dispatch('task-property-saved', taskId: $this->openTaskId);
    }

    public function moveBoardTask(string $taskId, string $to, string $from): bool
    {
        $actor = $this->access();
        $task = $this->scopedTask($actor, $taskId);
        if ($task === null || ! ProjectsData::runsTask($actor, $task)) {
            Flux::toast(variant: 'danger', text: WorkRules::forbiddenMessage());

            return false;
        }
        if ($task->status !== $from) {
            Flux::toast(variant: 'danger', text: 'That task changed before this action completed.');

            return false;
        }
        if (! ManualTaskStatus::canMove($from, $to, $task->submission_mode)) {
            Flux::toast(variant: 'danger', text: $to === 'SUBMITTED'
                ? 'Submit work from My Tasks with its response and files.'
                : TaskPolicy::transitionError());

            return false;
        }

        // Decisions and reopening need an explicit action in the task dialog.
        if (ManualTaskStatus::needsDecision($from, $to)) {
            $this->openTask($task->id);
            $responses = $task->isIndividual() ? app(WorkRecords::class)->responses($actor, $task->id) : collect();
            $moves = ProjectsData::manualMoves($actor, $task, $task->project->status ?? '',
                $responses->map(static fn (TaskResponseView $response): string => $response->status)->all());
            $this->manualStatus = in_array($to, $moves, true) ? $to : '';

            return false;
        }

        try {
            app(ChangeTaskStatus::class)->handle($actor, $this->projectId, $task->id, $to, '', $from);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The status could not be saved. Please try again.');

            return false;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return false;
        }

        Flux::toast(variant: 'success', text: "Task {$task->code} set to ".mb_strtolower(TaskPolicy::statusLabel($to)).'.');

        return true;
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

    protected function timelineTask(string $id): ?ProjectTask
    {
        $actor = $this->access();
        abort_unless($actor->hasModule(ModuleCode::PROJECTS), 403);

        return $this->scopedTask($actor, $id);
    }

    public function openSchedule(string $id): void
    {
        $actor = $this->access();
        $task = $this->scopedTask($actor, $id);
        if ($task === null || (! WorkRules::runsTask($actor, $task) && ! WorkRules::editsWork($actor, $task->project)) || TaskPolicy::isClosed($task->status)) {
            Flux::toast(variant: 'danger', text: 'These task dates are read-only.');

            return;
        }
        $this->scheduleTaskId = $task->id;
        $this->scheduleStart = $this->scheduleSeenStart = $task->start_date?->format('Y-m-d') ?? '';
        $this->scheduleDue = $this->scheduleSeenDue = $task->due_date?->format('Y-m-d') ?? '';
        $this->closeTaskPanel();
        $this->scheduling = true;
    }

    public function updatedScheduling(bool $value): void
    {
        if (! $value) {
            $this->scheduleTaskId = null;
            $this->scheduleStart = $this->scheduleDue = $this->scheduleSeenStart = $this->scheduleSeenDue = '';
        }
    }

    public function saveSchedule(): void
    {
        $actor = $this->access();
        if ($this->scheduleTaskId !== null && $this->writeSchedule($actor, $this->scheduleTaskId, new TaskDateEdit($this->scheduleStart, $this->scheduleDue, $this->scheduleSeenStart, $this->scheduleSeenDue))) {
            $this->scheduling = false;
            $this->updatedScheduling(false);
        }
    }

    public function moveSchedule(string $id, string $start, string $due, string $seenStart, string $seenDue): void
    {
        $actor = $this->access();
        $this->writeSchedule($actor, $id, new TaskDateEdit($start, $due, $seenStart, $seenDue));
    }

    private function writeSchedule(Access $actor, string $id, TaskDateEdit $edit): bool
    {
        try {
            $code = app(UpdateTaskDetails::class)->handle($actor, $this->projectId, $id, $edit);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The dates could not be saved. Your draft is still here.');

            return false;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return false;
        }
        if ($code === null) {
            return false;
        }
        if ($code !== '') {
            Flux::toast(variant: 'success', text: 'Task dates updated.');
        }

        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Render */
    /* ------------------------------------------------------------------ */

    private function contextPortfolio(Access $actor, string $clientId): ?PortfolioView
    {
        $client = app(ProjectClients::class)->client($actor, $clientId);

        return $client?->portfolio_id !== null && $client->portfolio_name !== null ? app(ProjectPortfolios::class)->portfolio($actor, $client->portfolio_id) : null;
    }

    public function render(): View
    {
        $actor = $this->access();
        $project = $this->scopedProject($actor);
        $today = ProjectsData::todayKey();
        // The blade keeps the manage-only "Delete project" control away from
        // a viewer it could only ever refuse; deleteProject() re-checks anyway.
        $canManage = ProjectsData::managesProject($actor, $project);
        $canReadFinance = $project !== null && app(ProjectFinancials::class)->canView($actor, $project->id);
        $canReadReporting = $project !== null && app(ProjectReports::class)->permissions($actor, $project->id)['view'];

        if ($project === null) {
            return view('livewire.projects.show', [
                'project' => null,
                'today' => $today,
                'canReadFinance' => $canReadFinance,
                'canReadReporting' => $canReadReporting,
                'canManage' => $canManage,
                'runsProject' => false,
            ]);
        }
        // A Projects manager, or this project's Task Leader: the hand-set
        // status controls and the start dates. Display only — every command
        // re-checks it on the locked rows.
        $runsProject = ProjectsData::runsProject($actor, $project);
        $canEditWork = WorkRules::editsWork($actor, $project);

        if (in_array($this->taskView, ['details', 'members'], true)) {
            $this->membersOpen = $this->taskView === 'members';
            $this->detailsOpen = true;
            $this->taskView = 'overview';
        }
        $this->taskView = in_array($this->taskView, ['overview', 'board', 'list', 'gantt', 'activity', 'finance', 'capacity', 'reporting', 'details', 'members'], true) ? $this->taskView : 'overview';
        if (in_array($this->taskView, ['board', 'list', 'gantt'], true)) {
            $this->lastTaskView = $this->taskView;
        }
        if ($this->taskView === 'finance' && ! $canReadFinance) {
            $this->taskView = 'overview';
        }
        if ($this->taskView === 'reporting' && ! $canReadReporting) {
            $this->taskView = 'overview';
        }
        if ($this->group !== 'ALL' && $this->groupSelection === []) {
            $this->groupSelection = [$this->group];
        }
        $filters = [
            'q' => $this->search,
            'status' => $this->status,
            'assignee' => $this->assignee,
            'priority' => $this->priority,
            'queue' => $this->queue,
            'group' => $this->group,
            'groups' => $this->groupSelection,
            'parent' => $this->parent,
        ];
        $lists = app(WorkLists::class);
        $rows = in_array($this->taskView, ['list', 'gantt'], true) ? $this->acceptPage($lists->taskPage(
            $actor, $project->id, $filters,
            $this->sortKey,
            $this->sortDir,
            $today,
            (int) $this->getPage(),
            $this->perPage,
            $this->groupList,
        )) : null;
        $board = [];
        if ($this->taskView === 'board') {
            $pages = [];
            foreach (TaskPolicy::STATUSES as $status => $label) {
                $pages[$status] = (int) $this->getPage('board_'.$status);
            }
            $board = $lists->taskBoard($actor, $project->id, $filters, $today, $pages);
            foreach ($board as $status => $lane) {
                $this->acceptPage($lane, 'board_'.$status);
            }
        }
        $visibleTasks = $rows?->getCollection() ?? collect($board)->flatMap(static fn (LengthAwarePaginator $lane): array => $lane->items());
        $records = app(WorkRecords::class);
        $summary = app(WorkSummary::class);
        $taskTotals = $summary->projects($actor, [$project->id], $today)[$project->id];
        $groups = app(ProjectTaskGroups::class)->forProject($actor, $project->id);
        $workload = in_array($this->taskView, ['board', 'list', 'gantt'], true) ? $summary->workload($actor, $project->id, $today) : collect();
        $taskIds = $visibleTasks->map(static fn (ProjectTask $task): string => $task->id)->values()->all();
        $counts = $records->counts($actor, array_values($taskIds));
        $activity = $this->taskView === 'activity' ? $records->projectActivity($actor, $project->id, (int) $this->getPage('activityPage'), $this->activitySearch, $this->activityEvent, str_starts_with($this->activityOrder, 'author-') ? 'author' : 'date', str_ends_with($this->activityOrder, '-asc') ? 'asc' : 'desc', $this->perPage) : null;
        if ($activity !== null) {
            $this->acceptPage($activity, 'activityPage');
        }
        $recentActivity = $activity?->getCollection() ?? collect();
        $people = app(ProjectPeople::class)->roster($actor, $project->id, $today);
        $team = $this->detailsOpen || $this->membersOpen || $this->taskView === 'members'
            ? $this->acceptPage(PeopleList::page($people, $this->peopleSearch, $this->peopleAccess, $this->peopleSort, $this->peopleDirection, (int) $this->getPage('peoplePage'), $this->peoplePerPage), 'peoplePage') : null;
        $activityIds = $recentActivity->map(static fn (TaskComment $comment): string => $comment->task_id)->unique()->values()->all();
        $openTask = $this->currentTask($actor);
        $focusedParent = $this->parent === 'ALL' ? null : $this->scopedTask($actor, $this->parent);
        $subtasks = null;
        if ($openTask !== null && ! $openTask->isSubtask() && $openTask->has_subtasks) {
            $subtasks = $lists->taskPage($actor, $project->id, ['q' => '', 'status' => 'ALL', 'assignee' => 'ALL', 'priority' => 'ALL', 'queue' => 'ALL', 'parent' => $openTask->id], 'stack', 'asc', $today, (int) $this->getPage('subtasks'), 10)->setPageName('subtasks');
            $this->acceptPage($subtasks, 'subtasks');
        }
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
            'contextPortfolio' => $project->client_id === null ? null : $this->contextPortfolio($actor, $project->client_id),
            'groups' => $groups,
            'activeGroup' => count($this->groupSelection) > 1 ? null : $groups->firstWhere('id', $this->groupSelection[0] ?? $this->group),
            'groupTotals' => $summary->groups($actor, $project->id, $today),
            'focusedParent' => $focusedParent,
            'creatingParent' => $this->taskParentId === null ? null : $this->scopedTask($actor, $this->taskParentId),
            'openSubtasks' => $subtasks,
            'canAddSubtask' => $canEditWork && ! in_array($project->status, ['COMPLETED', 'CANCELLED'], true) && $openTask !== null
                && TaskHierarchy::canAdd($openTask->status, $openTask->parent_id, $this->modeLocked($openTask)),
            'activeFilterCount' => count(array_filter([$this->status, $this->assignee, $this->priority], static fn (string $value): bool => $value !== 'ALL')) + (trim($this->search) === '' ? 0 : 1) + ($this->groupSelection === [] ? 0 : 1),
            'canCreateTasks' => $canEditWork && ! in_array($project->status, ['COMPLETED', 'CANCELLED'], true)
                && ($this->parent === 'ALL' || ($focusedParent !== null && TaskHierarchy::canAdd($focusedParent->status, $focusedParent->parent_id, $this->modeLocked($focusedParent)))),
            'today' => $today,
            'canReadFinance' => $canReadFinance,
            'canReadReporting' => $canReadReporting,
            'canManage' => $canManage,
            'runsProject' => $runsProject,
            'canEditWork' => $canEditWork,
            'canQuickIndicator' => app(ProjectResults::class)->permissions($actor, $project->id)['edit'],
            'canQuickExpense' => app(ProjectFinancials::class)->permissions($actor, $project->id)['edit'] && app(ProjectFinancials::class)->settings($actor, $project->id) !== null,
            'team' => $team,
            'peopleCount' => $people->count(),
            'delivery' => $this->taskView === 'overview' ? $summary->projectDelivery($actor, $project->id) : null,
            'memberAccounts' => $canManage && $this->memberEditorOpen && $this->editingMemberUserId === null ? app(ProjectPeople::class)->candidates($actor, $project->id) : collect(),
            'activity' => $activity,
            'taskTotals' => $taskTotals,
            'taskCount' => $taskTotals->total,
            'visibleCount' => $rows?->total() ?? array_sum(array_map(static fn (LengthAwarePaginator $lane): int => $lane->total(), $board)),
            'rows' => $rows,
            'board' => $board,
            'boardMoves' => $visibleTasks->mapWithKeys(static fn (ProjectTask $task): array => [$task->id => array_values(array_filter(
                ManualTaskStatus::canMoveTo($task->status, $task->submission_mode),
                static fn (string $next): bool => ! ManualTaskStatus::needsDecision($task->status, $next),
            ))])->all(),
            'taskAuthority' => WorkRules::taskPermissions($actor, $project, $visibleTasks),
            'dateAuthority' => $canEditWork ? $visibleTasks->mapWithKeys(static fn (ProjectTask $task): array => [$task->id => true])->all() : WorkRules::taskPermissions($actor, $project, $visibleTasks),
            'timeline' => $this->taskView === 'gantt' ? $this->timelineWindow() : null,
            'scheduleTask' => $this->scheduleTaskId === null ? null : $this->scopedTask($actor, $this->scheduleTaskId),
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
            'leaderIds' => $canManage ? ($project->client_id === null ? app(ProjectAccounts::class)->leaderIds($actor) : app(ClientPeople::class)->leaders($actor, $project->client_id)->pluck('id')->all()) : [],
            'client' => $project->client_id === null ? null : app(ProjectClients::class)->client($actor, $project->client_id),
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
            'openTaskCanComment' => $openTask !== null && ($canEditWork || ProjectsData::runsTask($actor, $openTask) || $openTask->hasAssignee($actor->employeeId)),
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
                'group' => $this->group,
                'groups' => $this->groupSelection,
                'parent' => $this->parent,
                'grouped' => in_array($this->taskView, ['list', 'gantt'], true) && $this->groupList ? '1' : '0',
                'sort' => $this->sortKey,
                'dir' => $this->sortDir,
            ], static fn (string|array $v): bool => $v !== '' && $v !== []))),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function resetTaskDraft(): void
    {
        $this->taskTitle = '';
        $this->taskGroupId = 'NONE';
        $this->requestedTaskStatus = $this->taskCreateStatus = 'PENDING';
        $this->taskParentId = null;
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
