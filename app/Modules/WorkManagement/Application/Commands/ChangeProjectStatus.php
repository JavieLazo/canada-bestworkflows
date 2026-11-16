<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Projects\ProjectPolicy;
use App\Models\Project;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Domain\Projects\ManualProjectStatus;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * The project status set by hand from the header select.
 *
 * DELIBERATE DIVERGENCE: the move follows ManualProjectStatus —
 * any status to any other, a completed or cancelled project included — not
 * the reference's terminal map; and whoever RUNS the project may make it: an
 * all-data PROJECTS holder, or the project's own Task Leader
 * (WorkRules::runsProject()). The two safeguards stand: ACTIVE needs a
 * leader, COMPLETED needs every task closed.
 *
 * The "every task closed" check reads the project's tasks PLAINLY right after
 * locking the project row (P) as the transaction's first statement. Every
 * writer that can open a task in it — a reopen (ChangeTaskStatus), a new
 * task (CreateTask) — holds P while it writes, so such a change either
 * committed before P was granted here and is in this snapshot, or waits for
 * this to commit and then finds the project completed (WorkRules' protocol). `$expectedFrom` is the status the screen showed: a project that
 * moved since is refused, never moved on from a state nobody saw.
 */
final class ChangeProjectStatus
{
    public function handle(Access $actor, string $projectId, string $next, ?string $expectedFrom = null): bool
    {
        $project = WorkRules::project($actor, $projectId);
        if ($project === null) {
            return false;
        }
        if ($next === $project->status) {
            return false;
        }
        if (! WorkRules::runsProject($actor, $project)) {
            throw new \RuntimeException(WorkRules::forbiddenMessage());
        }
        $moved = DB::transaction(static function () use ($actor, $project, $next, $expectedFrom): ?Project {
            // P first; the tasks after it are read plainly (see below).
            $locked = WorkRules::lockedProject($actor, $project->id);
            // Already there (someone else got there first): nothing to do.
            if ($locked === null || $locked->status === $next) {
                return null;
            }
            if ($expectedFrom !== null && $locked->status !== $expectedFrom) {
                throw new \RuntimeException('That project changed before this action completed.');
            }
            if (! WorkRules::runsProject($actor, $locked)) {
                throw new \RuntimeException(WorkRules::forbiddenMessage());
            }
            $tasks = $locked->tasks()->get(['id', 'status', 'due_date'])->all();
            $statusError = ManualProjectStatus::error(WorkRules::projectShape($locked), WorkRules::taskShapes($tasks), $next);
            if ($statusError !== null) {
                throw new \RuntimeException($statusError);
            }
            $from = $locked->status;
            $count = Project::query()->whereKey($locked->id)->where('status', $from)->update(['status' => $next]);
            if ($count === 0) {
                throw new \RuntimeException('That project changed before this action completed.');
            }
            AuditService::record($actor, ProjectPolicy::auditAction($from, $next), 'projects', $locked->id, sprintf('Project %s — %s moved from %s to %s', $locked->code, $locked->name, ProjectPolicy::statusLabel($from), ProjectPolicy::statusLabel($next)));

            return $locked;
        });

        return $moved !== null;
    }
}
