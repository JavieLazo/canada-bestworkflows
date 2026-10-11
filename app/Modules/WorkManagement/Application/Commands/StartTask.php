<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Projects\TaskPolicy;
use App\Models\ProjectTask;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Support\Access;
use App\Support\AuditService;

/** Task operation with server-derived ownership, transition guards and side effects. */
final class StartTask
{
    public function handle(Access $access, ?string $taskId): bool
    {
        abort_unless($access->hasModule(ModuleCode::PROJECTS, ModuleCode::TASKS), 403);
        $task = WorkRules::ownTask($access, $taskId);
        if ($task === null) {
            return false;
        }
        // Mode-aware: an INDIVIDUAL task is started once, for everyone, and
        // has no RETURNED state to restart from — its returns are per member.
        if (! TaskPolicy::employeeCanMove($task->status, 'IN_PROGRESS', $task->submission_mode)) {
            throw new \RuntimeException(TaskPolicy::transitionError());
        }
        // Optimistic concurrency, as the reference route's
        // `updateMany({ where: { id, status } })` — a task that moved under us
        // conflicts instead of being clobbered.
        $updated = ProjectTask::query()->whereKey($task->id)->where('status', $task->status)->update(['status' => 'IN_PROGRESS', 'submitted_at' => null]);
        if ($updated === 0) {
            throw new \RuntimeException('That task changed before this action completed.');
        }
        AuditService::record($access, AuditEvent::taskStatus('IN_PROGRESS'), 'project_tasks', $task->id, sprintf('Task %s "%s" → in_progress', $task->code, $task->title));

        return true;
    }
}
