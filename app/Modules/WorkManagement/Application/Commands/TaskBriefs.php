<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Modules\WorkManagement\Application\TaskFilePolicy;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Platform\Files\AttachmentStore;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Owns attachment authorization, persistence and audit; the UI handles upload state only. */
final class TaskBriefs
{
    public function attach(Access $actor, string $projectId, ?string $taskId, UploadedFile $file): ?string
    {
        if (! WorkRules::managesProjects($actor)) {
            throw new \RuntimeException(WorkRules::forbiddenMessage());
        }
        $task = WorkRules::task($actor, $projectId, $taskId);
        if ($task === null) {
            return null;
        }
        // The files route locks evidence outside these statuses; the dialog
        // keeps the button visible and the server answer becomes this toast.
        if (! in_array($task->status, TaskFilePolicy::BRIEF_EDITABLE_STATUSES, true)) {
            throw new \RuntimeException('Task evidence is locked in this status.');
        }
        $rejection = TaskFilePolicy::attachmentRejection($file->getClientOriginalName(), (int) $file->getSize());
        if ($rejection !== null) {
            throw new \RuntimeException($rejection);
        }
        $error = $this->store($actor, $task, $file, 'BRIEF');
        if ($error !== null) {
            throw new \RuntimeException($error);
        }
        AuditService::record($actor, AuditEvent::taskFile('BRIEF'), 'project_task_files', null, "{$file->getClientOriginalName()} attached to {$task->code}");

        return $file->getClientOriginalName();
    }

    public function delete(Access $actor, string $projectId, ?string $taskId, string $fileId): ?string
    {
        $task = WorkRules::task($actor, $projectId, $taskId);
        if ($task === null) {
            return null;
        }
        $file = ProjectTaskFile::query()->whereKey($fileId)->where('task_id', $task->id)->first();
        if ($file === null) {
            return null;
        }
        // The brief is the reviewer's spec — deleting it is not the assignee's
        // to do; the submission is not deletable from this screen at all.
        if ($file->kind !== 'BRIEF' || ! WorkRules::managesProjects($actor)) {
            throw new \RuntimeException(WorkRules::forbiddenMessage());
        }
        if (! in_array($task->status, TaskFilePolicy::BRIEF_EDITABLE_STATUSES, true)) {
            throw new \RuntimeException('Task evidence is locked in this status.');
        }
        $path = $file->getAttribute('path');
        $file->delete();
        if (is_string($path) && $path !== '') {
            Storage::disk('local')->delete($path);
        }
        AuditService::record($actor, AuditEvent::TASK_FILE_REMOVED, 'project_task_files', $fileId, "{$file->file_name} removed from {$task->code}");

        return $file->file_name;
    }

    /** @param list<UploadedFile> $files
     * @return list<string> */
    public function batch(Access $actor, string $projectId, string $taskId, array $files): array
    {
        if (! WorkRules::managesProjects($actor)) {
            throw new \RuntimeException(WorkRules::forbiddenMessage());
        }
        $task = WorkRules::task($actor, $projectId, $taskId);
        if ($task === null) {
            return [];
        }
        if (! in_array($task->status, TaskFilePolicy::BRIEF_EDITABLE_STATUSES, true)) {
            throw new \RuntimeException('Task evidence is locked in this status.');
        }
        $attached = 0;
        $errors = [];
        foreach ($files as $file) {
            $error = $this->store($actor, $task, $file, 'BRIEF');
            if ($error !== null) {
                $errors[] = "Task {$task->code} was created, but {$file->getClientOriginalName()} could not be attached: {$error}";
            } else {
                $attached++;
            }
        }
        if ($attached > 0) {
            AuditService::record($actor, AuditEvent::TASK_BRIEF_ATTACHED, 'project_task_files', $task->id, sprintf('%d brief %s attached to %s', $attached, $attached === 1 ? 'file' : 'files', $task->code));
        }

        return $errors;
    }

    private function store(Access $actor, ProjectTask $task, UploadedFile $file, string $kind): ?string
    {
        $size = (int) $file->getSize();

        $rejection = TaskFilePolicy::attachmentRejection($file->getClientOriginalName(), $size);
        if ($rejection !== null) {
            return $rejection;
        }
        $ceiling = TaskFilePolicy::storageCeilingError($size);
        if ($ceiling !== null) {
            return $ceiling;
        }

        try {
            app(AttachmentStore::class)->store($file, TaskFilePolicy::FILE_DIR, (string) Str::ulid(), static function (string $stored) use ($file, $task, $actor, $kind, $size): void {
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
            }, ceiling: TaskFilePolicy::storageCeilingBytes());
        } catch (\Throwable $e) {
            Log::error('[projects] upload failed', ['error' => $e->getMessage()]);

            return 'upload failed';
        }

        return null;
    }
}
