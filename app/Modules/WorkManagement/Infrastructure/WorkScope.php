<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Modules\WorkManagement\Domain\Tasks\TaskInbox;
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
    public const PROJECT_CLIENT_NAME_SQL = '(SELECT name FROM project_clients WHERE project_clients.id = projects.client_id)';

    /** The member's own state drives attention, just as it drives their status filter. */
    public const OWN_FOCUS_SQL = 'CASE WHEN ('.self::MY_STATUS_SQL.") IN ('PENDING', 'IN_PROGRESS', 'RETURNED') AND project_tasks.due_date < ? THEN ".TaskInbox::RANKS['OVERDUE']
        .' WHEN ('.self::MY_STATUS_SQL.") = 'IN_PROGRESS' THEN ".TaskInbox::RANKS['IN_PROGRESS']
        .' WHEN ('.self::MY_STATUS_SQL.") = 'RETURNED' THEN ".TaskInbox::RANKS['RETURNED']
        .' WHEN ('.self::MY_STATUS_SQL.") IN ('PENDING', 'IN_PROGRESS') AND (project_tasks.due_date = ? OR project_tasks.start_date = ?) THEN ".TaskInbox::RANKS['TODAY']
        .' WHEN ('.self::MY_STATUS_SQL.") IN ('PENDING', 'IN_PROGRESS') AND project_tasks.start_date > ? THEN ".TaskInbox::RANKS['UPCOMING']
        .' WHEN ('.self::MY_STATUS_SQL.") IN ('SUBMITTED', 'APPROVED') THEN ".TaskInbox::RANKS['WAITING']
        .' WHEN ('.self::MY_STATUS_SQL.") IN ('COMPLETED', 'CANCELLED') THEN ".TaskInbox::RANKS['CLOSED']
        .' ELSE '.TaskInbox::RANKS['NEXT'].' END';

    /** Group context rides on the scoped task read, without another query per row. */
    public const EFFECTIVE_GROUP_SQL = '(CASE WHEN project_tasks.parent_id IS NULL THEN project_tasks.group_id ELSE (SELECT task_parent.group_id FROM project_tasks AS task_parent WHERE task_parent.id = project_tasks.parent_id AND task_parent.project_id = project_tasks.project_id) END)';

    public const TASK_GROUP_NAME_SQL = '(SELECT name FROM project_task_groups WHERE project_task_groups.id = '.self::EFFECTIVE_GROUP_SQL.' AND project_task_groups.project_id = project_tasks.project_id)';

    /** A container is not another work unit; acceptance still counts every record. */
    public const LEAF_TASK_SQL = 'NOT EXISTS (SELECT 1 FROM project_tasks AS task_child WHERE task_child.parent_id = project_tasks.id)';

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
     * Client membership fences every client project. Explicit project members
     * discover its metadata; viewers and editors also read its shared tasks.
     * The projects the actor may see: every internal project for an all-data PROJECTS
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
    public static function projects(Access $actor, string $portfolio = 'ALL'): Builder
    {
        $leader = self::leaderAccountId($actor);

        $query = Project::query();
        if (! $actor->mayReadAllData(ModuleCode::PROJECTS)) {
            $query->where(static function (Builder $visible) use ($actor, $leader): void {
                $visible->toEmployeeVia($actor, 'tasks.assignments', 'employee_id', ModuleCode::PROJECTS);
                if ($leader !== null) {
                    $visible->orWhere('projects.manager_id', '=', $leader)
                        ->orWhereIn('projects.client_id', ClientScope::clients($actor)->where('owner_id', '=', $leader)->select('id'));
                }
                $visible->orWhereIn('projects.id', ProjectMembership::forActor($actor)->select('project_id'));
            });
        }

        if ($portfolio !== 'ALL') {
            $clients = PortfolioScope::clients(ClientScope::workClients($actor), $actor, $portfolio)->select('id');
            $query->where(static function (Builder $scoped) use ($portfolio, $clients): void {
                $scoped->whereIn('projects.client_id', $clients);
                if ($portfolio === 'NONE') {
                    $scoped->orWhereNull('projects.client_id');
                }
            });
        }

        return $query->where(static function (Builder $visible) use ($actor): void {
            $visible->whereNull('projects.client_id')
                ->orWhereIn('projects.client_id', ClientScope::workClients($actor)->select('id'));
        });
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
        $query = ProjectTask::query()->whereIn('project_tasks.project_id', self::projects($actor)->select('projects.id'));
        if (! $own) {
            $leader = self::leaderAccountId($actor);
            $led = static fn (int $account) => self::ledProjects($actor, $account)->select('projects.id');

            return match ($actor->rowVisibility(ModuleCode::PROJECTS)) {
                Access::ROWS_ALL => $query,
                Access::ROWS_OWN => $query->where(static function (Builder $visible) use ($actor, $leader, $led): void {
                    $visible->toEmployeeVia($actor, 'assignments', 'employee_id', ModuleCode::PROJECTS);
                    if ($leader !== null) {
                        $visible->orWhereIn('project_tasks.project_id', $led($leader));
                    }
                    $visible->orWhereIn('project_tasks.project_id', ProjectMembership::forActor($actor, true)->select('project_id'));
                }),
                Access::ROWS_NONE => $query->where(static function (Builder $visible) use ($actor, $leader, $led): void {
                    $visible->whereIn('project_tasks.project_id', ProjectMembership::forActor($actor, true)->select('project_id'));
                    if ($leader !== null) {
                        $visible->orWhereIn('project_tasks.project_id', $led($leader));
                    }
                }),
            };
        }
        if (! $actor->hasEmployeeLink()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('assignments', static fn (Builder $q) => $q->where('employee_id', '=', $actor->ownEmployeeId()));
    }

    /**
     * The ids of projects led by the actor or owned through their client, empty for
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

        return array_values(self::ledProjects($actor, $leader)->pluck('projects.id')
            ->map(static fn ($id): string => (string) $id)->all());
    }

    /** @return Builder<Project> */
    private static function ledProjects(Access $actor, int $leader): Builder
    {
        return self::projects($actor)->where(static function (Builder $query) use ($actor, $leader): void {
            $query->where('projects.manager_id', '=', $leader)
                ->orWhereIn('projects.client_id', ClientScope::clients($actor)->where('owner_id', '=', $leader)->select('id'));
        });
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
