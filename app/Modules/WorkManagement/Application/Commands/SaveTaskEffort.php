<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskEffort;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Domain\Projects\CapacityWindow;
use App\Modules\WorkManagement\Domain\Projects\PlanningHours;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveTaskEffort
{
    public function handle(Access $actor, string $taskId, string $employeeId, string $week, string $hours, int $revision): int
    {
        abort_unless($actor->isSignedIn() && $actor->hasModule(ModuleCode::PROJECTS, ModuleCode::TASKS), 403);
        CapacityWindow::validateWeek($week);
        $amount = PlanningHours::parse($hours);

        return DB::transaction(static function () use ($actor, $taskId, $employeeId, $week, $amount, $revision): int {
            $task = WorkRules::lockedTask($actor, $taskId);
            abort_if($task === null, 403);
            $taskId = $task->id;
            $project = WorkRules::lockedProject($actor, $task->project_id);
            abort_if($project === null, 403);
            $planner = WorkRules::editsWork($actor, $project, lock: true);
            abort_unless($planner || ($actor->employeeId === $employeeId && WorkRules::stillAssigned($task, $actor)), 403);
            $crew = ProjectTaskAssignee::query()->where('task_id', $taskId)->where('employee_id', $employeeId)->lockForUpdate()->first();
            if ($amount !== null && ($crew === null || in_array($task->status, ['COMPLETED', 'CANCELLED'], true) || in_array($project->status, ['COMPLETED', 'CANCELLED'], true))) {
                throw ValidationException::withMessages(['hours' => 'Estimate open work for a current assignee. Clear an old estimate or reopen the work first.']);
            }
            $row = ProjectTaskEffort::query()->where('task_id', $taskId)->where('employee_id', $employeeId)->where('week_start', $week)->lockForUpdate()->first();
            // Keep foreign keys in the form returned by their own records.
            $employeeId = $crew->employee_id ?? $row->employee_id ?? $employeeId;
            if (($row->revision ?? 0) !== $revision) {
                throw ValidationException::withMessages(['hours' => 'This estimate changed. Reopen it before saving.']);
            }
            if (($row === null && $amount === null) || ($row !== null && $row->hours_hundredths === $amount)) {
                return $revision;
            }
            $row ??= new ProjectTaskEffort;
            $row->forceFill(['task_id' => $taskId, 'employee_id' => $employeeId, 'week_start' => $week,
                'hours_hundredths' => $amount, 'revision' => $revision + 1, 'updated_by' => (int) $actor->userId])->save();
            AuditService::record($actor, AuditEvent::TASK_EFFORT_SAVED, 'project_tasks', $taskId, 'Weekly effort estimate updated for '.$task->code);

            return $row->revision;
        });
    }
}
