<?php

declare(strict_types=1);

use App\Livewire\Projects\Show;
use App\Models\ProjectTask;
use App\Modules\WorkManagement\Application\Commands\CreateTaskInStatus;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Commands\StartTask;
use App\Modules\WorkManagement\Application\Commands\SubmitTask;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskDetails;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Modules\WorkManagement\Application\TaskPropertyEdit;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('keeps an unchanged or reverted project draft free of discard prompts', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->call('openProjectDetails')->call('openEdit');
    $page->set('editName', 'Changed')->set('editName', ' '.$project->name.' ')->call('cancelProjectEdit')
        ->assertNotDispatched('social-confirm')->assertSet('editing', false)->assertSet('detailsOpen', true);
    $page->call('closeProjectDetails')->assertNotDispatched('social-confirm')->assertSet('detailsOpen', false);
    expect($project->fresh()->name)->toBe($project->name);
});

it('asks before discarding each changed project field and preserves the draft', function (string $field, string $value) {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $before = $project->getRawOriginal();
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->call('openProjectDetails')->call('openEdit')
        ->set($field, $value)->call('cancelProjectEdit')
        ->assertDispatched('social-confirm', fn ($name, $data) => $data['call'] === ['discardProjectDetails', false] && $data['cancel'] === 'Keep editing')
        ->assertSet('editing', true)->assertSet('detailsOpen', true)->assertSet($field, $value);
    expect($project->fresh()->getRawOriginal())->toBe($before);
})->with([
    ['editName', 'Changed name'], ['editDescription', 'Changed description'], ['editSiteId', 'different-site'],
    ['editStatus', 'ON_HOLD'], ['editStartDate', '2040-01-01'], ['editDueDate', '2040-02-01'], ['editManagerId', '123456'],
]);

it('discards only the draft and respects the requested details destination', function (bool $close) {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->call('openProjectDetails')->call('openEdit')->set('editName', 'Unsaved');
    if ($close) {
        $page->call('closeProjectDetails')->assertDispatched('social-confirm', fn ($name, $data) => $data['call'] === ['discardProjectDetails', true]);
    }
    $page->call('discardProjectDetails', $close)->assertSet('editing', false)->assertSet('projectStatusEditing', false)
        ->assertSet('detailsOpen', ! $close)->assertSet('projectEditOriginal', []);
    expect($project->fresh()->name)->toBe($project->name);
})->with([false, true]);

it('protects an unsaved status selection without changing the stored status', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->call('openProjectDetails')->call('editProjectStatus')
        ->set('editStatus', 'ON_HOLD')->call('closeProjectDetails')->assertDispatched('social-confirm')
        ->assertSet('detailsOpen', true)->assertSet('projectStatusEditing', true)
        ->call('discardProjectDetails', false)->assertSet('detailsOpen', true)->assertSet('projectStatusEditing', false);
    expect($project->fresh()->status)->toBe('ACTIVE');
});

it('closes saved project changes without asking to discard them', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->call('openProjectDetails')->call('openEdit')
        ->set('editName', 'Saved project name')->call('saveProject')->assertHasNoErrors()
        ->assertSet('editing', false)->assertSet('projectEditOriginal', [])
        ->call('closeProjectDetails')->assertNotDispatched('social-confirm')->assertSet('detailsOpen', false);
    expect($project->fresh()->name)->toBe('Saved project name');
});

it('keeps refused edits and still asks before closing them', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->call('openProjectDetails')->call('openEdit')
        ->set('editName', '')->call('saveProject')->assertHasErrors('projectEdit')->assertSet('editing', true)
        ->call('closeProjectDetails')->assertDispatched('social-confirm')->assertSet('editName', '');
    expect($project->fresh()->name)->toBe($project->name);
});

it('compares with the opened draft when another person changes the project', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->call('openProjectDetails')->call('openEdit');
    $project->update(['name' => 'Changed elsewhere']);
    $page->call('cancelProjectEdit')->assertNotDispatched('social-confirm')->assertSet('editing', false);
    expect($project->fresh()->name)->toBe('Changed elsewhere');
});

it('gives the popup description its own accessible expansion target', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $project->update(['description' => str_repeat('Shared project description. ', 30)]);
    cpaLogin($owner);
    $html = Livewire::test(Show::class, ['project' => $project->id])->call('openProjectDetails')->html();
    $pageId = 'work-description-'.md5($project->description);
    expect(substr_count($html, 'id="'.$pageId.'"'))->toBe(1)
        ->and(substr_count($html, 'id="project-details-description-'.$project->id.'"'))->toBe(1);
});

it('opens project facts and members read-only while keeping the current section in place', function () {
    $owner = cpaUser(cpaEmployee('Owner'));
    $viewer = cpaUser(cpaEmployee('Viewer'));
    $client = cpaClient($owner, [$viewer]);
    $project = cpaProject($owner, $client);
    cpaTask($owner, $project, [$viewer->employee]);
    cpaLogin($viewer);
    $page = Livewire::test(Show::class, ['project' => $project->id])->set('taskView', 'board');
    $page->assertSee('Active')->assertDontSeeHtml('aria-label="Project status"');
    $page->call('openProjectDetails')->assertSet('detailsOpen', true)->assertSet('editing', false)->assertSet('taskView', 'board')
        ->assertSee('Viewer Member')->assertDontSee('Edit details')->assertDontSeeHtml('id="project-edit-name"');
    $page->assertSeeHtml(e(route('projects.index', ['client' => $client->id, 'portfolio' => 'ALL'])));
    $page->call('editProjectStatus')->assertSet('projectStatusEditing', false)->call('openEdit')->assertSet('editing', false);
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->call('openProjectDetails')->call('editProjectStatus')
        ->assertSet('projectStatusEditing', true)->set('editStatus', 'ON_HOLD')->call('saveProjectStatus')
        ->assertSet('projectStatusEditing', false)->assertHasNoErrors();
    expect($project->fresh()->status)->toBe('ON_HOLD');
});

it('offers creation in every board header and renders all cards without subtask indentation', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->set('taskView', 'board');
    foreach (['Pending', 'In Progress', 'Submitted', 'Returned', 'Completed', 'Cancelled'] as $label) {
        $page->assertSeeHtml('aria-label="Add a task to '.$label.'"');
    }
    $page->assertDontSeeHtml('class="mt-3 w-full"')->assertDontSeeHtml('ppm-subtask-card')->assertSee('No tasks');
    $page->call('openTaskCreation', null, 'IN_PROGRESS')->assertSet('taskCreateStatus', 'IN_PROGRESS');
    $page->call('openTaskCreation', null, 'SUBMITTED')->assertSet('taskCreateStatus', 'PENDING')
        ->assertSee('Submission and return need a recorded response and review.');
});

it('creates in an allowed column through the canonical status command', function (string $status) {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = app(CreateTaskInStatus::class)->handle(Access::forUser($owner), $project->id, new TaskDraft('Created in column', '', 'NORMAL', '', []), $status);
    expect($task->status)->toBe($status);
    expect(ProjectTask::query()->sole()->completed_at !== null)->toBe($status === 'COMPLETED');
})->with(['PENDING', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED']);

it('rolls back the entire creation when the requested column is unauthorized or unsupported', function () {
    $owner = cpaUser();
    $editor = cpaUser(cpaEmployee('Editor'));
    $project = cpaProject($owner, cpaClient($owner, [$editor]));
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $editor->id, 'EDITOR');
    $before = DB::table('audit_logs')->count();
    foreach (['IN_PROGRESS', 'COMPLETED', 'SUBMITTED', 'RETURNED', 'unknown'] as $status) {
        expect(fn () => app(CreateTaskInStatus::class)->handle(Access::forUser($editor), $project->id, new TaskDraft('Must not persist', '', 'NORMAL', '', [$editor->employee_id]), $status))->toThrow(RuntimeException::class);
    }
    expect(ProjectTask::query()->count())->toBe(0)->and(DB::table('notifications')->count())->toBe(0)->and(DB::table('audit_logs')->count())->toBe($before);
});

it('patches only the chosen task property and refuses a stale version of that property', function () {
    $owner = cpaUser();
    $employee = cpaEmployee('Assigned');
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    $task->syncAssignees([$employee->id], $owner->id);
    $actor = Access::forUser($owner);
    $command = app(UpdateTaskDetails::class);
    $originalDue = $task->due_date->format('Y-m-d');
    $command->handle($actor, $project->id, $task->id, new TaskPropertyEdit('due', '2026-11-21', $originalDue));
    $command->handle($actor, $project->id, $task->id, new TaskPropertyEdit('priority', 'HIGH', 'NORMAL'));
    expect($task->fresh()->due_date->format('Y-m-d'))->toBe('2026-11-21')->and($task->fresh()->priority)->toBe('HIGH')->and($task->fresh()->assigneeIds())->toBe([$employee->id]);
    expect(fn () => $command->handle($actor, $project->id, $task->id, new TaskPropertyEdit('priority', 'LOW', 'NORMAL')))->toThrow(RuntimeException::class, 'changed elsewhere');
    expect($task->fresh()->priority)->toBe('HIGH');
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->call('openTask', $task->id)->call('saveTaskProperty', 'priority', 'LOW')
        ->assertHasNoErrors()->assertDispatched('task-property-saved');
    expect($task->fresh()->priority)->toBe('LOW')->and($task->fresh()->due_date->format('Y-m-d'))->toBe('2026-11-21');
});

it('does not let the creator approve their own newly assigned task', function () {
    $owner = cpaUser(cpaEmployee('Owner'));
    $project = cpaProject($owner, cpaClient($owner));
    expect(fn () => app(CreateTaskInStatus::class)->handle(Access::forUser($owner), $project->id, new TaskDraft('Own work', '', 'NORMAL', '', [$owner->employee_id]), 'COMPLETED'))->toThrow(RuntimeException::class);
    expect(ProjectTask::query()->count())->toBe(0)->and(DB::table('notifications')->count())->toBe(0);
});

it('preserves handed-in work when an inline response-mode change is refused', function () {
    $owner = cpaUser();
    $first = cpaUser(cpaEmployee('First'), ['PROJECTS', 'TASKS']);
    $second = cpaUser(cpaEmployee('Second'), ['PROJECTS', 'TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$first, $second]));
    $task = cpaTask($owner, $project, [$first->employee, $second->employee]);
    $actor = Access::forUser($owner);
    app(UpdateTaskDetails::class)->handle($actor, $project->id, $task->id, new TaskPropertyEdit('mode', 'INDIVIDUAL', 'GROUP'));
    app(StartTask::class)->handle(Access::forUser($first), $task->id);
    app(SubmitTask::class)->handle(Access::forUser($first), $task->id, 'Private submitted work');
    $before = DB::table('project_task_submissions')->get()->toJson();
    expect(fn () => app(UpdateTaskDetails::class)->handle($actor, $project->id, $task->id, new TaskPropertyEdit('mode', 'GROUP', 'INDIVIDUAL')))->toThrow(RuntimeException::class);
    expect($task->fresh()->submission_mode)->toBe('INDIVIDUAL')->and(DB::table('project_task_submissions')->get()->toJson())->toBe($before);
});

it('keeps the inline draft and explains a stale property without reporting success', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->call('openTask', $task->id)->set('draftPriority', 'HIGH');
    $task->update(['priority' => 'LOW']);
    $page->call('saveTaskProperty', 'priority')->assertHasErrors('taskProperty')->assertSet('draftPriority', 'HIGH')->assertNotDispatched('task-property-saved');
    expect($task->fresh()->priority)->toBe('LOW');
    $page->call('resetDetails')->assertHasNoErrors()->assertSet('draftPriority', 'LOW');
});

it('resets the assignee chooser and its discarded selection without changing the saved crew', function () {
    $owner = cpaUser();
    $first = cpaUser(cpaEmployee('First'));
    $second = cpaUser(cpaEmployee('Second'));
    $project = cpaProject($owner, cpaClient($owner, [$first, $second]));
    $task = cpaTask($owner, $project, [$first->employee]);
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->call('openTask', $task->id);
    $nonce = $page->get('reviewAssigneeNonce');
    $page->set('draftAssigneeIds', [$first->employee_id, $second->employee_id])->call('resetDetails')
        ->assertSet('draftAssigneeIds', [$first->employee_id])->assertSet('reviewAssigneeNonce', $nonce + 1);
    expect($task->fresh()->assigneeIds())->toBe([$first->employee_id]);
});

it('colors overdue project deadlines only while the project is open', function (string $status, bool $late) {
    $project = (object) ['status' => $status, 'due_date' => CarbonImmutable::parse('2026-11-01')];
    $html = Blade::render('<x-work.project-deadline :project="$project" today="2026-11-10" />', compact('project'));
    expect(str_contains($html, 'text-danger'))->toBe($late);
})->with([['ACTIVE', true], ['ON_HOLD', true], ['PLANNING', true], ['COMPLETED', false], ['CANCELLED', false]]);
