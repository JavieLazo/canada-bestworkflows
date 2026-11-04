<?php

declare(strict_types=1);

use App\Domain\Projects\TaskPolicy;
use App\Livewire\Projects\ProjectsData;
use App\Livewire\Tasks\MyTasksPage;
use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\WorkManagement\Application\Queries\WorkLists;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView;
use App\Modules\WorkManagement\Contracts\Views\ProjectView;
use App\Modules\WorkManagement\Contracts\Views\TaskResponseView;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Support\Access;
use App\Support\TextSort;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * INDIVIDUAL responses change what the task reads count, never how many
 * statements they take. The per-task response facts ride inside the task
 * select — on every task read, the paged project table included — My Tasks
 * reads the member's OWN status through one joined page select and shows,
 * filters and sorts by that same status, and responses() stays at three
 * queries whatever the crew size.
 * Every review read — the REVIEW queue on both lists, the stack order, the
 * project totals, the counters and each member's workload — says exactly
 * what the collection path (ProjectsData, i.e. ProjectTaskView::awaitsReview)
 * says, and a task that was cancelled with a response still submitted waits
 * on nobody.
 */

/** @param list<string> $modules module codes, one RoleModuleGrant each */
function treadUser(array $modules, bool $allData, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Tread '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
}

function treadEmployee(string $first): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'COP-'.Str::upper(Str::random(6)),
        'first_name' => $first,
        'last_name' => 'Lim',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

function treadProject(string $code): Project
{
    $project = new Project;
    $project->forceFill(['code' => $code, 'name' => 'Project '.$code, 'status' => 'ACTIVE', 'start_date' => '2026-09-01'])->save();

    return $project;
}

/**
 * A task and its members' own response rows. `$crew` maps each member to
 * their row's status, or null for a member with no row. Rows are written
 * directly, so any mix of states is one line of fixture.
 *
 * @param  array<string, array{0: EmployeeProfile, 1: string|null}>  $crew
 */
function treadTask(Project $project, string $code, string $mode, string $status, array $crew, ?string $due = null): ProjectTask
{
    $task = new ProjectTask;
    $task->forceFill([
        'code' => $code,
        'project_id' => $project->getKey(),
        'title' => 'Task '.$code,
        'status' => $status,
        'priority' => 'NORMAL',
        'submission_mode' => $mode,
        'due_date' => $due,
    ])->save();
    $task->syncAssignees(array_map(static fn (array $member): string => $member[0]->id, array_values($crew)), null);
    foreach ($crew as [$member, $rowStatus]) {
        if ($rowStatus !== null) {
            $row = new ProjectTaskSubmission;
            $row->forceFill([
                'task_id' => $task->id,
                'employee_id' => $member->id,
                'status' => $rowStatus,
                'remarks' => 'Response from '.$member->first_name,
                'submitted_at' => CarbonImmutable::now()->utc(),
                'revision' => 1,
            ])->save();
        }
    }

    return $task;
}

/** A file row with no bytes behind it — the reads below never open one. */
function treadFile(ProjectTask $task, string $kind, ?EmployeeProfile $member): void
{
    $file = new ProjectTaskFile;
    $file->fill(['task_id' => $task->id, 'kind' => $kind, 'file_name' => strtolower($kind).'.pdf', 'mime' => 'application/pdf', 'size' => 10, 'path' => 'task-files/'.Str::random(12).'.pdf']);
    $file->employee_id = $member?->id;
    $file->save();
}

/**
 * My Tasks for Ana: each entry is [mode, task status, Ana's row, Ben's row,
 * the status Ana must see]. Ben's rows prove a PEER's response never leaks
 * into Ana's own status.
 *
 * @return array<string, array{0: string, 1: string, 2: string|null, 3: string|null, 4: string}>
 */
function treadOwnSpec(): array
{
    return [
        'none' => ['INDIVIDUAL', 'IN_PROGRESS', null, 'APPROVED', 'IN_PROGRESS'],
        'submitted' => ['INDIVIDUAL', 'IN_PROGRESS', 'SUBMITTED', null, 'SUBMITTED'],
        'approved' => ['INDIVIDUAL', 'IN_PROGRESS', 'APPROVED', 'SUBMITTED', 'APPROVED'],
        'returned' => ['INDIVIDUAL', 'IN_PROGRESS', 'RETURNED', 'APPROVED', 'RETURNED'],
        'cancelled' => ['INDIVIDUAL', 'CANCELLED', 'SUBMITTED', null, 'CANCELLED'],
        'completed' => ['INDIVIDUAL', 'COMPLETED', 'APPROVED', 'APPROVED', 'COMPLETED'],
        'pending' => ['INDIVIDUAL', 'PENDING', null, null, 'PENDING'],
        'groupSubmitted' => ['GROUP', 'SUBMITTED', null, null, 'SUBMITTED'],
        'groupReturned' => ['GROUP', 'RETURNED', null, null, 'RETURNED'],
        'groupPending' => ['GROUP', 'PENDING', null, null, 'PENDING'],
        'groupProgress' => ['GROUP', 'IN_PROGRESS', null, null, 'IN_PROGRESS'],
    ];
}

/**
 * Ana's eleven tasks from treadOwnSpec(), shared with Ben, plus one task
 * that is Ben's alone. Ana signs in with TASKS and no all_data.
 *
 * @return array{actor: Access, user: User, tasks: array<string, ProjectTask>}
 */
function treadOwnFixture(): array
{
    $ana = treadEmployee('Ana');
    $ben = treadEmployee('Ben');
    $project = treadProject('PRJ-001');
    $tasks = [];
    $i = 0;
    foreach (treadOwnSpec() as $name => [$mode, $status, $anaRow, $benRow]) {
        $tasks[$name] = treadTask($project, sprintf('TSK-%03d', ++$i), $mode, $status, ['ana' => [$ana, $anaRow], 'ben' => [$ben, $benRow]]);
    }
    treadTask($project, 'TSK-900', 'INDIVIDUAL', 'IN_PROGRESS', ['ben' => [$ben, 'APPROVED']]);
    $user = treadUser(['TASKS'], false, $ana->id);

    return ['actor' => Access::forActor($user), 'user' => $user, 'tasks' => $tasks];
}

/**
 * A mixed-mode portfolio: project code => task code => [mode, status, due
 * date, member => that member's row status or null].
 *
 * @return array<string, array<string, array{0: string, 1: string, 2: string|null, 3: array<string, string|null>}>>
 */
function treadMixedSpec(): array
{
    return [
        'PRJ-001' => [
            'TSK-101' => ['GROUP', 'SUBMITTED', '2026-09-20', ['ana' => null, 'ben' => null]],
            'TSK-102' => ['INDIVIDUAL', 'IN_PROGRESS', '2026-09-18', ['ana' => 'SUBMITTED', 'ben' => 'APPROVED', 'cara' => null]],
            'TSK-103' => ['INDIVIDUAL', 'IN_PROGRESS', '2026-09-16', ['ana' => 'APPROVED', 'ben' => 'RETURNED']],
            'TSK-104' => ['GROUP', 'IN_PROGRESS', null, ['cara' => null]],
            'TSK-105' => ['INDIVIDUAL', 'PENDING', '2026-09-19', ['ben' => null, 'cara' => null]],
            'TSK-106' => ['INDIVIDUAL', 'COMPLETED', null, ['ana' => 'APPROVED', 'cara' => 'APPROVED']],
            'TSK-107' => ['INDIVIDUAL', 'CANCELLED', '2026-09-17', ['ana' => 'SUBMITTED', 'ben' => null]],
            'TSK-108' => ['GROUP', 'RETURNED', '2026-09-21', ['ben' => null]],
            'TSK-109' => ['INDIVIDUAL', 'IN_PROGRESS', '2026-09-18', ['ben' => 'SUBMITTED', 'cara' => 'RETURNED']],
        ],
        'PRJ-002' => ['TSK-201' => ['INDIVIDUAL', 'IN_PROGRESS', null, ['cara' => 'SUBMITTED', 'ben' => null]]],
        'PRJ-003' => ['TSK-301' => ['INDIVIDUAL', 'IN_PROGRESS', null, ['ana' => 'APPROVED', 'cara' => 'RETURNED']]],
        'PRJ-004' => ['TSK-401' => ['INDIVIDUAL', 'CANCELLED', null, ['ana' => 'SUBMITTED', 'cara' => 'SUBMITTED']]],
        'PRJ-005' => ['TSK-501' => ['GROUP', 'SUBMITTED', null, ['ben' => null]]],
    ];
}

/**
 * treadMixedSpec() in the database, with three readers: a PROJECTS manager
 * off every crew, and Ana and Cara reading their own tasks under PROJECTS.
 *
 * @return array{scopes: array<string, Access>, employees: array<string, EmployeeProfile>, projects: array<string, Project>, tasks: array<string, ProjectTask>}
 */
function treadMixedFixture(): array
{
    $employees = ['ana' => treadEmployee('Ana'), 'ben' => treadEmployee('Ben'), 'cara' => treadEmployee('Cara')];
    $projects = [];
    $tasks = [];
    foreach (treadMixedSpec() as $projectCode => $specs) {
        $projects[$projectCode] = treadProject($projectCode);
        foreach ($specs as $code => [$mode, $status, $due, $crew]) {
            $members = [];
            foreach ($crew as $name => $rowStatus) {
                $members[$name] = [$employees[$name], $rowStatus];
            }
            $tasks[$code] = treadTask($projects[$projectCode], $code, $mode, $status, $members, $due);
        }
    }

    return [
        'scopes' => [
            'reviewer' => Access::forActor(treadUser(['PROJECTS'], true)),
            'ana' => Access::forActor(treadUser(['PROJECTS', 'TASKS'], false, $employees['ana']->id)),
            'cara' => Access::forActor(treadUser(['PROJECTS', 'TASKS'], false, $employees['cara']->id)),
        ],
        'employees' => $employees,
        'projects' => $projects,
        'tasks' => $tasks,
    ];
}

/**
 * @param  iterable<int, ProjectTaskView|ProjectView>  $rows
 * @return list<string>
 */
function treadCodes(iterable $rows): array
{
    $codes = [];
    foreach ($rows as $row) {
        $codes[] = $row->code;
    }

    return $codes;
}

/*
|--------------------------------------------------------------------------
| Read budgets
|--------------------------------------------------------------------------
*/

it('reads the response facts inside the task select within the batch budget', function (int $count) {
    $ana = treadEmployee('Ana');
    $ben = treadEmployee('Ben');
    $cara = treadEmployee('Cara');
    $project = treadProject('PRJ-001');
    for ($i = 1; $i <= $count; $i++) {
        $task = treadTask($project, sprintf('TSK-%03d', $i), 'INDIVIDUAL', 'IN_PROGRESS', ['ana' => [$ana, 'SUBMITTED'], 'ben' => [$ben, 'APPROVED'], 'cara' => [$cara, null]]);
        // Odd tasks carry evidence; even ones only a brief, which does not count.
        treadFile($task, $i % 2 === 1 ? 'SUBMISSION' : 'BRIEF', $i % 2 === 1 ? $ana : null);
    }
    $actor = Access::forActor(treadUser(['PROJECTS'], true));

    DB::enableQueryLog();
    DB::flushQueryLog();
    $rows = app(WorkRecords::class)->tasks($actor);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($rows)->toHaveCount($count)->and($queries)->toBeLessThanOrEqual(5);
    foreach ($rows->values() as $i => $row) {
        expect($row->submission_mode)->toBe('INDIVIDUAL')
            ->and($row->isIndividual())->toBeTrue()
            ->and($row->responses_submitted)->toBe(1)
            ->and($row->responses_approved)->toBe(1)
            ->and($row->responses_returned)->toBe(0)
            ->and($row->responsesTotal())->toBe(2)
            ->and($row->has_submission_files)->toBe($i % 2 === 0)
            ->and($row->awaitsReview())->toBeTrue()
            ->and($row->responseSummary())->toBe('1 of 3 approved · 1 awaiting review · 1 not submitted');
    }
})->with(['one task' => 1, 'one hundred tasks' => 100]);

it('reads every member response in at most three queries', function (int $members) {
    $project = treadProject('PRJ-001');
    $crew = [];
    for ($i = 0; $i < $members; $i++) {
        $crew['m'.$i] = [treadEmployee('Member'.$i), $i % 2 === 0 ? 'SUBMITTED' : null];
    }
    $task = treadTask($project, 'TSK-001', 'INDIVIDUAL', 'IN_PROGRESS', $crew);
    $actor = Access::forActor(treadUser(['PROJECTS'], true));

    DB::enableQueryLog();
    DB::flushQueryLog();
    $responses = app(WorkRecords::class)->responses($actor, $task->id);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(3)
        ->and($responses)->toHaveCount($members)
        ->and($responses->filter(static fn (TaskResponseView $r): bool => $r->status === 'SUBMITTED'))->toHaveCount(intdiv($members + 1, 2))
        ->and($responses->filter(static fn (TaskResponseView $r): bool => $r->status === TaskSubmissionPolicy::NONE))->toHaveCount(intdiv($members, 2));
})->with(['two members' => 2, 'forty members' => 40]);

/*
|--------------------------------------------------------------------------
| My Tasks — the member's own status
|--------------------------------------------------------------------------
*/

it('pages own tasks in one select and reads each member own status', function () {
    ['actor' => $actor, 'tasks' => $tasks] = treadOwnFixture();
    $records = app(WorkRecords::class);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $page = $records->ownPage($actor, ['q' => '', 'status' => 'ALL', 'project' => 'ALL'], 'code', 'asc', 1, 10);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();
    $taskReads = array_values(array_filter($queries, static fn (string $sql): bool => str_contains(strtolower($sql), 'select') && str_contains($sql, 'project_tasks') && ! str_contains(strtolower($sql), 'count(')));
    expect($page)->toHaveCount(10)->and($page->total())->toBe(11)
        ->and($taskReads)->toHaveCount(1)->and(strtolower($taskReads[0]))->toContain('limit 10');

    $seen = [];
    foreach ($records->ownPage($actor, ['q' => '', 'status' => 'ALL', 'project' => 'ALL'], 'code', 'asc', 1, 100) as $row) {
        $seen[$row->id] = $row->my_status;
    }
    foreach (treadOwnSpec() as $name => [$mode, $status, $anaRow, , $expected]) {
        expect($seen[$tasks[$name]->id] ?? null)->toBe($expected, $name)
            // The SQL and its PHP twin agree, NONE (no row) included.
            ->and(TaskSubmissionPolicy::myStatus($mode, $status, $anaRow ?? TaskSubmissionPolicy::NONE))->toBe($expected, $name);
    }
    expect(count($seen))->toBe(11);

    $approved = $records->ownPage($actor, ['q' => '', 'status' => 'APPROVED', 'project' => 'ALL'], 'code', 'asc', 1, 10)->first();
    expect($approved?->myStatusLabel())->toBe('Approved')
        ->and($approved?->myStatusPill())->toBe('pill pill-success-strong')
        // The task itself is still in progress; only Ana's view of it moved.
        ->and($approved?->status)->toBe('IN_PROGRESS');
});

it('filters own tasks by the member own status', function (string $status, array $names) {
    ['actor' => $actor, 'tasks' => $tasks] = treadOwnFixture();

    $page = app(WorkRecords::class)->ownPage($actor, ['q' => '', 'status' => $status, 'project' => 'ALL'], 'code', 'asc', 1, 100);
    expect($page->getCollection()->pluck('id')->all())->toBe(array_map(static fn (string $name): string => $tasks[$name]->id, $names))
        ->and($page->total())->toBe(count($names));
})->with([
    'approved' => ['APPROVED', ['approved']],
    'returned' => ['RETURNED', ['returned', 'groupReturned']],
    'submitted' => ['SUBMITTED', ['submitted', 'groupSubmitted']],
    'in progress, no response yet' => ['IN_PROGRESS', ['none', 'groupProgress']],
    'cancelled with a response left' => ['CANCELLED', ['cancelled']],
    'pending' => ['PENDING', ['pending', 'groupPending']],
    'completed' => ['COMPLETED', ['completed']],
]);

it('sorts own tasks by the member own status label', function (string $direction) {
    ['actor' => $actor, 'tasks' => $tasks] = treadOwnFixture();
    $expectedStatus = [];
    foreach (treadOwnSpec() as $name => $spec) {
        $expectedStatus[$tasks[$name]->id] = $spec[4];
    }
    // treadOwnFixture() numbers the codes in spec order, so this starts in
    // code order; then a stable sort by label — the comparator
    // WorkPaginationTest pins for the task status, over the member's status.
    $ids = array_keys($expectedStatus);
    usort($ids, static fn (string $a, string $b): int => TextSort::compare(TaskSubmissionPolicy::myStatusLabel($expectedStatus[$a]), TaskSubmissionPolicy::myStatusLabel($expectedStatus[$b])));
    if ($direction === 'desc') {
        $ids = array_reverse($ids);
    }

    $actual = [];
    for ($page = 1; $page <= 3; $page++) {
        array_push($actual, ...app(WorkRecords::class)->ownPage($actor, ['q' => '', 'status' => 'ALL', 'project' => 'ALL'], 'status', $direction, $page, 4)->getCollection()->pluck('id')->all());
    }
    expect($actual)->toBe($ids);
})->with(['asc', 'desc']);

it('counts the My Tasks tiles by the member own status', function () {
    ['actor' => $actor] = treadOwnFixture();

    $summary = app(WorkRecords::class)->ownSummary($actor);
    expect($summary['total'])->toBe(11)
        ->and($summary['counts'])->toBe([
            'todo' => 4,      // pending, groupPending, none, groupProgress
            'awaiting' => 3,  // submitted, groupSubmitted, and the approved response
            'returned' => 2,  // returned, groupReturned
            'completed' => 1,
        ]);
});

it('shows filters and labels My Tasks rows by the member own status', function () {
    ['user' => $user, 'tasks' => $tasks] = treadOwnFixture();
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    // Ana's response on TSK-002 is submitted while the task is in progress:
    // the row answers the Submitted filter and says Submitted, not In Progress.
    Livewire::test(MyTasksPage::class)
        ->set('status', 'SUBMITTED')
        ->assertSee($tasks['submitted']->code)
        ->assertSee($tasks['groupSubmitted']->code)
        ->assertDontSee($tasks['none']->code)
        ->assertSeeHtml('<span class="pill pill-success">Submitted</span>')
        ->assertDontSeeHtml('<span class="pill pill-primary">In Progress</span>')
        // The approved response the Awaiting review tile counts has a filter
        // option of its own, and its row says Approved.
        ->assertSeeHtml('value="APPROVED"')
        ->set('status', 'APPROVED')
        ->assertSee($tasks['approved']->code)
        ->assertDontSee($tasks['submitted']->code)
        ->assertSeeHtml('<span class="pill pill-success-strong">Approved</span>')
        ->assertDontSeeHtml('<span class="pill pill-primary">In Progress</span>');
});

/*
|--------------------------------------------------------------------------
| Review reads — SQL equals the collection path
|--------------------------------------------------------------------------
*/

it('keeps the SQL review queue totals counters and stack equal to the collection path', function () {
    $f = treadMixedFixture();
    $lists = app(WorkLists::class);
    $summary = app(WorkSummary::class);
    $today = '2026-09-17';
    $projectFilters = ['q' => '', 'status' => 'ALL', 'site' => 'ALL', 'manager' => 'ALL', 'queue' => 'REVIEW'];
    $taskFilters = ['q' => '', 'status' => 'ALL', 'assignee' => 'ALL', 'priority' => 'ALL', 'queue' => 'ALL'];

    foreach ($f['scopes'] as $scopeName => $scope) {
        $data = ProjectsData::projectsDataset($scope);
        $expected = ProjectsData::visibleProjects($data['projects'], $data['tasksByProject'], $data['accounts'], $projectFilters, 'code', 'asc', $today);
        expect(treadCodes($lists->projectPage($scope, $projectFilters, 'code', 'asc', $today, 1, 100)->getCollection()))
            ->toBe(treadCodes($expected), $scopeName.' project REVIEW queue');

        $projectIds = array_values($data['projects']->map(static fn (ProjectView $project): string => $project->id)->all());
        $totals = $summary->projects($scope, $projectIds, $today);
        foreach ($data['projects'] as $project) {
            $tasks = $data['tasksByProject'][$project->id] ?? [];
            $employees = collect($tasks)->flatMap(static fn (ProjectTaskView $task) => $task->assignees)->unique('id')->values();
            foreach ([['queue' => 'REVIEW'], []] as $filter) {
                $selected = array_replace($taskFilters, $filter);
                foreach (['asc', 'desc'] as $direction) {
                    $oracle = ProjectsData::visibleTasks($tasks, $employees, $selected, 'stack', $direction, $today);
                    expect(treadCodes($lists->taskPage($scope, $project->id, $selected, 'stack', $direction, $today, 1, 100)->getCollection()))
                        ->toBe(treadCodes($oracle), $scopeName.' '.$project->code.' '.($filter['queue'] ?? 'ALL').' stack '.$direction);
                }
            }
            expect($totals[$project->id]->review)
                ->toBe(count(array_filter($tasks, static fn (ProjectTaskView $task): bool => $task->awaitsReview())), $scopeName.' '.$project->code.' totals');
        }
        expect($summary->counters($scope, $today)['review'])
            ->toBe($data['tasks']->filter(static fn (ProjectTaskView $task): bool => $task->awaitsReview())->count(), $scopeName.' counters');
    }

    // What the two paths agree on, spelled out for the reviewer.
    $reviewer = $f['scopes']['reviewer'];
    $p1 = $f['projects']['PRJ-001']->id;
    expect(treadCodes($lists->projectPage($reviewer, $projectFilters, 'code', 'asc', $today, 1, 100)->getCollection()))->toBe(['PRJ-001', 'PRJ-002', 'PRJ-005'])
        ->and(treadCodes($lists->taskPage($reviewer, $p1, array_replace($taskFilters, ['queue' => 'REVIEW']), 'stack', 'asc', $today, 1, 100)->getCollection()))->toBe(['TSK-102', 'TSK-109', 'TSK-101'])
        // An individual task waiting on review heads the stack as SUBMITTED;
        // the cancelled one with a response left in it sorts as cancelled.
        ->and(treadCodes($lists->taskPage($reviewer, $p1, $taskFilters, 'stack', 'asc', $today, 1, 100)->getCollection()))
        ->toBe(['TSK-102', 'TSK-109', 'TSK-101', 'TSK-108', 'TSK-103', 'TSK-104', 'TSK-105', 'TSK-106', 'TSK-107'])
        ->and($summary->projects($reviewer, [$p1], $today)[$p1]->review)->toBe(3)
        ->and($summary->counters($reviewer, $today)['review'])->toBe(5);
});

it('counts awaiting review per member in the workload', function () {
    $f = treadMixedFixture();

    // Derived from the seeded rows, not from another read path: a member
    // waits when the task was handed in as a group, or when the task is
    // individual, in progress and THEIR OWN row is submitted.
    $expected = array_fill_keys(array_keys($f['employees']), 0);
    foreach (treadMixedSpec()['PRJ-001'] as [$mode, $status, , $crew]) {
        foreach ($crew as $name => $rowStatus) {
            if ($status === 'SUBMITTED' || ($mode === 'INDIVIDUAL' && $status === 'IN_PROGRESS' && $rowStatus === 'SUBMITTED')) {
                $expected[$name]++;
            }
        }
    }
    expect($expected)->toBe(['ana' => 2, 'ben' => 2, 'cara' => 0]);

    $names = array_flip(array_map(static fn (EmployeeProfile $e): string => $e->id, $f['employees']));
    $actual = [];
    foreach (app(WorkSummary::class)->workload($f['scopes']['reviewer'], $f['projects']['PRJ-001']->id, '2026-09-17') as $row) {
        $actual[$names[$row['employee']->id]] = $row['review'];
    }
    ksort($actual);
    expect($actual)->toBe($expected);
});

it('carries the response facts on every paged task row, as on the task reads', function () {
    $f = treadMixedFixture();
    treadFile($f['tasks']['TSK-102'], 'SUBMISSION', $f['employees']['ana']);
    treadFile($f['tasks']['TSK-107'], 'BRIEF', null);
    $reviewer = $f['scopes']['reviewer'];
    $p1 = $f['projects']['PRJ-001']->id;
    $filters = ['q' => '', 'status' => 'ALL', 'assignee' => 'ALL', 'priority' => 'ALL', 'queue' => 'ALL'];
    $facts = static fn (ProjectTaskView $t): array => [
        $t->responses_submitted, $t->responses_approved, $t->responses_returned,
        $t->has_submission_files, $t->awaitsReview(), $t->responseSummary(),
    ];

    // The project task table and the export build their rows through
    // tasksByIds(); tasks() is the other task read. They must agree.
    $paged = [];
    foreach (app(WorkLists::class)->taskPage($reviewer, $p1, $filters, 'stack', 'asc', '2026-09-17', 1, 100)->getCollection() as $row) {
        $paged[$row->code] = $facts($row);
    }
    $read = [];
    foreach (app(WorkRecords::class)->tasks($reviewer, $p1) as $row) {
        $read[$row->code] = $facts($row);
    }
    ksort($paged);
    expect($paged)->toHaveCount(9)->and($paged)->toBe($read)
        ->and($paged['TSK-102'])->toBe([1, 1, 0, true, true, '1 of 3 approved · 1 awaiting review · 1 not submitted'])
        // Cancelled with Ana's response still submitted: nobody reviews it,
        // and a brief is not evidence.
        ->and($paged['TSK-107'])->toBe([1, 0, 0, false, false, '0 of 2 approved · 1 submitted · 1 not submitted']);

    $ids = array_values(array_map(static fn (ProjectTask $t): string => $t->id, $f['tasks']));
    DB::enableQueryLog();
    DB::flushQueryLog();
    $rows = app(WorkRecords::class)->tasksByIds($reviewer, $ids);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($rows)->toHaveCount(count($ids))->and($queries)->toBeLessThanOrEqual(5);
});

it('leaves a cancelled individual task with a submitted response out of every review read', function () {
    $ana = treadEmployee('Ana');
    $ben = treadEmployee('Ben');
    $project = treadProject('PRJ-001');
    $cancelled = treadTask($project, 'TSK-001', 'INDIVIDUAL', 'CANCELLED', ['ana' => [$ana, 'SUBMITTED'], 'ben' => [$ben, 'APPROVED']], '2026-09-10');
    treadTask($project, 'TSK-002', 'GROUP', 'PENDING', ['ana' => [$ana, null]], '2026-09-30');
    $reviewer = Access::forActor(treadUser(['PROJECTS'], true));
    $member = Access::forActor(treadUser(['TASKS'], false, $ana->id));
    $lists = app(WorkLists::class);
    $summary = app(WorkSummary::class);
    $today = '2026-09-17';
    $review = ['q' => '', 'status' => 'ALL', 'assignee' => 'ALL', 'priority' => 'ALL', 'queue' => 'REVIEW'];

    $view = app(WorkRecords::class)->task($reviewer, $cancelled->id);
    expect($view?->responses_submitted)->toBe(1)->and($view?->awaitsReview())->toBeFalse()
        ->and($view?->responseSummary())->toBe('1 of 2 approved · 1 submitted')
        ->and($lists->taskPage($reviewer, $project->id, $review, 'stack', 'asc', $today, 1, 100)->total())->toBe(0)
        ->and($lists->projectPage($reviewer, ['q' => '', 'status' => 'ALL', 'site' => 'ALL', 'manager' => 'ALL', 'queue' => 'REVIEW'], 'code', 'asc', $today, 1, 100)->total())->toBe(0)
        ->and($summary->projects($reviewer, [$project->id], $today)[$project->id]->review)->toBe(0)
        ->and($summary->counters($reviewer, $today)['review'])->toBe(0)
        ->and(treadCodes($lists->taskPage($reviewer, $project->id, array_replace($review, ['queue' => 'ALL']), 'stack', 'asc', $today, 1, 100)->getCollection()))->toBe(['TSK-002', 'TSK-001'])
        ->and(TaskPolicy::stackRank(TaskSubmissionPolicy::stackStatus('INDIVIDUAL', 'CANCELLED', 1)))->toBe(TaskPolicy::stackRank('CANCELLED'));
    foreach ($summary->workload($reviewer, $project->id, $today) as $row) {
        expect($row['review'])->toBe(0);
    }

    // Ana sees the task as cancelled, not as her response waiting on review.
    $own = app(WorkRecords::class)->ownPage($member, ['q' => '', 'status' => 'ALL', 'project' => 'ALL'], 'code', 'asc', 1, 10);
    expect($own->first()?->my_status)->toBe('CANCELLED')
        ->and(app(WorkRecords::class)->ownPage($member, ['q' => '', 'status' => 'SUBMITTED', 'project' => 'ALL'], 'code', 'asc', 1, 10)->total())->toBe(0)
        ->and(app(WorkRecords::class)->ownSummary($member)['counts']['awaiting'])->toBe(0);

    // Ana's response itself does not await review either, for the reviewer
    // or for her.
    foreach ([[$reviewer, false], [$member, true]] as [$reader, $own]) {
        $entry = app(WorkRecords::class)->responses($reader, $cancelled->id, $own)->get($ana->id);
        assert($entry instanceof TaskResponseView);
        expect($entry->status)->toBe('SUBMITTED')
            ->and($entry->task_status)->toBe('CANCELLED')
            ->and(TaskSubmissionPolicy::responseAwaitsReview($entry->task_status, $entry->status))->toBeFalse();
    }
});
