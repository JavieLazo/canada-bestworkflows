<?php

declare(strict_types=1);

use App\Livewire\Projects\Index;
use App\Livewire\Projects\ProjectsData;
use App\Livewire\Projects\Show;
use App\Livewire\Tasks\MyTasksPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\WorkManagement\Application\Commands\CreateTask;
use App\Modules\WorkManagement\Application\Commands\UpdateProject;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskDetails;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Application\TaskDetails;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * A task's PLANNED start date (DELIBERATE DIVERGENCE — the reference
 * task has a due date only). It is optional and typed by hand; the
 * commands keep it a real 'Y-m-d' day, inside the project's window and on or
 * before the task's own due date (TaskSchedule), and write nothing when they
 * refuse. CreateTask stores it and names it in the TASK_ASSIGNED bell, and
 * takes no task at all on a COMPLETED or CANCELLED project. UpdateTaskDetails
 * treats a null start as untouched and '' as a clear, checks the start and
 * due date as the pair that will stand, and audits `start_date` as a changed
 * field; the review panel posts the start only when the manager changed it,
 * so a start moved underneath an open dialog survives an unrelated save.
 * UpdateProject never strands a task's start outside the project window.
 * The date reaches the tasks CSV (before the due date), both task tables and
 * their sorts, and both task dialogs. And the new-project dialog keeps a
 * start date — today — after a successful create resets it.
 */

/** @param list<string> $modules module codes, one RoleModuleGrant each */
function tstartRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Tstart '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/**
 * Signs a fresh account in and drops any cached actor, so the next command,
 * Livewire::test() or request sees this one.
 *
 * @param  list<string>  $modules  module codes for the actor's fresh role
 */
function tstartActor(array $modules, bool $allData, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => tstartRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

/** A signed-in all-data Projects manager, as the Access the commands take. */
function tstartManager(): Access
{
    return Access::forActor(tstartActor(['PROJECTS'], allData: true));
}

function tstartEmployee(string $code, string $first, string $last): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => $first,
        'last_name' => $last,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/** An active login for an employee, in a role that only holds TASKS — never signed in here. */
function tstartAccountFor(EmployeeProfile $employee): User
{
    return User::factory()->create([
        'role_id' => tstartRole(['TASKS'], false)->getKey(),
        'employee_id' => $employee->id,
    ]);
}

/** @param array<string, mixed> $attributes column overrides, merged over the defaults */
function tstartProject(array $attributes = []): Project
{
    static $n = 0;
    $n++;

    $project = new Project;
    $project->forceFill(array_merge([
        'code' => sprintf('PRJ-%03d', 900 + $n),
        'name' => 'Start date project '.Str::random(6),
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
    ], $attributes))->save();

    return $project;
}

/**
 * `assignee_ids` is the one non-column key: the crew goes on through the
 * pivot after the row exists (ProjectTask::syncAssignees()).
 *
 * @param  array{assignee_ids?: list<string>, ...<string, mixed>}  $attributes  column overrides plus the optional pivot key
 */
function tstartTask(Project $project, array $attributes = []): ProjectTask
{
    static $n = 0;
    $n++;

    $assigneeIds = $attributes['assignee_ids'] ?? [];
    unset($attributes['assignee_ids']);

    $task = new ProjectTask;
    $task->forceFill(array_merge([
        'code' => sprintf('TSK-%03d', 900 + $n),
        'project_id' => $project->id,
        'title' => 'Task '.Str::random(8),
        'status' => 'PENDING',
        'priority' => 'NORMAL',
        'assigned_at' => CarbonImmutable::now(),
    ], $attributes))->save();

    if ($assigneeIds !== []) {
        $task->syncAssignees($assigneeIds, null);
    }

    return $task;
}

/**
 * CreateTask straight from the command, as a caller without a page would.
 *
 * @param  list<string>  $assigneeIds
 */
function tstartCreate(Access $actor, Project $project, string $startDate, string $dueDate = '', array $assigneeIds = [], string $title = 'Survey the site'): ?ProjectTaskView
{
    return app(CreateTask::class)->handle($actor, $project->id, new TaskDraft($title, '', 'NORMAL', $dueDate, $assigneeIds, 'GROUP', $startDate));
}

/** UpdateTaskDetails with the task's own crew, so only the fields named here can change. */
function tstartEdit(Access $actor, ProjectTask $task, string $priority, string $dueDate, ?string $startDate): ?string
{
    return app(UpdateTaskDetails::class)->handle($actor, $task->project_id, $task->id, new TaskDetails($priority, $dueDate, $task->assigneeIds(), null, $startDate));
}

/** The raw column, as the database holds it — no cast in the way. */
function tstartStoredStart(ProjectTask $task): ?string
{
    $value = DB::table('project_tasks')->where('id', $task->id)->value('start_date');

    return $value === null ? null : substr((string) $value, 0, 10);
}

/**
 * Six tasks for the sort checks: two share a start, two have none, so both
 * the blanks and the ties (broken by code) are exercised.
 *
 * @param  list<string>  $assigneeIds
 */
function tstartSortFixture(Project $project, array $assigneeIds = []): void
{
    foreach ([
        'TSK-S01' => '2026-03-01',
        'TSK-S02' => null,
        'TSK-S03' => '2026-02-01',
        'TSK-S04' => null,
        'TSK-S05' => '2026-03-01',
        'TSK-S06' => '2026-01-15',
    ] as $code => $start) {
        tstartTask($project, ['code' => $code, 'title' => 'Sorted '.$code, 'start_date' => $start, 'assignee_ids' => $assigneeIds]);
    }
}

/**
 * The codes of a rendered page, in order.
 *
 * @return list<string>
 */
function tstartCodes(mixed $rows): array
{
    assert($rows instanceof LengthAwarePaginator);

    return array_values($rows->getCollection()->map(static fn (ProjectTaskView $task): string => $task->code)->all());
}

/** What a refused create must leave behind: nothing at all. */
function tstartNothingCreated(): void
{
    expect(ProjectTask::query()->count())->toBe(0)
        ->and(ProjectTaskAssignee::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'TASK_ASSIGNED')->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
}

/*
|--------------------------------------------------------------------------
| Creating a task with a planned start
|--------------------------------------------------------------------------
*/

it('stores the planned start date a manager gives a new task and hands it back on the view', function () {
    $project = tstartProject(['due_date' => '2026-06-30']);
    $actor = tstartManager();

    $view = tstartCreate($actor, $project, '2026-02-10', '2026-03-01');

    expect($view)->not->toBeNull()
        ->and($view?->start_date?->format('Y-m-d'))->toBe('2026-02-10')
        ->and($view?->due_date?->format('Y-m-d'))->toBe('2026-03-01');
    $task = ProjectTask::query()->findOrFail($view?->id);
    expect(tstartStoredStart($task))->toBe('2026-02-10')
        ->and($task->start_date?->format('Y-m-d'))->toBe('2026-02-10');
});

it('accepts a start on the edges of the window: the project start, the project deadline, the task due date', function (string $start, string $due) {
    $project = tstartProject(['due_date' => '2026-06-30']);
    $actor = tstartManager();

    $view = tstartCreate($actor, $project, $start, $due);

    expect(tstartStoredStart(ProjectTask::query()->findOrFail($view?->id)))->toBe($start);
})->with([
    'on the project start day' => ['2026-01-01', ''],
    'on the project deadline' => ['2026-06-30', '2026-06-30'],
    'on its own due date' => ['2026-03-01', '2026-03-01'],
]);

it('stores no start date when the draft leaves it blank', function () {
    $project = tstartProject();
    $actor = tstartManager();

    $view = tstartCreate($actor, $project, '', '2026-03-01');

    expect($view?->start_date)->toBeNull()
        ->and(tstartStoredStart(ProjectTask::query()->findOrFail($view?->id)))->toBeNull();
});

it('adds a task with a start date through the add-task dialog, then clears the draft', function () {
    $project = tstartProject();
    tstartActor(['PROJECTS'], allData: true);

    Livewire::test(Show::class, ['project' => $project->id])
        ->set('adding', true)
        ->set('taskTitle', 'Plan the kickoff')
        ->set('taskStartDate', '2026-02-10')
        ->set('taskDueDate', '2026-03-01')
        ->call('saveTask')
        ->assertSet('adding', false)
        ->assertSet('taskStartDate', '');

    $task = ProjectTask::query()->where('title', 'Plan the kickoff')->firstOrFail();
    expect(tstartStoredStart($task))->toBe('2026-02-10');
});

it('refuses a start date the command cannot accept, and writes nothing', function (string $start, string $due, string $message) {
    $ana = tstartEmployee('TST-0101', 'Ana', 'Garcia');
    tstartAccountFor($ana);
    $project = tstartProject(['due_date' => '2026-06-30']);
    $actor = tstartManager();

    expect(fn () => tstartCreate($actor, $project, $start, $due, [$ana->id]))
        ->toThrow(RuntimeException::class, $message);

    tstartNothingCreated();
})->with([
    'a word' => ['soon', '', 'start_date: must be a date'],
    'an impossible day' => ['2026-02-30', '', 'start_date: must be a date'],
    'an unpadded key' => ['2026-2-5', '', 'start_date: must be a date'],
    'a day-first date' => ['05/02/2026', '', 'start_date: must be a date'],
    'before the project starts' => ['2025-12-31', '', 'A task cannot start before the project starts.'],
    'after the project deadline' => ['2026-07-01', '', 'A task cannot start after the project deadline.'],
    'after its own due date' => ['2026-03-10', '2026-03-01', 'The due date cannot fall before the start date.'],
]);

it('names the planned start in the TASK_ASSIGNED bell, and keeps the due-only sentence as it was', function (string $start, string $due, string $body) {
    $ana = tstartEmployee('TST-0201', 'Ana', 'Garcia');
    $account = tstartAccountFor($ana);
    $project = tstartProject();
    $actor = tstartManager();

    $view = tstartCreate($actor, $project, $start, $due, [$ana->id], 'Survey the site');

    $notice = Notification::query()->where('user_id', $account->getKey())->where('kind', 'TASK_ASSIGNED')->sole();
    expect($notice->title)->toBe("Task {$view?->code} assigned to you")
        ->and($notice->body)->toBe($body);
})->with([
    'a start and a due date' => ['2026-09-28', '2026-10-05', 'Survey the site — starts Sep 28, 2026, due Oct 05, 2026.'],
    'a due date alone, unchanged' => ['', '2026-10-05', 'Survey the site — due Oct 05, 2026.'],
    'a start alone' => ['2026-09-28', '', 'Survey the site — starts Sep 28, 2026.'],
    'neither' => ['', '', 'Survey the site'],
]);

it('takes no new task on a closed project, from the command or the dialog, and writes nothing', function (string $status) {
    $ana = tstartEmployee('TST-0301', 'Ana', 'Garcia');
    tstartAccountFor($ana);
    $project = tstartProject(['status' => $status]);
    $actor = tstartManager();

    expect(fn () => tstartCreate($actor, $project, '2026-02-10', '2026-03-01', [$ana->id]))
        ->toThrow(RuntimeException::class, 'Reopen the project before adding a task to it.');
    tstartNothingCreated();

    Livewire::test(Show::class, ['project' => $project->id])
        ->set('adding', true)
        ->set('taskTitle', 'Late addition')
        ->set('taskAssigneeIds', [$ana->id])
        ->call('saveTask')
        ->assertSet('adding', true)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['slots']['text'] ?? null) === 'Reopen the project before adding a task to it.');
    tstartNothingCreated();
})->with(['COMPLETED', 'CANCELLED']);

it('still adds a task to a project that is planning or on hold', function (string $status) {
    $project = tstartProject(['status' => $status]);
    $actor = tstartManager();

    expect(tstartCreate($actor, $project, '2026-02-10'))->not->toBeNull();
    expect(ProjectTask::query()->where('project_id', $project->id)->count())->toBe(1);
})->with(['PLANNING', 'ON_HOLD']);

/*
|--------------------------------------------------------------------------
| Editing the start date (UpdateTaskDetails)
|--------------------------------------------------------------------------
*/

it('leaves the stored start date alone when the edit does not touch it', function () {
    $project = tstartProject(['due_date' => '2026-06-30']);
    $task = tstartTask($project, ['start_date' => '2026-02-01', 'due_date' => '2026-03-01']);
    $actor = tstartManager();

    expect(tstartEdit($actor, $task, 'HIGH', '2026-03-01', null))->toBe($task->code);

    $task->refresh();
    expect(tstartStoredStart($task))->toBe('2026-02-01')
        ->and($task->priority)->toBe('HIGH');
    $audit = AuditLog::query()->where('action', 'TASK_UPDATED')->where('entity_id', $task->id)->sole();
    expect($audit->details)->toBe(sprintf('Task %s "%s" updated (priority)', $task->code, $task->title));
});

it('clears the start date on a blank and names start_date in the audit', function () {
    $project = tstartProject(['due_date' => '2026-06-30']);
    $task = tstartTask($project, ['start_date' => '2026-02-01', 'due_date' => '2026-03-01']);
    $actor = tstartManager();

    expect(tstartEdit($actor, $task, 'NORMAL', '2026-03-01', ''))->toBe($task->code);

    expect(tstartStoredStart($task))->toBeNull();
    $audit = AuditLog::query()->where('action', 'TASK_UPDATED')->where('entity_id', $task->id)->sole();
    expect($audit->details)->toBe(sprintf('Task %s "%s" updated (start_date)', $task->code, $task->title));
});

it('sets a new start date, audits start_date alone and tells the retained crew nothing', function () {
    $ana = tstartEmployee('TST-0401', 'Ana', 'Garcia');
    tstartAccountFor($ana);
    $project = tstartProject(['due_date' => '2026-06-30']);
    $task = tstartTask($project, ['start_date' => '2026-02-01', 'due_date' => '2026-03-01', 'assignee_ids' => [$ana->id]]);
    $actor = tstartManager();

    expect(tstartEdit($actor, $task, 'NORMAL', '2026-03-01', '2026-02-15'))->toBe($task->code);

    expect(tstartStoredStart($task))->toBe('2026-02-15')
        ->and($task->fresh()?->due_date?->format('Y-m-d'))->toBe('2026-03-01');
    $audit = AuditLog::query()->where('action', 'TASK_UPDATED')->where('entity_id', $task->id)->sole();
    expect($audit->details)->toBe(sprintf('Task %s "%s" updated (start_date)', $task->code, $task->title));
    expect(Notification::query()->count())->toBe(0);
});

it('tells an employee added to a planned task when it starts, in the same bell sentence CreateTask uses', function () {
    $ana = tstartEmployee('TST-0411', 'Ana', 'Garcia');
    $ben = tstartEmployee('TST-0412', 'Ben', 'Reyes');
    $anaAccount = tstartAccountFor($ana);
    $benAccount = tstartAccountFor($ben);
    $project = tstartProject();
    $task = tstartTask($project, ['title' => 'Survey the site', 'start_date' => '2026-02-01', 'due_date' => '2026-03-01', 'assignee_ids' => [$ana->id]]);
    $actor = tstartManager();

    app(UpdateTaskDetails::class)->handle($actor, $project->id, $task->id, new TaskDetails('NORMAL', '2026-03-01', [$ana->id, $ben->id]));

    $notice = Notification::query()->where('user_id', $benAccount->getKey())->where('kind', 'TASK_ASSIGNED')->sole();
    expect($notice->body)->toBe('Survey the site — starts Feb 01, 2026, due Mar 01, 2026.')
        ->and(Notification::query()->where('user_id', $anaAccount->getKey())->count())->toBe(0)
        ->and(tstartStoredStart($task))->toBe('2026-02-01');
});

it('does nothing when the posted start is the one already stored', function () {
    $project = tstartProject();
    $task = tstartTask($project, ['start_date' => '2026-02-01', 'due_date' => '2026-03-01']);
    $actor = tstartManager();

    expect(tstartEdit($actor, $task, 'NORMAL', '2026-03-01', '2026-02-01'))->toBe('');

    expect(tstartStoredStart($task))->toBe('2026-02-01')
        ->and(AuditLog::query()->where('action', 'TASK_UPDATED')->count())->toBe(0);
});

it('refuses a new start date outside the task or project window, leaving the row as it was', function (string $start, string $message) {
    $project = tstartProject(['due_date' => '2026-06-30']);
    $task = tstartTask($project, ['start_date' => '2026-02-01', 'due_date' => '2026-03-01']);
    $actor = tstartManager();

    expect(fn () => tstartEdit($actor, $task, 'HIGH', '2026-03-01', $start))
        ->toThrow(RuntimeException::class, $message);

    $task->refresh();
    expect(tstartStoredStart($task))->toBe('2026-02-01')
        ->and($task->priority)->toBe('NORMAL')
        ->and(AuditLog::query()->where('action', 'TASK_UPDATED')->count())->toBe(0);
})->with([
    'not a date' => ['2026-13-01', 'start_date: must be a date'],
    'an unpadded key' => ['2026-2-15', 'start_date: must be a date'],
    'before the project starts' => ['2025-12-31', 'A task cannot start before the project starts.'],
    'after the project deadline' => ['2026-07-01', 'A task cannot start after the project deadline.'],
    'after the task due date' => ['2026-03-02', 'The due date cannot fall before the start date.'],
]);

it('refuses a due date moved before the stored start date', function () {
    $project = tstartProject(['due_date' => '2026-06-30']);
    $task = tstartTask($project, ['start_date' => '2026-02-15', 'due_date' => '2026-03-01']);
    $actor = tstartManager();

    expect(fn () => tstartEdit($actor, $task, 'NORMAL', '2026-02-10', null))
        ->toThrow(RuntimeException::class, 'The due date cannot fall before the start date.');

    $task->refresh();
    expect($task->due_date?->format('Y-m-d'))->toBe('2026-03-01')
        ->and(tstartStoredStart($task))->toBe('2026-02-15')
        ->and(AuditLog::query()->where('action', 'TASK_UPDATED')->count())->toBe(0);
});

it('accepts an earlier due date when the start moves with it, checking the pair as it will stand', function () {
    $project = tstartProject(['due_date' => '2026-06-30']);
    $task = tstartTask($project, ['start_date' => '2026-02-15', 'due_date' => '2026-03-01']);
    $actor = tstartManager();

    expect(tstartEdit($actor, $task, 'NORMAL', '2026-02-10', '2026-02-05'))->toBe($task->code);

    $task->refresh();
    expect(tstartStoredStart($task))->toBe('2026-02-05')
        ->and($task->due_date?->format('Y-m-d'))->toBe('2026-02-10');
    $audit = AuditLog::query()->where('action', 'TASK_UPDATED')->where('entity_id', $task->id)->sole();
    expect($audit->details)->toBe(sprintf('Task %s "%s" updated (start_date, due_date)', $task->code, $task->title));
});

/*
|--------------------------------------------------------------------------
| The review panel (Projects\Show)
|--------------------------------------------------------------------------
*/

it('saves a start date a Projects manager types into the review panel', function () {
    $project = tstartProject(['due_date' => '2026-06-30']);
    $task = tstartTask($project, ['due_date' => '2026-03-01']);
    tstartActor(['PROJECTS'], allData: true);

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->call('toggleEditingDetails')
        ->assertSet('draftStartDate', '')
        ->assertSet('seenStartDate', '')
        ->set('draftStartDate', '2026-02-12')
        ->call('saveDetails')
        ->assertSet('editingDetails', false)
        ->assertSet('seenStartDate', '2026-02-12')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['slots']['text'] ?? null) === "Task {$task->code} updated.");

    expect(tstartStoredStart($task))->toBe('2026-02-12');
    $audit = AuditLog::query()->where('action', 'TASK_UPDATED')->where('entity_id', $task->id)->sole();
    expect($audit->details)->toContain('(start_date)');
});

it('keeps a start date changed underneath an open review panel when the manager saves something else', function () {
    $project = tstartProject(['due_date' => '2026-06-30']);
    $task = tstartTask($project, ['start_date' => '2026-02-01', 'due_date' => '2026-03-01']);
    tstartActor(['PROJECTS'], allData: true);

    $panel = Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->call('toggleEditingDetails')
        ->assertSet('draftStartDate', '2026-02-01');

    // Someone else — the project's Task Leader, say — moves the start while
    // the panel is open on the old one.
    $task->forceFill(['start_date' => '2026-02-20'])->save();

    $panel->set('draftPriority', 'HIGH')
        ->call('saveDetails')
        ->assertSet('editingDetails', false);

    $task->refresh();
    expect(tstartStoredStart($task))->toBe('2026-02-20')
        ->and($task->priority)->toBe('HIGH');
    $audit = AuditLog::query()->where('action', 'TASK_UPDATED')->where('entity_id', $task->id)->sole();
    expect($audit->details)->toBe(sprintf('Task %s "%s" updated (priority)', $task->code, $task->title));
});

it('toasts a review-panel start date outside the project window and leaves the row alone', function () {
    $project = tstartProject(['due_date' => '2026-06-30']);
    $task = tstartTask($project, ['start_date' => '2026-02-01', 'due_date' => '2026-03-01']);
    tstartActor(['PROJECTS'], allData: true);

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->call('toggleEditingDetails')
        ->set('draftStartDate', '2025-12-01')
        ->call('saveDetails')
        ->assertSet('editingDetails', true)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['slots']['text'] ?? null) === 'A task cannot start before the project starts.');

    expect(tstartStoredStart($task))->toBe('2026-02-01')
        ->and(AuditLog::query()->where('action', 'TASK_UPDATED')->count())->toBe(0);
});

it('shows the planned start in the task panel editor and leaves an unplanned start empty', function () {
    $project = tstartProject();
    $planned = tstartTask($project, ['start_date' => '2026-02-10', 'due_date' => '2026-03-01']);
    $blank = tstartTask($project, ['due_date' => '2026-03-01']);
    tstartActor(['PROJECTS'], allData: true);

    // Keep the dates in the panel's fields even when no task matches the search.
    $panel = Livewire::test(Show::class, ['project' => $project->id])
        ->set('search', 'no-match')
        ->call('openTask', $planned->id);
    $panel->assertSet('draftStartDate', '2026-02-10')->assertSet('draftDueDate', '2026-03-01')
        ->assertSeeHtml('aria-label="Start date"');
    $panel->call('openTask', $blank->id)->assertSet('draftStartDate', '')
        ->assertSeeHtml('aria-label="Start date"');
});

/*
|--------------------------------------------------------------------------
| The project window (UpdateProject)
|--------------------------------------------------------------------------
*/

it('refuses a project timeline that would leave a task start date outside it', function (?string $start, string $due, string $taskStatus) {
    $project = tstartProject(['status' => 'PLANNING', 'start_date' => '2026-01-01']);
    $task = tstartTask($project, ['status' => $taskStatus, 'start_date' => '2026-02-10']);
    $actor = tstartManager();

    expect(fn () => app(UpdateProject::class)->handle($actor, $project->id, new ProjectDraft($project->name, '', 'NONE', null, $start, $due, 'NONE')))
        ->toThrow(RuntimeException::class, "The project timeline would leave Task {$task->code} outside it.");

    $project->refresh();
    expect($project->start_date->format('Y-m-d'))->toBe('2026-01-01')
        ->and($project->due_date)->toBeNull()
        ->and(AuditLog::query()->whereIn('action', ['PROJECT_UPDATED', 'PROJECT_STATUS_CHANGED'])->count())->toBe(0);
})->with([
    'a start moved past the task start' => ['2026-03-01', '', 'PENDING'],
    'a deadline moved before the task start' => [null, '2026-02-05', 'PENDING'],
    'a closed task counts too' => ['2026-03-01', '', 'COMPLETED'],
]);

it('saves a project timeline that still holds every task start date', function () {
    $project = tstartProject(['status' => 'PLANNING', 'start_date' => '2026-01-01']);
    tstartTask($project, ['start_date' => '2026-02-10']);
    $actor = tstartManager();

    expect(app(UpdateProject::class)->handle($actor, $project->id, new ProjectDraft($project->name, '', 'NONE', null, '2026-02-10', '2026-02-10', 'NONE')))
        ->toBe($project->code);

    $project->refresh();
    expect($project->start_date->format('Y-m-d'))->toBe('2026-02-10')
        ->and($project->due_date?->format('Y-m-d'))->toBe('2026-02-10');
    $audit = AuditLog::query()->where('action', 'PROJECT_UPDATED')->where('entity_id', $project->id)->sole();
    expect($audit->details)->toContain('start_date');
});

it('keeps a refused project edit inside its dialog and saves nothing', function () {
    $project = tstartProject(['status' => 'PLANNING', 'start_date' => '2026-01-01']);
    $task = tstartTask($project, ['start_date' => '2026-02-10']);
    tstartActor(['PROJECTS'], allData: true);

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openEdit')
        ->set('editDueDate', '2026-02-05')
        ->call('saveProject')
        ->assertSet('editing', true)
        ->assertHasErrors('projectEdit')
        ->assertSee("The project timeline would leave Task {$task->code} outside it.")
        ->assertNotDispatched('toast-show');

    expect($project->refresh()->due_date)->toBeNull()
        ->and(AuditLog::query()->where('action', 'PROJECT_UPDATED')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The tasks CSV
|--------------------------------------------------------------------------
*/

it('exports the planned start date, as a Y-m-d key, right before the due date', function () {
    $project = tstartProject();
    tstartTask($project, ['code' => 'TSK-P01', 'title' => 'Planned', 'start_date' => '2026-02-10', 'due_date' => '2026-03-01']);
    tstartTask($project, ['code' => 'TSK-P02', 'title' => 'Unplanned']);
    tstartActor(['PROJECTS'], allData: true);

    $csv = $this->get('/projects/'.$project->id.'/tasks/export?sort=code&dir=asc')->assertOk()->streamedContent();

    expect($csv)->toContain('"Code","Task","Assignees","Priority","Start date","Due date","Status"')
        ->toContain('"TSK-P01","Planned","—","Normal","2026-02-10","2026-03-01","Pending"')
        ->toContain('"TSK-P02","Unplanned","—","Normal","","","Pending"');
});

it('orders the tasks CSV by start date when asked to', function () {
    $project = tstartProject();
    tstartSortFixture($project);
    tstartActor(['PROJECTS'], allData: true);

    $csv = $this->get('/projects/'.$project->id.'/tasks/export?sort=start&dir=asc')->assertOk()->streamedContent();

    preg_match_all('/"(TSK-S\d\d)"/', $csv, $matches);
    expect($matches[1])->toBe(['TSK-S06', 'TSK-S03', 'TSK-S01', 'TSK-S05', 'TSK-S02', 'TSK-S04']);
});

/*
|--------------------------------------------------------------------------
| Sorting and showing it in the two task tables
|--------------------------------------------------------------------------
*/

it('sorts the project task table by start date both ways, blanks last ascending and first descending, as the PHP oracle does', function () {
    $project = tstartProject();
    tstartSortFixture($project);
    $actor = Access::forActor(tstartActor(['PROJECTS'], allData: true));
    $filters = ['q' => '', 'status' => 'ALL', 'assignee' => 'ALL', 'priority' => 'ALL', 'queue' => 'ALL'];
    $oracle = static function (string $direction) use ($actor, $project, $filters): array {
        $tasks = ProjectsData::visibleTasks(array_values(app(WorkRecords::class)->tasks($actor, $project->id)->all()), collect(), $filters, 'start', $direction, ProjectsData::todayKey());

        return array_map(static fn (ProjectTaskView $task): string => $task->code, $tasks);
    };
    $ascending = ['TSK-S06', 'TSK-S03', 'TSK-S01', 'TSK-S05', 'TSK-S02', 'TSK-S04'];
    expect($oracle('asc'))->toBe($ascending)
        ->and($oracle('desc'))->toBe(array_reverse($ascending));

    // Driven in steps: the see-assertions forward to TestResponse through
    // `@mixin`, so a chain past them is no longer typed as the Testable.
    $table = Livewire::test(Show::class, ['project' => $project->id])->set('taskView', 'list');
    $table->call('sortBy', 'start')
        ->assertSet('sortKey', 'start')
        ->assertSet('sortDir', 'asc')
        ->assertSeeHtml("\$wire.sortBy('start')");
    $table->assertSeeInOrder(['Jan 15, 2026', 'Feb 01, 2026', 'Mar 01, 2026', 'Mar 01, 2026']);
    $table->assertViewHas('rows', fn (mixed $rows): bool => tstartCodes($rows) === $ascending);

    $table->call('sortBy', 'start')
        ->assertSet('sortDir', 'desc');
    $table->assertSeeInOrder(['Mar 01, 2026', 'Mar 01, 2026', 'Feb 01, 2026', 'Jan 15, 2026']);
    $table->assertViewHas('rows', fn (mixed $rows): bool => tstartCodes($rows) === array_reverse($ascending));
});

it('sorts My Tasks by start date both ways and prints each date', function () {
    $ana = tstartEmployee('TST-0501', 'Ana', 'Garcia');
    $project = tstartProject();
    tstartSortFixture($project, [$ana->id]);
    tstartActor(['TASKS'], allData: false, employeeId: $ana->id);
    $ascending = ['TSK-S06', 'TSK-S03', 'TSK-S01', 'TSK-S05', 'TSK-S02', 'TSK-S04'];

    // Driven in steps: the see-assertions forward to TestResponse through
    // `@mixin`, so a chain past them is no longer typed as the Testable.
    $page = Livewire::test(MyTasksPage::class);
    $page->call('sortBy', 'start')
        ->assertSet('sortKey', 'start')
        ->assertSet('sortDir', 'asc')
        ->assertSeeHtml('wire:click="sortBy(\'start\')"');
    $page->assertSeeInOrder(['Jan 15, 2026', 'Feb 01, 2026', 'Mar 01, 2026', 'Mar 01, 2026']);
    $page->assertViewHas('rows', fn (mixed $rows): bool => tstartCodes($rows) === $ascending);

    $page->call('sortBy', 'start')
        ->assertSet('sortDir', 'desc');
    $page->assertSeeInOrder(['Mar 01, 2026', 'Mar 01, 2026', 'Feb 01, 2026', 'Jan 15, 2026']);
    $page->assertViewHas('rows', fn (mixed $rows): bool => tstartCodes($rows) === array_reverse($ascending));
});

it('shows the planned start in the My Tasks detail dialog, or says there is none', function () {
    $ana = tstartEmployee('TST-0601', 'Ana', 'Garcia');
    $project = tstartProject();
    $planned = tstartTask($project, ['start_date' => '2026-02-10', 'due_date' => '2026-03-01', 'assignee_ids' => [$ana->id]]);
    $blank = tstartTask($project, ['due_date' => '2026-03-01', 'assignee_ids' => [$ana->id]]);
    tstartActor(['TASKS'], allData: false, employeeId: $ana->id);

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $planned->id)
        ->assertSet('openId', $planned->id)
        ->assertSeeHtmlInOrder(['>Start</dt>', 'Feb 10, 2026', '>Due</dt>', 'Mar 01, 2026'])
        ->call('openTask', $blank->id)
        ->assertSet('openId', $blank->id)
        ->assertSeeHtmlInOrder(['>Start</dt>', 'No start date', '>Due</dt>']);
});

/*
|--------------------------------------------------------------------------
| The new-project dialog keeps a start date
|--------------------------------------------------------------------------
*/

it('re-seeds today as the start date after a create, so a second project needs no retyping', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-25T12:00:00+08:00'));
    tstartActor(['PROJECTS'], allData: true);
    $today = ProjectsData::todayKey();
    expect($today)->toBe('2026-09-25');

    $dialog = Livewire::test(Index::class)
        ->assertSet('draftStartDate', $today)
        ->set('adding', true)
        ->set('draftName', 'First project')
        ->set('draftStartDate', '2026-08-01')
        ->call('createProject')
        ->assertSet('adding', false)
        ->assertSet('draftName', '')
        ->assertSet('draftStartDate', $today);

    $dialog->set('adding', true)
        ->set('draftName', 'Second project')
        ->call('createProject')
        ->assertSet('adding', false)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['slots']['text'] ?? null) === 'Second project created as project PRJ-002.');

    expect(Project::query()->where('name', 'First project')->sole()->start_date->format('Y-m-d'))->toBe('2026-08-01')
        ->and(Project::query()->where('name', 'Second project')->sole()->start_date->format('Y-m-d'))->toBe($today);

    // Closing the dialog resets the draft the same way.
    $dialog->set('adding', true)
        ->set('draftStartDate', '')
        ->set('adding', false)
        ->assertSet('draftStartDate', $today);
});
