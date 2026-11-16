<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Modules\WorkManagement\Application\TaskEvidenceLocked;
use App\Modules\WorkManagement\Application\TaskFilePolicy;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Platform\Files\AttachmentStore;
use App\Platform\Files\AttachmentUsage;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Owns attachment authorization, persistence and audit; the UI handles upload state only.
 *
 * Whether evidence may still be added is TaskFilePolicy::submissionEditable():
 * the task's status on a GROUP task, the member's own response on an
 * INDIVIDUAL one. The early check is a plain read for the message; the
 * deciding one runs inside AttachmentStore's persist callback, after the
 * budget lock (L5) and under the task-row lock (L1), so a submission or a
 * removal that commits while the upload waits is seen before the row lands.
 */
final class AttachSubmission
{
    public function handle(Access $access, ?string $taskId, ?UploadedFile $upload): ?string
    {
        if ($upload === null) {
            return null;
        }
        if (! $access->hasModule(ModuleCode::TASKS, ModuleCode::PROJECTS)) {
            abort(403);
        }
        $file = $upload;
        $name = (string) $file->getClientOriginalName();
        $size = (int) $file->getSize();
        $task = WorkRules::ownTask($access, $taskId);
        if ($task === null) {
            return null;
        }
        // The client-side rejection every upload point shares
        // (attachmentRejection, src/lib/attachments.ts:50-66; API mode has no
        // client-side total, so no "browser-only" suffix).
        if ($size > TaskFilePolicy::maxFileBytes()) {
            throw new \RuntimeException(sprintf('%s is %s — the limit is %s per file.', $name, TaskFilePolicy::humanSize($size), TaskFilePolicy::humanSize(TaskFilePolicy::maxFileBytes())));
        }
        $memberStatus = TaskSubmissionPolicy::isIndividual($task->submission_mode) ? WorkRules::ownResponseStatus($access, $task) : null;
        if (! TaskFilePolicy::submissionEditable($task->submission_mode, $task->status, $memberStatus)) {
            throw new \RuntimeException(TaskFilePolicy::lockedMessage($task->submission_mode, $task->status));
        }
        // The server's own ceiling: documents and task files share one budget.
        $used = AttachmentUsage::bytes();
        if ($used + $size > TaskFilePolicy::storageCeilingBytes()) {
            throw new \RuntimeException('The file store is full. Delete old attachments before uploading more.');
        }
        try {
            $row = app(AttachmentStore::class)->store($file, 'task-files', $file->hashName(), static function (string $path) use ($file, $task, $access, $name, $size): ProjectTaskFile {
                // L1, then L2. AttachmentStore has already taken the budget
                // lock (L5) and read the usage PLAINLY, which opened this
                // transaction's snapshot before the task lock — so every read
                // below that decides must be a locking one to be fresh.
                $locked = WorkRules::lockedTask($access, $task->id);
                if ($locked === null || $locked->submission_mode !== $task->submission_mode) {
                    throw new TaskEvidenceLocked('That task changed before this action completed.');
                }
                if (! WorkRules::stillAssigned($locked, $access)) {
                    throw new TaskEvidenceLocked('You are no longer assigned to this task.');
                }
                $own = TaskSubmissionPolicy::isIndividual($locked->submission_mode)
                    ? ProjectTaskSubmission::query()->where('task_id', $locked->id)->where('employee_id', '=', $access->ownEmployeeId())->lockForUpdate()->value('status')
                    : null;
                if (! TaskFilePolicy::submissionEditable($locked->submission_mode, $locked->status, is_string($own) ? $own : null)) {
                    throw new TaskEvidenceLocked(TaskFilePolicy::lockedMessage($locked->submission_mode, $locked->status));
                }
                $row = new ProjectTaskFile;
                $row->fill(['task_id' => $task->id, 'kind' => 'SUBMISSION', 'file_name' => mb_substr($name, 0, 300), 'mime' => mb_substr((string) ($file->getClientMimeType() ?: 'application/octet-stream'), 0, 120), 'size' => $size, 'path' => $path]);
                // Actor column — session-supplied, never mass-assigned.
                $row->uploaded_by = $access->userId === null ? null : (int) $access->userId;
                // The member this evidence belongs to, in either mode. ownTask()
                // matched the actor's own employee link to the crew, so the link
                // exists; it is set by property, never from input.
                $row->employee_id = $access->ownEmployeeId();
                $row->save();

                return $row;
            }, ceiling: TaskFilePolicy::storageCeilingBytes());
        } catch (TaskEvidenceLocked $refused) {
            // Decided under the lock; AttachmentStore has discarded the bytes.
            throw $refused;
        } catch (Throwable $e) {
            Log::error('task file upload failed', ['task' => $task->id, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Upload failed');
        }
        AuditService::record($access, AuditEvent::taskFile('SUBMISSION'), 'project_task_files', $row->id, sprintf('%s attached to %s', $row->file_name, $task->code));

        return $name;
    }
}
