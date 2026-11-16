<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Modules\WorkManagement\Application\TaskDiscussion;
use App\Modules\WorkManagement\Application\WorkNotifications as Notifier;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;

/** Task operation with server-derived ownership, transition guards and side effects. */
final class CommentOnProjectTask
{
    public function handle(Access $actor, string $projectId, ?string $taskId, string $body): bool
    {
        $text = trim($body);
        if ($text === '') {
            throw new \RuntimeException('Write something before posting.');
        }
        if (mb_strlen($text) > 8000) {
            throw new \RuntimeException('body: must be at most 8000 characters');
        }
        $task = WorkRules::task($actor, $projectId, $taskId);
        if ($task === null) {
            return false;
        }
        // Side is derived, never sent: whether you are MANAGEMENT or the
        // ASSIGNEE is a fact about the task (comments route). Whoever runs
        // the task — a Projects manager, or its project's Task Leader off the
        // crew — speaks for management.
        $isManagement = WorkRules::runsTask($actor, $task) || WorkRules::editsWork($actor, $task->project);
        $isAssignee = $task->hasAssignee($actor->employeeId);
        if (! $isManagement && ! $isAssignee) {
            throw new \RuntimeException(WorkRules::forbiddenMessage());
        }
        $comment = DB::transaction(static function () use ($actor, $task, $text) {
            $locked = WorkRules::lockedTask($actor, $task->id);
            $project = $locked === null ? null : WorkRules::lockedProject($actor, $locked->project_id);
            if ($locked === null || $project === null) {
                throw new \RuntimeException(WorkRules::forbiddenMessage());
            }
            $locked->setRelation('project', $project);
            if (! WorkRules::editsWork($actor, $project, lock: true)
                && ! WorkRules::runsTask($actor, $locked) && ! WorkRules::stillAssigned($locked, $actor)) {
                throw new \RuntimeException(WorkRules::forbiddenMessage());
            }

            return TaskDiscussion::append($actor, $locked, $text, null, $actor->fullName !== '' ? $actor->fullName : 'Unknown');
        });
        $isManagement = $comment->side === 'MANAGEMENT';
        $taskLabel = WorkRules::taskLabel($task);
        AuditService::record($actor, AuditEvent::TASK_COMMENTED, 'task_comments', $comment->id, "Comment added to {$taskLabel}");
        $preview = mb_strlen($text) > 120 ? mb_substr($text, 0, 117).'…' : $text;
        if ($isManagement) {
            // Management speaks to the whole crew — minus themselves, when
            // the reviewer is on it.
            Notifier::employees($actor, $task, WorkRules::crewExcept($task, $actor), [], 'TASK_COMMENT', "New comment on {$taskLabel}", $preview, '/my-tasks');
        } else {
            // An assignee speaks to the assigner — and to their co-assignees,
            // who share the task and the thread; never back to themselves,
            // and never twice to one account: an assigner who is also on the
            // crew is told once, through their account, and their employee
            // record leaves the crew fan-out. Only while that account is
            // active, though — employee fan-out fills in that person's other
            // active accounts while the direct assigned_by account is kept in
            // the ledger so it does not receive the same event twice.
            $assignedBy = $task->getAttribute('assigned_by');
            $assignerUserId = $assignedBy === null ? null : (int) $assignedBy;
            if ($assignerUserId !== null && (string) $assignerUserId !== $actor->userId) {
                Notifier::account($actor, $task, $assignerUserId, 'TASK_COMMENT', "New comment on {$taskLabel}", $preview, '/projects');
            }
            Notifier::employees($actor, $task, WorkRules::crewExcept($task, $actor), [$assignerUserId], 'TASK_COMMENT', "New comment on {$taskLabel}", $preview, '/my-tasks');
        }

        return true;
    }
}
