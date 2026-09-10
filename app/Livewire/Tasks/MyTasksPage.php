<?php

declare(strict_types=1);

namespace App\Livewire\Tasks;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Projects\TaskPolicy;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

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
 * MULTI-ASSIGNEE. Since 2026-09-10 a task is one SHARED unit of work with
 * 0..N assignees (the pivot, {@see ProjectTask::assignees()}). Status, remarks
 * and submitted_at stay one-per-task: any assignee may start or submit for
 * everyone, and `submitted_by` records who did. Co-assignees are told about a
 * peer's submission and a peer's comment; a management-side comment reaches
 * every other assignee. Each fan-out is resolved to ACCOUNTS and written once
 * per account, never to the actor's own (the fan-out helpers below). An
 * uploaded SUBMISSION file may be deleted only by the account that uploaded
 * it ({@see self::deleteFile()}).
 *
 * Statuses move through {@see TaskPolicy} only, and stamps (`submitted_at`,
 * `submitted_by`, actor columns) come from the session and the clock, never
 * from the client.
 */
#[Title('My Tasks')]
class MyTasksPage extends Component
{
    use WithFileUploads;
    use WithSorting;
    use WithTableFilters;

    /**
     * Per-file upload cap — 2 MB in the reference's API mode
     * (`MAX_FILE_BYTES`, src/lib/attachments.ts:19). Overridable through
     * BESTWORKFLOWS_MAX_UPLOAD_BYTES / config('bestworkflows.uploads.max_file_bytes').
     */
    private const int DEFAULT_MAX_FILE_BYTES = 2_000_000;

    /**
     * The organisation-wide ceiling across EVERY attachment — employee
     * documents and task files share one budget (`STORAGE_CEILING_BYTES`,
     * src/lib/api/storage.ts:15). Overridable through
     * BESTWORKFLOWS_STORAGE_CEILING_BYTES / config('bestworkflows.uploads.storage_ceiling_bytes').
     */
    private const int DEFAULT_STORAGE_CEILING_BYTES = 1_000_000_000;

    /** SUBMISSION evidence may change only while the assignee is being waited on. */
    private const array SUBMISSION_EDITABLE_STATUSES = ['IN_PROGRESS', 'RETURNED'];

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
        // useSort<SortKey>("priority") — my-tasks-page.tsx:58.
        $this->sortKey = 'priority';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['code', 'task', 'priority', 'due', 'status'];
    }

    /** Filter changes restart from page 1 (my-tasks-page.tsx:215, 231). */
    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedProjectId(): void
    {
        $this->resetPage();
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

        $task = $this->ownTasks($access)->whereKey($id)->first();

        if ($task === null) {
            return;
        }

        $this->openId = $task->id;
        // The dialog stays mounted across tasks, so a new one must not inherit
        // the draft typed against the last (task-detail-dialog.tsx:104-106).
        $this->reset('remarks', 'commentBody', 'upload');

        Flux::modal('task-detail')->show();
    }

    /** The dialog was dismissed (Close button, ESC, overlay). */
    public function dialogClosed(): void
    {
        $this->openId = null;
        $this->reset('remarks', 'commentBody', 'upload');
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
     * keep reading as waiting on the reviewer.
     */
    public function startTask(): void
    {
        $access = $this->actor();
        $this->authorizeModule($access);

        $task = $this->ownOpenTask($access);
        if ($task === null) {
            return;
        }

        if (! TaskPolicy::employeeCanMove($task->status, 'IN_PROGRESS')) {
            Flux::toast(variant: 'danger', text: TaskPolicy::transitionError());

            return;
        }

        // Optimistic concurrency, as the reference route's
        // `updateMany({ where: { id, status } })` — a task that moved under us
        // conflicts instead of being clobbered.
        $updated = ProjectTask::query()
            ->whereKey($task->id)
            ->where('status', $task->status)
            ->update(['status' => 'IN_PROGRESS', 'submitted_at' => null]);

        if ($updated === 0) {
            Flux::toast(variant: 'danger', text: 'That task changed before this action completed.');

            return;
        }

        AuditService::record(
            $access,
            AuditEvent::taskStatus('IN_PROGRESS'),
            'project_tasks',
            $task->id,
            sprintf('%s "%s" → in_progress', $task->code, $task->title),
        );

        Flux::toast(variant: 'success', text: 'Task started.');
        $this->closeDialog();
    }

    /**
     * "Submit for review" — IN_PROGRESS → SUBMITTED with the note the reviewer
     * reads. `remarks` only ever holds the latest note, so the thread comment
     * is what keeps a resubmission from erasing what was said the first time
     * round (task-detail-dialog.tsx:213-231).
     *
     * The task is shared, so this submits it for every assignee: the status
     * moves once, `submitted_by` names the assignee who pressed the button,
     * and the co-assignees are told alongside the assigner.
     */
    public function submitTask(): void
    {
        $access = $this->actor();
        $this->authorizeModule($access);

        $note = trim($this->remarks);
        if ($note === '') {
            Flux::toast(variant: 'danger', text: 'Say what you did before submitting.');

            return;
        }
        if (mb_strlen($note) > 8000) {
            // Patch schema: remarks max 8000 (api/tasks/[id]/route.ts:44).
            Flux::toast(variant: 'danger', text: 'remarks: must be at most 8000 characters.');

            return;
        }

        $task = $this->ownOpenTask($access);
        if ($task === null) {
            return;
        }

        if (! TaskPolicy::employeeCanMove($task->status, 'SUBMITTED')) {
            Flux::toast(variant: 'danger', text: TaskPolicy::transitionError());

            return;
        }

        $updated = ProjectTask::query()
            ->whereKey($task->id)
            ->where('status', $task->status)
            ->update([
                'status' => 'SUBMITTED',
                // Pre-formatted so the DATETIME(3) column keeps its
                // milliseconds — a raw builder update bypasses the model's
                // $dateFormat and the base grammar would write whole seconds.
                'submitted_at' => now()->format('Y-m-d H:i:s.v'),
                // Which ASSIGNEE submitted — resolved from the session, and
                // ownOpenTask() has already proven the actor is on the pivot.
                'submitted_by' => $access->ownEmployeeId(),
                'remarks' => $note,
            ]);

        if ($updated === 0) {
            Flux::toast(variant: 'danger', text: 'That task changed before this action completed.');

            return;
        }

        AuditService::record(
            $access,
            AuditEvent::taskStatus('SUBMITTED'),
            'project_tasks',
            $task->id,
            sprintf('%s "%s" → submitted', $task->code, $task->title),
        );

        // The thread copy of the note — fail-soft, exactly as the dialog's
        // try/catch around createTaskComment (task-detail-dialog.tsx:214-231).
        try {
            $comment = $this->writeComment($access, $task, $note, 'SUBMITTED');
            AuditService::record(
                $access,
                AuditEvent::TASK_COMMENTED,
                'task_comments',
                $comment->id,
                'Comment added to '.$this->taskName($task),
            );
        } catch (Throwable $e) {
            Log::error('submission comment write failed', ['task' => $task->id, 'error' => $e->getMessage()]);
        }

        // A submission is news to whoever handed the task out
        // (api/tasks/[id]/route.ts:210-216) — told first, so an assigner whose
        // login is also on the crew hears it once, as the reviewer.
        $told = $this->alreadyTold($access);
        $this->notifyAccount(
            $told,
            $task->assigned_by,
            'TASK_SUBMITTED',
            $this->taskName($task).' submitted for review',
            $note,
            '/projects',
        );

        // ...and to the co-assignees, whose task just moved under them.
        $this->notifyEmployees(
            $told,
            $this->peerAssigneeIds($task, $access),
            'TASK_SUBMITTED',
            $this->actorEmployeeName($task, $access).' submitted '.$this->taskName($task).' for review',
            $note,
            '/my-tasks',
        );

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
        $this->authorizeModule($access);

        $text = trim($this->commentBody);
        if ($text === '') {
            Flux::toast(variant: 'danger', text: 'Write something before posting.');

            return;
        }
        if (mb_strlen($text) > 8000) {
            Flux::toast(variant: 'danger', text: 'body: must be at most 8000 characters.');

            return;
        }

        $task = $this->ownOpenTask($access);
        if ($task === null) {
            return;
        }

        $comment = $this->writeComment($access, $task, $text, null);

        AuditService::record(
            $access,
            AuditEvent::TASK_COMMENTED,
            'task_comments',
            $comment->id,
            'Comment added to '.$this->taskName($task),
        );

        // The bell clamps the body to one line, so only the opening of the
        // note is stored — the thread keeps it whole.
        $preview = mb_strlen($text) > 120 ? mb_substr($text, 0, 117).'…' : $text;

        $title = 'New note on '.$this->taskName($task);

        $told = $this->alreadyTold($access);

        if ($comment->side === 'MANAGEMENT') {
            // Management speaks to the whole task: every OTHER assignee is
            // told — this is the actor's own queue, so they are on the crew
            // too, and need no news of their own note.
            $this->notifyEmployees($told, $this->peerAssigneeIds($task, $access), 'TASK_COMMENT', $title, $preview, '/my-tasks');
        } else {
            // An assignee speaks to the assigner — and to the peers sharing
            // the task, who would otherwise find the thread moved without them.
            $this->notifyAccount($told, $task->assigned_by, 'TASK_COMMENT', $title, $preview, '/projects');
            $this->notifyEmployees($told, $this->peerAssigneeIds($task, $access), 'TASK_COMMENT', $title, $preview, '/my-tasks');
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
     * Once a task is submitted the files are evidence under review, so they
     * must stop changing under the reviewer — the server locks SUBMISSION
     * evidence to IN_PROGRESS / RETURNED (api/tasks/[id]/files/route.ts:47-51).
     */
    public function updatedUpload(): void
    {
        if ($this->upload === null) {
            return;
        }

        $access = $this->actor();
        $this->authorizeModule($access);

        $file = $this->upload;
        $name = (string) $file->getClientOriginalName();
        $size = (int) $file->getSize();

        $task = $this->ownOpenTask($access);
        if ($task === null) {
            $this->clearUpload();

            return;
        }

        // The client-side rejection every upload point shares
        // (attachmentRejection, src/lib/attachments.ts:50-66; API mode has no
        // client-side total, so no "browser-only" suffix).
        if ($size > $this->maxFileBytes()) {
            Flux::toast(variant: 'danger', text: sprintf(
                '%s is %s — the limit is %s per file.',
                $name,
                self::humanSize($size),
                self::humanSize($this->maxFileBytes()),
            ));
            $this->clearUpload();

            return;
        }

        if (! in_array($task->status, self::SUBMISSION_EDITABLE_STATUSES, true)) {
            Flux::toast(variant: 'danger', text: 'Task evidence is locked in this status.');
            $this->clearUpload();

            return;
        }

        // The server's own ceiling: documents and task files share one budget.
        $used = (int) EmployeeDocument::query()->sum('size') + (int) ProjectTaskFile::query()->sum('size');
        if ($used + $size > $this->storageCeilingBytes()) {
            Flux::toast(variant: 'danger', text: 'The file store is full. Delete old attachments before uploading more.');
            $this->clearUpload();

            return;
        }

        try {
            $path = $file->store('task-files', 'local');
            if ($path === false) {
                throw new \RuntimeException('The file could not be stored.');
            }

            $row = new ProjectTaskFile;
            $row->fill([
                'task_id' => $task->id,
                'kind' => 'SUBMISSION',
                'file_name' => mb_substr($name, 0, 300),
                'mime' => mb_substr((string) ($file->getClientMimeType() ?: 'application/octet-stream'), 0, 120),
                'size' => $size,
                'path' => $path,
            ]);
            // Actor column — session-supplied, never mass-assigned.
            $row->uploaded_by = $access->userId === null ? null : (int) $access->userId;
            $row->save();
        } catch (Throwable $e) {
            Log::error('task file upload failed', ['task' => $task->id, 'error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: 'Upload failed');
            $this->clearUpload();

            return;
        }

        AuditService::record(
            $access,
            AuditEvent::taskFile('SUBMISSION'),
            'project_task_files',
            $row->id,
            sprintf('%s attached to %s', $row->file_name, $task->code),
        );

        Flux::toast(variant: 'success', text: $name.' attached.');
        $this->clearUpload();
    }

    /**
     * Withdraw one of the assignee's own SUBMISSION files. The BRIEF is the
     * reviewer's spec — deleting it is not the assignee's to do
     * (api/tasks/files/[id]/route.ts:41-45).
     *
     * "Own" is tighter than "on my task" now that a task is shared: a
     * co-assignee may download a peer's evidence (it is the task's), but only
     * the account that uploaded a file may withdraw it — `uploaded_by` is the
     * session-stamped actor column written by updatedUpload(). Being an
     * assignee is still required on top, so a reviewer who once uploaded
     * nothing and an ex-assignee who once did are both refused here.
     */
    public function deleteFile(string $fileId): void
    {
        $access = $this->actor();
        $this->authorizeModule($access);

        $file = ProjectTaskFile::query()->with('task')->whereKey($fileId)->first();
        if ($file === null || $file->task === null) {
            return;
        }

        $mine = $access->hasEmployeeLink()
            && $file->task->hasAssignee($access->ownEmployeeId())
            && $this->uploadedByActor($file, $access);

        if ($file->kind === 'BRIEF' || ! $mine) {
            abort(403);
        }

        if (! in_array($file->task->status, self::SUBMISSION_EDITABLE_STATUSES, true)) {
            Flux::toast(variant: 'danger', text: 'Task evidence is locked in this status.');

            return;
        }

        try {
            Storage::disk('local')->delete($file->path);
            $file->delete();
        } catch (Throwable $e) {
            Log::error('task file delete failed', ['file' => $fileId, 'error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: 'That file could not be removed.');

            return;
        }

        AuditService::record(
            $access,
            AuditEvent::TASK_FILE_REMOVED,
            'project_task_files',
            $file->id,
            sprintf('%s removed from %s', $file->file_name, $file->task->code),
        );

        Flux::toast(variant: 'success', text: $file->file_name.' removed.');
    }

    /* ---------------------------------- render --------------------------------- */

    public function render(): View
    {
        $access = $this->actor();
        $linked = $access->hasEmployeeLink();
        $today = ManilaClock::dayKey(CarbonImmutable::now());

        $employee = $linked
            ? EmployeeProfile::query()->find($access->ownEmployeeId())
            : null;

        // The actor's whole queue — tile counts and the project filter read it
        // unfiltered, exactly as `mine` did (my-tasks-page.tsx:63-66).
        // ownTasks() compiles to `1 = 0` for an unlinked account, so the
        // guard here only spares the query.
        $mine = $linked
            ? $this->ownTasks($access)
                ->with(['project', 'assignees'])
                ->orderBy('code')
                ->get()
            : ProjectTask::query()->whereRaw('1 = 0')->get();

        $counts = [
            'todo' => $mine->whereIn('status', ['PENDING', 'IN_PROGRESS'])->count(),
            'awaiting' => $mine->where('status', 'SUBMITTED')->count(),
            'returned' => $mine->where('status', 'RETURNED')->count(),
            'completed' => $mine->where('status', 'COMPLETED')->count(),
        ];

        // Only the projects that actually hold one of my tasks are worth
        // filtering by (my-tasks-page.tsx:74-77), in project-code order.
        $myProjects = $mine->pluck('project')->filter()->unique('id')->sortBy('code')->values();

        $taskIds = $mine->pluck('id');
        $fileCounts = ProjectTaskFile::query()
            ->whereIn('task_id', $taskIds)
            ->selectRaw('task_id, count(*) as n')
            ->groupBy('task_id')
            ->pluck('n', 'task_id');
        $commentCounts = TaskComment::query()
            ->whereIn('task_id', $taskIds)
            ->selectRaw('task_id, count(*) as n')
            ->groupBy('task_id')
            ->pluck('n', 'task_id');

        $needle = mb_strtolower(trim($this->search));
        $filtered = $mine
            ->filter(function (ProjectTask $t) use ($needle): bool {
                if ($this->status !== 'ALL' && $t->status !== $this->status) {
                    return false;
                }
                if ($this->projectId !== 'ALL' && $t->project_id !== $this->projectId) {
                    return false;
                }
                if ($needle === '') {
                    return true;
                }

                return str_contains(
                    mb_strtolower($t->code.' '.$t->title.' '.($t->project->name ?? '')),
                    $needle,
                );
            })
            ->values()
            ->all();

        usort($filtered, $this->comparator());
        if ($this->sortDir === 'desc') {
            // The reference reverses the WHOLE sorted list (my-tasks-page.tsx:107),
            // blanks-last ordering included — ported as written.
            $filtered = array_reverse($filtered);
        }

        $rows = $this->paginateClamped(new Collection($filtered));

        $open = $this->openId !== null ? $mine->firstWhere('id', $this->openId) : null;
        // The uploader rides along, with their employee record for the
        // spelling the rest of the dialog uses: on a shared task the
        // attachments partial names who attached each peer's file.
        $openFiles = $open
            ? ProjectTaskFile::query()->with('uploader.employee')->where('task_id', $open->id)->orderBy('created_at')->get()
            : new Collection;
        $openComments = $open
            ? TaskComment::query()->where('task_id', $open->id)->orderBy('created_at')->get()
            : new Collection;

        $assigner = $open?->assigner;
        $nexts = $open ? TaskPolicy::employeeCanMoveTo($open->status) : [];

        // The peers sharing the open task, and who last submitted it — a
        // shared task must not read "your submission" when it was a peer's.
        $coAssignees = $open ? $this->peerAssigneeNames($open, $access) : '';
        $submitter = $open?->submitted_by !== null
            ? $open->assignees->firstWhere('id', $open->submitted_by) ?? $open->submitter
            : null;
        $submittedByPeer = $submitter !== null && $linked && $submitter->getKey() !== $access->ownEmployeeId();

        return view('livewire.tasks.my-tasks-page', [
            'linked' => $linked,
            'employee' => $employee,
            'mine' => $mine,
            'counts' => $counts,
            'myProjects' => $myProjects,
            'fileCounts' => $fileCounts,
            'commentCounts' => $commentCounts,
            'rows' => $rows,
            'today' => $today,
            'open' => $open,
            'openFiles' => $openFiles,
            'openComments' => $openComments,
            'assignedBy' => $assigner?->getAttribute('full_name') ?? $assigner?->getAttribute('name'),
            'coAssignees' => $coAssignees,
            'submittedByName' => $submittedByPeer ? EmployeeName::display($submitter) : null,
            // Only the uploader may withdraw a file (deleteFile()), so the
            // partial needs to know who is looking.
            'actorUserId' => $access->userId === null ? null : (int) $access->userId,
            'canStart' => in_array('IN_PROGRESS', $nexts, true),
            'canSubmit' => in_array('SUBMITTED', $nexts, true),
            'nexts' => $nexts,
            'openOverdue' => $open ? TaskPolicy::isOverdue(
                ['status' => $open->status, 'due_date' => $open->due_date?->format('Y-m-d')],
                $today,
            ) : false,
            // Files may change only while the assignee is being waited on
            // (task-detail-dialog.tsx:115-117).
            'canAttach' => $open
                ? (! TaskPolicy::isClosed($open->status) && ! TaskPolicy::isAwaitingReview($open->status))
                : false,
            'commentSide' => $this->commentSide($access),
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
     * The actor's own queue — every task whose pivot names their employee.
     *
     * The guard comes BEFORE the comparison on purpose, and the two must stay
     * in this order: `ownEmployeeId()` is non-nullable and throws for an
     * unlinked account, so the only way to build the predicate at all is to
     * have proven the link first. That is what makes `employee_id IS NULL`
     * — the leak ScopesToActor's docblock is built around, which would hand
     * an unlinked account nothing today but every row of a pivot that ever
     * allowed a null — uncompilable here rather than merely avoided. The
     * false arm is an explicit impossible predicate, never an unfiltered
     * query: the unlinked card shows, and the count is zero, by construction.
     *
     * Not `toEmployeeVia()`: its ROWS_ALL arm would widen an all_data +
     * PROJECTS holder's queue to everyone's tasks (class docblock, SCOPING).
     *
     * @return Builder<ProjectTask>
     */
    private function ownTasks(Access $access): Builder
    {
        if (! $access->hasEmployeeLink()) {
            return ProjectTask::query()->whereRaw('1 = 0');
        }

        $employeeId = $access->ownEmployeeId();

        return ProjectTask::query()
            ->whereHas('assignments', static fn (Builder $q) => $q->where('employee_id', '=', $employeeId));
    }

    /** The open task, and only if the actor is one of its assignees. */
    private function ownOpenTask(Access $access): ?ProjectTask
    {
        if ($this->openId === null || ! $access->hasEmployeeLink()) {
            return null;
        }

        return $this->ownTasks($access)
            ->with('assignees')
            ->whereKey($this->openId)
            ->first();
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
        $self = $access->hasEmployeeLink() ? $access->ownEmployeeId() : null;

        return array_values(array_filter(
            $task->assigneeIds(),
            static fn (string $id): bool => $id !== $self,
        ));
    }

    /**
     * The peers' names for the dialog's "Working with" line, in the same
     * surname-first order the Projects screen prints assignees; '' when the
     * actor works alone.
     */
    private function peerAssigneeNames(ProjectTask $task, Access $access): string
    {
        $peers = $this->peerAssigneeIds($task, $access);

        if ($peers === []) {
            return '';
        }

        $employees = $task->assignees
            ->filter(static fn (EmployeeProfile $e): bool => in_array((string) $e->getKey(), $peers, true))
            ->sort(static fn (EmployeeProfile $a, EmployeeProfile $b): int => self::compareText($a->last_name, $b->last_name)
                ?: self::compareText($a->first_name, $b->first_name));

        return $employees->map(static fn (EmployeeProfile $e): string => EmployeeName::display($e))->implode(', ');
    }

    /**
     * How the actor is named to their peers — the employee record's spelling
     * (already loaded on the task's pivot), falling back to the account name.
     */
    private function actorEmployeeName(ProjectTask $task, Access $access): string
    {
        $self = $access->hasEmployeeLink()
            ? $task->assignees->firstWhere('id', $access->ownEmployeeId())
            : null;

        return $self instanceof EmployeeProfile ? EmployeeName::display($self) : $access->fullName;
    }

    /** Whether the signed-in account is the one that uploaded the file. */
    private function uploadedByActor(ProjectTaskFile $file, Access $access): bool
    {
        return $file->uploaded_by !== null
            && $access->userId !== null
            && (int) $file->uploaded_by === (int) $access->userId;
    }

    /**
     * Which side of the task the actor speaks from — a fact about the actor,
     * not a choice (api/tasks/[id]/comments/route.ts:39-47).
     *
     * @return 'MANAGEMENT'|'ASSIGNEE'
     */
    private function commentSide(Access $access): string
    {
        return $access->mayReadAllData(ModuleCode::PROJECTS) ? 'MANAGEMENT' : 'ASSIGNEE';
    }

    /** One append-only thread row, author columns from the session. */
    private function writeComment(Access $access, ProjectTask $task, string $body, ?string $event): TaskComment
    {
        $comment = new TaskComment;
        $comment->fill([
            'task_id' => $task->id,
            'side' => $this->commentSide($access),
            'body' => $body,
            'event' => $event,
        ]);
        // Actor columns — session-supplied, never mass-assigned.
        $comment->author_id = $access->userId === null ? null : (int) $access->userId;
        $comment->author_name = mb_substr($access->fullName, 0, 200);
        $comment->save();

        return $comment;
    }

    /** `task.code || task.title` — a task without a code is announced by name. */
    private function taskName(ProjectTask $task): string
    {
        return trim($task->code) !== '' ? $task->code : $task->title;
    }

    /* --------------------------------- fan-out --------------------------------- */

    /**
     * Notifications are addressed to ACCOUNTS, and one person can qualify for
     * the same event twice in one action: as the assigner (`assigned_by`, a
     * users id) and as an assignee (an employee id on the pivot, resolved to
     * its logins). Every fan-out therefore runs against a ledger of the
     * accounts already told, seeded with the actor's own login: the first
     * message a person qualifies for is the one they get, nobody gets one
     * event twice, and nobody is told about their own action.
     *
     * This is the ledger an action starts with.
     *
     * @return list<int>
     */
    private function alreadyTold(Access $access): array
    {
        return $access->userId === null ? [] : [(int) $access->userId];
    }

    /**
     * Tells one account — unless it is on the ledger already — and records
     * it. Does nothing when there is nobody to tell, and never breaks the
     * action that caused it (src/lib/api/notify.ts:23-38).
     *
     * @param  list<int>  $told
     */
    private function notifyAccount(array &$told, ?int $userId, string $kind, string $title, ?string $body, ?string $href): void
    {
        if ($userId === null || in_array($userId, $told, true)) {
            return;
        }

        $told[] = $userId;

        try {
            $n = new Notification;
            $n->fill([
                'kind' => $kind,
                'title' => mb_substr($title, 0, 300),
                'body' => $body,
                'href' => $href,
                'dedupe_key' => null,
            ]);
            // The addressee is server-assigned by the fan-out, never mass-assigned.
            $n->user_id = $userId;
            $n->save();
        } catch (Throwable $e) {
            Log::error('[notify] write failed', ['kind' => $kind, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Tells every active login attached to the given employees, each account
     * once (src/lib/api/notify.ts:40-58). An employee with no login is simply
     * not told, and the actor's own login is on the ledger before this runs.
     *
     * @param  list<int>  $told
     * @param  iterable<array-key, string|null>  $employeeIds
     */
    private function notifyEmployees(array &$told, iterable $employeeIds, string $kind, string $title, ?string $body, ?string $href): void
    {
        foreach ($this->accountIdsOf($employeeIds) as $userId) {
            $this->notifyAccount($told, $userId, $kind, $title, $body, $href);
        }
    }

    /**
     * The active logins behind a set of employees — distinct account ids in
     * id order, one query for the whole set, blanks skipped. Empty when the
     * lookup fails, which is logged rather than allowed to break the action.
     *
     * @param  iterable<array-key, string|null>  $employeeIds
     * @return list<int>
     */
    private function accountIdsOf(iterable $employeeIds): array
    {
        $ids = [];
        foreach ($employeeIds as $employeeId) {
            if ($employeeId !== null && $employeeId !== '' && ! in_array($employeeId, $ids, true)) {
                $ids[] = $employeeId;
            }
        }

        if ($ids === []) {
            return [];
        }

        try {
            return array_values(User::query()
                ->whereIn('employee_id', $ids)
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all());
        } catch (Throwable $e) {
            Log::error('[notify] lookup failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function clearUpload(): void
    {
        $this->reset('upload');
        // The hidden <input type=file> keeps its FileList; without this,
        // re-picking the same file would never fire a change event.
        $this->dispatch('task-upload-finished');
    }

    private function maxFileBytes(): int
    {
        return (int) config('bestworkflows.uploads.max_file_bytes', self::DEFAULT_MAX_FILE_BYTES);
    }

    private function storageCeilingBytes(): int
    {
        return (int) config('bestworkflows.uploads.storage_ceiling_bytes', self::DEFAULT_STORAGE_CEILING_BYTES);
    }

    /** Port of humanSize (src/lib/attachments.ts:26-29). */
    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1_000_000
            ? number_format($bytes / 1_000_000, 1).' MB'
            : max(1, (int) round($bytes / 1000)).' KB';
    }

    /**
     * The active sort, ported comparator for comparator
     * (my-tasks-page.tsx:88-106). Always compares ascending — the direction
     * is applied by reversing the whole list, exactly as the source did.
     */
    private function comparator(): callable
    {
        return match ($this->sortKey) {
            'task' => static fn (ProjectTask $a, ProjectTask $b): int => self::compareText($a->title, $b->title),
            'priority' => static function (ProjectTask $a, ProjectTask $b): int {
                // Urgent first, and within a priority the soonest deadline —
                // that is the order the work actually has to be picked up in.
                $rank = TaskPolicy::priorityRank($a->priority) <=> TaskPolicy::priorityRank($b->priority);

                return $rank !== 0
                    ? $rank
                    : self::compareText($a->due_date?->format('Y-m-d'), $b->due_date?->format('Y-m-d'));
            },
            'due' => static fn (ProjectTask $a, ProjectTask $b): int => self::compareText(
                $a->due_date?->format('Y-m-d'),
                $b->due_date?->format('Y-m-d'),
            ),
            'status' => static fn (ProjectTask $a, ProjectTask $b): int => self::compareText(
                TaskPolicy::statusLabel($a->status),
                TaskPolicy::statusLabel($b->status),
            ),
            default => static fn (ProjectTask $a, ProjectTask $b): int => self::compareText($a->code, $b->code),
        };
    }

    /** Port of compareText (useSort.ts:33-40): blanks always sort last. */
    private static function compareText(?string $a, ?string $b): int
    {
        $left = trim($a ?? '');
        $right = trim($b ?? '');

        if ($left === '' && $right === '') {
            return 0;
        }
        if ($left === '') {
            return 1;
        }
        if ($right === '') {
            return -1;
        }

        $folded = strcasecmp($left, $right);

        return $folded !== 0 ? $folded : strcmp($left, $right);
    }
}
