<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\Project;
use App\Models\ProjectAvailability;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskEffort;
use App\Modules\Workforce\Contracts\AssignmentEmployees;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\CapacityPersonView;
use App\Modules\WorkManagement\Contracts\Views\CapacityTaskView;
use App\Modules\WorkManagement\Contracts\Views\CapacityTotals;
use App\Modules\WorkManagement\Domain\Projects\CapacityWindow;
use App\Modules\WorkManagement\Infrastructure\PlanningScope;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Platform\ReadModels\QueryPage;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ProjectCapacity
{
    private const CURRENT_CREW = 'EXISTS (SELECT 1 FROM project_task_assignees WHERE project_task_assignees.task_id = project_task_efforts.task_id AND project_task_assignees.employee_id = project_task_efforts.employee_id)';

    public function __construct(private readonly string $portfolio = 'ALL', private readonly string $client = 'ALL') {}

    /** @return array{view: bool, edit: bool} */
    public function permissions(Access $actor, ?string $projectId, bool $own): array
    {
        if (! $actor->isSignedIn() || ! $actor->hasModule(ModuleCode::PROJECTS, ModuleCode::TASKS)) {
            return ['view' => false, 'edit' => false];
        }
        if ($own) {
            return ['view' => $actor->hasEmployeeLink(), 'edit' => false];
        }
        if ($projectId === null) {
            return ['view' => $actor->hasModule(ModuleCode::PROJECTS), 'edit' => false];
        }
        $project = ! $actor->hasModule(ModuleCode::PROJECTS) ? null : app(WorkRecords::class)->project($actor, $projectId);

        return ['view' => $project !== null, 'edit' => WorkRules::editsWork($actor, $project)];
    }

    /** @return array{hours: int|null, shared: bool, revision: int} */
    public function ownAvailability(Access $actor, string $week): array
    {
        abort_unless($this->permissions($actor, null, true)['view'], 403);
        CapacityWindow::validateWeek($week);
        $row = ProjectAvailability::query()->where('employee_id', $actor->ownEmployeeId())->where('week_start', $week)->first();

        return ['hours' => $row?->hours_hundredths, 'shared' => $row->shared ?? false, 'revision' => $row->revision ?? 0];
    }

    /** @return LengthAwarePaginator<int, CapacityPersonView> */
    public function people(Access $actor, ?string $projectId, bool $own, string $from, int $page = 1, string $search = '', int $perPage = 20): LengthAwarePaginator
    {
        $permissions = $this->permissions($actor, $projectId, $own);
        abort_unless($permissions['view'], 403);
        $tasks = $this->tasks($actor, $projectId, $own);
        $crew = ProjectTaskAssignee::query()->whereIn('task_id', (clone $tasks)->select('project_tasks.id'));
        if ($own) {
            $people = new LengthAwarePaginator(collect([new CapacityPersonView(['employee_id' => $actor->ownEmployeeId()])]), 1, 20, 1, ['pageName' => 'capacityPeople']);
        } else {
            $search = mb_strtolower(mb_substr(trim($search), 0, 100));
            if ($search !== '') {
                $ids = array_values((clone $crew)->select('employee_id')->distinct()->get()->map(static fn (ProjectTaskAssignee $member): string => $member->employee_id)->all());
                $matching = app(AssignmentEmployees::class)->identities($ids)->filter(static fn ($person): bool => str_contains(mb_strtolower(EmployeeName::display($person).' '.$person->employee_code), $search))->pluck('id')->all();
                $crew->whereIn('employee_id', $matching);
            }
            $people = QueryPage::get($crew->select('employee_id')->groupBy('employee_id')->orderBy('employee_id'), $page, max(1, min(100, $perPage)), CapacityPersonView::class, 'capacityPeople');
        }
        $ids = array_values($people->getCollection()->map(static fn (CapacityPersonView $person): string => $person->employee_id)->all());
        $names = app(AssignmentEmployees::class)->names($ids);
        $weeks = CapacityWindow::weeks($from);
        $planned = $this->efforts($actor, $projectId, $own)->whereRaw(self::CURRENT_CREW)->whereIn('employee_id', $ids)
            ->whereBetween('week_start', [$weeks[0], $weeks[3]])
            ->selectRaw('employee_id, week_start, SUM(hours_hundredths) AS planned')->groupBy('employee_id', 'week_start')->get()->keyBy(static fn ($row): string => $row->employee_id.'|'.$row->week_start);
        $availability = $this->available($actor, $tasks)->whereIn('employee_id', $ids)->whereBetween('week_start', [$weeks[0], $weeks[3]])
            ->get()->keyBy(static fn (ProjectAvailability $row): string => $row->employee_id.'|'.$row->week_start);

        return $people->through(static function (CapacityPersonView $person) use ($names, $weeks, $planned, $availability): CapacityPersonView {
            $cells = [];
            foreach ($weeks as $week) {
                $key = $person->employee_id.'|'.$week;
                $cells[$week] = ['planned' => (int) ($planned->get($key)?->getAttribute('planned') ?? 0), 'available' => $availability->get($key)?->hours_hundredths, 'shared' => $availability->get($key)->shared ?? false];
            }

            return new CapacityPersonView(['employee_id' => $person->employee_id, 'name' => $names[$person->employee_id] ?? 'Employee', 'weeks' => $cells]);
        });
    }

    public function needsEstimate(Access $actor, ?string $projectId, bool $own, string $from): int
    {
        $weeks = CapacityWindow::weeks($from);

        return ProjectTaskAssignee::query()->whereIn('task_id', $this->tasks($actor, $projectId, $own)->whereRaw(WorkScope::LEAF_TASK_SQL)->select('project_tasks.id'))
            ->when($own, static fn (Builder $query) => $query->where('employee_id', $actor->employeeId ?? ''))
            ->whereNotExists(static function ($query) use ($weeks): void {
                $query->selectRaw('1')->from('project_task_efforts')->whereColumn('task_id', 'project_task_assignees.task_id')->whereColumn('employee_id', 'project_task_assignees.employee_id')
                    ->whereNotNull('hours_hundredths')->whereBetween('week_start', [$weeks[0], $weeks[3]]);
            })->count();
    }

    public function totals(Access $actor, ?string $projectId, bool $own, string $from): ?CapacityTotals
    {
        if (! $this->permissions($actor, $projectId, $own)['view']) {
            return null;
        }
        $weeks = CapacityWindow::weeks($from);
        $load = $this->efforts($actor, $projectId, $own)->whereRaw(self::CURRENT_CREW)->whereNotNull('hours_hundredths')
            ->whereBetween('week_start', [$weeks[0], $weeks[3]])->select('employee_id', 'week_start')->selectRaw('SUM(hours_hundredths) AS planned')->groupBy('employee_id', 'week_start');
        $available = $this->available($actor, $this->tasks($actor, $projectId, $own))->whereBetween('week_start', [$weeks[0], $weeks[3]])->whereNotNull('hours_hundredths')->select('employee_id', 'week_start', 'hours_hundredths');
        $row = DB::query()->fromSub($load, 'capacity_load')->leftJoinSub($available, 'capacity_hours', static fn (JoinClause $join) => $join
            ->on('capacity_load.employee_id', '=', 'capacity_hours.employee_id')->on('capacity_load.week_start', '=', 'capacity_hours.week_start'))
            ->selectRaw('COALESCE(SUM(capacity_load.planned), 0) AS planned, COUNT(*) AS person_weeks,
                COALESCE(SUM(CASE WHEN capacity_hours.hours_hundredths IS NOT NULL THEN 1 ELSE 0 END), 0) AS known_weeks,
                COUNT(DISTINCT CASE WHEN capacity_load.planned > capacity_hours.hours_hundredths THEN capacity_load.employee_id ELSE NULL END) AS overloaded_people')->first();

        return new CapacityTotals((int) ($row->planned ?? 0), (int) ($row->person_weeks ?? 0), (int) ($row->known_weeks ?? 0),
            (int) ($row->overloaded_people ?? 0), $this->needsEstimate($actor, $projectId, $own, $from));
    }

    /** @return LengthAwarePaginator<int, CapacityTaskView> */
    public function tasksForWeek(Access $actor, ?string $projectId, bool $own, string $employeeId, string $week, string $search = '', int $page = 1): LengthAwarePaginator
    {
        $this->checkPerson($actor, $projectId, $own, $employeeId);
        CapacityWindow::validateWeek($week);
        $query = $this->taskRows($actor, $projectId, $own, $employeeId, $week)->whereRaw(WorkScope::LEAF_TASK_SQL)
            ->whereHas('assignments', static fn (Builder $crew) => $crew->where('employee_id', $employeeId));
        $search = mb_substr(trim($search), 0, 100);
        if ($search !== '') {
            $query->where(static fn (Builder $query) => $query->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('code', 'like', '%'.addcslashes($search, '%_\\').'%'));
        }

        return QueryPage::get($query->orderBy('project_tasks.title')->orderBy('project_tasks.id'), $page, 20, CapacityTaskView::class, 'capacityTasks');
    }

    /** @return array{task: CapacityTaskView, edit: bool, on_crew: bool} */
    public function estimate(Access $actor, ?string $projectId, bool $own, string $taskId, string $employeeId, string $week): array
    {
        CapacityWindow::validateWeek($week);
        abort_if($own && $employeeId !== $actor->employeeId, 403);
        $task = $this->taskRows($actor, $projectId, $own, $employeeId, $week)->whereKey($taskId)->first();
        abort_if($task === null, 403);
        $crew = ProjectTaskAssignee::query()->where('task_id', $taskId)->where('employee_id', $employeeId)->exists();
        $existing = ProjectTaskEffort::query()->where('task_id', $taskId)->where('employee_id', $employeeId)->where('week_start', $week)->exists();
        abort_unless($crew || $existing, 404);
        $edit = (bool) $task->getAttribute('can_plan') || ($crew && $employeeId === $actor->employeeId);

        return ['task' => new CapacityTaskView($task->getAttributes()), 'edit' => $edit, 'on_crew' => $crew];
    }

    /** @return LengthAwarePaginator<int, CapacityTaskView> */
    public function issues(Access $actor, ?string $projectId, bool $own, string $from, int $page = 1): LengthAwarePaginator
    {
        $weeks = CapacityWindow::weeks($from);
        $tasks = $this->tasks($actor, $projectId, $own);
        $query = ProjectTaskEffort::query()->whereIn('task_id', (clone $tasks)->select('project_tasks.id'))->whereNotNull('hours_hundredths')
            ->when($own, static fn (Builder $query) => $query->where('employee_id', $actor->employeeId ?? ''))
            ->whereBetween('week_start', [$weeks[0], $weeks[3]])
            ->where(static fn (Builder $query) => $query->whereRaw('NOT '.self::CURRENT_CREW)->orWhereIn('task_id', (clone $tasks)->whereRaw('NOT ('.WorkScope::LEAF_TASK_SQL.')')->select('project_tasks.id')))
            ->select('project_task_efforts.*')->selectRaw(self::CURRENT_CREW.' AS on_crew')->selectSub(ProjectTask::query()->select('title')->whereColumn('project_tasks.id', 'project_task_efforts.task_id'), 'title')
            ->selectSub(ProjectTask::query()->select('project_id')->whereColumn('project_tasks.id', 'project_task_efforts.task_id'), 'project_id')
            ->selectSub(ProjectTask::query()->select('code')->whereColumn('project_tasks.id', 'project_task_efforts.task_id'), 'code');
        $rows = QueryPage::get($query->orderBy('week_start')->orderBy('id'), $page, 10, CapacityTaskView::class, 'capacityIssues');
        $names = app(AssignmentEmployees::class)->names(array_values($rows->getCollection()->map(static fn (CapacityTaskView $row): string => $row->employee_id)->unique()->all()));

        $editable = PlanningScope::projects($actor)->whereIn('projects.id', $rows->getCollection()->pluck('project_id')->all())->pluck('projects.id')->all();

        return $rows->through(static fn (CapacityTaskView $row): CapacityTaskView => new CapacityTaskView([...$row->toArray(), 'person_name' => $names[$row->employee_id] ?? 'Employee', 'can_plan' => in_array($row->project_id, $editable, true)]));
    }

    public function checkPerson(Access $actor, ?string $projectId, bool $own, string $employeeId): void
    {
        abort_unless($this->permissions($actor, $projectId, $own)['view'], 403);
        if ($own) {
            abort_unless($employeeId === $actor->employeeId, 403);

            return;
        }
        abort_unless(ProjectTaskAssignee::query()->where('employee_id', $employeeId)->whereIn('task_id', $this->tasks($actor, $projectId, false)->select('project_tasks.id'))->exists(), 403);
    }

    /** @return Builder<ProjectTask> */
    private function tasks(Access $actor, ?string $projectId, bool $own): Builder
    {
        $allowed = $actor->isSignedIn() && ($own
            ? $actor->hasEmployeeLink() && $actor->hasModule(ModuleCode::PROJECTS, ModuleCode::TASKS)
            : $actor->hasModule(ModuleCode::PROJECTS));

        $projects = WorkScope::projects($actor, $this->portfolio)->when($this->client !== 'ALL', fn (Builder $query) => $query->where('projects.client_id', $this->client))->select('projects.id');

        return WorkScope::tasks($actor, $own)->when(! $allowed, static fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->whereIn('project_tasks.project_id', $projects)
            ->when($projectId !== null, static fn (Builder $query) => $query->where('project_tasks.project_id', $projectId))
            ->whereNotIn('project_tasks.status', ['COMPLETED', 'CANCELLED'])->whereHas('project', static fn (Builder $query) => $query->whereNotIn('status', ['COMPLETED', 'CANCELLED']));
    }

    /** @param Builder<ProjectTask> $tasks
     * @return Builder<ProjectAvailability>
     */
    private function available(Access $actor, Builder $tasks): Builder
    {
        $planningCrew = ProjectTaskAssignee::query()->select('employee_id')->whereIn('task_id', (clone $tasks)
            ->whereIn('project_tasks.project_id', PlanningScope::projects($actor)->select('projects.id'))->select('project_tasks.id'));

        return ProjectAvailability::query()->where(static function (Builder $query) use ($actor, $planningCrew): void {
            $query->where('employee_id', $actor->employeeId ?? '')
                ->orWhere(static fn (Builder $shared) => $shared->where('shared', true)->whereIn('employee_id', $planningCrew));
        });
    }

    /** @return Builder<ProjectTaskEffort> */
    private function efforts(Access $actor, ?string $projectId, bool $own): Builder
    {
        return ProjectTaskEffort::query()->whereIn('task_id', $this->tasks($actor, $projectId, $own)->whereRaw(WorkScope::LEAF_TASK_SQL)->select('project_tasks.id'))
            ->when($own, static fn (Builder $query) => $query->where('employee_id', $actor->employeeId ?? ''));
    }

    /** @return Builder<ProjectTask> */
    private function taskRows(Access $actor, ?string $projectId, bool $own, string $employeeId, string $week): Builder
    {
        $effort = ProjectTaskEffort::query()->whereColumn('task_id', 'project_tasks.id')->where('employee_id', $employeeId)->where('week_start', $week);

        return $this->tasks($actor, $projectId, $own)->select('project_tasks.id', 'project_tasks.project_id', 'project_tasks.title', 'project_tasks.code')
            ->selectSub((clone $effort)->select('hours_hundredths'), 'hours_hundredths')->selectSub((clone $effort)->select('revision'), 'revision')
            ->selectRaw('? AS employee_id', [$employeeId])->selectRaw('CASE WHEN '.WorkScope::LEAF_TASK_SQL.' THEN 0 ELSE 1 END AS is_parent')
            ->selectSub(PlanningScope::projects($actor)->whereColumn('projects.id', 'project_tasks.project_id')->selectRaw('1')->limit(1), 'can_plan')
            ->selectSub(Project::query()->selectRaw(WorkScope::PROJECT_CLIENT_NAME_SQL)->whereColumn('projects.id', 'project_tasks.project_id'), 'client_name')
            ->selectSub(Project::query()->select('name')->whereColumn('projects.id', 'project_tasks.project_id'), 'project_name');
    }
}
