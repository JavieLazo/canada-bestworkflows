<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * Owner-internal scopes shared by detail reads, paged lists, and aggregate
 * queries — and the one home of the task-state SQL those reads share.
 *
 * Each SQL constant below has a PHP twin in {@see TaskSubmissionPolicy}, and
 * the two MUST SAY THE SAME THING: a queue that disagrees with its own counter
 * is the bug. Every column is qualified, so the fragments can sit inside a
 * query that also joins a table with a `status` column. They use only CASE,
 * EXISTS and comparisons, which sqlite and MariaDB read identically.
 */
final class WorkScope
{
    /**
     * A task waiting on a reviewer: a GROUP task handed in, or an INDIVIDUAL
     * task in progress with at least one response submitted. The PHP twin is
     * {@see TaskSubmissionPolicy::awaitsReview()}. Needs `project_tasks` in
     * the FROM clause.
     */
    public const NEEDS_REVIEW_SQL = "(project_tasks.status = 'SUBMITTED' OR (project_tasks.submission_mode = 'INDIVIDUAL' AND project_tasks.status = 'IN_PROGRESS'"
        ." AND EXISTS (SELECT 1 FROM project_task_submissions WHERE project_task_submissions.task_id = project_tasks.id AND project_task_submissions.status = 'SUBMITTED')))";

    /**
     * NEEDS_REVIEW_SQL for ONE member of the task: a GROUP task handed in
     * waits on the reviewer for its whole crew, an INDIVIDUAL one only for a
     * member whose OWN response is submitted. The PHP twin is awaitsReview()
     * fed that member's count (1 when their row is SUBMITTED, else 0). Needs
     * `project_tasks` joined to `project_task_assignees`, one row per member;
     * the member test is an EXISTS, not a join, so the counts stay one per
     * pivot row.
     */
    public const MEMBER_NEEDS_REVIEW_SQL = "(project_tasks.status = 'SUBMITTED' OR (project_tasks.submission_mode = 'INDIVIDUAL' AND project_tasks.status = 'IN_PROGRESS'"
        .' AND EXISTS (SELECT 1 FROM project_task_submissions WHERE project_task_submissions.task_id = project_task_assignees.task_id'
        ." AND project_task_submissions.employee_id = project_task_assignees.employee_id AND project_task_submissions.status = 'SUBMITTED')))";

    /**
     * The status a task sorts by in a reviewer's stack: SUBMITTED while it
     * waits on review, whatever its mode. The PHP twin is
     * {@see TaskSubmissionPolicy::stackStatus()}.
     */
    public const STACK_STATUS_SQL = 'CASE WHEN '.self::NEEDS_REVIEW_SQL.' THEN \'SUBMITTED\' ELSE project_tasks.status END';

    /**
     * The status an assignee sees for their own task: their response's state
     * while an INDIVIDUAL task is in progress and they have a row, otherwise
     * the task's. The PHP twin is {@see TaskSubmissionPolicy::myStatus()}.
     * ONLY valid on a query that went through {@see self::joinOwnResponse()}:
     * it reads the joined row, and a missing row is the NULL of the left join.
     */
    public const MY_STATUS_SQL = "CASE WHEN project_tasks.submission_mode = 'INDIVIDUAL' AND project_tasks.status = 'IN_PROGRESS'"
        .' AND project_task_submissions.status IS NOT NULL THEN project_task_submissions.status ELSE project_tasks.status END';

    /**
     * The projects the actor may see: every project for an all-data PROJECTS
     * holder; otherwise the projects holding a task their employee is on, AND
     * the projects whose Task Leader they are.
     *
     * DELIBERATE DIVERGENCE: the reference scopes projects through task
     * membership alone (api/projects/route.ts:21-27). ems gives
     * the named Task Leader (`projects.manager_id`, an ACCOUNT) their whole
     * project, because they change its status, plan its start dates and
     * review its tasks (WorkRules::runsProject()). The leader arm is keyed on
     * the account, never on the employee, and exists only for a signed-in
     * actor holding PROJECTS ({@see self::leaderAccountId()}), so a guest or
     * a null id can never compile to `manager_id IS NULL`.
     *
     * A `match` with all three arms and no default, as the access guide asks:
     * wrapping ROWS_ALL in the same OR would narrow an admin to the projects
     * they lead, because the all-data scope adds no condition of its own.
     *
     * @return Builder<Project>
     */
    public static function projects(Access $actor): Builder
    {
        $leader = self::leaderAccountId($actor);

        return match ($actor->rowVisibility(ModuleCode::PROJECTS)) {
            Access::ROWS_ALL => Project::query(),
            Access::ROWS_OWN => Project::query()->where(static function (Builder $visible) use ($actor, $leader): void {
                $visible->toEmployeeVia($actor, 'tasks.assignments', 'employee_id', ModuleCode::PROJECTS);
                if ($leader !== null) {
                    $visible->orWhere('projects.manager_id', '=', $leader);
                }
            }),
            Access::ROWS_NONE => $leader !== null
                ? Project::query()->where('projects.manager_id', '=', $leader)
                : Project::query()->whereRaw('1 = 0'),
        };
    }

    /**
     * The tasks the actor may see. Beyond the reference scope (the tasks their
     * employee is on), a Task Leader sees EVERY task of the projects they
     * lead — the totals, progress and status checks they act on are
     * whole-project ({@see self::projects()}). `$own` is My Tasks: pinned to
     * the actor's own crew places, for everyone, and never widened.
     *
     * @return Builder<ProjectTask>
     */
    public static function tasks(Access $actor, bool $own = false): Builder
    {
        $query = ProjectTask::query();
        if (! $own) {
            $leader = self::leaderAccountId($actor);
            $led = static fn (int $account) => Project::query()->select('projects.id')->where('projects.manager_id', '=', $account);

            return match ($actor->rowVisibility(ModuleCode::PROJECTS)) {
                Access::ROWS_ALL => $query,
                Access::ROWS_OWN => $query->where(static function (Builder $visible) use ($actor, $leader, $led): void {
                    $visible->toEmployeeVia($actor, 'assignments', 'employee_id', ModuleCode::PROJECTS);
                    if ($leader !== null) {
                        $visible->orWhereIn('project_tasks.project_id', $led($leader));
                    }
                }),
                Access::ROWS_NONE => $leader !== null
                    ? $query->whereIn('project_tasks.project_id', $led($leader))
                    : $query->whereRaw('1 = 0'),
            };
        }
        if (! $actor->hasEmployeeLink()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('assignments', static fn (Builder $q) => $q->where('employee_id', '=', $actor->ownEmployeeId()));
    }

    /**
     * The ids of the projects the actor is the Task Leader of — empty for
     * anyone who cannot lead ({@see self::leaderAccountId()}). For per-task
     * checks over a batch (WorkRecords::visibleFiles()) without a lookup per
     * row.
     *
     * @return list<string>
     */
    public static function ledProjectIds(Access $actor): array
    {
        $leader = self::leaderAccountId($actor);
        if ($leader === null) {
            return [];
        }

        return array_values(Project::query()->where('manager_id', '=', $leader)->pluck('id')
            ->map(static fn ($id): string => (string) $id)->all());
    }

    /**
     * The account a leader arm compares `projects.manager_id` to, or null when
     * there must be no leader arm at all: nobody signed in (a null id would
     * compile to `IS NULL` and hand over every leaderless project), or an
     * account without PROJECTS, which leads nothing it could open.
     */
    private static function leaderAccountId(Access $actor): ?int
    {
        if (! $actor->isSignedIn() || ! $actor->hasModule(ModuleCode::PROJECTS)) {
            return null;
        }

        return (int) $actor->userId;
    }

    /**
     * LEFT JOIN the actor's OWN response row, and nobody else's, onto a task
     * query — at most one row per task (`pts_task_employee_unq`), so the join
     * never multiplies tasks or counts. The table name stays literal and
     * unaliased: that is how ModuleBoundariesTest recognises an owned join.
     * An unlinked actor joins nothing — an always-false ON, never an
     * `employee_id IS NULL` match; their task scope is already empty.
     *
     * @param  Builder<ProjectTask>  $query
     * @return Builder<ProjectTask>
     */
    public static function joinOwnResponse(Builder $query, Access $actor): Builder
    {
        return $query->leftJoin('project_task_submissions', static function (JoinClause $join) use ($actor): void {
            $join->on('project_task_submissions.task_id', '=', 'project_tasks.id');
            if ($actor->hasEmployeeLink()) {
                $join->where('project_task_submissions.employee_id', '=', $actor->ownEmployeeId());
            } else {
                $join->whereRaw('1 = 0');
            }
        });
    }
}
