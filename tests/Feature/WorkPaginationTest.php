<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Domain\Projects\ProjectPolicy;
use App\Domain\Projects\TaskPolicy;
use App\Livewire\Projects\ProjectsData;
use App\Livewire\Projects\Show;
use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\WorkManagement\Application\Queries\WorkLists;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView;
use App\Modules\WorkManagement\Contracts\Views\ProjectView;
use App\Modules\WorkManagement\Domain\Projects\ManualProjectStatus;
use App\Support\Access;
use App\Support\Csv;
use App\Support\LocalClock;
use App\Support\TextSort;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * SQL paging must preserve the existing own-task filters, byte ordering, and
 * scope — for every sort key, the hand-set task start date included (blank
 * start dates last ascending, first descending, exactly as the PHP
 * comparator puts them) — and the aggregate TaskTotals must answer every
 * project status question, reference and hand-set alike, as the pure
 * policies do.
 */

/** @return array{Access, EmployeeProfile, Project} */
function workPagingFixture(): array
{
    $employee = EmployeeProfile::create(['employee_code' => 'PAGE-'.Str::random(8), 'first_name' => 'Test', 'last_name' => 'Paging', 'date_hired' => '2025-01-01', 'employment_status' => 'Regular', 'is_active' => true]);
    $role = Role::create(['name' => 'Paging '.Str::random(8), 'all_data' => true, 'is_system' => false]);
    foreach ([ModuleCode::TASKS, ModuleCode::PROJECTS] as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id]);
    $project = Project::create(['code' => 'PRJ-001', 'name' => 'Project search', 'status' => 'PLANNING', 'start_date' => '2026-09-01']);

    return [Access::forActor($user), $employee, $project];
}

it('matches the previous comparator across every own-task sort and direction', function (string $sort, string $direction) {
    [$actor, $employee, $project] = workPagingFixture();
    $titles = ['zebra', 'Alpha', 'alpha', 'same', 'same', '', " \t ", "\tBravo\n", "\u{00C1}lvarez", "\u{00E1}lvarez", '100%_ready', '100xready'];
    // Mixed, repeated and blank start dates, in an order that is neither the
    // code order nor the due order, so sorting 'start' on the wrong column,
    // or with blanks first, cannot pass.
    $starts = ['2026-09-10', null, '2026-09-02', '2026-09-10', '2026-09-05', null, '2026-09-02', '2026-09-15', '2026-09-05', null, '2026-09-10', '2026-09-01'];
    foreach ($titles as $i => $title) {
        $task = ProjectTask::create(['code' => sprintf('TSK-%03d', $i), 'project_id' => $project->id, 'title' => $title, 'status' => ['PENDING', 'IN_PROGRESS', 'SUBMITTED', 'RETURNED', 'COMPLETED', 'CANCELLED'][$i % 6], 'priority' => ['LOW', 'NORMAL', 'HIGH', 'URGENT', 'UNKNOWN'][$i % 5], 'start_date' => $starts[$i], 'due_date' => $i % 3 === 0 ? null : '2026-09-20']);
        $task->syncAssignees([$employee->id], null);
    }
    $records = app(WorkRecords::class);
    $expected = $records->tasks($actor, own: true)->all();
    // Characterization of the pre-pagination comparator, independent of SQL.
    $compare = static function (ProjectTaskView $a, ProjectTaskView $b) use ($sort): int {
        return match ($sort) {
            'task' => TextSort::compare($a->title, $b->title),
            'start' => TextSort::compare($a->start_date?->format('Y-m-d'), $b->start_date?->format('Y-m-d')),
            'due' => TextSort::compare($a->due_date?->format('Y-m-d'), $b->due_date?->format('Y-m-d')),
            'priority' => (TaskPolicy::priorityRank($a->priority) <=> TaskPolicy::priorityRank($b->priority))
                ?: TextSort::compare($a->due_date?->format('Y-m-d'), $b->due_date?->format('Y-m-d')),
            'status' => TextSort::compare(TaskPolicy::statusLabel($a->status), TaskPolicy::statusLabel($b->status)),
            default => TextSort::compare($a->code, $b->code),
        };
    };
    usort($expected, $compare);
    if ($direction === 'desc') {
        $expected = array_reverse($expected);
    }
    $expectedIds = array_map(static fn ($row): string => $row->id, $expected);
    if ($sort === 'start') {
        // The fixture must discriminate: the start order is neither the code
        // order nor the due order, and every start date reached the view.
        $byCode = array_map(static fn ($row): string => $row->id, $records->tasks($actor, own: true)->sortBy('code')->values()->all());
        $byDue = $records->tasks($actor, own: true)->sortBy('code')->values()->all();
        usort($byDue, static fn (ProjectTaskView $a, ProjectTaskView $b): int => TextSort::compare($a->due_date?->format('Y-m-d'), $b->due_date?->format('Y-m-d')));
        expect($expectedIds)->not->toBe($direction === 'desc' ? array_reverse($byCode) : $byCode)
            ->and($expectedIds)->not->toBe(array_map(static fn ($row): string => $row->id, $direction === 'desc' ? array_reverse($byDue) : $byDue))
            ->and(array_map(static fn (ProjectTaskView $row): ?string => $row->start_date?->format('Y-m-d'), $records->tasks($actor, own: true)->sortBy('code')->values()->all()))->toBe($starts);
    }
    $actualIds = [];
    for ($page = 1; $page <= 4; $page++) {
        $result = $records->ownPage($actor, ['q' => '', 'status' => 'ALL', 'project' => 'ALL'], $sort, $direction, $page, 3);
        expect($result->total())->toBe(count($titles));
        array_push($actualIds, ...$result->getCollection()->pluck('id')->all());
    }
    expect($actualIds)->toBe($expectedIds);
})->with(['code', 'task', 'priority', 'start', 'due', 'status'])->with(['asc', 'desc']);

it('filters before paging and keeps unfiltered own summaries and scopes', function () {
    [$actor, $employee, $project] = workPagingFixture();
    for ($i = 1; $i <= 31; $i++) {
        $task = ProjectTask::create(['code' => sprintf('TSK-%03d', $i), 'project_id' => $project->id, 'title' => $i === 31 ? '100%_ready' : 'Other', 'status' => 'PENDING', 'priority' => 'NORMAL']);
        $task->syncAssignees([$employee->id], null);
    }
    ProjectTask::create(['code' => 'TSK-OTHER', 'project_id' => $project->id, 'title' => 'Not assigned to me', 'status' => 'PENDING', 'priority' => 'NORMAL']);
    $records = app(WorkRecords::class);
    $summary = $records->ownSummary($actor);
    expect($summary['total'])->toBe(31)->and($summary['counts']['todo'])->toBe(31);
    $result = $records->ownPage($actor, ['q' => '%_', 'status' => 'PENDING', 'project' => $project->id], 'code', 'asc', 99, 10);
    expect($result->total())->toBe(1)->and($result->currentPage())->toBe(1)->and($result->first()->title)->toBe('100%_ready');
    $result = $records->ownPage($actor, ['q' => 'PROJECT SEARCH', 'status' => 'ALL', 'project' => 'ALL'], 'code', 'asc', 99, 10);
    expect($result->total())->toBe(31)->and($result->currentPage())->toBe(4)->and($result->count())->toBe(1);
    expect($records->ownPage(Access::forGuest(), ['q' => '', 'status' => 'ALL', 'project' => 'ALL'], 'code', 'asc', 1, 10)->total())->toBe(0);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $page = $records->ownPage($actor, ['q' => '', 'status' => 'ALL', 'project' => 'ALL'], 'code', 'asc', 1, 10);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();
    expect($page)->toHaveCount(10);
    $taskReads = array_values(array_filter($queries, static fn (string $sql): bool => preg_match('/^select\s+[`"]?project_tasks[`"]?\.\*/i', $sql) === 1));
    expect($taskReads)->toHaveCount(1)->and(strtolower($taskReads[0]))->toContain('limit 10');
});

it('streams every filtered export batch and opens tasks outside the current page', function () {
    [$actor, $employee, $project] = workPagingFixture();
    $user = User::query()->where('employee_id', $employee->id)->firstOrFail();
    $this->actingAs($user);
    $last = null;
    for ($i = 0; $i < 105; $i++) {
        $last = ProjectTask::create(['code' => sprintf('TSK-%03d', $i), 'project_id' => $project->id, 'title' => 'Export '.($i === 104 ? '=formula' : $i), 'status' => 'PENDING', 'priority' => 'NORMAL']);
    }
    $filters = ['q' => '', 'status' => 'ALL', 'assignee' => 'ALL', 'priority' => 'ALL', 'queue' => 'ALL'];
    $tasks = ProjectsData::visibleTasks(array_values(app(WorkRecords::class)->tasks($actor, $project->id)->all()), collect(), $filters, 'code', 'desc', LocalClock::todayKey());
    $expected = Csv::build(['Code', 'Task', 'Assignees', 'Priority', 'Start date', 'Due date', 'Status'], array_map(fn ($task) => [$task->code, $task->title, ProjectsData::employeeName(null), 'Normal', '', '', 'Pending'], $tasks));
    $csv = $this->get('/projects/'.$project->id.'/tasks/export?sort=code&dir=desc')->assertOk()->streamedContent();
    expect($csv)->toBe($expected)->and(substr_count($csv, Csv::BOM))->toBe(1);
    Livewire::test(Show::class, ['project' => $project->id])->set('search', 'no-match')->call('openTask', $last->id)
        ->assertViewHas('visibleCount', 0)->assertViewHas('openTask', fn ($task) => $task->id === $last->id)->assertViewHas('taskCount', 105);

    for ($i = 2; $i <= 105; $i++) {
        Project::create(['code' => sprintf('PRJ-%03d', $i), 'name' => $i === 105 ? '=SUM(1,2)' : 'Project '.$i, 'status' => 'PLANNING', 'start_date' => '2026-09-01']);
    }
    $data = ProjectsData::projectsDataset($actor);
    $projects = ProjectsData::visibleProjects($data['projects'], $data['tasksByProject'], $data['accounts'], ['q' => '', 'status' => 'ALL', 'site' => 'ALL', 'manager' => 'ALL', 'queue' => 'ALL'], 'name', 'desc', LocalClock::todayKey());
    $expected = Csv::build(['Code', 'Project', 'Site', 'Task Leader', 'Start date', 'Due date', 'Progress', 'Health', 'Status'], array_map(fn ($row) => [$row->code, $row->name, '', '', '2026-09-01', '', '0%', 'On track', 'Planning'], $projects));
    expect($this->get('/projects/export?sort=name&dir=desc')->assertOk()->streamedContent())->toBe($expected);
});

it('keeps project task sorting filtering and aggregate policies equivalent to the collection path', function () {
    [$actor, $employee, $project] = workPagingFixture();
    $second = EmployeeProfile::create(['employee_code' => 'PAGE-SECOND', 'first_name' => 'Second', 'last_name' => 'Alpha', 'date_hired' => '2025-01-01', 'employment_status' => 'Regular', 'is_active' => true]);
    $third = EmployeeProfile::create(['employee_code' => 'PAGE-THIRD', 'first_name' => 'Third', 'last_name' => "\u{00C1}lvarez", 'date_hired' => '2025-01-01', 'employment_status' => 'Regular', 'is_active' => true]);
    for ($i = 0; $i < 18; $i++) {
        // Start dates cycle on 7 — out of step with every other column — with
        // repeats and blanks, so 'start' has its own order to get right.
        $task = ProjectTask::create(['code' => sprintf('TSK-%03d', $i), 'project_id' => $project->id, 'title' => ['Same', 'same', 'A', '', "\t ", '100%_ready', "\u{00C1}lvarez", "\u{0130}stanbul", "\u{039F}\u{03A3}", "A\u{0130}B"][$i % 10], 'status' => array_keys(TaskPolicy::STATUSES)[$i % 6], 'priority' => ['LOW', 'NORMAL', 'HIGH', 'URGENT', 'UNKNOWN'][$i % 5], 'start_date' => [null, '2026-09-05', '2026-09-03', '2026-09-05', null, '2026-09-10', '2026-09-03'][$i % 7], 'due_date' => [null, '2026-09-01', '2026-09-17', '2026-09-19', '2026-09-20'][$i % 5]]);
        $task->syncAssignees(match ($i % 4) {
            0 => [], 1 => [$employee->id], 2 => [$employee->id, $second->id], default => [$third->id]
        }, null);
    }
    $records = app(WorkRecords::class);
    $lists = app(WorkLists::class);
    $tasks = $records->tasks($actor, $project->id);
    $employees = $tasks->flatMap(fn ($task) => $task->assignees)->unique('id')->values();
    $filters = ['q' => '', 'status' => 'ALL', 'assignee' => 'ALL', 'priority' => 'ALL', 'queue' => 'ALL'];
    expect($tasks->filter(fn ($task) => $task->start_date === null)->count())->toBe(5)
        ->and($tasks->map(fn ($task) => $task->start_date?->format('Y-m-d'))->filter()->unique()->count())->toBe(3);
    foreach (['stack', 'code', 'task', 'assignee', 'priority', 'start', 'due', 'status'] as $sort) {
        foreach (['asc', 'desc'] as $direction) {
            $expected = ProjectsData::visibleTasks(array_values($tasks->all()), $employees, $filters, $sort, $direction, '2026-09-17');
            $ids = [];
            for ($page = 1; $page <= 3; $page++) {
                $result = $lists->taskPage($actor, $project->id, $filters, $sort, $direction, '2026-09-17', $page, 6);
                array_push($ids, ...$result->getCollection()->pluck('id')->all());
            }
            expect($ids)->toBe(array_map(fn ($row) => $row->id, $expected), $sort.' '.$direction);
        }
    }
    // The oracle agreeing with SQL proves nothing if 'start' fell back to
    // another column on both sides.
    $order = fn (string $sort, string $direction): array => $lists->taskPage($actor, $project->id, $filters, $sort, $direction, '2026-09-17', 1, 100)->getCollection()->pluck('id')->all();
    foreach (['asc', 'desc'] as $direction) {
        expect($order('start', $direction))->not->toBe($order('code', $direction))
            ->and($order('start', $direction))->not->toBe($order('due', $direction));
    }
    foreach ([['q' => '%_'], ['q' => "\u{00E1}LVAREZ"], ['q' => "\u{0130}"], ['q' => "\u{039F}\u{03A3}"], ['q' => "\u{03BF}\u{03C3}"], ['q' => 'ib'], ['status' => 'SUBMITTED'], ['priority' => 'UNKNOWN'], ['assignee' => 'UNASSIGNED'], ['assignee' => $second->id], ['queue' => 'REVIEW'], ['queue' => 'OVERDUE'], ['queue' => 'DUE_SOON'], ['queue' => 'UNASSIGNED']] as $filter) {
        $selected = array_replace($filters, $filter);
        $expected = ProjectsData::visibleTasks(array_values($tasks->all()), $employees, $selected, 'stack', 'asc', '2026-09-17');
        $actual = $lists->taskPage($actor, $project->id, $selected, 'stack', 'asc', '2026-09-17', 1, 100);
        expect($actual->getCollection()->pluck('id')->all())->toBe(array_map(fn ($row) => $row->id, $expected));
    }
    $view = $records->project($actor, $project->id);
    $summary = app(WorkSummary::class)->projects($actor, [$project->id], '2026-09-17')[$project->id];
    expect($summary->total)->toBe(18)
        ->and($summary->progress())->toBe(ProjectPolicy::progress(ProjectsData::taskShapes($tasks)))
        ->and($summary->health($view, '2026-09-17'))->toBe(ProjectPolicy::health(ProjectsData::projectShape($view), ProjectsData::taskShapes($tasks), '2026-09-17'));
    // The hand-set status question, for every current status, with and
    // without a Task Leader, on a project with open tasks, one whose tasks
    // are all closed and one with none: the aggregate's stand-in task must
    // answer as the full task list does. An unknown target is asked too.
    $closed = Project::create(['code' => 'PRJ-002', 'name' => 'All closed', 'status' => 'PLANNING', 'start_date' => '2026-09-01']);
    foreach (['COMPLETED', 'CANCELLED'] as $i => $status) {
        ProjectTask::create(['code' => sprintf('TSK-C%02d', $i), 'project_id' => $closed->id, 'title' => 'Closed', 'status' => $status, 'priority' => 'NORMAL']);
    }
    $empty = Project::create(['code' => 'PRJ-003', 'name' => 'No tasks', 'status' => 'PLANNING', 'start_date' => '2026-09-01']);
    $leader = User::factory()->create();
    $diverged = 0;
    foreach ([$project, $closed, $empty] as $row) {
        $rowTasks = $records->tasks($actor, $row->id);
        $totals = app(WorkSummary::class)->projects($actor, [$row->id], '2026-09-17')[$row->id];
        foreach ([null, $leader->id] as $managerId) {
            foreach (array_keys(ProjectPolicy::STATUSES) as $current) {
                $row->forceFill(['status' => $current, 'manager_id' => $managerId])->save();
                $rowView = $records->project($actor, $row->id);
                foreach ([...array_keys(ProjectPolicy::STATUSES), 'UNKNOWN'] as $next) {
                    $reference = ProjectPolicy::statusError(ProjectsData::projectShape($rowView), ProjectsData::taskShapes($rowTasks), $next);
                    $manual = ManualProjectStatus::error(ProjectsData::projectShape($rowView), ProjectsData::taskShapes($rowTasks), $next);
                    expect($totals->manualStatusError($rowView, $next))->toBe($manual, $row->code.' '.$current.' → '.$next.' (by hand)');
                    $diverged += $reference === $manual ? 0 : 1;
                }
            }
        }
    }
    // The hand-set map really differs from the reference one (a completed
    // project can be reopened), so the loop above does not merely restate
    // the reference map.
    expect($diverged)->toBeGreaterThan(0);
    $workload = app(WorkSummary::class)->workload($actor, $project->id, '2026-09-17');
    foreach ($workload as $row) {
        $assigned = $tasks->filter(fn ($task) => $task->hasAssignee($row['employee']->id));
        expect($row['active'])->toBe($assigned->filter(fn ($task) => ! TaskPolicy::isClosed($task->status))->count())
            ->and($row['overdue'])->toBe($assigned->filter(fn ($task) => TaskPolicy::isOverdue(ProjectsData::taskShape($task), '2026-09-17'))->count())
            ->and($row['review'])->toBe($assigned->where('status', 'SUBMITTED')->count());
    }
});

it('keeps project queues and sorting scoped while computing whole-scope counters', function () {
    [$actor, $employee] = workPagingFixture();
    $managers = [null, User::factory()->create(['name' => 'Alpha'])->id, User::factory()->create(['name' => 'alpha'])->id, User::factory()->create(['name' => 'Zebra'])->id];
    for ($i = 1; $i <= 15; $i++) {
        $project = Project::create(['code' => sprintf('PRJ-%03d', $i + 1), 'name' => ['same', 'Same', " \t", '100%_ready', 'Zulu'][$i % 5].' '.$i, 'status' => array_keys(ProjectPolicy::STATUSES)[$i % 5], 'start_date' => '2026-09-01', 'due_date' => [null, '2026-09-01', '2026-09-24', '2026-09-25'][$i % 4], 'manager_id' => $managers[$i % 4]]);
        for ($j = 0; $j < $i % 5; $j++) {
            $task = ProjectTask::create(['code' => sprintf('TSK-%03d-%d', $i, $j), 'project_id' => $project->id, 'title' => 'Task', 'status' => array_keys(TaskPolicy::STATUSES)[($i + $j) % 6], 'priority' => 'NORMAL', 'due_date' => [null, '2026-09-16', '2026-09-17', '2026-09-19', '2026-09-20'][($i + $j) % 5]]);
            if ($j % 2 === 1) {
                $task->syncAssignees([$employee->id], null);
            }
        }
    }
    $lists = app(WorkLists::class);
    $summary = app(WorkSummary::class);
    $filters = ['q' => '', 'status' => 'ALL', 'site' => 'ALL', 'manager' => 'ALL', 'queue' => 'ALL'];
    foreach ([$actor, Access::forActor(User::factory()->create(['employee_id' => $employee->id])), Access::forActor(User::factory()->create())] as $scope) {
        $data = ProjectsData::projectsDataset($scope);
        foreach (['code', 'name', 'manager', 'status'] as $sort) {
            foreach (['asc', 'desc'] as $direction) {
                $expected = ProjectsData::visibleProjects($data['projects'], $data['tasksByProject'], $data['accounts'], $filters, $sort, $direction, '2026-09-17');
                $actual = [];
                $page = 1;
                do {
                    $rows = $lists->projectPage($scope, $filters, $sort, $direction, '2026-09-17', $page++, 3);
                    array_push($actual, ...$rows->getCollection()->pluck('id')->all());
                } while ($rows->hasMorePages());
                expect($actual)->toBe(array_map(fn ($row) => $row->id, $expected));
            }
        }
        foreach ([['q' => '%_'], ['status' => 'ACTIVE'], ['manager' => (string) $managers[1]], ['queue' => 'REVIEW'], ['queue' => 'OVERDUE'], ['queue' => 'DUE_SOON'], ['queue' => 'UNASSIGNED']] as $filter) {
            $selected = array_replace($filters, $filter);
            $expected = ProjectsData::visibleProjects($data['projects'], $data['tasksByProject'], $data['accounts'], $selected, 'code', 'asc', '2026-09-17');
            $actual = $lists->projectPage($scope, $selected, 'code', 'asc', '2026-09-17', 1, 100);
            expect($actual->getCollection()->pluck('id')->all())->toBe(array_map(fn ($row) => $row->id, $expected));
        }
        $totals = $summary->projects($scope, array_values($data['projects']->map(static fn (ProjectView $project): string => $project->id)->all()), '2026-09-17');
        foreach ($data['projects'] as $project) {
            $tasks = $data['tasksByProject'][$project->id] ?? [];
            expect($totals[$project->id]->progress())->toBe(ProjectPolicy::progress(ProjectsData::taskShapes($tasks)))
                ->and($totals[$project->id]->health($project, '2026-09-17'))->toBe(ProjectPolicy::health(ProjectsData::projectShape($project), ProjectsData::taskShapes($tasks), '2026-09-17'));
        }
        $counters = $summary->counters($scope, '2026-09-17');
        expect($counters['active'])->toBe($data['projects']->where('status', 'ACTIVE')->count())
            ->and($counters['review'])->toBe($data['tasks']->where('status', 'SUBMITTED')->count())
            ->and($counters['overdue'])->toBe($data['tasks']->filter(fn ($task) => TaskPolicy::isOverdue(ProjectsData::taskShape($task), '2026-09-17'))->count())
            ->and($counters['unassigned'])->toBe($data['tasks']->filter(fn ($task) => $task->assigneeIds() === [])->count());
    }
});

it('preserves international searches across narrow scan batches and joined own-task reads', function () {
    [$actor, $employee, $project] = workPagingFixture();
    $last = null;
    for ($i = 0; $i < 501; $i++) {
        $last = ProjectTask::create(['code' => sprintf('TSK-%03d', $i), 'project_id' => $project->id, 'title' => "\u{0130}stanbul ".$i, 'status' => 'PENDING', 'priority' => 'NORMAL']);
    }
    $last->syncAssignees([$employee->id], null);
    ProjectTask::create(['code' => 'TSK-ASCII', 'project_id' => $project->id, 'title' => 'Istanbul', 'status' => 'PENDING', 'priority' => 'NORMAL']);
    $page = app(WorkLists::class)->taskPage($actor, $project->id, ['q' => "\u{0130}", 'status' => 'ALL', 'assignee' => 'ALL', 'priority' => 'ALL', 'queue' => 'ALL'], 'code', 'asc', '2026-09-17', 6, 100);
    expect($page->total())->toBe(501)->and($page->count())->toBe(1)->and($page->first()->id)->toBe($last->id);
    $own = app(WorkRecords::class)->ownPage($actor, ['q' => "\u{0130}", 'status' => 'ALL', 'project' => 'ALL'], 'code', 'asc', 1, 20);
    expect($own->total())->toBe(1)->and($own->first()->id)->toBe($last->id);
});
