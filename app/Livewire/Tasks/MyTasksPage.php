<?php

declare(strict_types=1);

namespace App\Livewire\Tasks;

use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Domain\Projects\TaskPolicy;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Concerns\WithTaskLink;
use App\Livewire\Concerns\WithTaskTimeline;
use App\Livewire\Projects\ProjectsData;
use App\Modules\WorkManagement\Application\Commands\AttachSubmission;
use App\Modules\WorkManagement\Application\Commands\CommentOnOwnTask;
use App\Modules\WorkManagement\Application\Commands\DeleteSubmission;
use App\Modules\WorkManagement\Application\Commands\StartTask;
use App\Modules\WorkManagement\Application\Commands\SubmitTask;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView as ProjectTask;
use App\Modules\WorkManagement\Contracts\Views\TaskResponseView;
use App\Modules\WorkManagement\Domain\Tasks\TaskInbox;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\LocalClock;
use App\Support\TextSort;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * My Tasks — the assignee's queue.
 *
 * Port of copems/src/app/(protected)/my-tasks/my-tasks-page.tsx (the list) and
 * my-tasks/task-detail-dialog.tsx (the dialog), with the shared
 * components/TaskComments.tsx and components/TaskAttachments.tsx folded in as
 * Blade partials. The write paths reproduce the SERVER routes — the canonical
 * side of the reference — rather than the demo drivers:
 *
 *   PATCH  /api/tasks/[id]            → startTask() / submitTask()
 *   POST   /api/tasks/[id]/comments   → postComment()
 *   POST   /api/tasks/[id]/files      → updatedUpload()
 *   DELETE /api/tasks/files/[id]      → deleteFile()
 *
 * SCOPING. This screen is ALWAYS the actor's own queue: the reference narrows
 * `tasks.filter(t => t.assignee_id === me.employeeId)` for every role,
 * reviewers included (my-tasks-page.tsx:63-66). `toEmployeeVia()` is
 * deliberately NOT used here — its ROWS_ALL arm would widen the queue to
 * everyone's tasks for an all_data + PROJECTS holder, which is the Projects
 * screen's behaviour, not this one's. The own-rows arm is reproduced instead
 * in {@see self::ownTasks()} with the same leak-proof construction the trait
 * uses: an EXISTS on the `project_task_assignees` pivot with an explicit '='
 * against {@see Access::ownEmployeeId()}, whose non-nullable return type makes
 * an `IS NULL` uncompilable, behind a {@see Access::hasEmployeeLink()} guard
 * whose false arm compiles to `1 = 0` (the unlinked card, and no rows).
 *
 * MULTI-ASSIGNEE. A task has 0..N assignees (the pivot,
 * {@see ProjectTask::assignees()}), and its crew hands work in one of two
 * ways (DELIBERATE DIVERGENCE: the reference has one assignee and no mode):
 *
 *   GROUP — one SHARED unit of work. Status, remarks and submitted_at stay
 *   one-per-task: any assignee may start or submit for everyone, and
 *   `submitted_by` records who did. Co-assignees are told about a peer's
 *   submission.
 *
 *   INDIVIDUAL — any assignee still starts the task for everyone, but each
 *   then submits, and is reviewed on, their OWN response
 *   (WorkRecords::responses(), read with `own: true`). The task stays in
 *   progress until management completes it; what this screen shows the actor
 *   is their response's state ($myState, the same rule as the list's
 *   `my_status`). Their note, their files and the review of them are theirs
 *   and the reviewer's alone: a co-assignee appears here by name and STATE,
 *   never by what they wrote or attached, and only the assigner hears of a
 *   member's submission.
 *
 * Either way a peer's comment and a management-side comment reach every other
 * assignee. Each fan-out is resolved to ACCOUNTS and written once per account,
 * never to the actor's own — {@see Notifier::employees()} with the actor's and
 * the assigner's accounts as its except-list, the assigner told separately
 * through {@see Notifier::account()} when they are not the actor. An uploaded
 * SUBMISSION file may be deleted only by the account that uploaded it
 * ({@see self::deleteFile()}).
 *
 * A task's status moves only through {@see TaskPolicy}'s mode-aware
 * transition maps, and an INDIVIDUAL member's own response only through
 * TaskSubmissionPolicy's member map (memberCanMove(), which SubmitTask
 * applies); what this page offers — submit, attach — is derived from those
 * rules and TaskFilePolicy, and every command re-checks them. Stamps
 * (`submitted_at`, `submitted_by`, actor columns) come from the session and
 * the clock, never from the client.
 */
#[Title('My Tasks')]
class MyTasksPage extends Component
{
    use WithFileUploads;
    use WithSorting;
    use WithTableFilters;
    use WithTaskLink;
    use WithTaskTimeline;

    #[Url(as: 'queue', except: 'ALL')]
    public string $queue = 'ALL';

    #[Url(as: 'view', except: 'list')]
    public string $taskView = 'list';

    /** Status filter — plain filter state, deliberately unlocked. */
    public string $status = 'ALL';

    /**
     * Project filter. Carries an id but is a FILTER, not an authorization
     * input: every query on this page is already narrowed to the actor's own
     * tasks before this value is consulted, so a tampered id can only shrink
     * the list further. Locked would break the wire:model binding the
     * FilterSelect needs.
     */
    public string $projectId = 'ALL';

    /** The task the detail dialog is showing. Id-bearing → #[Locked]; set only through openTask(). */
    #[Locked]
    public ?string $openId = null;

    /** The submission note — "What did you do?". */
    public string $remarks = '';

    /** The discussion composer draft. */
    public string $commentBody = '';

    /** The pending SUBMISSION upload (Livewire temporary file). */
    public mixed $upload = null;

    public function mount(): void
    {
        // Returned work and approaching dates put the next action first.
        $this->sortKey = 'focus';
        $this->followTaskLink();
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['code', 'task', 'priority', 'start', 'due', 'status', 'focus'];
    }

    /** Filter changes restart from page 1 (my-tasks-page.tsx:215, 231). */
    public function updatedStatus(): void
    {
        $this->queue = 'ALL';
        $this->resetPage();
    }

    public function updatedProjectId(): void
    {
        $this->resetPage();
    }

    public function updatedQueue(): void
    {
        $this->resetPage();
    }

    public function setQueue(string $queue): void
    {
        if (array_key_exists($queue, TaskInbox::QUEUES)) {
            $this->queue = $queue;
            $this->status = 'ALL';
            $this->resetPage();
        }
    }

    public function focusQueue(): void
    {
        $this->sortKey = 'focus';
        $this->sortDir = 'asc';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = $this->projectId = $this->queue = 'ALL';
        $this->resetPage();
    }

    protected function timelineTask(string $id): ?ProjectTask
    {
        $actor = $this->actor();
        $this->authorizeModule($actor);

        return app(WorkRecords::class)->task($actor, $id, own: true);
    }

    /* ---------------------------------- open ---------------------------------- */

    /** A row click. Only a task in the actor's own queue can open. */
    public function openTask(string $id): void
    {
        $access = $this->actor();
        $this->authorizeModule($access);

        if (! $access->hasEmployeeLink()) {
            return;
        }

        $task = app(WorkRecords::class)->task($access, $id, own: true);

        if ($task === null) {
            $this->linkedTask = null;
            Flux::toast(variant: 'danger', text: 'This task is no longer available to you.');

            return;
        }

        $this->openId = $task->id;
        $this->linkedTask = $task->id;
        $this->resetPage('subtasks');
        // The dialog stays mounted across tasks, so a new one must not inherit
        // the draft typed against the last (task-detail-dialog.tsx:104-106).
        $this->reset('remarks', 'commentBody', 'upload');

        Flux::modal('task-detail')->show();
    }

    /** The dialog was dismissed (Close button, ESC, overlay). */
    public function dialogClosed(): void
    {
        $this->linkedTask = null;
        $this->openId = null;
        $this->reset('remarks', 'commentBody', 'upload');
    }

    protected function closeTaskPanel(): void
    {
        $this->closeDialog();
    }

    /** Close it from an action of ours. */
    public function closeDialog(): void
    {
        Flux::modal('task-detail')->close();
        $this->dialogClosed();
    }

    /* ------------------------------- transitions ------------------------------- */

    /**
     * "Start task" / "Resume task" — the assignee taking the work (back) on.
     * PENDING → IN_PROGRESS and RETURNED → IN_PROGRESS, per
     * TaskPolicy::EMPLOYEE_TRANSITIONS. Taking the work back on drops any
     * submission stamp: PENDING never had one, and a returned task must not
     * keep reading as waiting on the reviewer. An INDIVIDUAL task is only
     * ever started (INDIVIDUAL_EMPLOYEE_TRANSITIONS): a returned response is
     * resubmitted, the task itself is never returned.
     */
    public function startTask(?string $taskId = null): void
    {
        $access = $this->actor();
        try {
            $done = app(StartTask::class)->handle($access, $taskId ?? $this->openId);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The task could not be updated. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        if (! $done) {
            return;
        }
        Flux::toast(variant: 'success', text: 'Task started.');
    }

    /**
     * "Submit for review" / "Submit my response". SubmitTask branches on the
     * task's mode:
     *
     * On a GROUP task — IN_PROGRESS → SUBMITTED with the note the reviewer
     * reads. `remarks` only ever holds the latest note, so the thread comment
     * is what keeps a resubmission from erasing what was said the first time
     * round (task-detail-dialog.tsx:213-231). The task is shared, so this
     * submits it for every assignee: the status moves once, `submitted_by`
     * names the assignee who pressed the button, and the co-assignees are
     * told alongside the assigner and current reviewers.
     *
     * On an INDIVIDUAL task — the actor's OWN response goes to SUBMITTED (a
     * first hand-in, or a resubmission after a return) while the task stays in
     * progress. The note is private to the actor and the reviewer, so it goes
     * to no thread or notification body. Current reviewers receive the handoff.
     */
    public function submitTask(): void
    {
        $access = $this->actor();
        try {
            $done = app(SubmitTask::class)->handle($access, $this->openId, $this->remarks);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The task could not be updated. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        if (! $done) {
            return;
        }
        Flux::toast(variant: 'success', text: 'Submitted for review.');
        $this->closeDialog();
    }

    /* -------------------------------- discussion ------------------------------- */

    /**
     * Post to the task's thread. Deliberately not gated on the task being
     * editable: asking about a task that is already under review — or one that
     * was cancelled — is the whole point of having a thread
     * (task-detail-dialog.tsx:383-396).
     */
    public function postComment(): void
    {
        $access = $this->actor();
        try {
            $done = app(CommentOnOwnTask::class)->handle($access, $this->openId, $this->commentBody);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The task could not be updated. Please try again.');

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

    /* -------------------------------- attachments ------------------------------ */

    /**
     * A SUBMISSION file was picked — store it. Fires as soon as the hidden
     * input receives a file, matching the reference's upload-on-pick
     * (TaskAttachments.tsx:52-88).
     *
     * Once work is handed in the files are evidence under review, so they
     * must stop changing under the reviewer. On a GROUP task the server locks
     * SUBMISSION evidence to IN_PROGRESS / RETURNED
     * (api/tasks/[id]/files/route.ts:47-51); on an INDIVIDUAL task the lock is
     * the member's OWN response — open while it can still be handed in, shut
     * while it is submitted or approved (TaskFilePolicy::submissionEditable(),
     * which AttachSubmission re-checks under the task lock).
     */
    public function updatedUpload(): void
    {
        if ($this->upload === null) {
            return;
        }
        $access = $this->actor();
        $this->authorizeModule($access);
        try {
            $name = app(AttachSubmission::class)->handle($access, $this->openId, $this->upload);
            if ($name !== null) {
                Flux::toast(variant: 'success', text: $name.' attached.');
            }
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'Upload failed');
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
        $this->clearUpload();
    }

    /**
     * Withdraw one of the assignee's own SUBMISSION files. The BRIEF is the
     * reviewer's spec — deleting it is not the assignee's to do
     * (api/tasks/files/[id]/route.ts:41-45).
     *
     * "Own" is tighter than "on my task" because a task is shared. On a
     * GROUP task a co-assignee may download a peer's evidence (it is the
     * task's); on an INDIVIDUAL task a member's evidence is theirs and the
     * reviewer's alone (WorkRecords::download()). Either way only the account
     * that uploaded a file may withdraw it — `uploaded_by` is the
     * session-stamped actor column written by updatedUpload() — and only
     * while the evidence is still editable (the rule updatedUpload() follows).
     * Being an assignee is still required on top, so a reviewer who once
     * uploaded nothing and an ex-assignee who once did are both refused here.
     */
    public function deleteFile(string $fileId): void
    {
        $access = $this->actor();
        $this->authorizeModule($access);
        try {
            $name = app(DeleteSubmission::class)->handle($access, $fileId);
            if ($name !== null) {
                Flux::toast(variant: 'success', text: $name.' removed.');
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'That file could not be removed.');
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    /* ---------------------------------- render --------------------------------- */

    public function render(): View
    {
        $access = $this->actor();
        $linked = $access->hasEmployeeLink();
        $today = LocalClock::dayKey(CarbonImmutable::now());

        $this->queue = array_key_exists($this->queue, TaskInbox::QUEUES) ? $this->queue : 'ALL';
        $this->taskView = in_array($this->taskView, ['list', 'gantt', 'capacity'], true) ? $this->taskView : 'list';

        // Counts cover all assigned work; row details stay paginated.
        $summary = app(WorkRecords::class)->ownSummary($access, $today);
        $rows = $this->acceptPage($this->ownTasks($access));
        $countsForTasks = app(WorkRecords::class)->counts($access, array_values($rows->getCollection()->map(static fn (ProjectTask $task): string => $task->id)->all()));
        $fileCounts = $countsForTasks['files'];
        $commentCounts = $countsForTasks['comments'];

        $open = $this->openId !== null ? app(WorkRecords::class)->task($access, $this->openId, own: true) : null;
        $subtasks = null;
        if ($open !== null && ! $open->isSubtask() && $open->has_subtasks) {
            $subtasks = app(WorkRecords::class)->ownPage($access, ['q' => '', 'status' => 'ALL', 'project' => $open->project_id, 'parent' => $open->id], 'code', 'asc', (int) $this->getPage('subtasks'), 10)->setPageName('subtasks');
            $this->acceptPage($subtasks, 'subtasks');
        }
        // The uploader rides along, with their employee record for the
        // spelling the rest of the dialog uses: on a shared task the
        // attachments partial names who attached each peer's file.
        $openFiles = $open
            ? app(WorkRecords::class)->files($access, [$open->id])
            : new Collection;
        $openComments = $open
            ? app(WorkRecords::class)->comments($access, [$open->id])
            : new Collection;

        // On an INDIVIDUAL task, every member's STATE and the actor's own
        // response in full — responses() hands a peer's entry over without
        // its content, so nothing below can print a peer's note. The actor's
        // own state is null for "no row"; every rule fed it (myState,
        // memberCanRespond, evidenceEditable) reads null and NONE alike.
        $individual = $open?->isIndividual() ?? false;
        $responses = $open !== null && $individual
            ? app(WorkRecords::class)->responses($access, $open->id, own: true)
            : new Collection;
        $mine = $linked ? $responses->get($access->ownEmployeeId()) : null;
        $memberStatus = $mine?->status;
        // What the actor sees as their status: on a GROUP task, the task's.
        $myState = $open ? ProjectsData::myState($open->submission_mode, $open->status, $memberStatus) : null;

        $assigner = $open?->assigner;
        $nexts = $open ? TaskPolicy::employeeCanMoveTo($open->status, $open->submission_mode) : [];

        // The peers sharing the open task, and who last submitted it — a
        // shared task must not read "your submission" when it was a peer's.
        // An INDIVIDUAL response is only ever the actor's own, so there is no
        // submitter to name.
        $coAssignees = $open ? $this->peerAssigneeNames($open, $access, $responses) : '';
        $submitter = ! $individual && $open?->submitted_by !== null
            ? $open->assignees->firstWhere('id', $open->submitted_by) ?? $open->submitter
            : null;
        $submittedByPeer = $submitter !== null && $linked && $submitter->getKey() !== $access->ownEmployeeId();

        // An INDIVIDUAL task's row carries a review note only once management
        // closed it with one (complete or cancel, meant for the whole crew),
        // and a reopen by hand clears it again (ManualTaskStatus::writes());
        // until then the review that matters is of the actor's own response.
        $reviewNote = $open?->review_remarks;
        if ($individual && trim((string) $reviewNote) === '') {
            $reviewNote = $mine?->review_remarks;
        }

        return view('livewire.tasks.my-tasks-page', [
            'linked' => $linked,
            'queues' => $summary['queues'],
            'total' => $summary['total'],
            'timeline' => $this->taskView === 'gantt' ? $this->timelineWindow() : null,
            'activeFilterCount' => (trim($this->search) !== '' ? 1 : 0) + ($this->status !== 'ALL' ? 1 : 0) + ($this->projectId !== 'ALL' ? 1 : 0) + ($this->queue !== 'ALL' ? 1 : 0),
            'mineEmpty' => $summary['total'] === 0,
            'counts' => $summary['counts'],
            'myProjects' => $summary['projects'],
            'fileCounts' => $fileCounts,
            'commentCounts' => $commentCounts,
            'rows' => $rows,
            'today' => $today,
            'open' => $open,
            'openSubtasks' => $subtasks,
            'openFiles' => $openFiles,
            'openComments' => $openComments,
            'assignedBy' => $assigner?->getAttribute('full_name') ?? $assigner?->getAttribute('name'),
            'coAssignees' => $coAssignees,
            'submittedByName' => $submittedByPeer ? EmployeeName::display($submitter) : null,
            // Only the uploader may withdraw a file (deleteFile()), so the
            // partial needs to know who is looking.
            'actorUserId' => $access->userId === null ? null : (int) $access->userId,
            'canStart' => in_array('IN_PROGRESS', $nexts, true),
            // An INDIVIDUAL task never moves to SUBMITTED itself: the member
            // hands in their own response, from its own state.
            'canSubmit' => $open !== null && ($individual
                ? ProjectsData::memberCanRespond($open->status, $memberStatus)
                : in_array('SUBMITTED', $nexts, true)),
            'nexts' => $nexts,
            'individual' => $individual,
            'myResponse' => $mine,
            'myState' => $myState,
            // The note handed in and the review of it — on an INDIVIDUAL task
            // the actor's own response, never the task row's.
            'submissionNote' => $individual ? $mine?->remarks : $open?->remarks,
            'reviewNote' => $reviewNote,
            'openOverdue' => $open !== null && TaskInbox::overdue($myState ?? $open->status, $open->due_date?->format('Y-m-d'), $today),
            // DELIBERATE DIVERGENCE: the reference offers Attach whenever the
            // task is neither closed nor under review
            // (task-detail-dialog.tsx:115-117), PENDING included, while its own
            // files route refuses a PENDING upload. Here the button follows the
            // server's rule (TaskFilePolicy::submissionEditable(), through
            // ProjectsData::evidenceEditable()), so it never offers what
            // AttachSubmission refuses: on a GROUP task that also hides it on
            // PENDING, and on an INDIVIDUAL task it follows the member's own
            // response.
            'canAttach' => $open !== null && ProjectsData::evidenceEditable($open->submission_mode, $open->status, $memberStatus),
            'commentSide' => $this->commentSide($access, $open),
        ]);
    }

    /* --------------------------------- helpers --------------------------------- */

    private function actor(): Access
    {
        return app(Access::class);
    }

    /**
     * The in-action gate — route middleware is not enough, because a mounted
     * component's public methods are reachable without the page load. The
     * list is the API's: a task moves under PROJECTS for a reviewer and under
     * TASKS for its assignee (guard.ts:80-83).
     */
    private function authorizeModule(Access $access): void
    {
        abort_unless($access->hasModule(ModuleCode::PROJECTS, ModuleCode::TASKS), 403);
    }

    /**
     * The actor's own queue, one page of it — every task whose pivot names
     * their employee, through WorkRecords::ownPage() and its own-task scope
     * (WorkScope::tasks(own: true)).
     *
     * That scope proves the link BEFORE it compares, and the two must stay in
     * this order: `ownEmployeeId()` is non-nullable and throws for an
     * unlinked account, so the only way to build the predicate at all is to
     * have proven the link first. That is what makes `employee_id IS NULL`
     * — the leak ScopesToActor's docblock is built around — uncompilable
     * rather than merely avoided. The false arm is an explicit impossible
     * predicate, never an unfiltered query: the unlinked card shows, and the
     * count is zero, by construction.
     *
     * Not `toEmployeeVia()`: its ROWS_ALL arm would widen an all_data +
     * PROJECTS holder's queue to everyone's tasks (class docblock, SCOPING).
     * Each row carries `my_status` — the actor's own response state on an
     * INDIVIDUAL task in progress, else the task's — and the status filter
     * and sort read that, not the task's status.
     *
     * @return LengthAwarePaginator<int, ProjectTask>
     */
    private function ownTasks(Access $access): LengthAwarePaginator
    {
        return app(WorkRecords::class)->ownPage(
            $access,
            ['q' => $this->search, 'status' => $this->status, 'project' => $this->projectId, 'queue' => $this->queue],
            $this->sortKey,
            $this->sortDir,
            (int) $this->getPage(),
            $this->perPage,
            LocalClock::todayKey(),
        );
    }

    /**
     * The task's OTHER assignees — everyone on the pivot but the actor. This
     * is the fan-out list for anything the actor did to the shared task,
     * from either side of the thread: the actor already knows.
     *
     * @return list<string>
     */
    private function peerAssigneeIds(ProjectTask $task, Access $access): array
    {
        // The same rule Projects\Show applies, in its one home.
        return ProjectsData::crewExcept($task, $access);
    }

    /**
     * The peers' names for the dialog's "Working with" line, in the same
     * surname-first order the Projects screen prints assignees; '' when the
     * actor works alone. On an INDIVIDUAL task each name carries that peer's
     * response STATE — "Ana Cruz (Approved), Ben Reyes (Not submitted)" — and
     * nothing more: a label is all a peer's entry in `$responses` holds.
     *
     * @param  Collection<string, TaskResponseView>  $responses
     */
    private function peerAssigneeNames(ProjectTask $task, Access $access, Collection $responses): string
    {
        $peers = $this->peerAssigneeIds($task, $access);
        if ($peers === []) {
            return '';
        }
        if (! $task->isIndividual()) {
            return ProjectsData::assigneeNames($task, $peers);
        }

        $names = [];
        foreach (ProjectsData::sortedAssignees($task, $peers) as $peer) {
            $state = $responses->get((string) $peer->getKey());
            $names[] = ProjectsData::employeeName($peer).($state === null ? '' : ' ('.$state->label().')');
        }

        return implode(', ', $names);
    }

    /**
     * Which side of the task the actor speaks from — a fact about the actor
     * and the task's project, not a choice (api/tasks/[id]/comments/route.ts:39-47).
     *
     * @return 'MANAGEMENT'|'ASSIGNEE'
     */
    private function commentSide(Access $access, ?ProjectTask $task): string
    {
        return ProjectsData::commentSide($access, $task);
    }

    /* --------------------------------- uploads --------------------------------- */

    private function clearUpload(): void
    {
        $this->reset('upload');
        // The hidden <input type=file> keeps its FileList; without this,
        // re-picking the same file would never fire a change event.
        $this->dispatch('task-upload-finished');
    }

    /** Port of humanSize (src/lib/attachments.ts:25-28), shared through HrisFormat. */
    public static function humanSize(int $bytes): string
    {
        return HrisFormat::bytes($bytes);
    }

    /** Port of compareText (useSort.ts:33-40): blanks always sort last. */
    protected static function compareText(?string $a, ?string $b): int
    {
        // One comparator for every sorted list, so no screen's order drifts
        // from another's. It stays a wrapper so call sites and Blade imports
        // keep calling it here; TextSortTest pins it.
        return TextSort::compare($a, $b);
    }
}
