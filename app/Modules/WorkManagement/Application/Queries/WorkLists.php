<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Domain\Projects\ProjectPolicy;
use App\Domain\Projects\TaskPolicy;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\Workforce\Contracts\AssignmentEmployees;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView;
use App\Modules\WorkManagement\Contracts\Views\ProjectView;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Platform\ReadModels\Snapshot;
use App\Platform\ReadModels\SqlText;
use App\Support\Access;
use App\Support\TextSort;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** Scoped filtering and ordering happen before list pagination, shared with exports. */
final class WorkLists
{
    /** @param array{q: string, status: string, assignee: string, priority: string, queue: string} $filters
     * @return LengthAwarePaginator<int, ProjectTaskView>
     */
    public function taskPage(Access $actor, string $projectId, array $filters, string $sort, string $direction, string $today, int $page, int $perPage): LengthAwarePaginator
    {
        $query = WorkScope::tasks($actor)->where('project_id', $projectId);
        foreach (['status', 'priority'] as $column) {
            if ($filters[$column] !== 'ALL') {
                $query->where($column, $filters[$column]);
            }
        }
        if ($filters['assignee'] === 'UNASSIGNED') {
            $query->whereDoesntHave('assignments');
        } elseif ($filters['assignee'] !== 'ALL') {
            $query->whereHas('assignments', static fn (Builder $q) => $q->where('employee_id', '=', $filters['assignee']));
        }
        $this->taskQueue($query, $filters['queue'], $today);
        SqlText::contains($query, ['code', 'title'], $filters['q']);
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        if ($sort === 'stack') {
            // A task waiting on review heads the stack whatever its mode: an
            // INDIVIDUAL task with a response handed in ranks as SUBMITTED.
            SqlText::orderMapped($query, '('.WorkScope::STACK_STATUS_SQL.')', TaskPolicy::STACK_RANK, '6', $direction, false);
            SqlText::order($query, 'due_date', $direction);
            SqlText::order($query, 'code', $direction);
        } elseif ($sort === 'priority') {
            SqlText::orderMapped($query, 'priority', TaskPolicy::PRIORITY_RANK, '2', $direction, false);
        } elseif ($sort === 'assignee') {
            $ids = ProjectTaskAssignee::query()->whereIn('task_id', (clone $query)->select('id'))->select('employee_id')->distinct()->get()
                ->map(static fn (ProjectTaskAssignee $row): string => $row->employee_id)->values()->all();
            $employees = app(AssignmentEmployees::class)->identities(array_values($ids));
            $names = [];
            foreach ($employees as $employee) {
                $names[$employee->id] = $employee->last_name;
            }
            [$sql, $bindings, $blankRank] = $this->rankedNames('employee_id', $names);
            $rank = ProjectTaskAssignee::query()->whereColumn('task_id', 'project_tasks.id')->selectRaw('COALESCE(MIN('.$sql.'), ?)', [...$bindings, $blankRank]);
            $query->orderBy($rank, $direction);
        } elseif ($sort === 'status') {
            SqlText::orderMapped($query, 'status', TaskPolicy::STATUSES, 'status', $direction);
        } else {
            $column = match ($sort) {
                'task' => 'title', 'start' => 'start_date', 'due' => 'due_date',
                default => 'code',
            };
            SqlText::order($query, $column, $direction);
        }
        $query->orderBy('code', $direction);
        $rows = $this->page($query, $page, $perPage, ['id']);
        $ids = $rows->getCollection()->map(static fn (ProjectTask $row): string => $row->id)->values()->all();
        $views = app(WorkRecords::class)->tasksByIds($actor, array_values($ids))->keyBy('id');
        $ordered = collect($ids)->map(static fn (string $id): ?ProjectTaskView => $views->get($id))
            ->filter(static fn (?ProjectTaskView $view): bool => $view !== null)->values();

        return new LengthAwarePaginator($ordered, $rows->total(), $rows->perPage(), $rows->currentPage(), $rows->getOptions());
    }

    /** @param array{q: string, status: string, site: string, manager: string, queue: string} $filters
     * @return LengthAwarePaginator<int, ProjectView>
     */
    public function projectPage(Access $actor, array $filters, string $sort, string $direction, string $today, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->projectFilter($actor, $filters, $today);
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        if ($sort === 'manager') {
            $names = [];
            foreach (app(AccountNames::class)->list($actor) as $account) {
                $names[(string) $account->id] = $account->name;
            }
            [$sql, $bindings] = $this->rankedNames('manager_id', $names);
            $query->orderByRaw($sql.' '.$direction, $bindings);
        } elseif ($sort === 'status') {
            SqlText::orderMapped($query, 'status', ProjectPolicy::STATUSES, 'status', $direction);
        } else {
            $column = match ($sort) {
                'name' => 'name',
                default => 'code',
            };
            SqlText::order($query, $column, $direction);
        }
        $query->orderBy('code', $direction);
        $rows = $this->page($query, $page, $perPage);

        return $rows->through(static fn (Project $row): ProjectView => Snapshot::of($row, ProjectView::class));
    }

    /** @param array{q: string, status: string, site: string, manager: string, queue: string} $filters
     * @return Builder<Project>
     */
    private function projectFilter(Access $actor, array $filters, string $today): Builder
    {
        $query = WorkScope::projects($actor);
        foreach (['status' => 'status', 'site' => 'site_id', 'manager' => 'manager_id'] as $filter => $column) {
            if ($filters[$filter] !== 'ALL') {
                $query->where($column, $filters[$filter]);
            }
        }
        SqlText::contains($query, ['code', 'name'], $filters['q']);
        if (in_array($filters['queue'], ['REVIEW', 'OVERDUE', 'UNASSIGNED'], true)) {
            $tasks = WorkScope::tasks($actor);
            $this->taskQueue($tasks, $filters['queue'], $today);
            $query->whereIn('id', $tasks->select('project_id'));
        } elseif ($filters['queue'] === 'DUE_SOON') {
            $overdue = WorkScope::tasks($actor);
            $this->taskQueue($overdue, 'OVERDUE', $today);
            $soon = WorkScope::tasks($actor);
            $this->taskQueue($soon, 'DUE_SOON', $today);
            $projectLimit = CarbonImmutable::parse($today, 'UTC')->addDays(ProjectPolicy::HEALTH_PROJECT_DUE_SOON_DAYS)->format('Y-m-d');
            $query->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->whereNotIn('id', $overdue->select('project_id'))
                ->where(static fn (Builder $q) => $q->whereIn('id', $soon->select('project_id'))->orWhere('due_date', '<=', $projectLimit));
        }

        return $query;
    }

    /** @param Builder<ProjectTask> $query */
    private function taskQueue(Builder $query, string $queue, string $today): void
    {
        match ($queue) {
            'REVIEW' => $query->whereRaw(WorkScope::NEEDS_REVIEW_SQL),
            'OVERDUE' => $query->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->where('due_date', '<', $today),
            'DUE_SOON' => $query->whereNotIn('status', ['COMPLETED', 'CANCELLED'])
                ->whereBetween('due_date', [$today, CarbonImmutable::parse($today, 'UTC')->addDays(ProjectPolicy::HEALTH_TASK_DUE_SOON_DAYS)->format('Y-m-d')]),
            'UNASSIGNED' => $query->whereDoesntHave('assignments'),
            default => null,
        };
    }

    /** @param literal-string $column
     * @param  array<int|string, string|null>  $names
     * @return array{literal-string, list<int|string>, int}
     */
    private function rankedNames(string $column, array $names): array
    {
        $sorted = array_values(array_unique([...array_values($names), null]));
        usort($sorted, [TextSort::class, 'compare']);
        $ranks = [];
        $rank = 0;
        $previous = null;
        foreach ($sorted as $name) {
            if ($previous !== null && TextSort::compare($previous, $name) !== 0) {
                $rank++;
            }
            $ranks[trim((string) $name)] = $rank;
            $previous = $name;
        }
        $blankRank = $ranks[''] ?? $rank;
        $sql = 'CASE '.$column;
        $bindings = [];
        foreach ($names as $id => $name) {
            $sql .= ' WHEN ? THEN ?';
            array_push($bindings, (string) $id, $ranks[trim((string) $name)]);
        }

        return [$names === [] ? '?' : $sql.' ELSE ? END', [...$bindings, $blankRank], $blankRank];
    }

    /** @template T of \Illuminate\Database\Eloquent\Model
     * @param  Builder<T>  $query
     * @param  list<string>  $columns
     * @return LengthAwarePaginator<int, T>
     */
    private function page(Builder $query, int $page, int $perPage, array $columns = ['*']): LengthAwarePaginator
    {
        $perPage = max(1, min(100, $perPage));
        $rows = $query->paginate($perPage, $columns, 'page', max(1, $page));

        return $rows->currentPage() > $rows->lastPage() ? $query->paginate($perPage, $columns, 'page', $rows->lastPage()) : $rows;
    }
}
