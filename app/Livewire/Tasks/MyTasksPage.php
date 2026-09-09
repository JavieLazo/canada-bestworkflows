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
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
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
 * reviewers included (my-tasks-page.tsx:63-66). `toEmployee()` is deliberately
 * NOT used here — its ROWS_ALL arm would widen the queue to everyone's tasks
 * for an all_data + PROJECTS holder, which is the Projects screen's behaviour,
 * not this one's. The own-rows arm is reproduced instead with the same
 * leak-proof construction the trait uses: an explicit '=' against
 * {@see Access::ownEmployeeId()}, whose non-nullable return type makes an
 * `IS NULL` uncompilable, behind a {@see Access::hasEmployeeLink()} guard whose
 * false arm renders the unlinked card and queries nothing.
 *
 * Statuses move through {@see TaskPolicy} only, and stamps (`submitted_at`,
 * actor columns) come from the session and the clock, never from the client.
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

        $task = ProjectTask::query()
            ->whereKey($id)
            ->where('assignee_id', '=', $access->ownEmployeeId())
            ->first();

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
        // (api/tasks/[id]/route.ts:210-216).
        $this->notifyAccount(
            $task->assigned_by,
            'TASK_SUBMITTED',
            $this->taskName($task).' submitted for review',
            $note,
            '/projects',
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

        if ($comment->side === 'MANAGEMENT') {
            $this->notifyEmployee($task->assignee_id, 'TASK_COMMENT', 'New note on '.$this->taskName($task), $preview, '/my-tasks');
        } else {
            $this->notifyAccount($task->assigned_by, 'TASK_COMMENT', 'New note on '.$this->taskName($task), $preview, '/projects');
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
            && $file->task->assignee_id !== null
            && $file->task->assignee_id === $access->ownEmployeeId();

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
        $mine = $linked
            ? ProjectTask::query()
                ->with('project')
                ->where('assignee_id', '=', $access->ownEmployeeId())
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
        $openFiles = $open
            ? ProjectTaskFile::query()->where('task_id', $open->id)->orderBy('created_at')->get()
            : new Collection;
        $openComments = $open
            ? TaskComment::query()->where('task_id', $open->id)->orderBy('created_at')->get()
            : new Collection;

        $assigner = $open?->assigner;
        $nexts = $open ? TaskPolicy::employeeCanMoveTo($open->status) : [];

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

    /** The open task, and only if it is the actor's own. */
    private function ownOpenTask(Access $access): ?ProjectTask
    {
        if ($this->openId === null || ! $access->hasEmployeeLink()) {
            return null;
        }

        return ProjectTask::query()
            ->whereKey($this->openId)
            ->where('assignee_id', '=', $access->ownEmployeeId())
            ->first();
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

    /**
     * Tells one account. Does nothing when there is nobody to tell, and never
     * breaks the action that caused it (src/lib/api/notify.ts:23-38).
     */
    private function notifyAccount(?int $userId, string $kind, string $title, ?string $body, ?string $href): void
    {
        if ($userId === null) {
            return;
        }

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
     * Tells whoever holds the login attached to an employee. An employee with
     * no login is simply not told (src/lib/api/notify.ts:40-58).
     */
    private function notifyEmployee(?string $employeeId, string $kind, string $title, ?string $body, ?string $href): void
    {
        if ($employeeId === null || $employeeId === '') {
            return;
        }

        try {
            $ids = User::query()
                ->where('employee_id', $employeeId)
                ->where('is_active', true)
                ->pluck('id');
        } catch (Throwable $e) {
            Log::error('[notify] lookup failed', ['kind' => $kind, 'error' => $e->getMessage()]);

            return;
        }

        foreach ($ids as $id) {
            $this->notifyAccount((int) $id, $kind, $title, $body, $href);
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
