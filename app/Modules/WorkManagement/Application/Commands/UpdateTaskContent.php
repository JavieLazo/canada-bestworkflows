<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Projects\TaskPolicy;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Modules\WorkManagement\Application\TaskDiscussion;
use App\Modules\WorkManagement\Application\WorkNotifications;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class UpdateTaskContent
{
    public function handle(Access $actor, string $projectId, string $taskId, string $title, string $description, string $seenTitle, string $seenDescription): bool
    {
        $seen = WorkRules::task($actor, $projectId, $taskId);
        if ($seen === null || ! WorkRules::editsWork($actor, $seen->project)) {
            throw new RuntimeException(WorkRules::forbiddenMessage());
        }
        $title = trim($title);
        $description = trim($description);
        if (($error = TaskPolicy::briefError($title, $description)) !== null) {
            throw new RuntimeException($error);
        }
        $changed = DB::transaction(static function () use ($actor, $projectId, $taskId, $title, $description, $seenTitle, $seenDescription): ?ProjectTask {
            $task = WorkRules::lockedTask($actor, $taskId, $projectId);
            $project = $task === null ? null : WorkRules::lockedProject($actor, $projectId);
            if ($task === null || ! WorkRules::editsWork($actor, $project, lock: true)) {
                throw new RuntimeException(WorkRules::forbiddenMessage());
            }
            if ($task->title !== $seenTitle || (string) $task->description !== $seenDescription) {
                throw new RuntimeException('The brief changed while you were editing. Reopen the editor to review its latest version.');
            }
            if ($task->title === $title && (string) $task->description === $description) {
                return null;
            }
            if (TaskSubmissionPolicy::modeLocked($task->status,
                TaskSubmissionPolicy::groupHandedIn($task->submitted_by, $task->submitted_at, $task->remarks),
                ProjectTaskSubmission::query()->where('task_id', $task->id)->lockForUpdate()->first(['id']) !== null ? 1 : 0,
                ProjectTaskFile::query()->where('task_id', $task->id)->where('kind', 'SUBMISSION')->lockForUpdate()->first(['id']) !== null)) {
                throw new RuntimeException('The brief is locked once work or evidence is handed in. Add clarifications in Discussion.');
            }
            $previous = $task->title."\n\n".(string) $task->description;
            $task->fill(['title' => $title, 'description' => $description === '' ? null : $description])->save();
            TaskDiscussion::append($actor, $task, $previous, 'BRIEF_UPDATED', $actor->fullName);
            AuditService::record($actor, AuditEvent::TASK_UPDATED, 'project_tasks', $task->id, 'Task '.$task->code.' brief updated');

            return $task;
        });
        if ($changed === null) {
            return false;
        }
        WorkNotifications::employees($actor, $changed, WorkRules::crewExcept($changed, $actor), [], 'TASK_UPDATED', WorkRules::taskLabel($changed).' brief updated', null, '/my-tasks');

        return true;
    }
}
