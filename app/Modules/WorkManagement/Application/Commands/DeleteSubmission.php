<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Modules\WorkManagement\Application\TaskFilePolicy;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Owns attachment authorization, persistence and audit; the UI handles upload state only.
 *
 * Only the member who uploaded a SUBMISSION file may delete it, and on an
 * INDIVIDUAL task only while it is their own evidence (`employee_id`) and
 * their response could still be handed in. The early checks are plain reads;
 * the deciding ones run in a transaction that opens with the task-row lock
 * (L1), then re-reads the actor's place on the crew with a locking read
 * (L2). A member removed while this request waited is therefore refused:
 * their evidence stays with the task (ProjectTask::syncAssignees), and they
 * can no longer destroy it. The row goes first and the bytes after the
 * commit, so a failed byte delete leaves an orphan file, never a row that
 * points at nothing.
 */
final class DeleteSubmission
{
    public function handle(Access $access, string $fileId): ?string
    {
        if (! $access->hasModule(ModuleCode::TASKS, ModuleCode::PROJECTS)) {
            abort(403);
        }
        $file = ProjectTaskFile::query()->with('task')->whereKey($fileId)->first();
        if ($file === null || $file->task === null) {
            return null;
        }
        $task = $file->task;
        $individual = TaskSubmissionPolicy::isIndividual($task->submission_mode);
        $mine = $access->hasEmployeeLink() && $task->hasAssignee($access->ownEmployeeId()) && ($access->userId !== null && (string) $file->uploaded_by === $access->userId)
            && (! $individual || $file->employee_id === $access->ownEmployeeId());
        if ($file->kind === 'BRIEF' || ! $mine) {
            abort(403);
        }
        if (! TaskFilePolicy::submissionEditable($task->submission_mode, $task->status, $individual ? WorkRules::ownResponseStatus($access, $task) : null)) {
            throw new \RuntimeException(TaskFilePolicy::lockedMessage($task->submission_mode, $task->status));
        }
        try {
            $removed = DB::transaction(static function () use ($access, $file, $task): bool {
                $locked = WorkRules::lockedTask($file->task_id);
                if ($locked === null) {
                    // The task — and with it this file — is gone.
                    return false;
                }
                if (! WorkRules::stillAssigned($locked, $access)) {
                    abort(403);
                }
                $current = ProjectTaskFile::query()->whereKey($file->id)->lockForUpdate()->first();
                if ($current === null) {
                    return false;
                }
                // A plain read, and fresh: nothing was read before L1, and
                // every writer of this row holds L1.
                $own = TaskSubmissionPolicy::isIndividual($locked->submission_mode)
                    ? ProjectTaskSubmission::query()->where('task_id', $locked->id)->where('employee_id', '=', $access->ownEmployeeId())->value('status')
                    : null;
                if ($locked->submission_mode !== $task->submission_mode
                    || ! TaskFilePolicy::submissionEditable($locked->submission_mode, $locked->status, is_string($own) ? $own : null)) {
                    throw new \RuntimeException(TaskFilePolicy::lockedMessage($locked->submission_mode, $locked->status));
                }
                $current->delete();

                return true;
            });
        } catch (QueryException $e) {
            Log::error('task file delete failed', ['file' => $fileId, 'error' => $e->getMessage()]);
            throw new \RuntimeException('That file could not be removed.');
        }
        if (! $removed) {
            return null;
        }
        try {
            if (! Storage::disk('local')->delete($file->path)) {
                Log::error('task file bytes not removed', ['file' => $fileId, 'path' => $file->path]);
            }
        } catch (Throwable $e) {
            Log::error('task file bytes not removed', ['file' => $fileId, 'path' => $file->path, 'error' => $e->getMessage()]);
        }
        AuditService::record($access, AuditEvent::TASK_FILE_REMOVED, 'project_task_files', $file->id, sprintf('%s removed from %s', $file->file_name, $task->code));

        return $file->file_name;
    }
}
