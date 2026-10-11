<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Domain\Enums\ModuleCode;
use App\Models\ProjectTask;
use App\Models\TaskComment;
use App\Support\Access;

/**
 * Writes an authorized task-thread entry with author identity supplied only by the server.
 *
 * Who may write: whoever runs the task (a Projects manager, or its project's
 * Task Leader off the crew — WorkRules::runsTask()) or one of its assignees.
 */
final class TaskDiscussion
{
    public static function append(Access $actor, ProjectTask $task, string $body, ?string $event, string $authorName): TaskComment
    {
        abort_unless($actor->hasModule(ModuleCode::PROJECTS, ModuleCode::TASKS)
            && (WorkRules::runsTask($actor, $task) || $task->hasAssignee($actor->employeeId)), 403);
        $comment = new TaskComment;
        $comment->fill(['task_id' => $task->id, 'side' => WorkRules::commentSide($actor, $task), 'body' => $body, 'event' => $event]);
        $comment->author_id = $actor->userId === null ? null : (int) $actor->userId;
        $comment->author_name = mb_substr($authorName, 0, 200);
        $comment->save();

        return $comment;
    }
}
