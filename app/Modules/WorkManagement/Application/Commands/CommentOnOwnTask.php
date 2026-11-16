<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Modules\WorkManagement\Application\TaskDiscussion;
use App\Modules\WorkManagement\Application\WorkNotifications as Notifier;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Support\Access;
use App\Support\AuditService;

/** Task operation with server-derived ownership, transition guards and side effects. */
final class CommentOnOwnTask
{
    public function handle(Access $access, ?string $taskId, string $body): bool
    {
        abort_unless($access->hasModule(ModuleCode::PROJECTS, ModuleCode::TASKS), 403);
        $text = trim($body);
        if ($text === '') {
            throw new \RuntimeException('Write something before posting.');
        }
        if (mb_strlen($text) > 8000) {
            throw new \RuntimeException('body: must be at most 8000 characters.');
        }
        $task = WorkRules::ownTask($access, $taskId);
        if ($task === null) {
            return false;
        }
        $comment = TaskDiscussion::append($access, $task, $text, null, mb_substr($access->fullName, 0, 200));
        AuditService::record($access, AuditEvent::TASK_COMMENTED, 'task_comments', $comment->id, 'Comment added to '.WorkRules::taskLabel($task));
        // The bell clamps the body to one line, so only the opening of the
        // note is stored — the thread keeps it whole.
        $preview = mb_strlen($text) > 120 ? mb_substr($text, 0, 117).'…' : $text;
        $title = 'New note on '.WorkRules::taskLabel($task);
        if ($comment->side === 'MANAGEMENT') {
            // Management speaks to the whole task: every OTHER assignee is
            // told — this is the actor's own queue, so they are on the crew
            // too, and need no news of their own note.
            Notifier::employees($access, $task, WorkRules::crewExcept($task, $access), [$access->userId], 'TASK_COMMENT', $title, $preview, '/my-tasks');
        } else {
            // An assignee speaks to the assigner — and to the peers sharing
            // the task, who would otherwise find the thread moved without them.
            // The assigner is told once, through their account; the crew
            // fan-out then skips both the actor and that account.
            $assignerUserId = $task->assigned_by === null ? null : (int) $task->assigned_by;
            if ($assignerUserId !== null && (string) $assignerUserId !== $access->userId) {
                Notifier::account($access, $task, $assignerUserId, 'TASK_COMMENT', $title, $preview, '/projects');
            }
            Notifier::employees($access, $task, WorkRules::crewExcept($task, $access), [$access->userId, $assignerUserId], 'TASK_COMMENT', $title, $preview, '/my-tasks');
        }

        return true;
    }
}
