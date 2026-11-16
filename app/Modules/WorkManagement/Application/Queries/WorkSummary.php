<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Domain\Projects\ProjectPolicy;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Modules\Workforce\Contracts\AssignmentEmployees;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView;
use App\Modules\WorkManagement\Contracts\Views\ProjectView;
use App\Modules\WorkManagement\Contracts\Views\TaskTotals;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Whole-scope counters and page-specific totals share the same ownership constraints. */
final class WorkSummary
{
    /** @return array{states: array<string, int>, deadlines: Collection<int, ProjectTaskView>} */
    public function projectDelivery(Access $actor, string $projectId): array
    {
        $tasks = WorkScope::tasks($actor)->where('project_id', $projectId)
            ->whereIn('project_id', WorkScope::projects($actor)->select('projects.id'));
        $deadlineIds = (clone $tasks)->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->whereNotNull('due_date')
            ->orderBy('due_date')->orderBy('id')->limit(4)->get(['id'])
            ->map(static fn (ProjectTask $task): string => $task->id)->values()->all();

        return [
            'states' => (clone $tasks)->select('status')->selectRaw('COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(static fn ($n): int => (int) $n)->all(),
            'deadlines' => app(WorkRecords::class)->tasksByIds($actor, array_values($deadlineIds))->sortBy('due_date')->values(),
        ];
    }

    /** @return array{projects: array<string, int>, states: array<string, int>, totals: TaskTotals, attention: Collection<int, ProjectView>, deadlines: Collection<int, ProjectView>} */
    public function overview(Access $actor, string $today, string $client = 'ALL', string $portfolio = 'ALL'): array
    {
        $projects = WorkScope::projects($actor, $portfolio)->when($client !== 'ALL', static fn (Builder $query) => $query->where('projects.client_id', $client));
        $tasks = WorkScope::tasks($actor)->whereIn('project_id', (clone $projects)->select('projects.id'));
        $row = $this->aggregate(clone $tasks, $today)->first();
        $attention = (clone $projects)->whereIn('projects.id', (clone $tasks)->where(static function (Builder $query) use ($today): void {
            $query->whereRaw(WorkScope::NEEDS_REVIEW_SQL)
                ->orWhere(static fn (Builder $late) => $late->whereNotIn('project_tasks.status', ['COMPLETED', 'CANCELLED'])->where('project_tasks.due_date', '<', $today))
                ->orWhere(static fn (Builder $empty) => $empty->whereNotIn('project_tasks.status', ['COMPLETED', 'CANCELLED'])->whereDoesntHave('assignments'));
        })->select('project_id'));
        $deadlines = (clone $projects)->whereNotIn('projects.status', ['COMPLETED', 'CANCELLED'])->whereNotNull('projects.due_date');

        return [
            'projects' => (clone $projects)->select('status')->selectRaw('COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(static fn ($n): int => (int) $n)->all(),
            'states' => (clone $tasks)->select('project_tasks.status')->selectRaw('COUNT(*) AS n')->groupBy('project_tasks.status')->pluck('n', 'status')->map(static fn ($n): int => (int) $n)->all(),
            'totals' => $row === null ? new TaskTotals : $this->totals($row),
            'attention' => $this->overviewProjects($attention),
            'deadlines' => $this->overviewProjects($deadlines),
        ];
    }

    /** Whole-scope task totals, the numbers overview() reads without its project lists. */
    public function scopeTotals(Access $actor, string $today, string $portfolio = 'ALL'): TaskTotals
    {
        $row = $this->aggregate(WorkScope::tasks($actor)->whereIn('project_id', WorkScope::projects($actor, $portfolio)->select('projects.id')), $today)->first();

        return $row === null ? new TaskTotals : $this->totals($row);
    }

    /** @param Builder<Project> $query
     * @return Collection<int, ProjectView>
     */
    private function overviewProjects(Builder $query): Collection
    {
        return $query->select('projects.*')->selectRaw(WorkScope::PROJECT_CLIENT_NAME_SQL.' AS client_name')
            ->orderByRaw('CASE WHEN projects.due_date IS NULL THEN 1 ELSE 0 END')->orderBy('projects.due_date')->orderBy('projects.id')->limit(6)->get()
            ->map(static fn (Project $project): ProjectView => Snapshot::of($project, ProjectView::class));
    }

    /** @return array<string, TaskTotals> */
    public function groups(Access $actor, string $projectId, string $today): array
    {
        $rows = $this->aggregate(WorkScope::tasks($actor)->where('project_id', $projectId), $today)
            ->selectRaw(WorkScope::EFFECTIVE_GROUP_SQL.' AS group_key')->groupBy('group_key')->get();
        $totals = [];
        foreach ($rows as $row) {
            $totals[$row->getAttribute('group_key') ?? 'NONE'] = $this->totals($row);
        }

        return $totals;
    }

    /** @param list<string> $projectIds
     * @return array<string, TaskTotals>
     */
    public function projects(Access $actor, array $projectIds, string $today): array
    {
        $rows = $this->aggregate(WorkScope::tasks($actor)->whereIn('project_id', $projectIds), $today)
            ->addSelect('project_id')->groupBy('project_id')->get();
        $totals = array_fill_keys($projectIds, new TaskTotals);
        foreach ($rows as $row) {
            $totals[$row->project_id] = $this->totals($row);
        }

        return $totals;
    }

    /** @param list<string> $clientIds
     * @return array<string, TaskTotals>
     */
    public function clients(Access $actor, array $clientIds, string $today): array
    {
        $clientKey = '(SELECT client_id FROM projects WHERE projects.id = project_tasks.project_id)';
        $rows = $this->aggregate(WorkScope::tasks($actor)->whereIn('project_id', WorkScope::projects($actor)->whereIn('client_id', $clientIds)->select('projects.id')), $today)
            ->selectRaw($clientKey.' AS client_key')->groupBy('client_key')->get();
        $totals = array_fill_keys($clientIds, new TaskTotals);
        foreach ($rows as $row) {
            $totals[(string) $row->getAttribute('client_key')] = $this->totals($row);
        }

        return $totals;
    }

    /** @return array{active: int, review: int, overdue: int, dueSoon: int, unassigned: int} */
    public function counters(Access $actor, string $today, ?string $clientId = null, string $portfolio = 'ALL'): array
    {
        $projects = WorkScope::projects($actor, $portfolio);
        $tasks = WorkScope::tasks($actor)->whereIn('project_id', (clone $projects)->select('projects.id'));
        if ($clientId !== null) {
            $projects->where('projects.client_id', $clientId);
            $tasks->whereIn('project_id', (clone $projects)->select('projects.id'));
        }
        $row = $this->aggregate($tasks, $today)->first();
        $totals = $row === null ? new TaskTotals : $this->totals($row);
        $dueSoon = app(WorkLists::class)->projectPage($actor, ['q' => '', 'client' => $clientId ?? 'ALL', 'portfolio' => $portfolio, 'status' => 'ALL', 'site' => 'ALL', 'manager' => 'ALL', 'queue' => 'DUE_SOON'], 'code', 'asc', $today, 1, 1)->total();

        return ['active' => $projects->where('status', 'ACTIVE')->count(), 'review' => $totals->review, 'overdue' => $totals->overdue, 'dueSoon' => $dueSoon, 'unassigned' => $totals->unassigned];
    }

    /**
     * Each assignee's open, overdue and awaiting-review tasks. Review is
     * counted PER MEMBER (WorkScope::MEMBER_NEEDS_REVIEW_SQL): a GROUP task
     * handed in counts for its whole crew, an INDIVIDUAL one only for the
     * members whose own response waits on the reviewer.
     *
     * @return Collection<int, array{employee: DirectoryEmployee, active: int, overdue: int, review: int}>
     */
    public function workload(Access $actor, ?string $projectId, string $today, ?int $limit = null, string $client = 'ALL', string $portfolio = 'ALL'): Collection
    {
        $tasks = WorkScope::tasks($actor)->whereIn('project_id', WorkScope::projects($actor, $portfolio)->select('projects.id'));
        if ($projectId !== null) {
            $tasks->where('project_id', $projectId);
        } elseif ($client !== 'ALL') {
            $tasks->whereIn('project_id', WorkScope::projects($actor)->where('projects.client_id', $client)->select('projects.id'));
        }
        $groups = ProjectTaskAssignee::query()->join('project_tasks', 'project_tasks.id', '=', 'project_task_assignees.task_id')
            ->whereIn('task_id', $tasks->select('id'))
            ->select('employee_id')->selectRaw("SUM(CASE WHEN status NOT IN ('COMPLETED', 'CANCELLED') THEN 1 ELSE 0 END) AS active_n, SUM(CASE WHEN status NOT IN ('COMPLETED', 'CANCELLED') AND due_date < ? THEN 1 ELSE 0 END) AS overdue_n, SUM(CASE WHEN ".WorkScope::MEMBER_NEEDS_REVIEW_SQL.' THEN 1 ELSE 0 END) AS review_n', [$today])
            ->groupBy('employee_id');
        if ($limit !== null) {
            $groups->havingRaw('SUM(CASE WHEN status NOT IN (\'COMPLETED\', \'CANCELLED\') THEN 1 ELSE 0 END) > 0')
                ->orderByDesc('active_n')->orderBy('employee_id')->limit(max(1, min(20, $limit)));
        }
        $groups = $groups->get()->keyBy('employee_id');
        $ids = $groups->values()->map(static fn (ProjectTaskAssignee $row): string => $row->employee_id)->all();
        $employees = app(AssignmentEmployees::class)->identities(array_values($ids))
            ->sortBy([['last_name', 'asc'], ['first_name', 'asc']], SORT_NATURAL | SORT_FLAG_CASE)->values();

        $entries = $employees->map(static fn (DirectoryEmployee $employee): array => [
            'employee' => $employee,
            'active' => (int) $groups[$employee->id]->getAttribute('active_n'),
            'overdue' => (int) $groups[$employee->id]->getAttribute('overdue_n'),
            'review' => (int) $groups[$employee->id]->getAttribute('review_n'),
        ]);

        return $limit === null ? $entries : $entries->sortByDesc('active')->values();
    }

    /** @param Builder<ProjectTask> $query
     * @return Builder<ProjectTask>
     */
    private function aggregate(Builder $query, string $today): Builder
    {
        $limit = CarbonImmutable::parse($today, 'UTC')->addDays(ProjectPolicy::HEALTH_TASK_DUE_SOON_DAYS)->format('Y-m-d');

        return $query->selectRaw('COUNT(*) AS total_n,
            SUM(CASE WHEN '.WorkScope::LEAF_TASK_SQL.' THEN 1 ELSE 0 END) AS leaf_n,
            SUM(CASE WHEN '.WorkScope::LEAF_TASK_SQL." AND status = 'COMPLETED' THEN 1 ELSE 0 END) AS leaf_completed_n,
            SUM(CASE WHEN ".WorkScope::LEAF_TASK_SQL." AND status = 'CANCELLED' THEN 1 ELSE 0 END) AS leaf_cancelled_n,
            SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) AS completed_n,
            SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END) AS cancelled_n,
            SUM(CASE WHEN ".WorkScope::NEEDS_REVIEW_SQL." THEN 1 ELSE 0 END) AS review_n,
            SUM(CASE WHEN status NOT IN ('COMPLETED', 'CANCELLED') AND due_date < ? THEN 1 ELSE 0 END) AS overdue_n,
            SUM(CASE WHEN status NOT IN ('COMPLETED', 'CANCELLED') AND due_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS soon_n,
            SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM project_task_assignees WHERE task_id = project_tasks.id) THEN 1 ELSE 0 END) AS unassigned_n,
            MIN(CASE WHEN status NOT IN ('COMPLETED', 'CANCELLED') THEN due_date ELSE NULL END) AS earliest_due", [$today, $today, $limit]);
    }

    private function totals(ProjectTask $row): TaskTotals
    {
        $earliest = $row->getAttribute('earliest_due');

        return new TaskTotals((int) $row->getAttribute('total_n'), (int) $row->getAttribute('completed_n'), (int) $row->getAttribute('cancelled_n'), (int) $row->getAttribute('review_n'), (int) $row->getAttribute('overdue_n'), (int) $row->getAttribute('soon_n'), (int) $row->getAttribute('unassigned_n'), is_string($earliest) ? $earliest : null,
            (int) $row->getAttribute('leaf_n'), (int) $row->getAttribute('leaf_completed_n'), (int) $row->getAttribute('leaf_cancelled_n'));
    }
}
