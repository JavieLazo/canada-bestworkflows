<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Projects\ProjectPolicy;
use App\Models\Project;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Domain\Tasks\TaskSchedule;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Plans when a project starts — the one project field its Task Leader may set.
 *
 * DELIBERATE DIVERGENCE: in the reference only management edits a project
 * (PATCH /api/projects/[id], requireAllDataModule). Here whoever
 * RUNS the project — a Projects manager, or its own Task Leader
 * (WorkRules::runsProject()) — may move its start date. Everything else about
 * the project stays with UpdateProject, which is manager-only: a leader can
 * neither rename it nor hand the leadership on.
 *
 * The start date is required, must not fall after the project's due date,
 * and must keep every task's planned start and due date inside the window —
 * checked on the project's tasks read right after the project row is locked
 * (P, the transaction's first statement): every writer of a task date holds P
 * while it writes, so it is either seen here or waits for this save.
 */
final class ScheduleProject
{
    public function handle(Access $actor, string $projectId, string $startDate): ?string
    {
        $project = WorkRules::project($actor, $projectId);
        if ($project === null && WorkRules::managesProjects($actor)) {
            return null;
        }
        if ($project === null || ! WorkRules::runsProject($actor, $project)) {
            throw new \RuntimeException(WorkRules::forbiddenMessage());
        }
        if ($startDate === '') {
            throw new \RuntimeException('A project needs a start date.');
        }
        if (! TaskSchedule::isDateKey($startDate)) {
            throw new \RuntimeException('start_date: must be a date');
        }

        /** @var Project|null $saved */
        $saved = DB::transaction(static function () use ($actor, $project, $startDate): ?Project {
            // P first; the tasks are read plainly after it (WorkRules' protocol).
            $locked = WorkRules::lockedProject($actor, $project->id);
            if ($locked === null) {
                return null;
            }
            if (! WorkRules::runsProject($actor, $locked)) {
                throw new \RuntimeException(WorkRules::forbiddenMessage());
            }
            $dueDate = WorkRules::dateKey($locked->due_date);
            $dateError = ProjectPolicy::dateError($startDate, $dueDate);
            if ($dateError !== null) {
                throw new \RuntimeException($dateError);
            }
            if (WorkRules::dateKey($locked->start_date) === $startDate) {
                return $locked;
            }
            foreach ($locked->tasks()->get() as $task) {
                if (! TaskSchedule::fitsWindow(WorkRules::dateKey($task->start_date), WorkRules::dateKey($task->due_date), $startDate, $dueDate)) {
                    throw new \RuntimeException('The project timeline would leave '.WorkRules::taskLabel($task).' outside it.');
                }
            }
            $locked->fill(['start_date' => $startDate]);
            $locked->save();
            AuditService::record($actor, AuditEvent::PROJECT_UPDATED, 'projects', $locked->id, sprintf('%s — %s updated (start_date)', $locked->code, $locked->name));

            return $locked;
        });

        return $saved?->code;
    }
}
