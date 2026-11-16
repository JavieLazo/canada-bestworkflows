<?php

declare(strict_types=1);

use App\Livewire\Projects\ProjectsData;
use App\Livewire\Projects\Show;
use App\Livewire\Tasks\MyTasksPage;
use App\Models\AuditLog;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskGroup;
use App\Models\ProjectTaskSubmission;
use App\Modules\WorkManagement\Application\Commands\CreateTask;
use App\Modules\WorkManagement\Application\Commands\DeleteTaskGroup;
use App\Modules\WorkManagement\Application\Commands\ImportTasks;
use App\Modules\WorkManagement\Application\Commands\MoveTaskToGroup;
use App\Modules\WorkManagement\Application\Commands\ReviewTask;
use App\Modules\WorkManagement\Application\Commands\ReviewTaskResponse;
use App\Modules\WorkManagement\Application\Commands\SaveTaskGroup;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Commands\StartTask;
use App\Modules\WorkManagement\Application\Commands\SubmitTask;
use App\Modules\WorkManagement\Application\Queries\ProjectTaskGroups;
use App\Modules\WorkManagement\Application\Queries\WorkLists;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Platform\Transfer\ImportOutcome;
use App\Platform\Transfer\ImportRow;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('keeps legacy tasks ungrouped and counts no group as a work unit', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    $actor = Access::forUser($owner);
    $before = app(WorkSummary::class)->projects($actor, [$project->id], '2026-11-01')[$project->id]->progress();
    app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Discovery');
    $totals = app(WorkSummary::class)->groups($actor, $project->id, '2026-11-01');
    expect($task->fresh()->group_id)->toBeNull()->and($totals['NONE']->total)->toBe(1)
        ->and(app(WorkSummary::class)->projects($actor, [$project->id], '2026-11-01')[$project->id]->progress())->toBe($before);
});

it('creates and renames project groups without rewriting their tasks or duplicating noop audit entries', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $save = app(SaveTaskGroup::class);
    $group = $save->handle($actor, $project->id, null, '  Discovery  ');
    $task = app(CreateTask::class)->handle($actor, $project->id, new TaskDraft('Interview', '', 'NORMAL', '', [], groupId: $group->id));
    $before = ProjectTask::query()->findOrFail($task->id)->getRawOriginal();
    $save->handle($actor, $project->id, $group->id, 'Research');
    $save->handle($actor, $project->id, $group->id, 'Research');
    expect(ProjectTask::query()->findOrFail($task->id)->getRawOriginal())->toBe($before)
        ->and(app(WorkRecords::class)->task($actor, $task->id)->group_name)->toBe('Research')
        ->and(AuditLog::query()->where('action', 'TASK_GROUP_CREATED')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'TASK_GROUP_RENAMED')->count())->toBe(1);
});

it('validates names and keeps their uniqueness inside the project', function () {
    $owner = cpaUser();
    $client = cpaClient($owner);
    $first = cpaProject($owner, $client, 'First');
    $second = cpaProject($owner, $client, 'Second');
    $actor = Access::forUser($owner);
    $save = app(SaveTaskGroup::class);
    $save->handle($actor, $first->id, null, 'Build');
    expect(fn () => $save->handle($actor, $first->id, null, 'Build'))->toThrow(ValidationException::class);
    expect(fn () => $save->handle($actor, $first->id, null, ' '))->toThrow(ValidationException::class);
    expect(fn () => $save->handle($actor, $first->id, null, str_repeat('a', 121)))->toThrow(ValidationException::class);
    $save->handle($actor, $second->id, null, 'Build');
    expect(ProjectTaskGroup::query()->count())->toBe(2);
});

it('moves a task without changing assignments, submissions, reviews, files or notifications', function () {
    $owner = cpaUser();
    $first = cpaEmployee('First');
    $second = cpaEmployee('Second');
    $worker = cpaUser($first, ['TASKS']);
    $peer = cpaUser($second, ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker, $peer]));
    $task = cpaTask($owner, $project, [$first, $second], 'INDIVIDUAL');
    app(StartTask::class)->handle(Access::forUser($worker), $task->id);
    app(SubmitTask::class)->handle(Access::forUser($worker), $task->id, 'Private first response');
    $response = ProjectTaskSubmission::query()->sole();
    $actor = Access::forUser($owner);
    app(ReviewTaskResponse::class)->handle($actor, $project->id, $task->id, $response->id, 'APPROVED', 'Accepted response', 1);
    $file = new ProjectTaskFile;
    $file->forceFill(['task_id' => $task->id, 'kind' => 'SUBMISSION', 'file_name' => 'private.pdf', 'mime' => 'application/pdf', 'size' => 5, 'path' => 'task-files/private.pdf', 'employee_id' => $first->id, 'uploaded_by' => $worker->id])->save();
    $tables = ['project_task_assignees', 'project_task_submissions', 'project_task_files', 'task_comments', 'notifications'];
    $before = array_map(static fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    $attributes = $task->fresh()->getRawOriginal();
    unset($attributes['group_id'], $attributes['updated_at']);
    $group = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Delivery');
    expect(app(MoveTaskToGroup::class)->handle($actor, $project->id, $task->id, $group->id, null))->toBeTrue();
    $after = $task->fresh()->getRawOriginal();
    unset($after['group_id'], $after['updated_at']);
    expect($after)->toBe($attributes)
        ->and(array_map(static fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(), $tables))->toBe($before);
    expect(app(WorkRecords::class)->task(Access::forUser($worker), $task->id, own: true)->group_name)->toBe('Delivery');
    $peerResponses = app(WorkRecords::class)->responses(Access::forUser($peer), $task->id, own: true);
    expect($peerResponses->get($first->id)->content_visible)->toBeFalse();
    cpaLogin($worker);
    Livewire::test(MyTasksPage::class)->call('openTask', $task->id)->assertSee('Delivery')->assertSee('Private first response');
});

it('refuses cross-project groups in commands and the database', function () {
    $owner = cpaUser();
    $client = cpaClient($owner);
    $first = cpaProject($owner, $client, 'First');
    $second = cpaProject($owner, $client, 'Second');
    $actor = Access::forUser($owner);
    $group = app(SaveTaskGroup::class)->handle($actor, $first->id, null, 'Private phase');
    $task = cpaTask($owner, $second, []);
    expect(fn () => app(MoveTaskToGroup::class)->handle($actor, $second->id, $task->id, $group->id, null))->toThrow(RuntimeException::class);
    expect(fn () => app(CreateTask::class)->handle($actor, $second->id, new TaskDraft('Invalid', '', 'NORMAL', '', [], groupId: $group->id)))->toThrow(RuntimeException::class);
    expect(fn () => DB::table('project_tasks')->where('id', $task->id)->update(['group_id' => $group->id]))->toThrow(QueryException::class);
    expect($task->fresh()->group_id)->toBeNull()->and(ProjectTask::query()->count())->toBe(1);
});

it('does not overwrite a concurrent group move or audit an unchanged choice', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    $actor = Access::forUser($owner);
    $first = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'First');
    $second = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Second');
    $move = app(MoveTaskToGroup::class);
    $move->handle($actor, $project->id, $task->id, $first->id, null);
    expect($move->handle($actor, $project->id, $task->id, $first->id, $first->id))->toBeFalse();
    expect(fn () => $move->handle($actor, $project->id, $task->id, $second->id, null))->toThrow(RuntimeException::class, 'moved to another activity');
    expect($task->fresh()->group_id)->toBe($first->id)->and(AuditLog::query()->where('action', 'TASK_UPDATED')->count())->toBe(1);
    $move->handle($actor, $project->id, $task->id, null, $first->id);
    expect($task->fresh()->group_id)->toBeNull();
});

it('removes only empty groups and does not delete their tasks', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $group = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Build');
    $task = app(CreateTask::class)->handle($actor, $project->id, new TaskDraft('Keep', '', 'NORMAL', '', [], groupId: $group->id));
    $delete = app(DeleteTaskGroup::class);
    expect(fn () => $delete->handle($actor, $project->id, $group->id))->toThrow(RuntimeException::class, 'Move this activity');
    app(MoveTaskToGroup::class)->handle($actor, $project->id, $task->id, null, $group->id);
    expect($delete->handle($actor, $project->id, $group->id))->toBeTrue()
        ->and($delete->handle($actor, $project->id, $group->id))->toBeFalse()
        ->and(ProjectTask::query()->whereKey($task->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'TASK_GROUP_DELETED')->count())->toBe(1);
});

it('shows ordinary members only groups containing visible tasks and lets leads see empty groups', function () {
    $owner = cpaUser();
    $mine = cpaEmployee('Mine');
    $member = cpaUser($mine);
    $other = cpaEmployee('Other');
    $otherAccount = cpaUser($other);
    $leader = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$member, $otherAccount, $leader]));
    $project->update(['manager_id' => $leader->id]);
    $actor = Access::forUser($owner);
    $visible = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Visible');
    $hidden = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Hidden');
    $empty = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Empty');
    $task = cpaTask($owner, $project, [$mine]);
    app(MoveTaskToGroup::class)->handle($actor, $project->id, $task->id, $visible->id, null);
    $otherTask = cpaTask($owner, $project, [$other]);
    app(MoveTaskToGroup::class)->handle($actor, $project->id, $otherTask->id, $hidden->id, null);
    $queries = app(ProjectTaskGroups::class);
    expect($queries->forProject(Access::forUser($member), $project->id)->pluck('id')->all())->toBe([$visible->id])
        ->and($queries->forProject(Access::forUser($leader), $project->id)->pluck('id')->all())->toBe([$visible->id, $hidden->id, $empty->id]);
    expect(app(WorkSummary::class)->groups(Access::forUser($member), $project->id, '2026-11-01'))->toHaveKeys([$visible->id])->toHaveCount(1);
    cpaLogin($member);
    Livewire::test(Show::class, ['project' => $project->id])->set('taskView', 'board')->assertSee('Visible')->assertDontSee('Hidden')->assertDontSee('Empty')->assertDontSee('New activity');
});

it('refuses group writes by leads, assignees, outsiders and revoked managers', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $member = cpaUser($employee);
    $leader = cpaUser();
    $manager = cpaUser(allData: true);
    $outsider = cpaUser(allData: true);
    $client = cpaClient($owner, [$member, $leader, $manager]);
    $project = cpaProject($owner, $client);
    $project->update(['manager_id' => $leader->id]);
    $task = cpaTask($owner, $project, [$employee]);
    $group = app(SaveTaskGroup::class)->handle(Access::forUser($owner), $project->id, null, 'Keep');
    app(SetClientMember::class)->handle(Access::forUser($owner), $client->id, $manager->id, false);
    foreach ([$member, $leader, $manager, $outsider] as $user) {
        $actor = Access::forUser($user);
        expect(fn () => app(SaveTaskGroup::class)->handle($actor, $project->id, $group->id, 'Changed'))->toThrow(RuntimeException::class);
        expect(fn () => app(DeleteTaskGroup::class)->handle($actor, $project->id, $group->id))->toThrow(RuntimeException::class);
        expect(fn () => app(MoveTaskToGroup::class)->handle($actor, $project->id, $task->id, $group->id, null))->toThrow(RuntimeException::class);
    }
    expect(ProjectTaskGroup::query()->sole()->name)->toBe('Keep')->and($task->fresh()->group_id)->toBeNull();
});

it('shares group filtering across Board, List and CSV and clears it with the other filters', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $group = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Design');
    $task = app(CreateTask::class)->handle($actor, $project->id, new TaskDraft('Grouped task', '', 'NORMAL', '', [], groupId: $group->id));
    cpaTask($owner, $project, [], title: 'Ungrouped task');
    $filters = ['q' => '', 'status' => 'ALL', 'priority' => 'ALL', 'assignee' => 'ALL', 'queue' => 'ALL', 'group' => $group->id];
    $lists = app(WorkLists::class);
    $board = $lists->taskBoard($actor, $project->id, $filters, '2026-11-01');
    $list = $lists->taskPage($actor, $project->id, $filters, 'group', 'asc', '2026-11-01', 1, 20);
    expect($list->pluck('id')->all())->toBe([$task->id])
        ->and(collect($board)->flatMap(fn ($lane) => $lane->pluck('id'))->all())->toBe([$task->id]);
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->set('taskView', 'board')->set('group', $group->id);
    $page->assertViewHas('visibleCount', 1);
    $page->set('taskView', 'list')->assertViewHas('visibleCount', 1);
    $page->set('search', 'not found')->call('clearFilters')->assertSet('group', 'ALL')->assertSet('groupSelection', [])->assertViewHas('visibleCount', 2);
    $csv = $this->get(route('projects.tasks.export', ['project' => $project->id, 'group' => $group->id]))->assertOk()->streamedContent();
    expect($csv)->toContain('Grouped task')->not->toContain('Ungrouped task');
    $page->set('group', 'NONE')->assertViewHas('visibleCount', 1)->assertSee('Ungrouped task')->assertDontSee('Grouped task');
});

it('sorts grouped and ungrouped tasks like the shared text-order oracle without extra row queries', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    foreach (['Zebra', 'Alpha'] as $name) {
        $group = app(SaveTaskGroup::class)->handle($actor, $project->id, null, $name);
        app(CreateTask::class)->handle($actor, $project->id, new TaskDraft($name.' task', '', 'NORMAL', '', [], groupId: $group->id));
    }
    cpaTask($owner, $project, []);
    $filters = ['q' => '', 'status' => 'ALL', 'priority' => 'ALL', 'assignee' => 'ALL', 'queue' => 'ALL', 'group' => 'ALL'];
    $tasks = app(WorkRecords::class)->tasks($actor, $project->id);
    foreach (['asc', 'desc'] as $direction) {
        $oracle = ProjectsData::visibleTasks(array_values($tasks->all()), collect(), $filters, 'group', $direction, '2026-11-01');
        $rows = app(WorkLists::class)->taskPage($actor, $project->id, $filters, 'group', $direction, '2026-11-01', 1, 20);
        expect($rows->pluck('id')->all())->toBe(array_map(static fn ($row): string => $row->id, $oracle));
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(WorkRecords::class)->tasks($actor, $project->id);
    $small = count(DB::getQueryLog());
    for ($i = 0; $i < 25; $i++) {
        cpaTask($owner, $project, [], title: 'Card '.$i);
    }
    DB::flushQueryLog();
    app(WorkRecords::class)->tasks($actor, $project->id);
    $large = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($large)->toBe($small)->toBeLessThanOrEqual(5);
});

it('creates a task in its selected group and offers Add task above the List pager', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->call('openGroupEditor')->set('groupName', 'Build')->call('saveGroup');
    $group = ProjectTaskGroup::query()->sole();
    $page->assertSet('group', $group->id)->assertSet('groupEditorOpen', false)->call('openTaskCreation')->assertSet('taskGroupId', $group->id)
        ->set('taskTitle', 'Implement')->call('saveTask')->assertSet('reviewOpen', true);
    expect(ProjectTask::query()->sole()->group_id)->toBe($group->id);
    $page->set('reviewOpen', false)->set('taskView', 'list');
    $page->assertSeeInOrder(['Add task', 'Showing']);
    $page->assertDontSeeHtml('wire:model="quickTitle"');
});

it('keeps a rejected group-name draft and refuses SQLite schema rebuilding inside an app transaction', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->call('openGroupEditor')->set('groupName', str_repeat('x', 121))->call('saveGroup')
        ->assertHasErrors('groupName')->assertSet('groupEditorOpen', true)->assertSet('groupName', str_repeat('x', 121));
    if (DB::connection()->getDriverName() === 'sqlite') {
        $migration = require database_path('migrations/2026_11_01_0001_add_project_task_groups.php');
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'outside an active SQLite transaction');
    }
});

it('retains existing groups through CSV updates and starts imported new work ungrouped', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $task = cpaTask($owner, $project, []);
    $group = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Delivery');
    app(MoveTaskToGroup::class)->handle($actor, $project->id, $task->id, $group->id, null);
    $fields = ['Code' => $task->code, 'Task' => $task->title, 'Assignees' => '', 'Priority' => 'High', 'Start date' => '', 'Due date' => '2026-11-20', 'Status' => 'Pending'];
    expect(app(ImportTasks::class)->row($actor, $project->id, new ImportRow(2, $fields)))->toBe(ImportOutcome::UPDATED)
        ->and($task->fresh()->group_id)->toBe($group->id);
    $fields['Code'] = '';
    $fields['Task'] = 'Imported new task';
    expect(app(ImportTasks::class)->row($actor, $project->id, new ImportRow(3, $fields)))->toBe(ImportOutcome::CREATED)
        ->and(ProjectTask::query()->where('title', 'Imported new task')->sole()->group_id)->toBeNull();
});

it('organizes accepted work without reopening it or changing its review', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee, ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker]));
    $task = cpaTask($owner, $project, [$employee]);
    $actor = Access::forUser($owner);
    app(StartTask::class)->handle(Access::forUser($worker), $task->id);
    app(SubmitTask::class)->handle(Access::forUser($worker), $task->id, 'Accepted submission');
    app(ReviewTask::class)->handle($actor, $project->id, $task->id, 'COMPLETED', 'Accepted decision');
    $before = $task->fresh()->getRawOriginal();
    unset($before['group_id'], $before['updated_at']);
    $group = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Archive');
    app(MoveTaskToGroup::class)->handle($actor, $project->id, $task->id, $group->id, null);
    $after = $task->fresh()->getRawOriginal();
    unset($after['group_id'], $after['updated_at']);
    expect($after)->toBe($before);
});
