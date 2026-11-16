<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectPolicy;
use App\Livewire\Projects\ProjectsData;
use App\Livewire\Projects\Show;
use App\Livewire\Tasks\MyTasksPage;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Modules\WorkManagement\Application\Commands\ChangeProjectStatus;
use App\Modules\WorkManagement\Application\Commands\CreateTask;
use App\Modules\WorkManagement\Application\Commands\ImportTasks;
use App\Modules\WorkManagement\Application\Commands\MoveTaskToGroup;
use App\Modules\WorkManagement\Application\Commands\ReviewTask;
use App\Modules\WorkManagement\Application\Commands\ReviewTaskResponse;
use App\Modules\WorkManagement\Application\Commands\SaveTaskGroup;
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
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

/** @param list<string> $assignees */
function subtaskCreate(Access $actor, Project $project, string $parentId, string $title = 'Child work', array $assignees = []): ProjectTask
{
    $view = app(CreateTask::class)->handle($actor, $project->id, new TaskDraft($title, '', 'NORMAL', '', $assignees, parentId: $parentId));
    if ($view === null) {
        throw new LogicException('The fixture project must accept its subtask.');
    }

    return ProjectTask::query()->findOrFail($view->id);
}

it('creates one canonical subtask level and derives the group from the parent', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $parent = cpaTask($owner, $project, []);
    $first = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Design');
    $second = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Build');
    app(MoveTaskToGroup::class)->handle($actor, $project->id, $parent->id, $first->id, null);
    $child = subtaskCreate($actor, $project, $parent->id);
    $before = $child->getRawOriginal();
    expect($child->parent_id)->toBe($parent->id)->and($child->group_id)->toBeNull();
    $records = app(WorkRecords::class);
    expect($records->task($actor, $child->id)->group_name)->toBe('Design')
        ->and($records->task($actor, $child->id)->effective_group_id)->toBe($first->id)
        ->and($records->task($actor, $child->id)->parent->title)->toBe($parent->title);
    app(MoveTaskToGroup::class)->handle($actor, $project->id, $parent->id, $second->id, $first->id);
    expect($child->fresh()->getRawOriginal())->toBe($before)
        ->and($records->task($actor, $child->id)->group_name)->toBe('Build');
    expect(fn () => app(MoveTaskToGroup::class)->handle($actor, $project->id, $child->id, $first->id, null))->toThrow(RuntimeException::class, 'follow their parent');
});

it('refuses deeper, missing and cross-project parents and separate child group assignment', function () {
    $owner = cpaUser();
    $client = cpaClient($owner);
    $project = cpaProject($owner, $client, 'First');
    $other = cpaProject($owner, $client, 'Other');
    $actor = Access::forUser($owner);
    $parent = cpaTask($owner, $project, []);
    $child = subtaskCreate($actor, $project, $parent->id);
    expect(fn () => subtaskCreate($actor, $project, $child->id))->toThrow(RuntimeException::class, 'one level');
    expect(fn () => subtaskCreate($actor, $project, 'missing'))->toThrow(RuntimeException::class, 'parent task');
    expect(fn () => subtaskCreate($actor, $other, $parent->id))->toThrow(RuntimeException::class, 'parent task');
    $group = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Phase');
    expect(fn () => app(CreateTask::class)->handle($actor, $project->id, new TaskDraft('Invalid', '', 'NORMAL', '', [], groupId: $group->id, parentId: $parent->id)))->toThrow(RuntimeException::class, 'inherit');
    $otherTask = cpaTask($owner, $other, []);
    expect(fn () => DB::table('project_tasks')->where('id', $otherTask->id)->update(['parent_id' => $parent->id]))->toThrow(QueryException::class);
    expect(ProjectTask::query()->count())->toBe(3);
});

it('does not add new scope after a parent has evidence, a response or a closed state', function (string $lock) {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee, ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker]));
    $actor = Access::forUser($owner);
    $parent = cpaTask($owner, $project, [$employee]);
    if ($lock === 'evidence') {
        $file = new ProjectTaskFile;
        $file->forceFill(['task_id' => $parent->id, 'kind' => 'SUBMISSION', 'file_name' => 'evidence.pdf', 'mime' => 'application/pdf', 'size' => 5, 'path' => 'task-files/evidence.pdf', 'employee_id' => $employee->id, 'uploaded_by' => $worker->id])->save();
    } elseif ($lock === 'response') {
        app(StartTask::class)->handle(Access::forUser($worker), $parent->id);
        app(SubmitTask::class)->handle(Access::forUser($worker), $parent->id, 'Keep submitted scope');
    } else {
        $parent->update(['status' => $lock]);
    }
    $before = $parent->fresh()->getRawOriginal();
    expect(fn () => subtaskCreate($actor, $project, $parent->id))->toThrow(RuntimeException::class, 'handed in or closed');
    expect($parent->fresh()->getRawOriginal())->toBe($before)->and(ProjectTask::query()->count())->toBe(1);
})->with(['evidence', 'response', 'RETURNED', 'COMPLETED', 'CANCELLED']);

it('counts leaf work once but keeps parent acceptance in project closure', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $parent = cpaTask($owner, $project, []);
    $first = subtaskCreate($actor, $project, $parent->id, 'First child');
    $second = subtaskCreate($actor, $project, $parent->id, 'Second child');
    $standalone = cpaTask($owner, $project, [], title: 'Standalone');
    $first->update(['status' => 'COMPLETED']);
    $second->update(['status' => 'CANCELLED']);
    $standalone->update(['status' => 'COMPLETED']);
    $totals = app(WorkSummary::class)->projects($actor, [$project->id], '2026-11-01')[$project->id];
    expect($totals->total)->toBe(4)->and($totals->progress())->toBe(['total' => 2, 'completed' => 2, 'percent' => 100]);
    expect($parent->fresh()->status)->toBe('PENDING');
    expect(fn () => app(ChangeProjectStatus::class)->handle($actor, $project->id, 'COMPLETED', 'ACTIVE'))->toThrow(RuntimeException::class);
});

it('keeps explicit parent review independent without changing child state', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee, ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker]));
    $actor = Access::forUser($owner);
    $parent = cpaTask($owner, $project, [$employee]);
    $child = subtaskCreate($actor, $project, $parent->id, assignees: [$employee->id]);
    app(StartTask::class)->handle(Access::forUser($worker), $parent->id);
    app(SubmitTask::class)->handle(Access::forUser($worker), $parent->id, 'Parent handoff');
    app(ReviewTask::class)->handle($actor, $project->id, $parent->id, 'COMPLETED', 'Explicit parent acceptance');
    expect($child->fresh()->status)->toBe('PENDING')->and($parent->fresh()->status)->toBe('COMPLETED');
    app(StartTask::class)->handle(Access::forUser($worker), $child->id);
    expect($child->fresh()->status)->toBe('IN_PROGRESS')->and($parent->fresh()->review_remarks)->toBe('Explicit parent acceptance');
    expect(fn () => app(ChangeProjectStatus::class)->handle($actor, $project->id, 'COMPLETED', 'ACTIVE'))->toThrow(RuntimeException::class);
});

it('does not grant parent or child visibility and keeps group context on visible work', function () {
    $owner = cpaUser();
    $parentEmployee = cpaEmployee('Parent');
    $childEmployee = cpaEmployee('Child');
    $parentWorker = cpaUser($parentEmployee);
    $childWorker = cpaUser($childEmployee, ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$parentWorker, $childWorker]));
    $actor = Access::forUser($owner);
    $parent = cpaTask($owner, $project, [$parentEmployee], title: 'Restricted parent title');
    $group = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Delivery');
    app(MoveTaskToGroup::class)->handle($actor, $project->id, $parent->id, $group->id, null);
    $child = subtaskCreate($actor, $project, $parent->id, assignees: [$childEmployee->id]);
    $records = app(WorkRecords::class);
    $view = $records->task(Access::forUser($childWorker), $child->id, own: true);
    expect($view->parent)->toBeNull()->and($view->group_name)->toBe('Delivery')
        ->and($records->task(Access::forUser($childWorker), $parent->id, own: true))->toBeNull();
    $parentView = $records->task(Access::forUser($parentWorker), $parent->id);
    expect($parentView->has_subtasks)->toBeTrue()->and($parentView->subtasks_count)->toBe(0)
        ->and($records->task(Access::forUser($parentWorker), $child->id))->toBeNull();
    $filters = ['q' => '', 'status' => 'ALL', 'priority' => 'ALL', 'assignee' => 'ALL', 'queue' => 'ALL', 'group' => $group->id];
    $rows = app(WorkLists::class)->taskPage($actor, $project->id, $filters, 'code', 'asc', '2026-11-01', 1, 20);
    expect($rows->pluck('id')->all())->toContain($parent->id, $child->id);
    $groups = app(ProjectTaskGroups::class)->forProject(Access::forUser($parentWorker), $project->id);
    expect($groups->pluck('id')->all())->toBe([$group->id]);
    Storage::fake('local');
    $file = new ProjectTaskFile;
    $file->forceFill(['task_id' => $parent->id, 'kind' => 'BRIEF', 'file_name' => 'parent-only.pdf', 'mime' => 'application/pdf', 'size' => 5, 'path' => 'task-files/parent-only.pdf', 'uploaded_by' => $owner->id])->save();
    Storage::disk('local')->put($file->path, 'proof');
    cpaLogin($childWorker);
    Livewire::test(MyTasksPage::class)->call('openTask', $child->id)->assertDontSee('Restricted parent title')->assertSee('Subtask');
    $this->get(route('tasks.files.download', $file->id))->assertForbidden();
});

it('creates and navigates subtasks through the same drawer with parent context', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $parent = cpaTask($owner, $project, [], title: 'Launch handoff');
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->call('openTask', $parent->id)->assertSee('Add subtask');
    $page->call('openSubtaskCreation', $parent->id)->assertSet('taskParentId', $parent->id)->assertSet('adding', true)->assertSee('New subtask')
        ->set('taskTitle', 'Confirm release')->call('saveTask')->assertSet('adding', false)->assertSet('reviewOpen', true);
    $child = ProjectTask::query()->whereNotNull('parent_id')->sole();
    $page->assertSet('openTaskId', $child->id)->assertSee('Subtask of Launch handoff')->assertDontSee('Add subtask');
    $page->call('openTask', $parent->id)->assertViewHas('openSubtasks', fn ($rows): bool => $rows->total() === 1)->assertSee('Confirm release');
    $page->call('openSubtaskCreation', $parent->id)->set('adding', false)->assertSet('openTaskId', $parent->id)->assertSet('reviewOpen', true);
});

it('paginates a parents visible subtasks without changing the main table size', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $parent = cpaTask($owner, $project, []);
    $actor = Access::forUser($owner);
    for ($i = 0; $i < 13; $i++) {
        subtaskCreate($actor, $project, $parent->id, 'Sub work '.$i);
    }
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->call('openTask', $parent->id);
    $page->assertViewHas('openSubtasks', fn ($rows): bool => $rows->count() === 10 && $rows->total() === 13);
    $page->call('nextPage', 'subtasks')->assertSet('perPage', 20);
    $page->assertViewHas('openSubtasks', fn ($rows): bool => $rows->count() === 3 && $rows->currentPage() === 2);
    $page->call('showSubtasks', $parent->id)->assertSet('taskView', 'list')->assertSet('parent', $parent->id)->assertViewHas('visibleCount', 13);
});

it('keeps individual subtask responses private from peers and independent of the parent review', function () {
    Storage::fake('local');
    $owner = cpaUser();
    $first = cpaEmployee('First');
    $second = cpaEmployee('Second');
    $worker = cpaUser($first, ['TASKS']);
    $peer = cpaUser($second, ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker, $peer]));
    $actor = Access::forUser($owner);
    $parent = cpaTask($owner, $project, [$first, $second]);
    $view = app(CreateTask::class)->handle($actor, $project->id, new TaskDraft('Individual child', '', 'NORMAL', '', [$first->id, $second->id], 'INDIVIDUAL', parentId: $parent->id));
    $child = ProjectTask::query()->findOrFail($view->id);
    $parentBefore = $parent->fresh()->getRawOriginal();
    app(StartTask::class)->handle(Access::forUser($worker), $child->id);
    app(SubmitTask::class)->handle(Access::forUser($worker), $child->id, 'Private child response');
    $response = ProjectTaskSubmission::query()->where('task_id', $child->id)->sole();
    app(ReviewTaskResponse::class)->handle($actor, $project->id, $child->id, $response->id, 'RETURNED', 'Private child review', 1);
    $file = new ProjectTaskFile;
    $file->forceFill(['task_id' => $child->id, 'kind' => 'SUBMISSION', 'file_name' => 'private-child.pdf', 'mime' => 'application/pdf', 'size' => 5, 'path' => 'task-files/private-child.pdf', 'employee_id' => $first->id, 'uploaded_by' => $worker->id])->save();
    Storage::disk('local')->put($file->path, 'proof');
    $records = app(WorkRecords::class);
    $peerActor = Access::forUser($peer);
    $peerResponse = $records->responses($peerActor, $child->id, own: true)->get($first->id);
    expect($peerResponse->status)->toBe('RETURNED')->and($peerResponse->content_visible)->toBeFalse()
        ->and($peerResponse->toArray())->not->toHaveKeys(['remarks', 'review_remarks', 'id', 'revision'])
        ->and($records->files($peerActor, [$child->id]))->toBeEmpty()
        ->and($records->counts($peerActor, [$child->id])['files']->get($child->id, 0))->toBe(0)
        ->and($parent->fresh()->getRawOriginal())->toBe($parentBefore);
    cpaLogin($peer);
    Livewire::test(MyTasksPage::class)->call('openTask', $parent->id)->assertSee('Individual child')->assertDontSee('Private child response')->assertDontSee('Private child review');
    Livewire::test(MyTasksPage::class)->call('openTask', $child->id)->assertSee('Subtask of')->assertDontSee('Private child response')->assertDontSee('Private child review')->assertDontSee('private-child.pdf');
    $this->get(route('tasks.files.download', $file->id))->assertForbidden();
    cpaLogin($worker);
    Livewire::test(MyTasksPage::class)->call('openTask', $child->id)->assertSee('Private child response')->assertSee('Private child review')->assertSee('private-child.pdf');
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->call('openTask', $child->id)->call('selectResponse', $first->id)->assertSee('Private child response')->assertSee('Private child review');
});

it('keeps scoped leaf progress honest and bounds hierarchy queries as children grow', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee);
    $project = cpaProject($owner, cpaClient($owner, [$worker]));
    $actor = Access::forUser($owner);
    $workerActor = Access::forUser($worker);
    $parent = cpaTask($owner, $project, [$employee]);
    $child = subtaskCreate($actor, $project, $parent->id);
    $child->update(['status' => 'COMPLETED']);
    $summary = app(WorkSummary::class);
    $records = app(WorkRecords::class);
    $scope = $summary->projects($workerActor, [$project->id], '2026-11-01')[$project->id];
    expect($scope->total)->toBe(1)->and($scope->progress())->toBe(['total' => 0, 'completed' => 0, 'percent' => 0]);
    expect(ProjectPolicy::progress(array_values($records->tasks($workerActor, $project->id)->map(static fn ($task): array => ProjectsData::taskShape($task))->all())))->toBe($scope->progress());
    $filters = ['q' => '', 'status' => 'ALL', 'project' => 'ALL'];
    DB::enableQueryLog();
    DB::flushQueryLog();
    $records->ownPage($workerActor, $filters, 'task', 'asc', 1, 20);
    $small = count(DB::getQueryLog());
    DB::disableQueryLog();
    for ($i = 0; $i < 10; $i++) {
        subtaskCreate($actor, $project, $parent->id, 'Assigned child '.$i, [$employee->id]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $rows = $records->ownPage($workerActor, $filters, 'task', 'asc', 1, 20);
    $large = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($rows->count())->toBe(11)->and($large)->toBeLessThanOrEqual($small + 1)
        ->and($rows->firstWhere('id', $parent->id)->subtasks_count)->toBe(10)
        ->and($summary->projects($workerActor, [$project->id], '2026-11-01')[$project->id]->progress())->toBe(['total' => 10, 'completed' => 0, 'percent' => 0]);
    expect($records->task($actor, $parent->id)->subtaskProgress())->toBe(['total' => 11, 'completed' => 1, 'percent' => 9]);
});

it('retains subtask linkage through legacy CSV updates and imports new work at the root', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $parent = cpaTask($owner, $project, []);
    $child = subtaskCreate($actor, $project, $parent->id);
    $fields = ['Code' => $child->code, 'Task' => $child->title, 'Assignees' => '', 'Priority' => 'High', 'Start date' => '', 'Due date' => '2026-11-20', 'Status' => 'Pending'];
    expect(app(ImportTasks::class)->row($actor, $project->id, new ImportRow(2, $fields)))->toBe(ImportOutcome::UPDATED)
        ->and($child->fresh()->parent_id)->toBe($parent->id)->and($child->fresh()->group_id)->toBeNull();
    $fields['Code'] = '';
    $fields['Task'] = 'Imported root';
    expect(app(ImportTasks::class)->row($actor, $project->id, new ImportRow(3, $fields)))->toBe(ImportOutcome::CREATED)
        ->and(ProjectTask::query()->where('title', 'Imported root')->sole()->parent_id)->toBeNull();
});

it('offers creation only for editable parent scope and rechecks the acting managers rights', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee);
    $project = cpaProject($owner, cpaClient($owner, [$worker]));
    $parent = cpaTask($owner, $project, [$employee]);
    expect(fn () => subtaskCreate(Access::forUser($worker), $project, $parent->id))->toThrow(RuntimeException::class);
    cpaLogin($worker);
    Livewire::test(Show::class, ['project' => $project->id])->call('openSubtaskCreation', $parent->id)->assertSet('adding', false)->assertSet('taskParentId', null);
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->call('showSubtasks', $parent->id);
    $page->assertViewHas('canCreateTasks', true);
    $parent->update(['status' => 'COMPLETED']);
    $page->call('$refresh')->assertViewHas('canCreateTasks', false);
    $page->call('openSubtaskCreation', $parent->id)->assertSet('adding', false)->assertSet('taskParentId', null);
    $page->call('clearParent')->assertViewHas('canCreateTasks', true);
    expect(ProjectTask::query()->count())->toBe(1);
});

it('keeps parents beside their children in the default grouped list without overriding explicit sorts', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $first = cpaTask($owner, $project, [], title: 'First parent');
    $second = cpaTask($owner, $project, [], title: 'Second parent');
    $secondChild = subtaskCreate($actor, $project, $second->id, 'Second child');
    $firstChild = subtaskCreate($actor, $project, $first->id, 'First child');
    $firstChild->update(['status' => 'IN_PROGRESS']);
    $filters = ['q' => '', 'status' => 'ALL', 'priority' => 'ALL', 'assignee' => 'ALL', 'queue' => 'ALL'];
    $lists = app(WorkLists::class);
    expect($lists->taskPage($actor, $project->id, $filters, 'stack', 'asc', '2026-11-01', 1, 20, true)->pluck('id')->all())->toBe([$first->id, $firstChild->id, $second->id, $secondChild->id]);
    expect($lists->taskPage($actor, $project->id, $filters, 'task', 'asc', '2026-11-01', 1, 20, true)->pluck('title')->all())->toBe(['First child', 'First parent', 'Second child', 'Second parent']);
    expect($lists->taskPage($actor, $project->id, $filters, 'stack', 'asc', '2026-11-01', 1, 20)->first()->id)->toBe($firstChild->id);
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->set('taskView', 'list')->set('group', 'NONE')
        ->assertViewHas('rows', fn ($rows): bool => $rows->pluck('id')->all() === [$first->id, $firstChild->id, $second->id, $secondChild->id]);
});

it('retains an open child draft when its parent closes before saving', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $parent = cpaTask($owner, $project, []);
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->call('showSubtasks', $parent->id)->call('openTaskCreation')->set('taskTitle', 'Keep my child draft');
    $parent->update(['status' => 'COMPLETED']);
    $page->call('saveTask')->assertSet('adding', true)->assertSet('taskTitle', 'Keep my child draft')->assertSee('New subtask');
    $page->assertViewHas('canCreateTasks', false);
    $page->set('adding', false)->assertSet('openTaskId', $parent->id)->assertSet('reviewOpen', true);
    expect(ProjectTask::query()->count())->toBe(1);
});

it('keeps grouped list and scoped child exports in the same order', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $parent = cpaTask($owner, $project, [], title: 'Parent outside child export');
    $group = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Planning');
    app(MoveTaskToGroup::class)->handle($actor, $project->id, $parent->id, $group->id, null);
    $first = subtaskCreate($actor, $project, $parent->id, 'First scoped child');
    $second = subtaskCreate($actor, $project, $parent->id, 'Second scoped child');
    cpaLogin($owner);
    $csv = $this->get(route('projects.tasks.export', ['project' => $project->id, 'parent' => $parent->id, 'group' => $group->id, 'grouped' => 1, 'sort' => 'code']))->assertOk()->streamedContent();
    expect($csv)->toContain($first->title, $second->title)->not->toContain($parent->title);
    $filters = ['q' => '', 'status' => 'ALL', 'priority' => 'ALL', 'assignee' => 'ALL', 'queue' => 'ALL'];
    $rows = app(WorkLists::class)->taskPage($actor, $project->id, $filters, 'code', 'asc', '2026-11-01', 1, 20, true);
    expect($rows->count())->toBe(3)->and($rows->pluck('group_name')->unique()->all())->toBe(['Planning']);
});
