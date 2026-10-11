<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Domain\Projects\ProjectPolicy;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Modules\Workforce\Contracts\AssignmentEmployees;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\WorkManagement\Contracts\Views\TaskTotals;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Whole-scope counters and page-specific totals share the same ownership constraints. */
final class WorkSummary
{
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

    /** @return array{active: int, review: int, overdue: int, dueSoon: int, unassigned: int} */
    public function counters(Access $actor, string $today): array
    {
        $row = $this->aggregate(WorkScope::tasks($actor), $today)->first();
        $totals = $row === null ? new TaskTotals : $this->totals($row);
        $dueSoon = app(WorkLists::class)->projectPage($actor, ['q' => '', 'status' => 'ALL', 'site' => 'ALL', 'manager' => 'ALL', 'queue' => 'DUE_SOON'], 'code', 'asc', $today, 1, 1)->total();

        return ['active' => WorkScope::projects($actor)->where('status', 'ACTIVE')->count(), 'review' => $totals->review, 'overdue' => $totals->overdue, 'dueSoon' => $dueSoon, 'unassigned' => $totals->unassigned];
    }

    /**
     * Each assignee's open, overdue and awaiting-review tasks. Review is
     * counted PER MEMBER (WorkScope::MEMBER_NEEDS_REVIEW_SQL): a GROUP task
     * handed in counts for its whole crew, an INDIVIDUAL one only for the
     * members whose own response waits on the reviewer.
     *
     * @return Collection<int, array{employee: DirectoryEmployee, active: int, overdue: int, review: int}>
     */
    public function workload(Access $actor, string $projectId, string $today): Collection
    {
        $groups = ProjectTaskAssignee::query()->join('project_tasks', 'project_tasks.id', '=', 'project_task_assignees.task_id')
            ->whereIn('task_id', WorkScope::tasks($actor)->where('project_id', $projectId)->select('id'))
            ->select('employee_id')->selectRaw("SUM(CASE WHEN status NOT IN ('COMPLETED', 'CANCELLED') THEN 1 ELSE 0 END) AS active_n, SUM(CASE WHEN status NOT IN ('COMPLETED', 'CANCELLED') AND due_date < ? THEN 1 ELSE 0 END) AS overdue_n, SUM(CASE WHEN ".WorkScope::MEMBER_NEEDS_REVIEW_SQL.' THEN 1 ELSE 0 END) AS review_n', [$today])
            ->groupBy('employee_id')->get()->keyBy('employee_id');
        $ids = $groups->values()->map(static fn (ProjectTaskAssignee $row): string => $row->employee_id)->all();
        $employees = app(AssignmentEmployees::class)->identities(array_values($ids))
            ->sortBy([['last_name', 'asc'], ['first_name', 'asc']], SORT_NATURAL | SORT_FLAG_CASE)->values();

        return $employees->map(static fn (DirectoryEmployee $employee): array => [
            'employee' => $employee,
            'active' => (int) $groups[$employee->id]->getAttribute('active_n'),
            'overdue' => (int) $groups[$employee->id]->getAttribute('overdue_n'),
            'review' => (int) $groups[$employee->id]->getAttribute('review_n'),
        ]);
    }

    /** @param Builder<ProjectTask> $query
     * @return Builder<ProjectTask>
     */
    private function aggregate(Builder $query, string $today): Builder
    {
        $limit = CarbonImmutable::parse($today, 'UTC')->addDays(ProjectPolicy::HEALTH_TASK_DUE_SOON_DAYS)->format('Y-m-d');

        return $query->selectRaw("COUNT(*) AS total_n,
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

        return new TaskTotals((int) $row->getAttribute('total_n'), (int) $row->getAttribute('completed_n'), (int) $row->getAttribute('cancelled_n'), (int) $row->getAttribute('review_n'), (int) $row->getAttribute('overdue_n'), (int) $row->getAttribute('soon_n'), (int) $row->getAttribute('unassigned_n'), is_string($earliest) ? $earliest : null);
    }
}
